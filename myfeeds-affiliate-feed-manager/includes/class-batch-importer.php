<?php
/**
 * MyFeeds Batch Importer v2.4
 * Unified Feed Processing with Smart Mapper Integration
 * 
 * Architecture v2.4: ONE AS JOB = ONE COMPLETE FEED
 * - A single Action Scheduler job processes an entire feed in an internal loop
 * - Only at feed boundaries is a new AS job scheduled
 * - This reduces AS jobs from ~200+ to ~5 (one per feed)
 * - AS queue runner time limit set to 300s via WP filter
 * - AS queue runner batch size set to 1 (one job per tick)
 * - Batch size 1000 rows per iteration for memory safety
 * - set_time_limit(0) for AS background jobs
 * - 90s watchdog with heartbeat + FeedCache check
 * - Force-overwrite prices in Quick Sync
 * - Unavailable product detection + marking
 */

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// FEATURE FLAG: Action Scheduler Debug Logging
// Set to false to disable all AS diagnostic logging
// =============================================================================
if (!defined('MYFEEDS_AS_DEBUG')) {
    define('MYFEEDS_AS_DEBUG', true);
}

/**
 * Ultra-safe structured logger for Action Scheduler diagnostics
 * JSON format, grepable, never crashes callbacks
 * 
 * @param string $event Event name (e.g. 'batch_start', 'schedule_attempt')
 * @param array $data Payload data (scalars only, no big arrays)
 * @param string|null $run_id Unique run ID for correlation
 */
function myfeeds_as_log($event, array $data = array(), $run_id = null) {
    // Feature flag check
    if (!defined('MYFEEDS_AS_DEBUG') || MYFEEDS_AS_DEBUG !== true) {
        return;
    }
    
    // AS diagnostic logs are debug-level only — skip at info/error to reduce log volume
    $current_level = get_option('myfeeds_log_level', 'info');
    if ($current_level !== 'debug') {
        return;
    }
    
    try {
        // Auto-generate run_id if not provided
        if ($run_id === null) {
            $run_id = uniqid('as_', true);
        }
        
        // Build payload with auto fields
        $payload = array(
            'ts_iso' => gmdate('c'),
            'micro' => microtime(true),
            'run_id' => $run_id,
            'pid' => function_exists('getmypid') ? getmypid() : null,
            'event' => $event,
            'data' => $data,
        );
        
        // Build JSON line
        $json_line = 'MYFEEDS_AS: ' . wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        
        // Write to uploads/myfeeds-debug.log
        myfeeds_log($json_line, 'debug');
        
    } catch (\Throwable $e) {
        // Silently continue - logging must never crash callbacks (\Throwable catches both Exception and Error)
    }
}

/**
 * Helper: Get short summary of batch state option (safe, no big arrays)
 * 
 * @param string $option_name Option name to read
 * @return array Short summary
 */
function myfeeds_as_get_state_summary($option_name) {
    try {
        $value = get_option($option_name, null);
        
        if ($value === null) {
            return array('type' => 'null');
        }
        
        if (!is_array($value)) {
            return array('type' => gettype($value), 'value' => substr((string)$value, 0, 100));
        }
        
        // Extract key fields only (no big arrays)
        $summary = array('type' => 'array');
        $safe_keys = array(
            'status', 'mode', 'phase', 'feed_index', 'current_feed_index',
            'offset', 'current_offset', 'processed', 'processed_products',
            'total', 'total_products', 'total_feeds', 'processed_feeds',
            'started_at', 'last_activity', 'last_updated'
        );
        
        foreach ($safe_keys as $key) {
            if (isset($value[$key])) {
                $v = $value[$key];
                // Keep scalars only
                if (is_scalar($v)) {
                    $summary[$key] = $v;
                } elseif (is_array($v)) {
                    $summary[$key] = 'array(' . count($v) . ')';
                }
            }
        }
        
        return $summary;
        
    } catch (\Throwable $e) {
        return array('type' => 'error', 'msg' => $e->getMessage());
    }
}

/**
 * Helper: Get short summary of queue state (safe, no big arrays)
 * 
 * @param array $queue Queue array
 * @return array Short summary per feed
 */
function myfeeds_as_get_queue_summary($queue) {
    try {
        if (!is_array($queue)) {
            return array('type' => gettype($queue));
        }
        
        $summary = array('count' => count($queue), 'feeds' => array());
        
        foreach ($queue as $idx => $feed) {
            if (!is_array($feed)) continue;
            $summary['feeds'][$idx] = array(
                'name' => isset($feed['feed_name']) ? substr($feed['feed_name'], 0, 30) : '?',
                'phase' => $feed['phase'] ?? '?',
                'status' => $feed['status'] ?? '?',
                'offset' => $feed['offset'] ?? 0,
                'total_rows' => $feed['total_rows'] ?? 0,
            );
        }
        
        return $summary;
        
    } catch (\Throwable $e) {
        return array('type' => 'error', 'msg' => $e->getMessage());
    }
}

/**
 * Simple Logger for MyFeeds/MyFeeds
 * Provides robust error logging for background tasks
 */
if (!class_exists('MyFeeds_Logger')) {
    class MyFeeds_Logger {
        
        public static function log($message, $level = 'INFO') {
            // Respect global log level setting
            $config_level = get_option('myfeeds_log_level', 'info');
            $levels = array('error' => 0, 'info' => 1, 'debug' => 2);
            $msg_priority = $levels[strtolower($level)] ?? 2;
            $threshold = $levels[$config_level] ?? 1;
            
            if ($msg_priority > $threshold) {
                return; // Skip: message is too verbose for current level
            }
            
            myfeeds_log("MyFeeds [{$level}]: {$message}", strtolower($level));
        }
        
        public static function error($message) {
            self::log($message, 'ERROR');
        }
        
        public static function info($message) {
            self::log($message, 'INFO');
        }
        
        public static function debug($message) {
            self::log($message, 'DEBUG');
        }
    }
}

class MyFeeds_Batch_Importer {
    
    const OPTION_IMPORT_STATUS = 'myfeeds_import_status';
    const OPTION_IMPORT_QUEUE = 'myfeeds_import_queue';
    const OPTION_ACTIVE_IDS = 'myfeeds_active_product_ids';
    const OPTION_BATCH_STATE = 'myfeeds_batch_state';  // NEW: Tracks current batch position for resume
    const CRON_HOOK = 'myfeeds_process_batch';         // Legacy - kept for backwards compat
    const CENTRAL_HOOK = 'myfeeds_start_feed_update';
    const INDEX_FILE = 'myfeeds-feed-index.json';
    
    // Action Scheduler constants
    const AS_GROUP = 'myfeeds';                        // NEW: Action Scheduler group name
    const AS_HOOK_PROCESS_BATCH = 'myfeeds_process_batch';  // NEW: AS hook for batch processing
    const AS_HOOK_COMPLETE = 'myfeeds_complete_import';     // NEW: AS hook for completion
    
    // Import modes
    const MODE_FULL = 'full';
    const MODE_ACTIVE_ONLY = 'active_only';
    
    // Batch size: 500 products per Action Scheduler job
    // Reduced from 2000 to ensure each batch completes well under 60 seconds,
    // even on slow shared hosting with PHP time_limit=120s.
    private $batch_size = 1000;
    private $smart_mapper;
    private $feed_manager;
    
    /**
     * Check if a value is a valid product ID.
     * Accepts numeric AND alphanumeric IDs (Webgains, Admitad etc.)
     * Minimal validation: not empty, not only whitespace, max 200 chars.
     */
    private function is_valid_product_id($id) {
        if (!is_string($id) && !is_numeric($id)) {
            return false;
        }
        $id = trim((string) $id);
        if ($id === '') {
            return false;
        }
        if (strlen($id) > 200) {
            return false;
        }
        return true;
    }
    
    public function __construct($smart_mapper = null) {
        $this->smart_mapper = $smart_mapper;
        
        // Batch size: 500 rows per job (safe for all hosting sizes)
        $this->batch_size = 1000;
    }
    
    /**
     * Set feed manager reference
     */
    public function set_feed_manager($feed_manager) {
        $this->feed_manager = $feed_manager;
    }
    
    /**
     * Spawn a background worker via wp_remote_post
     * This allows the main request to return immediately while work continues in background
     * Uses TRANSIENT-based authentication instead of user nonces (more reliable for loopback)
     * 
     * @param string $action The AJAX action to trigger
     * @param array $args Additional arguments to pass
     * @return bool Whether the worker was spawned successfully
     */
    private function spawn_background_worker($action, $args = array()) {
        try {
            $url = admin_url('admin-ajax.php');
            
            // Generate a one-time secret key for this background job
            $secret_key = wp_generate_password(32, false);
            set_transient('myfeeds_bg_secret_' . $action, $secret_key, 300); // 5 min expiry
            
            $body = array_merge(array(
                'action' => $action,
                'secret_key' => $secret_key,
                '_background' => 1,
            ), $args);
            
            MyFeeds_Logger::info("Spawning background worker: {$action}");
            
            // Fire and forget - 0.01 second timeout means we don't wait for response
            $response = wp_remote_post($url, array(
                'timeout' => 0.01,
                'blocking' => false,
                'sslverify' => false,
                'body' => $body,
            ));
            
            // Check for immediate errors (connection refused, etc.)
            if (is_wp_error($response)) {
                MyFeeds_Logger::error("Failed to spawn worker {$action}: " . $response->get_error_message());
                
                // Fallback: Try direct execution if loopback fails
                MyFeeds_Logger::info("Attempting direct execution fallback for {$action}");
                $this->execute_background_task_directly($action, $args);
                return true;
            }
            
            MyFeeds_Logger::info("Background worker spawned successfully: {$action}");
            return true;
            
        } catch (\Throwable $e) {
            MyFeeds_Logger::error("Exception spawning worker: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Fallback: Execute background task directly if loopback fails
     * This runs synchronously but ensures the task completes
     */
    private function execute_background_task_directly($action, $args) {
        set_time_limit(300);
        ignore_user_abort(true);
        
        MyFeeds_Logger::info("Direct execution: {$action}");
        
        try {
            if ($action === 'myfeeds_bg_full_update') {
                do_action(self::CENTRAL_HOOK, 'direct', array('mode' => self::MODE_FULL));
            } elseif ($action === 'myfeeds_bg_quick_sync') {
                $active_ids = isset($args['active_ids']) ? json_decode($args['active_ids'], true) : array();
                if (!empty($active_ids)) {
                    $this->start_active_only_import($active_ids);
                }
            }
        } catch (\Throwable $e) {
            MyFeeds_Logger::error("Direct execution failed: " . $e->getMessage());
        }
    }
    
    /**
     * Initialize batch importer hooks
     */
    public function init() {
        // =====================================================================
        // ACTION SCHEDULER HOOKS (NEW - Primary batch processing system)
        // These replace the old spawn_cron mechanism for robust, resumable jobs
        // =====================================================================
        add_action(self::AS_HOOK_PROCESS_BATCH, array($this, 'as_process_batch'), 10, 1);
        add_action(self::AS_HOOK_COMPLETE, array($this, 'as_complete_import'), 10, 0);
        
        // Legacy cron hook - kept as fallback when Action Scheduler is NOT available
        add_action(self::CRON_HOOK, array($this, 'process_next_batch'));
        
        // Central hook - unified entry point for all feed updates
        // Now accepts $mode parameter: 'full' or 'active_only'
        add_action(self::CENTRAL_HOOK, array($this, 'handle_central_update'), 10, 2);
        
        // AJAX handlers for admin
        add_action('wp_ajax_myfeeds_start_batch_import', array($this, 'ajax_start_batch_import'));
        add_action('wp_ajax_myfeeds_get_import_status', array($this, 'ajax_get_import_status'));
        add_action('wp_ajax_myfeeds_cancel_import', array($this, 'ajax_cancel_import'));
        add_action('wp_ajax_myfeeds_unified_rebuild', array($this, 'ajax_unified_rebuild'));
        add_action('wp_ajax_myfeeds_quick_sync_active', array($this, 'ajax_quick_sync_active'));
        
        // Background worker handlers (non-blocking execution) - LEGACY, kept for fallback
        add_action('wp_ajax_myfeeds_bg_full_update', array($this, 'ajax_background_full_update'));
        add_action('wp_ajax_myfeeds_bg_quick_sync', array($this, 'ajax_background_quick_sync'));
        add_action('wp_ajax_nopriv_myfeeds_bg_full_update', array($this, 'ajax_background_full_update'));
        add_action('wp_ajax_nopriv_myfeeds_bg_quick_sync', array($this, 'ajax_background_quick_sync'));
        
        // Daily cron schedule (Quick Sync)
        add_action('myfeeds_daily_feed_index', array($this, 'trigger_daily_update'));
        
        // Weekly cron schedule (Full Import)
        add_action('myfeeds_weekly_full_import', array($this, 'trigger_weekly_update'));
        
        // Legacy queue checker: only active when Action Scheduler is NOT available
        add_action('myfeeds_check_import_queue', array($this, 'check_and_process_queue'));
        
        // If Action Scheduler is available, remove legacy minute-by-minute cron
        // to prevent race conditions between AS and legacy batch processing
        if (function_exists('as_has_scheduled_action')) {
            wp_clear_scheduled_hook('myfeeds_check_import_queue');
        } else {
            // Fallback: Legacy cron only if AS is not available
            if (!wp_next_scheduled('myfeeds_check_import_queue')) {
                wp_schedule_event(time(), 'every_minute', 'myfeeds_check_import_queue');
            }
        }
    }
    
    /**
     * Background worker: Execute Full Update
     * This runs in a separate request spawned by spawn_background_worker()
     * Uses TRANSIENT-based authentication (no user session required)
     */
    public function ajax_background_full_update() {
        MyFeeds_Logger::info('Background Full Update: Handler called');
        
        // Verify this is a background request
        if (empty($_POST['_background'])) {
            MyFeeds_Logger::error('Background Full Update: Not a background request');
            wp_die('Direct access not allowed');
        }
        
        // Verify secret key (transient-based auth)
        $provided_key = sanitize_text_field($_POST['secret_key'] ?? '');
        $stored_key = get_transient('myfeeds_bg_secret_myfeeds_bg_full_update');
        
        if (empty($provided_key) || $provided_key !== $stored_key) {
            MyFeeds_Logger::error('Background Full Update: Invalid secret key');
            wp_die('Invalid authentication');
        }
        
        // Delete transient after use (one-time key)
        delete_transient('myfeeds_bg_secret_myfeeds_bg_full_update');
        
        // Increase time limit for background processing
        set_time_limit(300);
        ignore_user_abort(true);
        
        MyFeeds_Logger::info('Background Full Update: Starting execution');
        
        try {
            do_action(self::CENTRAL_HOOK, 'background', array('mode' => self::MODE_FULL));
            MyFeeds_Logger::info('Background Full Update: Completed successfully');
        } catch (\Throwable $e) {
            MyFeeds_Logger::error('Background Full Update failed: ' . $e->getMessage());
            
            // Update status to show error
            $status = get_option(self::OPTION_IMPORT_STATUS, array());
            $status['status'] = 'error';
            $status['errors'][] = array('message' => $e->getMessage());
            update_option(self::OPTION_IMPORT_STATUS, $status, false);
        }
        
        wp_die();
    }
    
    /**
     * Background worker: Execute Quick Sync
     * This runs in a separate request spawned by spawn_background_worker()
     * Uses TRANSIENT-based authentication (no user session required)
     */
    public function ajax_background_quick_sync() {
        MyFeeds_Logger::info('Background Quick Sync: Handler called');
        
        // Verify this is a background request
        if (empty($_POST['_background'])) {
            MyFeeds_Logger::error('Background Quick Sync: Not a background request');
            wp_die('Direct access not allowed');
        }
        
        // Verify secret key (transient-based auth)
        $provided_key = sanitize_text_field($_POST['secret_key'] ?? '');
        $stored_key = get_transient('myfeeds_bg_secret_myfeeds_bg_quick_sync');
        
        if (empty($provided_key) || $provided_key !== $stored_key) {
            MyFeeds_Logger::error('Background Quick Sync: Invalid secret key');
            wp_die('Invalid authentication');
        }
        
        // Delete transient after use (one-time key)
        delete_transient('myfeeds_bg_secret_myfeeds_bg_quick_sync');
        
        // Increase time limit for background processing
        set_time_limit(300);
        ignore_user_abort(true);
        
        $active_ids = isset($_POST['active_ids']) ? json_decode(stripslashes($_POST['active_ids']), true) : array();
        
        // Fallback: Try to get from option if not in POST
        if (empty($active_ids)) {
            $active_ids = get_option(self::OPTION_ACTIVE_IDS, array());
        }
        
        if (empty($active_ids)) {
            MyFeeds_Logger::error('Background Quick Sync: No active IDs available');
            wp_die('No active IDs');
        }
        
        MyFeeds_Logger::info('Background Quick Sync: Starting with ' . count($active_ids) . ' products');
        
        try {
            $this->start_active_only_import($active_ids);
            MyFeeds_Logger::info('Background Quick Sync: Completed successfully');
        } catch (\Throwable $e) {
            MyFeeds_Logger::error('Background Quick Sync failed: ' . $e->getMessage());
            
            // Update status to show error
            $status = get_option(self::OPTION_IMPORT_STATUS, array());
            $status['status'] = 'error';
            $status['errors'][] = array('message' => $e->getMessage());
            update_option(self::OPTION_IMPORT_STATUS, $status, false);
        }
        
        wp_die();
    }
    
    /**
     * Register custom cron intervals
     */
    public static function register_cron_intervals($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute', 'myfeeds-affiliate-feed-manager')
        );
        $schedules['every_30_seconds'] = array(
            'interval' => 30,
            'display' => __('Every 30 Seconds', 'myfeeds-affiliate-feed-manager')
        );
        return $schedules;
    }
    
    // =========================================================================
    // ACTIVE PRODUCTS DISCOVERY - Find products in use
    // =========================================================================
    
    /**
     * Get product IDs that are actively used in posts/pages
     * STRICT: Only returns IDs found in actual MyFeeds blocks/shortcodes, NOT from index!
     * OPTIMIZED: Only searches in PUBLISHED posts (not drafts/revisions)
     * PRECISION: Only extracts IDs from recognized MyFeeds patterns - no false positives!
     * 
     * @param bool $include_index_ids If true, also includes IDs from existing index (for Full Update only)
     * @return array Array of unique product IDs in use
     */
    public function get_active_product_ids($include_index_ids = false) {
        global $wpdb;
        
        $product_ids = array();
        $discovery_stats = array(
            'resolver_gutenberg_blocks' => 0,
            'resolver_shortcodes' => 0,
            'resolver_json_attrs' => 0,
            'posts_scanned' => 0,
            'posts_with_blocks' => 0,
            'posts_empty_content' => 0,
            'block_names_found' => array(),
        );
        
        MyFeeds_Logger::info('=== DISCOVERY START ===');
        MyFeeds_Logger::debug('Discovery: include_index_ids=' . ($include_index_ids ? 'true' : 'false'));
        
        // =====================================================================
        // STEP 1: Count total publish posts (diagnostic)
        // =====================================================================
        $total_publish = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page')"
        );
        MyFeeds_Logger::debug("Discovery: Total publish posts/pages in DB: {$total_publish}");
        
        // =====================================================================
        // STEP 2: Search for content with MyFeeds markers
        // DIAGNOSTIC: Log which LIKE patterns matched
        // =====================================================================
        $posts = $wpdb->get_results(
            "SELECT ID, post_content FROM {$wpdb->posts} 
             WHERE post_status = 'publish'
             AND post_type IN ('post', 'page')
             AND post_content != ''
             AND (
                 post_content LIKE '%wp:myfeeds%' 
                 OR post_content LIKE '%[myfeeds%'
                 OR post_content LIKE '%wp:product-picker%'
             )"
        );
        
        $discovery_stats['posts_scanned'] = count($posts);
        MyFeeds_Logger::debug("Discovery SQL: Found " . count($posts) . " posts matching LIKE patterns (wp:myfeeds, [myfeeds, wp:product-picker)");
        
        // =====================================================================
        // DIAGNOSTIC: Also check what we're MISSING - posts with other patterns
        // This helps identify if block names differ from expected
        // =====================================================================
        $posts_with_any_block = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_status = 'publish'
             AND post_type IN ('post', 'page')
             AND post_content LIKE '%<!-- wp:%'"
        );
        MyFeeds_Logger::debug("Discovery: Posts with ANY Gutenberg blocks: {$posts_with_any_block}");
        
        // Check for myfeeds/product-picker pattern specifically (diagnostic)
        $posts_with_my_product_picker = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_status = 'publish'
             AND post_type IN ('post', 'page')
             AND (post_content LIKE '%myfeeds/product-picker%' OR post_content LIKE '%my-product-picker%')"
        );
        MyFeeds_Logger::debug("Discovery DIAGNOSTIC: Posts with 'myfeeds' block in content: {$posts_with_my_product_picker}");
        
        if (!empty($posts)) {
            foreach ($posts as $post) {
                $post_ids_before = count($product_ids);
                
                // Check if post_content is empty (diagnostic for builder detection)
                if (empty(trim($post->post_content))) {
                    $discovery_stats['posts_empty_content']++;
                    MyFeeds_Logger::debug("Discovery Post #{$post->ID}: post_content is empty (may use page builder meta)");
                    continue;
                }
                
                // =====================================================================
                // RESOLVER 1: Parse Gutenberg blocks (MOST RELIABLE)
                // DIAGNOSTIC: Log actual block names found
                // =====================================================================
                if (function_exists('has_blocks') && has_blocks($post->post_content)) {
                    $discovery_stats['posts_with_blocks']++;
                    $blocks = parse_blocks($post->post_content);
                    
                    // DIAGNOSTIC: Collect all block names for analysis
                    $this->collect_block_names_diagnostic($blocks, $discovery_stats['block_names_found']);
                    
                    $ids_before = count($product_ids);
                    $this->extract_ids_from_blocks($blocks, $product_ids, $post->ID);
                    $ids_found = count($product_ids) - $ids_before;
                    
                    if ($ids_found > 0) {
                        $discovery_stats['resolver_gutenberg_blocks'] += $ids_found;
                        MyFeeds_Logger::debug("Discovery Post #{$post->ID}: Gutenberg resolver found {$ids_found} IDs");
                    }
                }
                
                // =====================================================================
                // RESOLVER 2: Parse MyFeeds Shortcodes ONLY
                // =====================================================================
                preg_match_all('/\[myfeeds[^\]]*\b(?:id|ids|product_id|product_ids)\s*=\s*["\']?([^"\'\]]+)["\']?[^\]]*\]/i', 
                    $post->post_content, $shortcode_matches);
                
                if (!empty($shortcode_matches[1])) {
                    foreach ($shortcode_matches[1] as $match) {
                        $ids = array_filter(array_map('trim', explode(',', $match)));
                        foreach ($ids as $id) {
                            if ($this->is_valid_product_id($id)) {
                                if (!isset($product_ids[$id])) {
                                    $discovery_stats['resolver_shortcodes']++;
                                }
                                $product_ids[$id] = true;
                            }
                        }
                    }
                    MyFeeds_Logger::debug("Discovery Post #{$post->ID}: Shortcode resolver found " . count($shortcode_matches[1]) . " matches");
                }
                
                // =====================================================================
                // RESOLVER 3: Parse JSON attributes in Gutenberg block comments
                // =====================================================================
                preg_match_all('/<!--\s*wp:(?:myfeeds|product-picker)[^\s]*\s+(\{[^}]+\})\s*-->/i', 
                    $post->post_content, $block_matches);
                
                if (!empty($block_matches[1])) {
                    $ids_before = count($product_ids);
                    foreach ($block_matches[1] as $json_str) {
                        $attrs = json_decode($json_str, true);
                        if (is_array($attrs)) {
                            $id_attrs = array('productId', 'product_id', 'ids', 'productIds', 'selectedProducts', 'id');
                            foreach ($id_attrs as $attr) {
                                if (isset($attrs[$attr])) {
                                    $this->extract_ids_from_value($attrs[$attr], $product_ids);
                                }
                            }
                        }
                    }
                    $ids_found = count($product_ids) - $ids_before;
                    $discovery_stats['resolver_json_attrs'] += $ids_found;
                    MyFeeds_Logger::debug("Discovery Post #{$post->ID}: JSON attr resolver found {$ids_found} IDs");
                }
                
                $post_total = count($product_ids) - $post_ids_before;
                if ($post_total > 0) {
                    MyFeeds_Logger::debug("Discovery Post #{$post->ID}: Total {$post_total} unique IDs extracted");
                }
            }
        }
        
        // Only for Full Update: Also get IDs from existing index
        if ($include_index_ids) {
            $this->get_ids_from_existing_index($product_ids);
        }
        
        // Convert to indexed array
        $result = array_keys($product_ids);
        
        // =====================================================================
        // DIAGNOSTIC SUMMARY
        // =====================================================================
        MyFeeds_Logger::info('=== DISCOVERY SUMMARY ===');
        MyFeeds_Logger::info("Discovery: Posts scanned: {$discovery_stats['posts_scanned']}");
        MyFeeds_Logger::info("Discovery: Posts with Gutenberg blocks: {$discovery_stats['posts_with_blocks']}");
        MyFeeds_Logger::info("Discovery: Posts with empty content: {$discovery_stats['posts_empty_content']}");
        MyFeeds_Logger::info("Discovery: IDs from Gutenberg resolver: {$discovery_stats['resolver_gutenberg_blocks']}");
        MyFeeds_Logger::info("Discovery: IDs from Shortcode resolver: {$discovery_stats['resolver_shortcodes']}");
        MyFeeds_Logger::info("Discovery: IDs from JSON attr resolver: {$discovery_stats['resolver_json_attrs']}");
        MyFeeds_Logger::info("Discovery: TOTAL unique IDs found: " . count($result));
        
        // Log unique block names found (critical for debugging)
        if (!empty($discovery_stats['block_names_found'])) {
            $block_names_list = implode(', ', array_keys($discovery_stats['block_names_found']));
            MyFeeds_Logger::info("Discovery: Block names found in content: [{$block_names_list}]");
        } else {
            MyFeeds_Logger::info("Discovery: NO block names found in scanned posts");
        }
        
        MyFeeds_Logger::info('=== DISCOVERY END ===');
        
        return $result;
    }
    
    /**
     * DIAGNOSTIC ONLY: Collect all block names recursively for analysis
     * This helps identify if block names differ from expected patterns
     */
    private function collect_block_names_diagnostic($blocks, &$block_names) {
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if ($name) {
                $block_names[$name] = ($block_names[$name] ?? 0) + 1;
            }
            if (!empty($block['innerBlocks'])) {
                $this->collect_block_names_diagnostic($block['innerBlocks'], $block_names);
            }
        }
    }
    
    /**
     * Helper: Extract IDs from various value types
     * ENHANCED: Now handles both direct IDs and object arrays with ID fields
     */
    private function extract_ids_from_value($value, &$product_ids) {
        if (is_array($value)) {
            foreach ($value as $v) {
                // Check if this is an object/associative array with ID fields
                if (is_array($v) || is_object($v)) {
                    $v_arr = (array) $v;
                    $extracted = false;
                    
                    // Try to extract ID from known field names
                    foreach (['id', 'product_id', 'productId', 'external_id', 'aw_product_id'] as $id_field) {
                        if (isset($v_arr[$id_field]) && !empty($v_arr[$id_field])) {
                            $extracted_id = (string) $v_arr[$id_field];
                            if ($this->is_valid_product_id($extracted_id)) {
                                $product_ids[$extracted_id] = true;
                                $extracted = true;
                                break;
                            }
                        }
                    }
                    
                    // If no ID field found, recurse into the array
                    if (!$extracted) {
                        $this->extract_ids_from_value($v, $product_ids);
                    }
                } else {
                    // Direct value, recurse
                    $this->extract_ids_from_value($v, $product_ids);
                }
            }
        } elseif (is_string($value)) {
            // Handle comma-separated IDs (split by comma only, NOT spaces — IDs may contain spaces)
            $ids = array_filter(array_map('trim', explode(',', $value)));
            foreach ($ids as $id) {
                if ($this->is_valid_product_id($id)) {
                    $product_ids[$id] = true;
                }
            }
        } elseif (is_numeric($value)) {
            $id_str = trim((string) $value);
            if ($id_str !== '' && strlen($id_str) <= 200) {
                $product_ids[$id_str] = true;
            }
        }
    }
    
    /**
     * Get product IDs from existing index file
     * Only used for Full Update priority pass, NOT for Quick Sync
     */
    private function get_ids_from_existing_index(&$product_ids) {
        $upload_dir = wp_upload_dir();
        $index_path = $upload_dir['basedir'] . '/' . self::INDEX_FILE;
        
        if (file_exists($index_path)) {
            $index_data = json_decode(file_get_contents($index_path), true);
            
            if (!empty($index_data['items'])) {
                foreach (array_keys($index_data['items']) as $id) {
                    $id_str = trim((string) $id);
                    if ($id_str !== '' && strlen($id_str) <= 200) {
                        $product_ids[$id_str] = true;
                    }
                }
                myfeeds_log('Discovery: Added ' . count($index_data['items']) . ' products from existing index', 'debug');
            }
        }
    }
    
    /**
     * Recursively extract product IDs from Gutenberg blocks
     * DIAGNOSTIC: Added detailed logging to identify attr structure mismatch
     */
    private function extract_ids_from_blocks($blocks, &$product_ids, $post_id = 0) {
        foreach ($blocks as $block) {
            $block_name = $block['blockName'] ?? '';
            
            // Check block name for MyFeeds blocks
            if (strpos($block_name, 'myfeeds') !== false) {
                MyFeeds_Logger::debug("=== BLOCK ATTR DIAGNOSIS (Post #{$post_id}) ===");
                MyFeeds_Logger::info("BLOCK_DIAG Post #{$post_id}: blockName='{$block_name}', attrs_keys=[" . (!empty($block['attrs']) ? implode(', ', array_keys($block['attrs'])) : 'EMPTY') . "], selectedProducts_count=" . (isset($block['attrs']['selectedProducts']) ? count($block['attrs']['selectedProducts']) : 'N/A'));
                MyFeeds_Logger::debug("Block: name='{$block_name}'");
                
                // Extract from block attributes
                if (!empty($block['attrs'])) {
                    $attrs = $block['attrs'];
                    $attr_keys = array_keys($attrs);
                    
                    MyFeeds_Logger::debug("Block attrs keys: [" . implode(', ', $attr_keys) . "]");
                    
                    // DIAGNOSTIC: Deep inspection of selectedProducts
                    if (isset($attrs['selectedProducts'])) {
                        $sp = $attrs['selectedProducts'];
                        $sp_type = gettype($sp);
                        $sp_count = is_array($sp) ? count($sp) : 0;
                        
                        MyFeeds_Logger::debug("selectedProducts: type={$sp_type}, count={$sp_count}");
                        
                        if (is_array($sp) && $sp_count > 0) {
                            // Log structure of first element
                            $first = $sp[0];
                            $first_type = gettype($first);
                            
                            if (is_array($first) || is_object($first)) {
                                $first_arr = (array) $first;
                                $first_keys = array_keys($first_arr);
                                MyFeeds_Logger::debug("selectedProducts[0]: type={$first_type}, keys=[" . implode(', ', $first_keys) . "]");
                                
                                // Log potential ID fields
                                foreach (['id', 'product_id', 'productId', 'external_id', 'aw_product_id'] as $id_key) {
                                    if (isset($first_arr[$id_key])) {
                                        MyFeeds_Logger::debug("selectedProducts[0]['{$id_key}'] = " . substr(json_encode($first_arr[$id_key]), 0, 100));
                                    }
                                }
                            } else {
                                // It's a scalar (ID directly)
                                MyFeeds_Logger::debug("selectedProducts[0]: type={$first_type}, value=" . substr(json_encode($first), 0, 100));
                            }
                        }
                    } else {
                        MyFeeds_Logger::debug("selectedProducts: NOT FOUND in attrs");
                    }
                    
                    // DIAGNOSTIC: Check all known ID attr patterns
                    $id_attrs = array('productId', 'product_id', 'ids', 'productIds', 'selectedProducts');
                    $found_any = false;
                    
                    foreach ($id_attrs as $attr) {
                        if (isset($attrs[$attr])) {
                            $found_any = true;
                            $value = $attrs[$attr];
                            $value_type = gettype($value);
                            
                            MyFeeds_Logger::debug("Trying attr '{$attr}': type={$value_type}");
                            
                            // ENHANCED EXTRACTION: Handle both ID arrays and object arrays
                            if (is_array($value)) {
                                foreach ($value as $item) {
                                    // Case 1: Item is a direct ID (numeric or string)
                                    if ($this->is_valid_product_id($item)) {
                                        $product_ids[trim((string) $item)] = true;
                                        MyFeeds_Logger::debug("Extracted ID (direct): " . trim((string) $item));
                                    }
                                    // Case 2: Item is an object/array with ID fields
                                    elseif (is_array($item) || is_object($item)) {
                                        $item_arr = (array) $item;
                                        // Try multiple possible ID field names
                                        foreach (['id', 'product_id', 'productId', 'external_id', 'aw_product_id'] as $id_field) {
                                            if (isset($item_arr[$id_field]) && !empty($item_arr[$id_field])) {
                                                $extracted_id = $item_arr[$id_field];
                                                if ($this->is_valid_product_id($extracted_id)) {
                                                    $product_ids[trim((string) $extracted_id)] = true;
                                                    MyFeeds_Logger::debug("Extracted ID from object['{$id_field}']: " . trim((string) $extracted_id));
                                                    break; // Found ID, no need to check other fields
                                                }
                                            }
                                        }
                                    }
                                }
                            } elseif (is_string($value)) {
                                $ids = array_filter(array_map('trim', explode(',', $value)));
                                foreach ($ids as $id) {
                                    if ($this->is_valid_product_id($id)) {
                                        $product_ids[$id] = true;
                                        MyFeeds_Logger::debug("Extracted ID (from string): {$id}");
                                    }
                                }
                            } elseif ($this->is_valid_product_id($value)) {
                                $product_ids[trim((string) $value)] = true;
                                MyFeeds_Logger::debug("Extracted ID (single value): " . trim((string) $value));
                            }
                        }
                    }
                    
                    if (!$found_any) {
                        MyFeeds_Logger::info("WARNING: Block '{$block_name}' in Post #{$post_id} has attrs but NONE of expected ID attrs found!");
                        MyFeeds_Logger::info("Available attrs: [" . implode(', ', $attr_keys) . "]");
                    }
                } else {
                    MyFeeds_Logger::info("Block '{$block_name}': attrs is EMPTY");
                    
                    // DIAGNOSTIC: Check if IDs might be in innerHTML or raw content
                    if (!empty($block['innerHTML'])) {
                        $snippet = substr($block['innerHTML'], 0, 300);
                        MyFeeds_Logger::debug("Block innerHTML snippet (first 300 chars): " . preg_replace('/\s+/', ' ', $snippet));
                    }
                }
                
                MyFeeds_Logger::debug("=== END BLOCK DIAGNOSIS ===");
            }
            
            // Recursively check inner blocks
            if (!empty($block['innerBlocks'])) {
                // DIAGNOSTIC: Log inner blocks count
                if (strpos($block_name, 'myfeeds') !== false) {
                    MyFeeds_Logger::debug("Block '{$block_name}' has " . count($block['innerBlocks']) . " innerBlocks");
                }
                $this->extract_ids_from_blocks($block['innerBlocks'], $product_ids, $post_id);
            }
        }
    }
    
    // =========================================================================
    // CENTRAL HOOK HANDLER - Unified Entry Point
    // =========================================================================
    
    /**
     * Central hook handler - combines re-mapping and rebuild
     * 
     * MODE A (Quick Sync / active_only): NUR aktive Produkte -> Ende
     * MODE B (Full Update / full): Aktive Produkte ZUERST -> dann Rest -> Ende
     * 
     * @param string $trigger Source of trigger ('manual', 'daily', 'ajax')
     * @param array $options Additional options including 'mode' (full|active_only)
     */
    public function handle_central_update($trigger = 'manual', $options = array()) {
        $mode = isset($options['mode']) ? $options['mode'] : self::MODE_FULL;
        
        // Guard against double execution (e.g. loopback + direct fallback race)
        $lock_key = 'myfeeds_import_lock_' . $mode;
        if (get_transient($lock_key)) {
            MyFeeds_Logger::info("Central update SKIPPED: Lock active for mode={$mode} (already running)");
            return new WP_Error('already_running', 'Import already in progress');
        }
        set_transient($lock_key, time(), 120); // 2 min lock (reduced from 10 min to allow faster recovery)
        
        myfeeds_log("Central update triggered: trigger={$trigger}, mode={$mode}", 'info');
        
        $feeds = get_option('myfeeds_feeds', array());
        
        if (empty($feeds)) {
            myfeeds_log('No feeds configured', 'info');
            return new WP_Error('no_feeds', 'No feeds configured');
        }
        
        // =====================================================================
        // MODE A: QUICK SYNC (active_only)
        // STRIKT: Nur IDs aus Posts/Pages, NICHT aus Index!
        // =====================================================================
        if ($mode === self::MODE_ACTIVE_ONLY) {
            // STRICT: false = Keine Index-IDs, nur echte Post-IDs
            $active_ids = $this->get_active_product_ids(false);
            
            if (empty($active_ids)) {
                myfeeds_log('Quick Sync: No active products found in posts/pages', 'info');
                return new WP_Error('no_active_products', 'No active products found on the website.');
            }
            
            myfeeds_log("Quick Sync: Starting for " . count($active_ids) . " active products", 'info');
            
            // Store active IDs for batch processing
            update_option(self::OPTION_ACTIVE_IDS, $active_ids, false);
            
            // Skip re-mapping for quick sync - direkt zum Import
            return $this->start_active_only_import($active_ids);
        }
        
        // =====================================================================
        // MODE B: FULL UPDATE (full)
        // Aktive Produkte ZUERST (für schnelle Live-Updates), dann Rest
        // =====================================================================
        
        // Phase 1: Identify active products (ohne Index-IDs für Prioritäts-Pass)
        $active_ids = $this->get_active_product_ids(false);
        
        if (!empty($active_ids)) {
            update_option(self::OPTION_ACTIVE_IDS, $active_ids, false);
            myfeeds_log("Full Update: " . count($active_ids) . " active products will be prioritized", 'info');
        } else {
            myfeeds_log("Full Update: No active products found, skipping prioritization", 'debug');
        }
        
        // Phase 2: Re-analyze and update mappings
        $this->phase_remapping($feeds);
        
        // Phase 3: Start batch import with Active-First prioritization
        return $this->start_full_import_with_priority($active_ids);
    }
    
    /**
     * Start full import with Active-First prioritization
     * Phase 1: Process active products first (schnelle Live-Updates)
     * Phase 2: Process ALL products (vollständiger Import)
     */
    public function start_full_import_with_priority($active_ids = array()) {
        $feeds = get_option('myfeeds_feeds', array());
        
        if (empty($feeds)) {
            return new WP_Error('no_feeds', 'No feeds configured');
        }
        
        // Clear any existing import status
        $this->clear_import_status();
        
        // Start atomic rebuild (DB or JSONL depending on feature flag)
        if (MyFeeds_DB_Manager::is_db_mode()) {
            MyFeeds_DB_Manager::start_full_import();
        } else {
            MyFeeds_Atomic_Index_Manager::start_atomic_rebuild();
        }
        
        // Determine if we have active products to prioritize
        $has_priority = !empty($active_ids);
        
        // Initialize import status
        $status = array(
            'status' => 'running',
            'mode' => self::MODE_FULL,
            'phase' => $has_priority ? 'priority_active' : 'import',
            'started_at' => current_time('mysql'),
            'total_feeds' => count($feeds),
            'processed_feeds' => 0,
            'current_feed' => null,
            'current_feed_name' => '',
            'total_products' => 0,
            'processed_products' => 0,
            'processed_rows' => 0,
            'unique_products' => 0,
            'active_ids_count' => count($active_ids),
            'priority_complete' => false,
            'errors' => array(),
            'remapping_done' => true,
        );
        
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        // Queue all feeds - Phase 1: Priority pass for active products
        $queue = array();
        
        if ($has_priority) {
            // First pass: Process only active products from each feed
            foreach ($feeds as $key => $feed) {
                // Skip inactive feeds (plan limit)
                if (isset($feed['plan_active']) && $feed['plan_active'] === false) {
                    myfeeds_log("Skipping inactive feed: " . ($feed['name'] ?? 'unknown') . " (plan limit)", 'info');
                    continue;
                }
                // Ensure stable_id exists (assign if missing)
                if (empty($feed['stable_id'])) {
                    MyFeeds_DB_Manager::assign_stable_id($feed);
                    $feeds[$key] = $feed;
                    myfeeds_log("DIAG queue_build: Feed '{$feed['name']}' had no stable_id, assigned {$feed['stable_id']}", 'info');
                }
                $sid = (int) $feed['stable_id'];
                myfeeds_log("DIAG queue_build: Priority feed_key={$key}, name={$feed['name']}, stable_id={$sid}", 'info');
                $queue[] = array(
                    'feed_key' => $key,
                    'stable_id' => $sid,
                    'feed_name' => $feed['name'] . ' (Priority)',
                    'feed_url' => $feed['url'],
                    'mapping' => $feed['mapping'] ?? array(),
                    'format_hint' => $feed['detected_format'] ?? '',
                    'status' => 'pending',
                    'offset' => 0,
                    'total_rows' => 0,
                    'mode' => 'priority_pass',
                    'phase' => 'priority_active',
                );
            }
        }
        
        // Second pass: Full import
        foreach ($feeds as $key => $feed) {
            // Skip inactive feeds (plan limit)
            if (isset($feed['plan_active']) && $feed['plan_active'] === false) {
                myfeeds_log("Skipping inactive feed: " . ($feed['name'] ?? 'unknown') . " (plan limit)", 'info');
                continue;
            }
            // Ensure stable_id exists (assign if missing)
            if (empty($feed['stable_id'])) {
                MyFeeds_DB_Manager::assign_stable_id($feed);
                $feeds[$key] = $feed;
                myfeeds_log("DIAG queue_build: Feed '{$feed['name']}' had no stable_id, assigned {$feed['stable_id']}", 'info');
            }
            $sid = (int) $feed['stable_id'];
            myfeeds_log("DIAG queue_build: Full feed_key={$key}, name={$feed['name']}, stable_id={$sid}", 'info');
            $queue[] = array(
                'feed_key' => $key,
                'stable_id' => $sid,
                'feed_name' => $feed['name'],
                'feed_url' => $feed['url'],
                'mapping' => $feed['mapping'] ?? array(),
                'format_hint' => $feed['detected_format'] ?? '',
                'status' => 'pending',
                'offset' => 0,
                'total_rows' => 0,
                'mode' => self::MODE_FULL,
                'phase' => 'import',
            );
        }
        
        // Save feeds config if any stable_ids were assigned
        update_option('myfeeds_feeds', $feeds);
        
        update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
        
        // Initialize batch state for resume capability
        $batch_state = array(
            'current_feed_index' => 0,
            'current_offset' => 0,
            'started_at' => time(),
        );
        update_option(self::OPTION_BATCH_STATE, $batch_state, false);
        
        // Start processing immediately using Action Scheduler
        MyFeeds_Logger::info('Full Import: Starting with Action Scheduler, ' . count($queue) . ' feed passes queued');
        
        // Clear any leftover legacy cron events that could cause race conditions
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook('myfeeds_check_import_queue');
        $this->schedule_next_batch(array(
            'feed_index' => 0,
            'offset' => 0,
        ));
        
        return true;
    }
    
    /**
     * Phase 1: Re-analyze feed structures and update mappings
     */
    private function phase_remapping(&$feeds) {
        myfeeds_log('Re-Mapping: Starting Phase 1', 'info');
        
        if (!$this->smart_mapper) {
            myfeeds_log('Re-Mapping: Smart Mapper not available, skipping', 'info');
            return;
        }
        
        $updated = false;
        
        foreach ($feeds as $key => &$feed) {
            // Skip inactive feeds (plan limit)
            if (isset($feed['plan_active']) && $feed['plan_active'] === false) {
                myfeeds_log("Re-Mapping: Skipping inactive feed: " . ($feed['name'] ?? 'unknown') . " (plan limit)", 'info');
                continue;
            }
            $url = $feed['url'] ?? '';
            
            // Skip feeds with no URL
            if (empty($url)) {
                continue;
            }
            
            myfeeds_log("Re-Mapping: Analyzing feed: {$feed['name']}", 'debug');
            
            // Fetch sample data from feed
            $sample_result = $this->fetch_feed_sample($url);
            
            if (is_wp_error($sample_result)) {
                myfeeds_log("Re-Mapping: Failed to fetch sample for {$feed['name']}: " . $sample_result->get_error_message(), 'error');
                continue;
            }
            
            $sample_data = $sample_result['sample'];
            $detected_format = $sample_result['format'];
            
            // Check if feed structure has changed
            $current_fields = array_keys($sample_data);
            $stored_fields = $feed['detected_fields'] ?? array();
            
            $structure_changed = $this->has_structure_changed($current_fields, $stored_fields);
            
            if ($structure_changed || empty($feed['mapping'])) {
                myfeeds_log("Re-Mapping: Structure changed for {$feed['name']}, regenerating", 'info');
                
                // Defensive: Skip re-mapping if sample is suspiciously small (likely truncated partial download)
                $sample_fields_count = is_array($sample_data) ? count($sample_data) : 0;
                $existing_mapping_count = count($feed['mapping'] ?? array());
                
                if ($sample_fields_count > 0 && $existing_mapping_count > 10 && $sample_fields_count < ($existing_mapping_count / 2)) {
                    myfeeds_log("Re-Mapping: Skipping {$feed['name']} — sample has only {$sample_fields_count} fields vs {$existing_mapping_count} in stored mapping (likely truncated)", 'info');
                    continue;
                }
                
                // Generate new mapping
                $new_mapping = $this->smart_mapper->auto_map_fields($sample_data, $url);
                
                if ($new_mapping) {
                    // Defensive check: Only accept new mapping if it has all critical fields
                    $critical = array('id', 'title', 'price', 'affiliate_link');
                    $has_all_critical = true;
                    foreach ($critical as $cf) {
                        if (empty($new_mapping[$cf])) {
                            $has_all_critical = false;
                            break;
                        }
                    }
                    
                    if ($has_all_critical) {
                        $feed['mapping'] = $new_mapping;
                        $feed['detected_fields'] = $current_fields;
                        $feed['detected_format'] = $detected_format;
                        $feed['mapping_updated'] = current_time('mysql');
                        $feed['mapping_confidence'] = $this->smart_mapper->get_mapping_confidence($new_mapping);
                        $updated = true;
                        
                        myfeeds_log("Re-Mapping: Accepted new mapping for {$feed['name']} (" . count($new_mapping) . " fields, confidence: {$feed['mapping_confidence']}%)", 'info');
                    } else {
                        myfeeds_log("Re-Mapping: Rejected incomplete new mapping for {$feed['name']} (missing critical fields), keeping existing mapping", 'info');
                    }
                } else {
                    myfeeds_log("Re-Mapping: auto_map_fields returned false for {$feed['name']}, keeping existing mapping", 'info');
                }
            } else {
                myfeeds_log("Re-Mapping: No structure change for {$feed['name']}", 'debug');
            }
        }
        
        if ($updated) {
            update_option('myfeeds_feeds', $feeds);
            myfeeds_log('Re-Mapping: Feed mappings updated', 'info');
        }
    }
    
    /**
     * Check if feed structure has changed
     */
    private function has_structure_changed($current_fields, $stored_fields) {
        if (empty($stored_fields)) {
            return true;
        }
        
        // Check for new or removed fields
        $new_fields = array_diff($current_fields, $stored_fields);
        $removed_fields = array_diff($stored_fields, $current_fields);
        
        // Consider structure changed if more than 10% of fields differ
        $total_fields = max(count($current_fields), count($stored_fields));
        $changed_fields = count($new_fields) + count($removed_fields);
        
        return ($total_fields > 0) ? (($changed_fields / $total_fields) > 0.1) : true;
    }
    
    /**
     * Fetch sample data from feed URL
     */
    private function fetch_feed_sample($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'limit_response_size' => 65536,
            'headers' => array('Accept-Encoding' => 'gzip, deflate'),
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Handle gzip
        if (substr($body, 0, 2) === "\x1f\x8b") {
            $decoded = @gzdecode($body);
            if ($decoded !== false) {
                $body = $decoded;
            }
        }
        
        // Detect format
        $format = $this->detect_format($body);
        
        // Parse first row as sample
        $sample = $this->parse_sample($body, $format);
        
        if (!$sample) {
            return new WP_Error('parse_failed', 'Could not parse feed sample');
        }
        
        return array(
            'sample' => $sample,
            'format' => $format,
        );
    }
    
    /**
     * Detect feed format from content
     */
    private function detect_format($content) {
        $content = trim($content);
        
        if (substr($content, 0, 5) === '<?xml' || substr($content, 0, 1) === '<') {
            return 'xml';
        }
        
        if (substr($content, 0, 1) === '{' || substr($content, 0, 1) === '[') {
            return 'json';
        }
        
        $lines = explode("\n", $content);
        if (count($lines) > 1 && substr(trim($lines[0]), 0, 1) === '{') {
            return 'json_lines';
        }
        
        // Auto-detect delimiter
        $first_line = $lines[0] ?? '';
        $tab_count   = substr_count($first_line, "\t");
        $semi_count  = substr_count($first_line, ';');
        $pipe_count  = substr_count($first_line, '|');
        $comma_count = substr_count($first_line, ',');
        
        if ($tab_count > $comma_count && $tab_count > $semi_count && $tab_count > $pipe_count) return 'tsv';
        if ($semi_count > $comma_count && $semi_count > $tab_count && $semi_count > $pipe_count) return 'ssv';
        if ($pipe_count > $comma_count && $pipe_count > $tab_count && $pipe_count > $semi_count) return 'psv';
        
        return 'csv';
    }
    
    /**
     * Parse sample from feed content using Feed Reader
     */
    private function parse_sample($content, $format) {
        $tmp_path = wp_tempnam('myfeeds_sample_');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temp file for Feed Reader
        file_put_contents($tmp_path, $content);
        
        $reader = new MyFeeds_Feed_Reader();
        if (!$reader->open($tmp_path, $format)) {
            wp_delete_file($tmp_path);
            return null;
        }
        
        $first_item = $reader->read_next();
        $reader->close();
        wp_delete_file($tmp_path);
        
        return $first_item !== false ? $first_item : null;
    }
    
    /**
     * Convert XML element to array
     */
    private function xml_to_array($xml_element) {
        $array = array();
        
        foreach ($xml_element->children() as $child) {
            $name = $child->getName();
            
            if ($child->count() > 0) {
                $array[$name] = $this->xml_to_array($child);
            } else {
                $array[$name] = (string) $child;
            }
        }
        
        return $array;
    }
    
    // =========================================================================
    // PHASE 2: BATCH IMPORT
    // =========================================================================
    
    /**
     * Start a batch import for all feeds (FULL mode)
     */
    public function start_full_import() {
        $feeds = get_option('myfeeds_feeds', array());
        
        if (empty($feeds)) {
            return new WP_Error('no_feeds', 'No feeds configured');
        }
        
        // Clear any existing import status
        $this->clear_import_status();
        
        // Start atomic rebuild (DB or JSONL depending on feature flag)
        if (MyFeeds_DB_Manager::is_db_mode()) {
            MyFeeds_DB_Manager::start_full_import();
        } else {
            MyFeeds_Atomic_Index_Manager::start_atomic_rebuild();
        }
        
        // Initialize import status
        $status = array(
            'status' => 'running',
            'mode' => self::MODE_FULL,
            'phase' => 'import',
            'started_at' => current_time('mysql'),
            'total_feeds' => count($feeds),
            'processed_feeds' => 0,
            'current_feed' => null,
            'current_feed_name' => '',
            'total_products' => 0,
            'processed_products' => 0,
            'errors' => array(),
            'remapping_done' => true,
        );
        
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        // Queue all feeds for import
        $queue = array();
        foreach ($feeds as $key => $feed) {
            // Skip inactive feeds (plan limit)
            if (isset($feed['plan_active']) && $feed['plan_active'] === false) {
                myfeeds_log("Skipping inactive feed: " . ($feed['name'] ?? 'unknown') . " (plan limit)", 'info');
                continue;
            }
            // Ensure stable_id exists (assign if missing)
            if (empty($feed['stable_id'])) {
                MyFeeds_DB_Manager::assign_stable_id($feed);
                $feeds[$key] = $feed;
                myfeeds_log("DIAG queue_build: start_full_import Feed '{$feed['name']}' had no stable_id, assigned {$feed['stable_id']}", 'info');
            }
            $sid = (int) $feed['stable_id'];
            myfeeds_log("DIAG queue_build: start_full_import feed_key={$key}, name={$feed['name']}, stable_id={$sid}", 'info');
            $queue[] = array(
                'feed_key' => $key,
                'stable_id' => $sid,
                'feed_name' => $feed['name'],
                'feed_url' => $feed['url'],
                'mapping' => $feed['mapping'] ?? array(),
                'format_hint' => $feed['detected_format'] ?? '',
                'status' => 'pending',
                'offset' => 0,
                'total_rows' => 0,
            );
        }
        // Save feeds config if any stable_ids were assigned
        update_option('myfeeds_feeds', $feeds);
        
        update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
        
        // Initialize batch state for resume capability
        $batch_state = array(
            'current_feed_index' => 0,
            'current_offset' => 0,
            'started_at' => time(),
        );
        update_option(self::OPTION_BATCH_STATE, $batch_state, false);
        
        // Start processing immediately using Action Scheduler
        MyFeeds_Logger::info('Full Import (simple): Starting with Action Scheduler');
        $this->schedule_next_batch(array(
            'feed_index' => 0,
            'offset' => 0,
        ));
        
        return true;
    }
    
    /**
     * Start import for ACTIVE PRODUCTS ONLY (Quick Sync mode)
     * Much faster as it only updates products that are actually in use
     * 
     * @param array $active_ids Array of product IDs to sync
     */
    public function start_active_only_import($active_ids) {
        $feeds = get_option('myfeeds_feeds', array());
        
        if (empty($feeds)) {
            return new WP_Error('no_feeds', 'No feeds configured');
        }
        
        if (empty($active_ids)) {
            return new WP_Error('no_active_ids', 'No active product IDs provided');
        }
        
        // =====================================================================
        // PERFORMANCE-OPTIMIERUNG: Quick Sync läuft SYNCHRON und SOFORT!
        // Keine WP-Cron Batches für wenige Produkte - direkt verarbeiten!
        // =====================================================================
        
        $start_time = microtime(true);
        $total_active = count($active_ids);
        $active_ids_hash = array_flip($active_ids); // O(1) Lookup
        
        myfeeds_log("Quick Sync: Starting FAST sync for $total_active active products", 'info');
        
        // Initialize status
        $status = array(
            'status' => 'running',
            'mode' => self::MODE_ACTIVE_ONLY,
            'phase' => 'quick_sync',
            'started_at' => current_time('mysql'),
            'total_feeds' => count($feeds),
            'processed_feeds' => 0,
            'current_feed' => null,
            'current_feed_name' => '',
            'total_products' => $total_active,
            'active_ids_count' => $total_active,
            'processed_products' => 0,
            'found_products' => 0,
            'errors' => array(),
        );
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        // Load existing index for update (JSON mode only)
        $upload_dir = wp_upload_dir();
        $index_path = $upload_dir['basedir'] . '/' . self::INDEX_FILE;
        $use_db = MyFeeds_DB_Manager::is_db_mode();
        $existing_data = array('__search_fields' => array(), 'items' => array());
        $items = array();
        
        if (!$use_db) {
            if (file_exists($index_path)) {
                $existing_data = json_decode(file_get_contents($index_path), true) ?: $existing_data;
            }
            $items = $existing_data['items'];
        }
        $found_count = 0;
        $remaining_ids = $active_ids_hash; // Track which IDs we still need to find
        $feeds_with_errors = 0; // Track feed download failures
        
        // Process each feed - but STOP EARLY when all IDs are found!
        foreach ($feeds as $key => $feed) {
            // Skip inactive feeds (plan limit)
            if (isset($feed['plan_active']) && $feed['plan_active'] === false) {
                myfeeds_log("Skipping inactive feed: " . ($feed['name'] ?? 'unknown') . " (plan limit)", 'info');
                continue;
            }
            // EARLY EXIT: When all IDs found, stop immediately!
            if (empty($remaining_ids)) {
                myfeeds_log("Quick Sync: All $total_active IDs found! Stopping early.", 'info');
                break;
            }
            
            $status['current_feed'] = $key;
            $status['current_feed_name'] = $feed['name'];
            update_option(self::OPTION_IMPORT_STATUS, $status, false);
            
            // Download feed
            $response = wp_remote_get($feed['url'], array(
                'timeout' => 30,
                'headers' => array('Accept-Encoding' => 'gzip, deflate'),
            ));
            
            if (is_wp_error($response)) {
                $feeds_with_errors++;
                $status['errors'][] = array('feed' => $feed['name'], 'error' => $response->get_error_message());
                myfeeds_log("Quick Sync: Feed '{$feed['name']}' download failed: " . $response->get_error_message(), 'error');
                continue;
            }
            
            $body = wp_remote_retrieve_body($response);
            
            // Handle gzip
            if (substr($body, 0, 2) === "\x1f\x8b") {
                $decoded = @gzdecode($body);
                if ($decoded !== false) {
                    $body = $decoded;
                }
            }
            
            // =====================================================================
            // SEARCH, DON'T CRAWL: Parse feed and extract ONLY needed IDs
            // =====================================================================
            $result = $this->quick_extract_products_by_ids($body, $feed['mapping'], $remaining_ids, $feed['detected_format'] ?? '');
            
            if (!empty($result['products'])) {
                foreach ($result['products'] as $product_id => $product_data) {
                    if ($use_db) {
                        // Resolve stable_id for this feed
                        $qs_stable_id = (int) ($feed['stable_id'] ?? 0);
                        if ($qs_stable_id === 0) {
                            $live_feeds_qs = get_option('myfeeds_feeds', array());
                            if (isset($live_feeds_qs[$key]['stable_id'])) {
                                $qs_stable_id = (int) $live_feeds_qs[$key]['stable_id'];
                            }
                        }
                        if ($qs_stable_id === 0) {
                            myfeeds_log("SKIPPED quick_sync_product: stable_id=0 for feed '{$feed['name']}', product_id={$product_id}", 'error');
                            continue;
                        }
                        MyFeeds_DB_Manager::quick_sync_product($product_data, $qs_stable_id);
                    } else {
                        $items[$product_id] = $product_data;
                    }
                    $found_count++;
                    unset($remaining_ids[$product_id]); // Mark as found
                    
                    // Update progress in real-time
                    $status['found_products'] = $found_count;
                    $status['processed_products'] = $found_count;
                }
            }
            
            $status['processed_feeds']++;
            update_option(self::OPTION_IMPORT_STATUS, $status, false);
        }
        
        if ($use_db) {
            // DB mode: Quick Sync products already written via quick_sync_product()
            // ARCHITECTURE DECISION: Quick Sync NEVER marks products as unavailable.
            // Quick Sync only updates prices/stock/links for products it finds.
            // Unavailable detection is exclusively the job of the weekly Full Import,
            // which processes ALL feeds completely and can make an informed decision.
            // This prevents false unavailable markings from transient feed download
            // failures, timeouts, or partial feed responses during nightly sync.
            if (!empty($remaining_ids)) {
                myfeeds_log("Quick Sync (DB): " . count($remaining_ids) . " products not found in feeds — NOT marking as unavailable (Quick Sync policy)", 'info');
            }
            // Re-activate products that were previously marked unavailable but are now found
            // in feeds. The single bulk UPDATE below replaces a per-product UPDATE loop —
            // same WHERE guard (status='unavailable'), identical semantics, 1 round trip
            // instead of N.
            $reactivated_count = 0;
            $found_ids = array();
            foreach ($active_ids_hash as $aid => $v) {
                if (!isset($remaining_ids[$aid])) {
                    $found_ids[] = (string) $aid;
                }
            }
            if (!empty($found_ids)) {
                global $wpdb;
                $table = MyFeeds_DB_Manager::table_name();
                $placeholders = implode(',', array_fill(0, count($found_ids), '%s'));
                $reactivated_count = (int) $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status = 'active'
                     WHERE status = 'unavailable' AND external_id IN ({$placeholders})",
                    ...$found_ids
                ));
            }
            if ($reactivated_count > 0) {
                myfeeds_log("Quick Sync (DB): {$reactivated_count} previously unavailable products reactivated", 'info');
            }
        } else {
            // JSON mode: Save updated index
            $existing_data['items'] = $items;
            $existing_data['__search_fields'] = array(
                'title' => 3,
                'brand' => 2,
                'shopname' => 1,
                'merchant' => 1,
                'attributes.color' => 2,
            );
            
            // ARCHITECTURE DECISION: Quick Sync NEVER marks products as unavailable.
            // See DB mode block above for rationale.
            if (!empty($remaining_ids)) {
                myfeeds_log("Quick Sync (JSON): " . count($remaining_ids) . " products not found in feeds — NOT marking as unavailable (Quick Sync policy)", 'info');
            }
            
            // Products that WERE found: clear any previous unavailable status
            foreach ($active_ids_hash as $aid => $v) {
                if (!isset($remaining_ids[$aid]) && isset($items[$aid]['status']) && $items[$aid]['status'] === 'unavailable') {
                    unset($items[$aid]['status']);
                    unset($items[$aid]['unavailable_since']);
                }
            }
            
            $existing_data['items'] = $items;
            
            file_put_contents($index_path, json_encode($existing_data), LOCK_EX);
        }
        $elapsed = round((microtime(true) - $start_time) * 1000); // ms
        
        $status['status'] = 'completed';
        $status['completed_at'] = current_time('mysql');
        $status['processed_products'] = $found_count;
        $status['found_products'] = $found_count;
        $status['elapsed_ms'] = $elapsed;
        
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        // NOTE: Do NOT run cleanup_orphaned_products() after Quick Sync!
        // It compares ALL products against configured feeds and can incorrectly
        // delete products. Orphan cleanup only runs after Full Imports.
        
        // Cleanup
        delete_option(self::OPTION_ACTIVE_IDS);
        
        myfeeds_log("Quick Sync: COMPLETED in {$elapsed}ms — Found $found_count of $total_active products", 'info');
        
        // Trigger completion action
        do_action('myfeeds_feed_update_complete', $status);
        
        return true;
    }
    
    /**
     * PERFORMANCE: Extract only specific product IDs from feed data
     * Uses hash-based lookup for O(1) ID matching - no linear search!
     * Stops processing as soon as all needed IDs are found.
     * 
     * @param string $feed_content Raw feed content
     * @param array $mapping Field mapping configuration
     * @param array $needed_ids Hash of IDs we're looking for (ID => true)
     * @return array ['products' => [...], 'scanned_rows' => int]
     */
    private function quick_extract_products_by_ids($feed_content, $mapping, $needed_ids, $format_hint = '') {
        $products = array();
        $scanned_rows = 0;
        $needed_count = count($needed_ids);
        
        // Write content to temp file for Feed Reader
        $tmp_path = wp_tempnam('myfeeds_qs_');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temp file for Feed Reader
        file_put_contents($tmp_path, $feed_content);
        
        $reader = new MyFeeds_Feed_Reader();
        if (!$reader->open($tmp_path, $format_hint)) {
            wp_delete_file($tmp_path);
            return array('products' => array(), 'scanned_rows' => 0);
        }
        
        $total_rows = $reader->count_items();
        
        while (($raw = $reader->read_next()) !== false) {
            $scanned_rows++;
            
            $row_id = $this->extract_product_id_fast($raw);
            
            // Skip if not an ID we're looking for
            if ($row_id === null || !isset($needed_ids[$row_id])) {
                continue;
            }
            
            // FOUND A MATCH! Full mapping
            $mapped = $this->map_product($raw, $mapping);
            
            // Apply processing
            if ($this->smart_mapper) {
                $mapped = $this->smart_mapper->apply_intelligent_processing($mapped);
            }
            
            if ($this->feed_manager && method_exists($this->feed_manager, 'process_critical_fields')) {
                $mapped = $this->feed_manager->process_critical_fields($mapped, $raw);
            } else {
                $mapped = $this->process_critical_fields_fallback($mapped, $raw);
            }
            
            $mapped['id'] = $row_id;
            
            // FIX 4: FORCE OVERWRITE critical fields from raw feed data.
            // Quick Sync MUST always update prices, stock, images, and links
            // from the current feed. No "only write when empty" allowed here.
            $force_overwrite_map = array(
                'search_price'       => 'price',
                'store_price'        => 'price',
                'rrp_price'          => 'old_price',
                'in_stock'           => 'in_stock',
                'merchant_image_url' => 'image_url',
                'aw_deep_link'       => 'affiliate_link',
                'aw_image_url'       => 'image_url',
                'merchant_deep_link' => 'affiliate_link',
            );
            foreach ($force_overwrite_map as $raw_field => $mapped_field) {
                if (isset($raw[$raw_field]) && $raw[$raw_field] !== '') {
                    // For price fields, ensure numeric conversion
                    if (in_array($mapped_field, array('price', 'old_price'))) {
                        $val = floatval($raw[$raw_field]);
                        if ($val > 0) {
                            $mapped[$mapped_field] = $val;
                        }
                    } else {
                        $mapped[$mapped_field] = $raw[$raw_field];
                    }
                }
            }
            
            // Recalculate discount after force-overwriting prices
            $price_val = floatval($mapped['price'] ?? 0);
            $old_price_val = floatval($mapped['old_price'] ?? 0);
            if ($old_price_val > $price_val && $price_val > 0) {
                $mapped['discount_percentage'] = round((($old_price_val - $price_val) / $old_price_val) * 100);
            }
            
            $products[$row_id] = $mapped;
            
            // EARLY EXIT: Stop scanning if we found all needed IDs!
            if (count($products) >= $needed_count) {
                myfeeds_log("Quick Sync: Found all $needed_count IDs after scanning only $scanned_rows of $total_rows rows!", 'debug');
                break;
            }
        }
        
        $reader->close();
        wp_delete_file($tmp_path);
        
        return array(
            'products' => $products,
            'scanned_rows' => $scanned_rows,
            'total_rows' => $total_rows,
        );
    }
    
    /**
     * Find the index of the ID column in the header for fast lookup
     */
    private function find_id_column_index($header, $mapping) {
        // First check mapping
        if (!empty($mapping['id'])) {
            $id_field = $mapping['id'];
            $index = array_search($id_field, $header);
            if ($index !== false) {
                return $index;
            }
        }
        
        // Try common ID field names
        $id_fields = array('aw_product_id', 'product_id', 'id', 'sku', 'ID', 'ProductID');
        foreach ($id_fields as $field) {
            $index = array_search($field, $header);
            if ($index !== false) {
                return $index;
            }
        }
        
        return null;
    }
    
    /**
     * Fast extraction of product ID from raw data
     */
    private function extract_product_id_fast($raw) {
        $id_fields = array('aw_product_id', 'product_id', 'id', 'sku', 'ID', 'ProductID');
        foreach ($id_fields as $field) {
            if (!empty($raw[$field])) {
                return (string) $raw[$field];
            }
        }
        return null;
    }
    
    /**
     * Start import for a single feed
     */
    public function start_single_feed_import($feed_key, $is_reimport = false) {
        $feeds = get_option('myfeeds_feeds', array());
        
        if (!isset($feeds[$feed_key])) {
            return new WP_Error('invalid_feed', 'Feed not found');
        }
        
        $feed = $feeds[$feed_key];
        
        // First, update mapping for this feed
        if ($this->smart_mapper) {
            $sample_result = $this->fetch_feed_sample($feed['url']);
            
            if (!is_wp_error($sample_result)) {
                $new_mapping = $this->smart_mapper->auto_map_fields($sample_result['sample'], $feed['url']);
                
                if ($new_mapping) {
                    $feeds[$feed_key]['mapping'] = $new_mapping;
                    $feeds[$feed_key]['mapping_updated'] = current_time('mysql');
                    $feeds[$feed_key]['mapping_confidence'] = $this->smart_mapper->get_mapping_confidence($new_mapping);
                    update_option('myfeeds_feeds', $feeds);
                    
                    $feed['mapping'] = $new_mapping;
                }
            }
        }
        
        // Clear any existing import
        $this->clear_import_status();
        
        // Initialize status
        $status = array(
            'status' => 'running',
            'phase' => 'import',
            'import_type' => 'single_feed',
            'is_reimport' => $is_reimport,
            'single_feed_name' => $feed['name'],
            'started_at' => current_time('mysql'),
            'total_feeds' => 1,
            'processed_feeds' => 0,
            'current_feed' => $feed_key,
            'current_feed_name' => $feed['name'],
            'total_products' => 0,
            'processed_products' => 0,
            'processed_rows' => 0,
            'unique_products' => 0,
            'errors' => array(),
            'remapping_done' => true,
        );
        
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        // Ensure stable_id exists (assign if missing)
        if (empty($feed['stable_id'])) {
            MyFeeds_DB_Manager::assign_stable_id($feed);
            $feeds[$feed_key] = $feed;
            update_option('myfeeds_feeds', $feeds);
            myfeeds_log("DIAG queue_build: start_single_feed_import Feed '{$feed['name']}' had no stable_id, assigned {$feed['stable_id']}", 'info');
        }
        $sid = (int) $feed['stable_id'];
        myfeeds_log("DIAG queue_build: start_single_feed_import feed_key={$feed_key}, name={$feed['name']}, stable_id={$sid}", 'info');

        // Queue single feed
        $queue = array(
            array(
                'feed_key' => $feed_key,
                'stable_id' => $sid,
                'feed_name' => $feed['name'],
                'feed_url' => $feed['url'],
                'mapping' => $feed['mapping'] ?? array(),
                'format_hint' => $feed['detected_format'] ?? '',
                'status' => 'pending',
                'offset' => 0,
                'total_rows' => 0,
            )
        );
        
        update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
        
        // Initialize batch state for watchdog resume support
        update_option(self::OPTION_BATCH_STATE, array(
            'current_feed_index' => 0,
            'current_offset' => 0,
            'last_updated' => time(),
        ), false);
        
        // Start processing
        $this->schedule_next_batch();
        
        return true;
    }

    /**
     * Schedule a background import for a newly created feed.
     * 
     * Uses the REGULAR import pipeline (start_single_feed_import) which:
     * - Downloads feed to disk cache (no RAM issues with large feeds)
     * - Parses in 500-item batches via Action Scheduler
     * - Handles auto-status-update to 'active' after successful import
     * 
     * If no mapping exists, auto-maps from a small sample (64KB) before queuing.
     * If auto-mapping fails, the regular pipeline will still attempt import
     * via phase_remapping-like logic in the batch processor.
     * 
     * @param int $feed_key The feed's key in the myfeeds_feeds array
     * @return bool True if import was started successfully
     */
    public function schedule_new_feed_import($feed_key, $is_reimport = false) {
        // Don't start if a global import is already running — it will handle this feed
        $global_status = get_option(self::OPTION_IMPORT_STATUS, array());
        if (!empty($global_status['status']) && $global_status['status'] === 'running') {
            MyFeeds_Logger::info("schedule_new_feed_import: Skipped — global import is running (feed key={$feed_key} will be included)");
            return false;
        }
        
        $feeds = get_option('myfeeds_feeds', array());
        if (!isset($feeds[$feed_key])) {
            MyFeeds_Logger::error("schedule_new_feed_import: Feed key={$feed_key} not found");
            return false;
        }
        
        // DIAG LOG 5: Log when single-feed import is queued
        myfeeds_log("DIAG new_feed_import: feed_key={$feed_key}, feed_name=" . ($feeds[$feed_key]['name'] ?? 'N/A') . ", stable_id=" . ($feeds[$feed_key]['stable_id'] ?? 'N/A') . ", is_reimport=" . ($is_reimport ? 'yes' : 'no'), 'info');
        
        // Pre-map if no mapping exists (uses 64KB sample, fast)
        $feed = $feeds[$feed_key];
        if (empty($feed['mapping']) && $this->smart_mapper) {
            $sample_result = $this->fetch_feed_sample($feed['url']);
            if (!is_wp_error($sample_result)) {
                $new_mapping = $this->smart_mapper->auto_map_fields($sample_result['sample'], $feed['url']);
                if ($new_mapping) {
                    $feeds[$feed_key]['mapping'] = $new_mapping;
                    $feeds[$feed_key]['detected_fields'] = array_keys($sample_result['sample']);
                    $feeds[$feed_key]['detected_format'] = $sample_result['format'] ?? '';
                    $feeds[$feed_key]['mapping_confidence'] = $this->smart_mapper->get_mapping_confidence($new_mapping);
                    $feeds[$feed_key]['mapping_updated'] = current_time('mysql');
                    update_option('myfeeds_feeds', $feeds);
                    MyFeeds_Logger::info("schedule_new_feed_import: Pre-mapped " . count($new_mapping) . " fields for feed key={$feed_key}");
                }
            } else {
                MyFeeds_Logger::info("schedule_new_feed_import: Pre-mapping sample failed for feed key={$feed_key} (will retry during import): " . $sample_result->get_error_message());
            }
        }
        
        // Use the regular single-feed import pipeline
        // This creates a queue with 1 entry and schedules AS batch processing
        $result = $this->start_single_feed_import($feed_key, $is_reimport);
        
        if (is_wp_error($result)) {
            MyFeeds_Logger::error("schedule_new_feed_import: start_single_feed_import failed for feed key={$feed_key}: " . $result->get_error_message());
            return false;
        }
        
        MyFeeds_Logger::info("schedule_new_feed_import: Started regular import pipeline for feed key={$feed_key}");
        return true;
    }

    
    /**
     * Initialize temporary index file
     */
    /**
     * Schedule next batch processing using Action Scheduler
     * 
     * NEW ARCHITECTURE (replaces spawn_cron):
     * - Uses as_schedule_single_action() for robust, resumable batch jobs
     * - Each batch is an independent, idempotent job
     * - On failure: job is marked failed, next run resumes from last successful offset
     * - No spawn_cron(), no loopback requests, no timeout issues
     * 
     * @param array $batch_args Arguments for the batch (feed_index, offset, etc.)
     */
    private function schedule_next_batch($batch_args = array()) {
        $sched_run_id = uniqid('sched_', true);
        
        // Check if Action Scheduler is available
        if (!function_exists('as_schedule_single_action')) {
            MyFeeds_Logger::error('schedule_next_batch: Action Scheduler NOT available! Falling back to legacy spawn_cron.');
            
            myfeeds_as_log('schedule_error', array(
                'reason' => 'action_scheduler_not_available',
                'fallback' => 'legacy_spawn_cron',
            ), $sched_run_id);
            
            $this->schedule_next_batch_legacy();
            return;
        }
        
        // Get current batch state
        $batch_state = get_option(self::OPTION_BATCH_STATE, array());
        
        // Find current feed index and offset
        $current_feed_index = $batch_state['current_feed_index'] ?? 0;
        $current_offset = $batch_state['current_offset'] ?? 0;
        
        // Build deterministic job args (NO timestamp — enables proper deduplication)
        $job_args = array(
            'feed_index' => isset($batch_args['feed_index']) ? intval($batch_args['feed_index']) : $current_feed_index,
            'offset' => isset($batch_args['offset']) ? intval($batch_args['offset']) : $current_offset,
        );
        
        $as_args = array('args' => $job_args);
        
        MyFeeds_Logger::debug("schedule_next_batch [AS]: feed_index={$job_args['feed_index']}, offset={$job_args['offset']}");
        
        // =================================================================
        // DEDUP CHECK: Only schedule if no identical action is already pending.
        // We use our own as_has_scheduled_action() check instead of AS's
        // built-in unique=true, which has known reliability issues in AS 3.9.x
        // (always returns 0 even for genuinely new actions).
        // =================================================================
        $has_pending = as_has_scheduled_action(
            self::AS_HOOK_PROCESS_BATCH,
            $as_args,
            self::AS_GROUP
        );
        
        if ($has_pending) {
            MyFeeds_Logger::debug('schedule_next_batch [AS]: Action already pending (dedup). Skipping.');
            
            myfeeds_as_log('schedule_dedup_ok', array(
                'reason' => 'as_has_scheduled_action_true',
                'args' => $job_args,
            ), $sched_run_id);
            
            return;
        }
        
        // Schedule the batch — no unique constraint (we handle dedup above)
        $scheduled_time = time() + 5;
        $action_id = as_schedule_single_action(
            $scheduled_time,
            self::AS_HOOK_PROCESS_BATCH,
            $as_args,
            self::AS_GROUP,
            false
        );
        
        if ($action_id) {
            MyFeeds_Logger::debug("schedule_next_batch [AS]: Scheduled action_id={$action_id}");
            
            myfeeds_as_log('schedule_success', array(
                'action_id' => $action_id,
                'scheduled_args' => $job_args,
            ), $sched_run_id);
            
        } else {
            // Fallback: Try as_enqueue_async_action
            if (function_exists('as_enqueue_async_action')) {
                $action_id = as_enqueue_async_action(
                    self::AS_HOOK_PROCESS_BATCH,
                    $as_args,
                    self::AS_GROUP
                );
                
                if ($action_id) {
                    MyFeeds_Logger::debug("schedule_next_batch [AS]: Async fallback succeeded, action_id={$action_id}");
                }
            }
            
            if (!$action_id) {
                MyFeeds_Logger::error('schedule_next_batch [AS]: All scheduling methods failed!');
                
                myfeeds_as_log('schedule_error', array(
                    'reason' => 'all_methods_failed',
                    'attempted_args' => $job_args,
                ), $sched_run_id);
            }
        }
    }
    
    /**
     * LEGACY: Fallback to old spawn_cron mechanism
     * Only used if Action Scheduler is not available
     * @deprecated Will be removed once AS migration is complete
     */
    private function schedule_next_batch_legacy() {
        MyFeeds_Logger::debug('schedule_next_batch_legacy: Using deprecated spawn_cron mechanism');
        
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $scheduled = wp_schedule_single_event(time(), self::CRON_HOOK);
        
        if (!defined('DOING_CRON') || !DOING_CRON) {
            spawn_cron();
        }
    }
    
    /**
     * Check queue and process if needed.
     * GUARDED: If Action Scheduler is available, it handles all batch processing.
     * Legacy process_next_batch must NOT run alongside AS — causes race conditions.
     */
    public function check_and_process_queue() {
        // If Action Scheduler is available, it handles all batch processing.
        // Legacy process_next_batch must NOT run — it causes race conditions.
        if (function_exists('as_has_scheduled_action')) {
            myfeeds_log('Legacy batch skipped — Action Scheduler is handling imports.', 'debug');
            return;
        }
        
        $status = get_option(self::OPTION_IMPORT_STATUS, array());
        
        if (!empty($status) && $status['status'] === 'running') {
            $this->process_next_batch();
        }
    }
    
    // =========================================================================
    // ACTION SCHEDULER BATCH PROCESSING (NEW - Replaces spawn_cron)
    // =========================================================================
    
    /**
     * Process a single batch via Action Scheduler
     * 
     * This function is called by Action Scheduler for each batch job.
     * KEY PROPERTIES:
     * - IDEMPOTENT: Can be safely re-run without side effects
     * - RESUMABLE: On failure, saves state for next run to continue
     * - ISOLATED: Each batch is independent, no shared state beyond options
     * 
     * @param array $args Job arguments (feed_index, offset, timestamp)
     */
    public function as_process_batch($args) {
        // FIX 1: Remove PHP time limit for Action Scheduler background jobs.
        // PHP's default time_limit (often 120s) silently kills long-running batches.
        // AS jobs run in background — set_time_limit(0) is correct and safe here.
        if (!ini_get('safe_mode')) {
            set_time_limit(0);
        }
        ignore_user_abort(true);
        
        $batch_start_time = microtime(true);
        $batch_start_memory = memory_get_usage(true);
        
        // =================================================================
        // EXECUTION LOCK: Ensure only ONE batch runs at a time
        // MySQL GET_LOCK is atomic across all PHP processes.
        // Timeout 0 = non-blocking: returns immediately if lock is held.
        //
        // CRITICAL FIX: On lock failure, RESCHEDULE the batch with a delay
        // instead of silently returning. Otherwise the batch chain breaks
        // and the import stalls permanently.
        // =================================================================
        global $wpdb;
        $got_lock = $wpdb->get_var("SELECT GET_LOCK('myfeeds_batch_exec', 0)");
        if ($got_lock != 1) {
            MyFeeds_Logger::info('AS Batch: LOCK CONTENTION - another batch is executing. RESCHEDULING in 10s.');
            
            // Extract args to reschedule
            $feed_index = isset($args['feed_index']) ? intval($args['feed_index']) : 0;
            $offset = isset($args['offset']) ? intval($args['offset']) : 0;
            
            // Verify import is still running before rescheduling
            $status = get_option(self::OPTION_IMPORT_STATUS, array());
            if (!empty($status) && $status['status'] === 'running') {
                // Reschedule with delay — the chain must NOT break
                if (function_exists('as_schedule_single_action')) {
                    as_schedule_single_action(
                        time() + 10,
                        self::AS_HOOK_PROCESS_BATCH,
                        array('args' => array(
                            'feed_index' => $feed_index,
                            'offset' => $offset,
                        )),
                        self::AS_GROUP,
                        false // No unique constraint — force schedule
                    );
                    MyFeeds_Logger::debug("AS Batch: Rescheduled feed_index={$feed_index}, offset={$offset} for +10s");
                }
            } else {
                MyFeeds_Logger::info('AS Batch: Import no longer running, not rescheduling');
            }
            return;
        }
        
        try {
            $this->as_process_batch_inner($args, $batch_start_time, $batch_start_memory);
        } finally {
            // Always release lock, even on exception
            $wpdb->query("SELECT RELEASE_LOCK('myfeeds_batch_exec')");
        }
    }
    
    /**
     * Inner batch processing (called under GET_LOCK protection)
     * 
     * ARCHITECTURE v2.4: ONE AS JOB = ONE COMPLETE FEED
     * Instead of scheduling a new AS job for every 500-row batch,
     * this method processes an entire feed in an internal loop.
     * Progress is saved after each iteration for resume capability.
     * Only at feed boundaries does a new AS job get scheduled.
     * 
     * This reduces AS jobs from ~200+ (one per batch) to ~5 (one per feed).
     */
    private function as_process_batch_inner($args, $batch_start_time, $batch_start_memory) {
        
        $as_run_id = uniqid('batch_', true);
        
        // Temporary error handler: logs exact file + line for ANY PHP error (including Division by zero)
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            myfeeds_log("MYFEEDS_PHP_ERROR in import: [{$errno}] {$errstr} at {$errfile}:{$errline}", 'error');
            return false; // Let PHP handle it normally after logging
        });
        
        // Read state BEFORE any mutation
        $state_before_status = myfeeds_as_get_state_summary(self::OPTION_IMPORT_STATUS);
        $state_before_batch = myfeeds_as_get_state_summary(self::OPTION_BATCH_STATE);
        $queue_raw = get_option(self::OPTION_IMPORT_QUEUE, array());
        $queue_summary_before = myfeeds_as_get_queue_summary($queue_raw);
        
        myfeeds_as_log('batch_start', array(
            'hook' => function_exists('current_filter') ? current_filter() : null,
            'group' => self::AS_GROUP,
            'args' => array(
                'feed_index' => isset($args['feed_index']) ? $args['feed_index'] : null,
                'offset' => isset($args['offset']) ? $args['offset'] : null,
                'batch_size' => $this->batch_size,
            ),
            'memory_mb' => round($batch_start_memory / 1024 / 1024, 2),
            'option_state_before' => array(
                'OPTION_IMPORT_STATUS' => $state_before_status,
                'OPTION_BATCH_STATE' => $state_before_batch,
                'OPTION_IMPORT_QUEUE' => $queue_summary_before,
            ),
        ), $as_run_id);
        
        MyFeeds_Logger::info('=== AS JOB START (1 job = 1 complete feed) ===');
        MyFeeds_Logger::info('AS Job: args=' . json_encode($args));
        
        // Extract arguments
        $feed_index = isset($args['feed_index']) ? intval($args['feed_index']) : 0;
        $offset = isset($args['offset']) ? intval($args['offset']) : 0;
        
        // Load current state
        $status = get_option(self::OPTION_IMPORT_STATUS, array());
        $queue = get_option(self::OPTION_IMPORT_QUEUE, array());
        
        // Validate import is still running
        if (empty($status) || $status['status'] !== 'running') {
            MyFeeds_Logger::info('AS Job: Import not running, aborting');
            myfeeds_as_log('batch_end', array(
                'decision_reason' => 'import_not_running',
            ), $as_run_id);
            return;
        }
        
        // Validate queue has this feed
        if (empty($queue) || !isset($queue[$feed_index])) {
            MyFeeds_Logger::info("AS Job: feed_index={$feed_index} not in queue (count=" . count($queue) . "), completing import");
            myfeeds_as_log('batch_end', array(
                'decision_reason' => empty($queue) ? 'queue_empty' : 'feed_index_not_in_queue',
            ), $as_run_id);
            $this->as_schedule_complete();
            return;
        }
        
        $current_feed = $queue[$feed_index];
        
        // Fix 6: Check if feed still exists (user may have deleted it during import)
        $feed_key_to_check = $current_feed['feed_key'] ?? null;
        if ($feed_key_to_check !== null) {
            $all_feeds = get_option('myfeeds_feeds', array());
            if (!isset($all_feeds[$feed_key_to_check])) {
                MyFeeds_Logger::info("AS Job: Feed key={$feed_key_to_check} was deleted, skipping to next");
                $queue[$feed_index]['status'] = 'completed';
                update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
                $this->as_schedule_next_feed($feed_index + 1);
                return;
            }
        }
        
        // Skip if feed already completed
        if ($current_feed['status'] === 'completed' || $current_feed['status'] === 'failed') {
            MyFeeds_Logger::info("AS Job: Feed #{$feed_index} already {$current_feed['status']}, moving to next");
            $this->as_schedule_next_feed($feed_index + 1);
            return;
        }
        
        // =====================================================================
        // FEED DOWNLOAD: Stream feed to disk cache (ZERO RAM usage)
        // OOM FIX: Uses myfeeds_ensure_feed_cached() which streams via
        // wp_remote_get(stream=true). Feed body never enters PHP memory.
        // =====================================================================
        $feed_name = $current_feed['feed_name'] ?? 'unknown';
        $feed_url = $current_feed['feed_url'];
        $mapping = $current_feed['mapping'] ?? array();
        $feed_mode = $current_feed['mode'] ?? self::MODE_FULL;
        $feed_phase = $current_feed['phase'] ?? 'import';
        
        // DIAG LOG 2: Log feed_index vs feed_key vs stable_id mapping
        $stable_id_for_upsert = (int) ($current_feed['stable_id'] ?? 0);
        
        // Safety: If queue entry has stable_id=0, resolve from live feed config
        if ($stable_id_for_upsert === 0) {
            $fk = $current_feed['feed_key'] ?? null;
            if ($fk !== null) {
                $live_feeds = get_option('myfeeds_feeds', array());
                if (isset($live_feeds[$fk]['stable_id'])) {
                    $stable_id_for_upsert = (int) $live_feeds[$fk]['stable_id'];
                    myfeeds_log("DIAG stable_id FALLBACK: queue had stable_id=0, resolved to {$stable_id_for_upsert} from live config for feed_key={$fk}, feed_name={$feed_name}", 'info');
                } else {
                    // Last resort: assign a new stable_id
                    $live_feed = $live_feeds[$fk] ?? array();
                    $stable_id_for_upsert = MyFeeds_DB_Manager::assign_stable_id($live_feed);
                    $live_feeds[$fk] = $live_feed;
                    update_option('myfeeds_feeds', $live_feeds);
                    myfeeds_log("DIAG stable_id ASSIGNED: feed_key={$fk} had no stable_id anywhere, assigned new stable_id={$stable_id_for_upsert}", 'info');
                }
            }
        }
        
        myfeeds_log("DIAG batch_inner: feed_index={$feed_index}, feed_key=" . ($current_feed['feed_key'] ?? 'N/A') . ", feed_name={$feed_name}, stable_id={$stable_id_for_upsert}, phase={$feed_phase}", 'info');
        
        // GUARD: Refuse to process feed with stable_id=0 — would create orphan rows
        if ($stable_id_for_upsert === 0) {
            myfeeds_log("SKIPPED upsert: stable_id=0 for feed '{$feed_name}' (feed_index={$feed_index}), refusing to write to DB", 'error');
            $queue[$feed_index]['status'] = 'failed';
            $queue[$feed_index]['error'] = 'stable_id=0 — cannot write to DB';
            $status['processed_feeds'] = ($status['processed_feeds'] ?? 0) + 1;
            update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
            update_option(self::OPTION_IMPORT_STATUS, $status, false);
            $this->as_schedule_next_feed($feed_index + 1);
            return;
        }
        
        $status['current_feed'] = $current_feed['feed_key'];
        $status['current_feed_name'] = $feed_name;
        $status['last_activity'] = current_time('mysql');
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        MyFeeds_Logger::info("AS Job: Processing ENTIRE feed '{$feed_name}' starting at offset {$offset}");
        
        // Stream download to disk cache (OOM-safe)
        $import_run_id = md5($status['started_at'] ?? '');
        $cache_path = myfeeds_ensure_feed_cached($feed_url, $feed_index, $import_run_id);
        
        if (is_wp_error($cache_path)) {
            MyFeeds_Logger::error("AS Job: Feed fetch failed - " . $cache_path->get_error_message());
            $queue[$feed_index]['status'] = 'failed';
            $queue[$feed_index]['error'] = $cache_path->get_error_message();
            $status['errors'][] = array('feed' => $feed_name, 'error' => $cache_path->get_error_message());
            $status['processed_feeds'] = ($status['processed_feeds'] ?? 0) + 1;
            update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
            update_option(self::OPTION_IMPORT_STATUS, $status, false);
            $this->as_schedule_next_feed($feed_index + 1);
            return;
        }
        
        // =====================================================================
        // PARSE FEED FROM DISK: Universal Feed Reader (CSV/TSV/XML/JSON)
        // =====================================================================
        $format_hint = $current_feed['format_hint'] ?? '';
        $reader = new MyFeeds_Feed_Reader();
        if (!$reader->open($cache_path, $format_hint)) {
            MyFeeds_Logger::error("AS Job: Feed Reader cannot open cache file for feed '{$feed_name}'");
            $queue[$feed_index]['status'] = 'failed';
            $queue[$feed_index]['error'] = 'Cannot open cached feed file';
            $status['processed_feeds'] = ($status['processed_feeds'] ?? 0) + 1;
            update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
            update_option(self::OPTION_IMPORT_STATUS, $status, false);
            $this->as_schedule_next_feed($feed_index + 1);
            return;
        }
        
        $detected_format = $reader->get_detected_format();
        MyFeeds_Logger::info("AS Job: Feed Reader opened '{$feed_name}' as '{$detected_format}'");
        
        $header = $reader->get_headers();
        $total_rows = $reader->count_items();
        
        if ($total_rows < 1) {
            $reader->close();
            MyFeeds_Logger::error("AS Job: Feed '{$feed_name}' has no data rows");
            $queue[$feed_index]['status'] = 'failed';
            $queue[$feed_index]['error'] = 'Feed is empty';
            $status['processed_feeds'] = ($status['processed_feeds'] ?? 0) + 1;
            update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
            update_option(self::OPTION_IMPORT_STATUS, $status, false);
            $this->as_schedule_next_feed($feed_index + 1);
            return;
        }
        
        MyFeeds_Logger::info("AS Job: Feed on disk ({$cache_path}) — {$total_rows} rows, starting internal loop at offset {$offset}");
        
        // =====================================================================
        // AUTO-MAPPING: If feed has no mapping, create one from first data row.
        // =====================================================================
        if (empty($mapping) && $this->smart_mapper) {
            $first_row = $reader->read_next();
            
            if ($first_row !== false) {
                $new_mapping = $this->smart_mapper->auto_map_fields($first_row, $feed_url);
                if ($new_mapping) {
                    $mapping = $new_mapping;
                    
                    // Save mapping to feed config
                    $auto_feed_key = $current_feed['feed_key'] ?? null;
                    if ($auto_feed_key !== null) {
                        $auto_feeds = get_option('myfeeds_feeds', array());
                        if (isset($auto_feeds[$auto_feed_key])) {
                            $auto_feeds[$auto_feed_key]['mapping'] = $new_mapping;
                            $auto_feeds[$auto_feed_key]['detected_fields'] = $header;
                            $auto_feeds[$auto_feed_key]['detected_format'] = $detected_format;
                            $auto_feeds[$auto_feed_key]['mapping_updated'] = current_time('mysql');
                            $auto_feeds[$auto_feed_key]['mapping_confidence'] = $this->smart_mapper->get_mapping_confidence($new_mapping);
                            update_option('myfeeds_feeds', $auto_feeds);
                        }
                    }
                    
                    // Update queue entry with new mapping
                    $queue[$feed_index]['mapping'] = $new_mapping;
                    update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
                    
                    $clean_name = preg_replace('/\s*\(Priority\)\s*$/', '', $feed_name);
                    myfeeds_log("Auto-mapping created for feed '{$clean_name}' (stable_id={$stable_id_for_upsert}) during import", 'info');
                } else {
                    myfeeds_log("Auto-mapping failed for feed '{$feed_name}' (stable_id={$stable_id_for_upsert}) — smart_mapper returned empty", 'error');
                }
            }
            
            // Close and reopen to start from the beginning
            $reader->close();
            $reader->open($cache_path, $format_hint);
            $reader->count_items(); // Re-count to reset internal state
        }
        
        // Skip to offset if resuming a crashed job
        if ($offset > 0) {
            $reader->skip_to($offset);
        }
        
        // For priority pass, get active IDs
        $active_ids = array();
        $is_priority_pass = ($feed_phase === 'priority_active');
        if ($is_priority_pass) {
            $active_ids = array_flip(get_option(self::OPTION_ACTIVE_IDS, array()));
            MyFeeds_Logger::info("AS Job: Priority phase — looking for " . count($active_ids) . " active IDs");
        }
        
        // =====================================================================
        // INTERNAL LOOP: Process all batches of this feed within ONE AS job
        // Saves progress after each batch iteration for resume capability.
        // Wrapped in try/catch for error recovery.
        // =====================================================================
        $current_offset = $offset;
        $total_processed_in_job = 0;
        $iteration = 0;
        
        try {
        
        while ($current_offset < $total_rows) {
            $iteration++;
            $iter_start = microtime(true);
            
            // Iteration heartbeat — visible in logs even if the iteration hangs
            MyFeeds_Logger::debug("AS Job: LOOP iteration={$iteration}, offset={$current_offset}/{$total_rows}, feed='{$feed_name}'");
            
            // Calculate batch boundaries
            $batch_end = min($current_offset + $this->batch_size, $total_rows);
            
            // Process batch
            $batch_items = array();
            $processed_count = 0;
            $skipped_priority = 0;
            $rows_to_process = $batch_end - $current_offset;
            
            // Fix P1: Log batch build start
            myfeeds_log("BATCH_BUILD_START: iteration={$iteration}, offset={$current_offset}, rows={$rows_to_process}, feed='{$feed_name}'", 'debug');
            
            for ($i = $current_offset; $i < $batch_end; $i++) {
                $row_idx = $i - $current_offset; // 0-based within this batch
                
                $raw = $reader->read_next();
                if ($raw === false) break; // EOF
                
                $product_id = $this->extract_product_id_fast($raw);
                if (!$product_id) {
                    myfeeds_log("ROW_SKIP: row={$row_idx}, reason=no_product_id", 'debug');
                    continue;
                }
                
                // Priority pass: only process active products
                if ($is_priority_pass && !isset($active_ids[$product_id])) {
                    $skipped_priority++;
                    continue;
                }
                
                // Map and process product
                $mapped = $this->map_product($raw, $mapping);
                
                if ($this->smart_mapper) {
                    $mapped = $this->smart_mapper->apply_intelligent_processing($mapped);
                }
                
                if ($this->feed_manager && method_exists($this->feed_manager, 'process_critical_fields')) {
                    $mapped = $this->feed_manager->process_critical_fields($mapped, $raw);
                } else {
                    $mapped = $this->process_critical_fields_fallback($mapped, $raw);
                }
                
                $mapped['id'] = $product_id;
                
                $batch_items[$product_id] = $mapped;
                $processed_count++;
            }
            
            // Fix P1: Log batch build complete
            myfeeds_log("BATCH_BUILD_DONE: iteration={$iteration}, batch_size=" . count($batch_items) . ", processed={$processed_count}, skipped_priority={$skipped_priority}", 'debug');
            
            // Write batch to building index or DB
            // Fix P2: Strip priority suffix from feed_name before DB write
            $db_feed_name = preg_replace('/\s*\(Priority\)\s*$/', '', $feed_name);
            
            $building_total = 0;
            if (!empty($batch_items)) {
                if (MyFeeds_DB_Manager::is_db_mode()) {
                    myfeeds_log("DIAG upsert: feed_index={$feed_index}, feed_name={$db_feed_name}, stable_id={$stable_id_for_upsert}, batch_size=" . count($batch_items) . ", phase={$feed_phase}", 'info');
                    try {
                        MyFeeds_DB_Manager::upsert_batch($batch_items, $stable_id_for_upsert, $db_feed_name);
                    } catch (\Throwable $upsert_ex) {
                        MyFeeds_Logger::error("AS Job: upsert_batch exception in iteration={$iteration}, feed='{$feed_name}': " . $upsert_ex->getMessage());
                        // Continue to next iteration — don't let a bad batch kill the whole feed
                    }
                    // Always get fresh count from DB
                    $building_total = MyFeeds_DB_Manager::get_active_product_count();
                } else {
                    $building_total = MyFeeds_Atomic_Index_Manager::add_items_to_building_index($batch_items);
                }
            }
            unset($batch_items); // Free memory immediately
            
            $total_processed_in_job += $processed_count;
            $current_offset = $batch_end;
            
            // =============================================================
            // =============================================================
            // SAVE PROGRESS after each iteration (for resume + UI updates)
            // PERF: Full status + queue only every 5 iterations to reduce
            // wp_options writes. Batch state every iteration for watchdog.
            // =============================================================
            $status['consecutive_errors'] = 0;
            $status['heartbeat_timestamp'] = time();
            $status['processed_rows'] = ($status['processed_rows'] ?? 0) + $processed_count;
            $status['last_activity'] = current_time('mysql');
            
            if (!$is_priority_pass) {
                $status['processed_products'] = ($status['processed_products'] ?? 0) + $processed_count;
            }
            if ($building_total > 0) {
                $status['unique_products'] = $building_total;
            }

            // Batch state: EVERY iteration (watchdog needs fresh timestamps)
            update_option(self::OPTION_BATCH_STATE, array(
                'current_feed_index' => $feed_index,
                'current_offset' => $current_offset,
                'last_updated' => time(),
            ), false);

            // Status + Queue: every 5 iterations OR on last iteration of feed
            if ($iteration % 5 === 0 || $current_offset >= $total_rows) {
                update_option(self::OPTION_IMPORT_STATUS, $status, false);
                
                $queue[$feed_index]['offset'] = $current_offset;
                $queue[$feed_index]['total_rows'] = $total_rows;
                $queue[$feed_index]['status'] = 'processing';
                update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
            } else {
                // Still update queue offset in memory for feed-complete logic
                $queue[$feed_index]['offset'] = $current_offset;
                $queue[$feed_index]['total_rows'] = $total_rows;
                $queue[$feed_index]['status'] = 'processing';
            }
            
            $iter_duration = round((microtime(true) - $iter_start) * 1000);
            MyFeeds_Logger::debug("AS Job: iteration={$iteration}, rows={$current_offset}/{$total_rows}, processed={$processed_count}, skipped_priority={$skipped_priority}, building_total={$building_total}, duration={$iter_duration}ms");
        }
        
        // =====================================================================
        // FEED COMPLETE
        // =====================================================================
        $queue[$feed_index]['offset'] = $current_offset;
        $queue[$feed_index]['total_rows'] = $total_rows;
        $queue[$feed_index]['status'] = 'completed';
        
        if (!$is_priority_pass) {
            $status['processed_feeds'] = ($status['processed_feeds'] ?? 0) + 1;
            $status['total_products'] = ($status['total_products'] ?? 0) + $total_rows;
            
            // Update feed config: status, last_sync and product_count (deduplicated from DB)
            $feed_key_val = $current_feed['feed_key'] ?? null;
            if ($feed_key_val !== null && $total_processed_in_job > 0) {
                $current_feeds = get_option('myfeeds_feeds', array());
                if (isset($current_feeds[$feed_key_val])) {
                    $old_status = $current_feeds[$feed_key_val]['status'] ?? 'unknown';
                    if ($old_status !== 'active') {
                        $current_feeds[$feed_key_val]['status'] = 'active';
                        MyFeeds_Logger::info("Feed '{$feed_name}' status changed from '{$old_status}' to 'active'");
                    }
                    $current_feeds[$feed_key_val]['last_sync'] = current_time('mysql');
                    // Always store deduplicated product count when feed completes (Fix 2)
                    if (MyFeeds_DB_Manager::is_db_mode()) {
                        // Count by feed_name (catches all products including legacy feed_id=0)
                        $feed_counts_map = MyFeeds_DB_Manager::get_feed_counts();
                        $current_feeds[$feed_key_val]['product_count'] = $feed_counts_map[$db_feed_name] ?? MyFeeds_DB_Manager::get_feed_product_count($stable_id_for_upsert);
                    }
                    update_option('myfeeds_feeds', $current_feeds);
                    MyFeeds_Logger::info("Feed '{$feed_name}' import complete: {$total_processed_in_job} rows, products={$current_feeds[$feed_key_val]['product_count']}");
                }
            }
        }
        
        update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        // Clean up feed cache file
        myfeeds_cleanup_feed_cache($feed_index, $import_run_id);
        
        // Close Feed Reader and explicit memory cleanup after each feed
        $reader->close();
        unset($header, $active_ids);
        gc_collect_cycles();
        
        // Fix 3: Memory logging after each feed
        $mem_usage_mb = round(memory_get_usage(true) / 1024 / 1024, 2);
        $mem_peak_mb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        if (MyFeeds_DB_Manager::is_db_mode()) {
            $db_stats = MyFeeds_DB_Manager::get_stats();
            MyFeeds_Logger::debug("[MEMORY] After feed {$feed_index} ('{$feed_name}'): usage={$mem_usage_mb}MB, peak={$mem_peak_mb}MB, db_products=" . ($db_stats['count'] ?? '?'));
        } else {
            $building_stats = MyFeeds_Atomic_Index_Manager::get_building_stats();
            MyFeeds_Logger::debug("[MEMORY] After feed {$feed_index} ('{$feed_name}'): usage={$mem_usage_mb}MB, peak={$mem_peak_mb}MB, building_index_entries=" . ($building_stats['items_appended'] ?? '?') . ", building_file_size=" . ($building_stats['file_size_mb'] ?? '?') . "MB");
        }
        
        $job_duration = round((microtime(true) - $batch_start_time) * 1000);
        $memory_used = round((memory_get_usage(true) - $batch_start_memory) / 1024 / 1024, 2);
        MyFeeds_Logger::info("AS Job: Feed '{$feed_name}' COMPLETED — {$total_processed_in_job} products in {$iteration} iterations, {$job_duration}ms, +{$memory_used}MB");
        
        myfeeds_as_log('batch_end', array(
            'feed_name' => $feed_name,
            'total_processed' => $total_processed_in_job,
            'iterations' => $iteration,
            'duration_ms' => $job_duration,
            'decision_schedule_next_batch' => 'NO',
            'decision_reason' => 'feed_complete_scheduling_next_feed',
            'next_feed_index' => $feed_index + 1,
        ), $as_run_id);
        
        // Schedule next feed
        MyFeeds_Logger::info("AS Job: Scheduling next feed (index=" . ($feed_index + 1) . ")");
        $this->as_schedule_next_feed($feed_index + 1);
        
        } catch (\Throwable $e) {
            // Error during feed processing — save state for resume and skip to next feed
            MyFeeds_Logger::error("AS Job: Exception in feed '{$feed_name}' at offset {$current_offset} — " . $e->getMessage());
            
            $status = get_option(self::OPTION_IMPORT_STATUS, array());
            $status['errors'][] = array(
                'feed' => $feed_name,
                'error' => 'Exception: ' . $e->getMessage(),
                'offset' => $current_offset,
            );
            $status['last_activity'] = current_time('mysql');
            $status['consecutive_errors'] = ($status['consecutive_errors'] ?? 0) + 1;
            
            if ($status['consecutive_errors'] >= 5) {
                MyFeeds_Logger::error('AS Job: 5 consecutive errors — stopping import');
                $status['status'] = 'error';
                $status['error_message'] = 'Too many consecutive errors: ' . $e->getMessage();
                update_option(self::OPTION_IMPORT_STATUS, $status, false);
            } else {
                update_option(self::OPTION_IMPORT_STATUS, $status, false);
                MyFeeds_Logger::info("AS Job: Recovering — skipping to next feed (index=" . ($feed_index + 1) . ")");
                $this->as_schedule_next_feed($feed_index + 1);
            }
            
            myfeeds_as_log('batch_end', array(
                'decision_reason' => 'exception:' . substr($e->getMessage(), 0, 100),
                'consecutive_errors' => $status['consecutive_errors'] ?? 0,
            ), $as_run_id);
        }
        
        MyFeeds_Logger::info('=== AS JOB END ===');
    }
    
    /**
     * Schedule the next batch for the same feed
     */
    private function as_schedule_next_batch($feed_index, $offset) {
        $job_args = array(
            'feed_index' => $feed_index,
            'offset' => $offset,
        );
        
        MyFeeds_Logger::info("AS: Scheduling next batch - feed_index={$feed_index}, offset={$offset}");
        $this->schedule_next_batch($job_args);
    }
    
    /**
     * Schedule processing of the next feed
     */
    private function as_schedule_next_feed($feed_index) {
        $queue = get_option(self::OPTION_IMPORT_QUEUE, array());
        $queue_count = count($queue);
        
        MyFeeds_Logger::debug("=== AS_SCHEDULE_NEXT_FEED ===");
        MyFeeds_Logger::debug("AS Next Feed: requested feed_index={$feed_index}, queue_count={$queue_count}");
        
        // Log queue state for debugging
        foreach ($queue as $idx => $q) {
            $q_status = $q['status'] ?? 'unknown';
            $q_phase = $q['phase'] ?? 'unknown';
            $q_offset = $q['offset'] ?? 0;
            $q_total = $q['total_rows'] ?? 0;
            MyFeeds_Logger::debug("AS Queue[{$idx}]: name='{$q['feed_name']}', phase={$q_phase}, status={$q_status}, offset={$q_offset}/{$q_total}");
        }
        
        // Check if there are more feeds
        if ($feed_index >= $queue_count) {
            MyFeeds_Logger::info("AS Next Feed: No more feeds (index={$feed_index} >= count={$queue_count}) -> COMPLETING IMPORT");
            $this->as_schedule_complete();
            return;
        }
        
        // Check if this feed is already completed
        $next_feed = $queue[$feed_index];
        if ($next_feed['status'] === 'completed') {
            MyFeeds_Logger::info("AS Next Feed: Feed #{$feed_index} already completed, skipping to next");
            $this->as_schedule_next_feed($feed_index + 1);
            return;
        }
        
        // Schedule first batch of next feed
        $job_args = array(
            'feed_index' => $feed_index,
            'offset' => 0,
            'timestamp' => time(),
        );
        
        MyFeeds_Logger::info("AS Next Feed: Scheduling feed #{$feed_index} ('{$next_feed['feed_name']}', phase={$next_feed['phase']})");
        $this->schedule_next_batch($job_args);
    }
    
    /**
     * Schedule the import completion action
     */
    private function as_schedule_complete() {
        if (!function_exists('as_schedule_single_action')) {
            MyFeeds_Logger::info('AS: as_schedule_single_action not available, completing directly');
            $this->complete_import();
            return;
        }
        
        // Check if already scheduled
        if (as_has_scheduled_action(self::AS_HOOK_COMPLETE, array(), self::AS_GROUP)) {
            MyFeeds_Logger::debug('AS: Complete action already scheduled');
            return;
        }
        
        // Use time() + 5 to ensure future timestamp
        $action_id = as_schedule_single_action(
            time() + 5,
            self::AS_HOOK_COMPLETE,
            array(),
            self::AS_GROUP,
            false  // No unique constraint - we already checked
        );
        
        if ($action_id) {
            MyFeeds_Logger::info("AS: Scheduled completion action, action_id={$action_id}");
        } else {
            // Fallback: complete directly if scheduling fails
            MyFeeds_Logger::info('AS: Failed to schedule completion, completing directly');
            $this->complete_import();
        }
    }
    
    /**
     * Complete the import (called by Action Scheduler)
     */
    public function as_complete_import() {
        MyFeeds_Logger::info('=== ACTION SCHEDULER IMPORT COMPLETE ===');
        $this->complete_import();
    }
    
    /**
     * Process a single batch from a feed (Action Scheduler version)
     * 
     * This is a simplified, more robust version of process_feed_batch()
     * optimized for Action Scheduler's job model.
     * 
     * @param array $feed Feed configuration
     * @param int $offset Starting position in feed
     * @return array|WP_Error Result with new_offset, is_complete, processed_count
     */
    private function as_process_feed_batch($feed, $offset = 0) {
        // FIX 1: Remove PHP time limit for feed batch processing
        if (!ini_get('safe_mode')) {
            set_time_limit(0);
        }
        
        $url = $feed['feed_url'];
        $mapping = $feed['mapping'] ?? array();
        $feed_mode = $feed['mode'] ?? self::MODE_FULL;
        $feed_phase = $feed['phase'] ?? 'import';
        $feed_name = $feed['feed_name'] ?? 'unknown';
        $feed_index = $feed['feed_index'] ?? 0;
        $feed_key = $feed['feed_key'] ?? $feed_index;
        
        MyFeeds_Logger::debug("=== AS_PROCESS_FEED_BATCH START ===");
        MyFeeds_Logger::debug("AS Feed Batch: feed_name='{$feed_name}', url={$url}");
        MyFeeds_Logger::debug("AS Feed Batch: offset={$offset}, mode={$feed_mode}, phase={$feed_phase}");
        
        // Determine import run_id for cache keying
        $status = get_option(self::OPTION_IMPORT_STATUS, array());
        $run_id = md5($status['started_at'] ?? '');
        
        // Fetch feed content via cache helper (downloads only on first batch per feed)
        $feed_content = myfeeds_get_feed_content($url, $feed_index, $run_id);
        
        if (is_wp_error($feed_content)) {
            MyFeeds_Logger::error("AS Feed Batch: Feed fetch failed - " . $feed_content->get_error_message());
            return $feed_content;
        }
        
        $content_length = strlen($feed_content);
        MyFeeds_Logger::debug("AS Feed Batch: Feed content length = {$content_length} bytes");
        
        // Parse feed into rows
        $lines = preg_split('/\r\n|\n|\r/', trim($feed_content));
        $total_rows = count($lines) - 1; // Exclude header
        
        MyFeeds_Logger::debug("AS Feed Batch: total_rows={$total_rows} (excluding header)");
        
        if ($total_rows < 1) {
            MyFeeds_Logger::error("AS Feed Batch: Feed is empty or has no data rows");
            return new WP_Error('empty_feed', 'Feed is empty or has no data rows');
        }
        
        // Get header (IMPORTANT: work with copy to not affect cache)
        $header = str_getcsv($lines[0]);
        
        // Calculate batch boundaries
        $start = $offset;
        $end = min($offset + $this->batch_size, $total_rows);
        
        MyFeeds_Logger::debug("AS Feed Batch: Processing rows {$start} to {$end} of {$total_rows} (batch_size={$this->batch_size})");
        
        // Collect batch items in memory — will be written to building index after loop
        $batch_items = array();
        $processed_count = 0;
        $skipped_priority = 0;
        
        // For priority pass, get active IDs
        $active_ids = array();
        if ($feed_phase === 'priority_active') {
            $active_ids = array_flip(get_option(self::OPTION_ACTIVE_IDS, array()));
            MyFeeds_Logger::debug("AS Feed Batch: Priority phase - looking for " . count($active_ids) . " active IDs");
        }
        
        // Process batch - NOTE: $lines[0] is header, data starts at $lines[1]
        for ($i = $start; $i < $end && ($i + 1) < count($lines); $i++) {
            $line = $lines[$i + 1]; // +1 because $lines[0] is header
            $fields = str_getcsv($line);
            
            if (count($fields) !== count($header)) {
                continue;
            }
            
            $raw = array_combine($header, $fields);
            
            // Extract product ID
            $product_id = $this->extract_product_id_fast($raw);
            if (!$product_id) {
                continue;
            }
            
            // For priority pass: only process active products
            if ($feed_phase === 'priority_active' && !isset($active_ids[$product_id])) {
                $skipped_priority++;
                continue;
            }
            
            // Map and process product
            $mapped = $this->map_product($raw, $mapping);
            
            if ($this->smart_mapper) {
                $mapped = $this->smart_mapper->apply_intelligent_processing($mapped);
            }
            
            if ($this->feed_manager && method_exists($this->feed_manager, 'process_critical_fields')) {
                $mapped = $this->feed_manager->process_critical_fields($mapped, $raw);
            } else {
                $mapped = $this->process_critical_fields_fallback($mapped, $raw);
            }
            
            $mapped['id'] = $product_id;
            $batch_items[$product_id] = $mapped;
            $processed_count++;
        }
        
        // Write batch to building index or DB via feature flag
        // Fix P2: Strip priority suffix from feed_name before DB write
        $db_feed_name = preg_replace('/\s*\(Priority\)\s*$/', '', $feed_name);
        
        // Resolve stable_id with safety fallback (same pattern as as_process_batch_inner)
        $stable_id_for_batch = (int) ($feed['stable_id'] ?? 0);
        if ($stable_id_for_batch === 0) {
            $fk = $feed['feed_key'] ?? null;
            if ($fk !== null) {
                $live_feeds = get_option('myfeeds_feeds', array());
                if (isset($live_feeds[$fk]['stable_id'])) {
                    $stable_id_for_batch = (int) $live_feeds[$fk]['stable_id'];
                    myfeeds_log("DIAG as_process_feed_batch stable_id FALLBACK: resolved to {$stable_id_for_batch} for feed_key={$fk}", 'info');
                }
            }
        }
        
        $building_total = 0;
        if (!empty($batch_items)) {
            if (MyFeeds_DB_Manager::is_db_mode()) {
                // GUARD: Refuse to write with stable_id=0
                if ($stable_id_for_batch === 0) {
                    myfeeds_log("SKIPPED upsert: stable_id=0 for feed '{$feed_name}' in as_process_feed_batch, refusing to write", 'error');
                } else {
                    try {
                        MyFeeds_DB_Manager::upsert_batch($batch_items, $stable_id_for_batch, $db_feed_name);
                    } catch (\Throwable $upsert_ex) {
                        MyFeeds_Logger::error("AS Feed Batch: upsert_batch exception: " . $upsert_ex->getMessage());
                    }
                }
                $building_total = MyFeeds_DB_Manager::get_active_product_count();
            } else {
                $building_total = MyFeeds_Atomic_Index_Manager::add_items_to_building_index($batch_items);
            }
        }
        
        // Determine if this feed is complete
        $is_complete = ($end >= $total_rows);
        
        // DIAGNOSTIC: Log decision
        MyFeeds_Logger::debug("AS Feed Batch RESULT: processed_count={$processed_count}, skipped_priority={$skipped_priority}");
        MyFeeds_Logger::debug("AS Feed Batch RESULT: batch_items=" . count($batch_items) . ", building_total={$building_total}");
        MyFeeds_Logger::debug("AS Feed Batch RESULT: new_offset={$end}, is_complete=" . ($is_complete ? 'YES' : 'NO'));
        MyFeeds_Logger::debug("AS Feed Batch DECISION: end({$end}) >= total_rows({$total_rows}) ? " . ($is_complete ? 'YES->COMPLETE' : 'NO->SCHEDULE_NEXT'));
        MyFeeds_Logger::debug("=== AS_PROCESS_FEED_BATCH END ===");
        
        return array(
            'new_offset' => $end,
            'is_complete' => $is_complete,
            'processed_count' => $processed_count,
            'total_rows' => $total_rows,
            'building_total' => $building_total,
        );
    }
    
    /**
     * Cancel the current import and clear all scheduled actions
     */
    public function cancel_import_as() {
        MyFeeds_Logger::info('AS: Cancelling import');
        
        // Clear all scheduled actions in our group
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::AS_HOOK_PROCESS_BATCH, array(), self::AS_GROUP);
            as_unschedule_all_actions(self::AS_HOOK_COMPLETE, array(), self::AS_GROUP);
        }
        
        // Clear state
        delete_option(self::OPTION_BATCH_STATE);
        delete_option(self::OPTION_IMPORT_QUEUE);
        
        // Update status
        $status = get_option(self::OPTION_IMPORT_STATUS, array());
        $status['status'] = 'cancelled';
        $status['cancelled_at'] = current_time('mysql');
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        MyFeeds_Logger::info('AS: Import cancelled successfully');
    }
    
    // =========================================================================
    // LEGACY BATCH PROCESSING (Will be deprecated)
    // =========================================================================
    
    /**
     * Process the next batch of products
     * DIAGNOSTIC: Added extensive logging for 40% stop debugging
     */
    public function process_next_batch() {
        // GUARD: If Action Scheduler is available, legacy batch must NOT run.
        // Legacy can be triggered directly via the myfeeds_process_batch cron hook,
        // bypassing check_and_process_queue(). This guard catches that case.
        if (function_exists('as_has_scheduled_action')) {
            myfeeds_log('Legacy process_next_batch blocked — Action Scheduler is handling imports.', 'info');
            // Also clear the legacy cron hook to prevent future triggers
            wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }
        
        $batch_start_time = microtime(true);
        $batch_start_memory = memory_get_usage(true);
        
        // DIAGNOSTIC: Register shutdown function to catch fatal errors
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                MyFeeds_Logger::error('=== FATAL ERROR IN BATCH PROCESSING ===');
                MyFeeds_Logger::error('Error type: ' . $error['type']);
                MyFeeds_Logger::error('Error message: ' . $error['message']);
                MyFeeds_Logger::error('Error file: ' . $error['file'] . ':' . $error['line']);
                MyFeeds_Logger::error('Memory at crash: ' . round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB');
            }
        });
        
        // FIX 1: Remove PHP time limit for legacy batch processing too
        if (!ini_get('safe_mode')) {
            set_time_limit(0);
        }
        ignore_user_abort(true);
        
        $status = get_option(self::OPTION_IMPORT_STATUS, array());
        $queue = get_option(self::OPTION_IMPORT_QUEUE, array());
        
        MyFeeds_Logger::debug('=== BATCH START ===');
        MyFeeds_Logger::debug("Batch: Status=" . ($status['status'] ?? 'unknown') . ", Queue items=" . count($queue));
        MyFeeds_Logger::debug("Batch: Memory start=" . round($batch_start_memory / 1024 / 1024, 2) . " MB");
        MyFeeds_Logger::debug("Batch: PHP memory_limit=" . ini_get('memory_limit') . ", max_execution_time=" . ini_get('max_execution_time'));
        
        if (empty($status) || $status['status'] !== 'running') {
            MyFeeds_Logger::info('Batch: Aborting - status is not running');
            return;
        }
        
        if (empty($queue)) {
            MyFeeds_Logger::info('Batch: Queue empty - completing import');
            $this->complete_import();
            return;
        }
        
        // Get current feed from queue
        $current_feed = null;
        $current_index = null;
        
        foreach ($queue as $index => $feed) {
            if ($feed['status'] === 'pending' || $feed['status'] === 'processing') {
                $current_feed = $feed;
                $current_index = $index;
                break;
            }
        }
        
        if ($current_feed === null) {
            MyFeeds_Logger::info('Batch: No pending feeds in queue - completing import');
            $this->complete_import();
            return;
        }
        
        // Bestimme den Modus für diesen Feed-Pass
        $feed_mode = $current_feed['mode'] ?? self::MODE_FULL;
        $feed_phase = $current_feed['phase'] ?? 'import';
        
        $offset = $current_feed['offset'] ?? 0;
        $total_rows = $current_feed['total_rows'] ?? 0;
        $progress_pct = $total_rows > 0 ? round(($offset / $total_rows) * 100, 1) : 0;
        
        MyFeeds_Logger::debug("Batch: Feed='{$current_feed['feed_name']}', Mode={$feed_mode}, Phase={$feed_phase}");
        MyFeeds_Logger::debug("Batch: Offset={$offset}, TotalRows={$total_rows}, Progress={$progress_pct}%");
        
        // Update status mit aktueller Phase
        $status['current_feed'] = $current_feed['feed_key'];
        $status['current_feed_name'] = $current_feed['feed_name'];
        $status['phase'] = $feed_phase;
        $status['last_activity'] = current_time('mysql');
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        // Process batch
        $result = $this->process_feed_batch($current_feed);
        
        if (is_wp_error($result)) {
            $queue[$current_index]['status'] = 'failed';
            $queue[$current_index]['error'] = $result->get_error_message();
            
            $status['errors'][] = array(
                'feed' => $current_feed['feed_name'],
                'error' => $result->get_error_message(),
            );
            $status['processed_feeds']++;
            
            update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
            update_option(self::OPTION_IMPORT_STATUS, $status, false);
            
            MyFeeds_Logger::error("Batch: Feed failed - " . $result->get_error_message());
            $this->schedule_next_batch();
            return;
        }
        
        // DIAGNOSTIC: Log batch result
        $batch_duration = round((microtime(true) - $batch_start_time) * 1000);
        $memory_used = round((memory_get_usage(true) - $batch_start_memory) / 1024 / 1024, 2);
        $memory_peak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        
        MyFeeds_Logger::debug("Batch Result: processed={$result['processed_count']}, new_offset={$result['new_offset']}, total_rows={$result['total_rows']}");
        MyFeeds_Logger::debug("Batch Performance: duration={$batch_duration}ms, memory_delta={$memory_used}MB, memory_peak={$memory_peak}MB");
        
        // Update queue with progress
        $queue[$current_index]['offset'] = $result['new_offset'];
        $queue[$current_index]['total_rows'] = $result['total_rows'];
        $queue[$current_index]['status'] = $result['is_complete'] ? 'completed' : 'processing';
        
        // Update status
        $status['processed_products'] = ($status['processed_products'] ?? 0) + $result['processed_count'];
        $status['last_activity'] = current_time('mysql');
        
        if ($result['is_complete']) {
            $status['processed_feeds'] = ($status['processed_feeds'] ?? 0) + 1;
            $status['total_products'] = ($status['total_products'] ?? 0) + $result['total_rows'];
            MyFeeds_Logger::info("Batch: Feed '{$current_feed['feed_name']}' COMPLETED");
            
            // Prüfe ob Prioritäts-Phase abgeschlossen ist
            if ($feed_phase === 'priority_active') {
                $remaining_priority = false;
                foreach ($queue as $q) {
                    if (($q['phase'] ?? '') === 'priority_active' && $q['status'] !== 'completed') {
                        $remaining_priority = true;
                        break;
                    }
                }
                if (!$remaining_priority) {
                    $status['priority_complete'] = true;
                    $status['phase'] = 'import';
                    MyFeeds_Logger::info("Batch: Priority phase completed, switching to full import");
                }
            }
        }
        
        update_option(self::OPTION_IMPORT_QUEUE, $queue, false);
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        // Schedule next batch if not complete
        if (!$result['is_complete'] || $this->has_pending_feeds($queue)) {
            MyFeeds_Logger::debug("Batch: Scheduling next batch (is_complete={$result['is_complete']}, pending_feeds=" . ($this->has_pending_feeds($queue) ? 'yes' : 'no') . ")");
            $this->schedule_next_batch();
        } else {
            MyFeeds_Logger::info("Batch: All feeds complete - finalizing import");
            $this->complete_import();
        }
        
        MyFeeds_Logger::debug('=== BATCH END ===');
    }
    
    /**
     * Process a batch from a specific feed
     * Supports FULL, ACTIVE_ONLY, and PRIORITY_PASS modes
     * 
     * OPTIMIZATION: Priority Pass uses "Search, don't Crawl" - stops when all active IDs found!
     */
    private function process_feed_batch($feed_config) {
        $url = $feed_config['feed_url'];
        $mapping = $feed_config['mapping'];
        $offset = $feed_config['offset'];
        $mode = $feed_config['mode'] ?? self::MODE_FULL;
        
        // Get active IDs for filtering (für active_only UND priority_pass Modi)
        $active_ids = array();
        $filter_by_active = ($mode === self::MODE_ACTIVE_ONLY || $mode === 'priority_pass');
        
        if ($filter_by_active) {
            $active_ids_array = get_option(self::OPTION_ACTIVE_IDS, array());
            if (!empty($active_ids_array)) {
                $active_ids = array_flip($active_ids_array); // Convert to hash for O(1) lookup
            }
        }
        
        // Download feed data
        $response = wp_remote_get($url, array(
            'timeout' => 60,
            'headers' => array('Accept-Encoding' => 'gzip, deflate'),
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Handle gzip
        if (substr($body, 0, 2) === "\x1f\x8b") {
            $decoded = @gzdecode($body);
            if ($decoded !== false) {
                $body = $decoded;
            }
        }
        
        // Parse CSV
        $lines = preg_split('/\r\n|\n|\r/', trim($body));
        $total_rows = count($lines) - 1;
        
        if ($total_rows < 1) {
            return new WP_Error('empty_feed', 'Feed is empty');
        }
        
        // Get header
        $header = str_getcsv(array_shift($lines));
        
        // Bestimme welchen Index wir verwenden
        $upload_dir = wp_upload_dir();
        $batch_items = array();
        
        if ($mode === self::MODE_ACTIVE_ONLY) {
            // ACTIVE_ONLY: Lese den Haupt-Index für In-Place-Updates
            $index_path = $upload_dir['basedir'] . '/' . self::INDEX_FILE;
            $existing_data = array('__search_fields' => array(), 'items' => array());
            
            if (file_exists($index_path)) {
                $existing_data = json_decode(file_get_contents($index_path), true) ?: $existing_data;
            }
            $items = $existing_data['items'];
        } else {
            // FULL mode und priority_pass: Sammle Items für Building Index
            $items = array();
        }
        
        // =====================================================================
        // OPTIMIZATION: Priority Pass uses "Search, don't Crawl" strategy
        // Scans the feed but stops early when all active IDs are found!
        // =====================================================================
        if ($mode === 'priority_pass' && !empty($active_ids)) {
            // Track which IDs we've already found
            $remaining_ids = $active_ids;
            foreach ($items as $existing_id => $existing_data) {
                unset($remaining_ids[$existing_id]);
            }
            
            // If all IDs already found from previous batches, mark as complete
            if (empty($remaining_ids)) {
                myfeeds_log("Priority Pass: All active IDs already found, skipping feed scan", 'debug');
                return array(
                    'processed_count' => 0,
                    'matched_active' => 0,
                    'new_offset' => $total_rows, // Mark as complete
                    'total_rows' => $total_rows,
                    'is_complete' => true,
                );
            }
        }
        
        // Calculate batch boundaries
        $start = $offset;
        $end = min($offset + $this->batch_size, $total_rows);
        
        // Process batch
        $processed_count = 0;
        $matched_active_count = 0;
        $found_all_active = false;
        
        for ($i = $start; $i < $end && $i < count($lines); $i++) {
            $line = $lines[$i];
            $fields = str_getcsv($line);
            
            if (count($fields) !== count($header)) {
                continue;
            }
            
            $raw = array_combine($header, $fields);
            
            // Map product using Smart Mapper
            $mapped = $this->map_product($raw, $mapping);
            
            // Apply intelligent processing (discount calculation, shipping, etc.)
            if ($this->smart_mapper) {
                $mapped = $this->smart_mapper->apply_intelligent_processing($mapped);
            }
            
            // Process critical fields via Feed Manager (Single Source of Truth)
            if ($this->feed_manager && method_exists($this->feed_manager, 'process_critical_fields')) {
                $mapped = $this->feed_manager->process_critical_fields($mapped, $raw);
            } else {
                $mapped = $this->process_critical_fields_fallback($mapped, $raw);
            }
            
            if (!empty($mapped['id'])) {
                $product_id = (string) $mapped['id'];
                
                // Filtern nach aktiven Produkten wenn im entsprechenden Modus
                if ($filter_by_active && !empty($active_ids)) {
                    if (isset($active_ids[$product_id])) {
                        $items[$product_id] = $mapped;
                        $matched_active_count++;
                        $processed_count++;
                        
                        // =====================================================================
                        // EARLY EXIT CHECK: Have we found all active IDs?
                        // Only for priority_pass - stops scanning once all IDs are found!
                        // =====================================================================
                        if ($mode === 'priority_pass') {
                            $found_ids_count = 0;
                            foreach ($active_ids as $aid => $v) {
                                if (isset($items[$aid])) {
                                    $found_ids_count++;
                                }
                            }
                            if ($found_ids_count >= count($active_ids)) {
                                $found_all_active = true;
                                myfeeds_log("Priority Pass: All " . count($active_ids) . " active IDs found! Early exit at row $i of $total_rows", 'debug');
                                break; // Exit the loop early!
                            }
                        }
                    }
                    // Skip products not in active list für priority_pass und active_only
                } else {
                    // FULL mode ohne Filter: alle Produkte verarbeiten
                    $items[$product_id] = $mapped;
                    $processed_count++;
                }
            }
        }
        
        // Save updated index
        if ($mode === self::MODE_ACTIVE_ONLY) {
            if (MyFeeds_DB_Manager::is_db_mode()) {
                // DB mode: upsert active-only products directly
                // Resolve stable_id for this feed
                $qs_legacy_stable_id = (int) ($feed_config['stable_id'] ?? 0);
                if ($qs_legacy_stable_id === 0) {
                    $fk = $feed_config['feed_key'] ?? null;
                    if ($fk !== null) {
                        $live_feeds = get_option('myfeeds_feeds', array());
                        if (isset($live_feeds[$fk]['stable_id'])) {
                            $qs_legacy_stable_id = (int) $live_feeds[$fk]['stable_id'];
                            myfeeds_log("DIAG legacy quick_sync stable_id FALLBACK: resolved to {$qs_legacy_stable_id} for feed_key={$fk}", 'info');
                        }
                    }
                }
                if ($qs_legacy_stable_id === 0) {
                    myfeeds_log("SKIPPED quick_sync_product: stable_id=0 for feed '{$feed_config['feed_name']}' in process_feed_batch (active_only), refusing to write", 'error');
                } else {
                    foreach ($items as $pid => $pdata) {
                        MyFeeds_DB_Manager::quick_sync_product($pdata, $qs_legacy_stable_id);
                    }
                }
            } else {
                // ACTIVE_ONLY: Write directly to main index (we're updating existing items)
                $existing_data['items'] = $items;
                $existing_data['__search_fields'] = array(
                    'title' => 3,
                    'brand' => 2,
                    'shopname' => 1,
                    'merchant' => 1,
                    'attributes.color' => 2,
                );
                $index_path = $upload_dir['basedir'] . '/' . self::INDEX_FILE;
                file_put_contents($index_path, json_encode($existing_data), LOCK_EX);
            }
        } else {
            // FULL mode und priority_pass: Write to building index or DB
            if (!empty($items)) {
                if (MyFeeds_DB_Manager::is_db_mode()) {
                    // Resolve stable_id with safety fallback
                    $legacy_stable_id = (int) ($feed_config['stable_id'] ?? 0);
                    if ($legacy_stable_id === 0) {
                        $fk = $feed_config['feed_key'] ?? null;
                        if ($fk !== null) {
                            $live_feeds = get_option('myfeeds_feeds', array());
                            if (isset($live_feeds[$fk]['stable_id'])) {
                                $legacy_stable_id = (int) $live_feeds[$fk]['stable_id'];
                                myfeeds_log("DIAG legacy stable_id FALLBACK: queue had 0, resolved to {$legacy_stable_id} for feed_key={$fk}", 'info');
                            }
                        }
                    }
                    // Strip " (Priority)" suffix from feed name before DB write
                    $legacy_db_name = preg_replace('/\s*\(Priority\)\s*$/', '', ($feed_config['feed_name'] ?? ''));
                    myfeeds_log("DIAG legacy upsert: feed_key=" . ($feed_config['feed_key'] ?? 'N/A') . ", feed_name={$legacy_db_name}, stable_id={$legacy_stable_id}, products=" . count($items), 'info');
                    // GUARD: Refuse to write with stable_id=0
                    if ($legacy_stable_id === 0) {
                        myfeeds_log("SKIPPED upsert: stable_id=0 for feed '{$legacy_db_name}' in legacy process_feed_batch, refusing to write", 'error');
                    } else {
                        MyFeeds_DB_Manager::upsert_batch($items, $legacy_stable_id, $legacy_db_name);
                    }
                } else {
                    MyFeeds_Atomic_Index_Manager::add_items_to_building_index($items);
                }
            }
        }
        
        // Determine if batch/feed is complete
        // For priority_pass: also complete if all active IDs found (early exit)
        $is_complete = ($end >= $total_rows) || $found_all_active;
        
        // Set new_offset to total_rows if early exit (marks feed as complete)
        $new_offset = $found_all_active ? $total_rows : $end;
        
        // Logging basierend auf Modus
        if ($mode === self::MODE_ACTIVE_ONLY || $mode === 'priority_pass') {
            $early_exit_msg = $found_all_active ? ' [EARLY EXIT - all active IDs found!]' : '';
            myfeeds_log("Priority Pass: Matched $matched_active_count active products (offset $start to $end of $total_rows)$early_exit_msg", 'debug');
        } else {
            myfeeds_log("Batch: Processed $processed_count products (offset $start to $end of $total_rows)", 'debug');
        }
        
        return array(
            'processed_count' => $processed_count,
            'matched_active' => $matched_active_count,
            'new_offset' => $new_offset,
            'total_rows' => $total_rows,
            'is_complete' => $is_complete,
            'found_all_active' => $found_all_active,
        );
    }
    
    /**
     * Map product using mapping configuration
     */
    private function map_product(array $item, array $mapping) {
        $m = array();
        foreach ($mapping as $k => $p) {
            if ($k === 'attributes' && is_array($p)) {
                foreach ($p as $attr => $path) {
                    $value = $this->extract_field_value($item, $path);
                    $m['attributes'][$attr] = is_array($value) ? $value : array($value);
                }
            } else {
                $m[$k] = $this->extract_field_value($item, $p);
            }
        }
        return $m;
    }
    
    /**
     * Extract field value from item
     */
    private function extract_field_value($item, $path) {
        if (!is_string($path)) return '';
        return isset($item[$path]) ? $item[$path] : '';
    }
    
    /**
     * Process critical fields (FALLBACK version)
     */
    private function process_critical_fields_fallback(array $mapped, array $raw) {
        // ID
        if (empty($mapped['id'])) {
            $id_fields = array('aw_product_id', 'product_id', 'id', 'sku', 'offer_id');
            foreach ($id_fields as $f) {
                if (!empty($raw[$f])) {
                    $mapped['id'] = (string) $raw[$f];
                    break;
                }
            }
        }
        
        // Title
        if (empty($mapped['title'])) {
            $title_fields = array('product_name', 'title', 'name', 'n');
            foreach ($title_fields as $f) {
                if (!empty($raw[$f])) {
                    $mapped['title'] = trim($raw[$f]);
                    break;
                }
            }
        }
        
        // Merchant
        if (empty($mapped['merchant'])) {
            $merchant_fields = array('merchant_name', 'advertiser_name', 'shop_name', 'program_name');
            foreach ($merchant_fields as $f) {
                if (!empty($raw[$f]) && !preg_match('/^\d+$/', $raw[$f])) {
                    $mapped['merchant'] = trim($raw[$f]);
                    break;
                }
            }
        }
        
        // Price (floatval handles "2.00 EUR" correctly in PHP)
        if (empty($mapped['price']) || floatval($mapped['price']) <= 0) {
            $price_fields = array('search_price', 'store_price', 'price', 'sale_price');
            foreach ($price_fields as $f) {
                if (!empty($raw[$f]) && floatval($raw[$f]) > 0) {
                    $mapped['price'] = floatval($raw[$f]);
                    break;
                }
            }
        }
        
        // Old price
        $old_price = 0;
        $old_price_fields = array('rrp_price', 'product_price_old', 'rrp', 'original_price', 'oldprice', 'was_price');
        foreach ($old_price_fields as $f) {
            if (!empty($raw[$f]) && floatval($raw[$f]) > 0) {
                $old_price = floatval($raw[$f]);
                break;
            }
        }
        
        // Google Shopping price swap: sale_price < price
        if (!empty($raw['sale_price']) && !empty($raw['price'])) {
            $raw_sale = floatval($raw['sale_price']);
            $raw_price = floatval($raw['price']);
            if ($raw_sale > 0 && $raw_price > 0 && $raw_sale < $raw_price) {
                $mapped['price'] = $raw_sale;
                $old_price = $raw_price;
            }
        }
        
        if ($old_price > 0 && $old_price > floatval($mapped['price'])) {
            $mapped['old_price'] = $old_price;
        }
        
        // Direct discount from feed
        $discount = 0;
        $discount_fields = array('savings_percent', 'discount', 'discount_percentage');
        foreach ($discount_fields as $f) {
            if (!empty($raw[$f]) && floatval($raw[$f]) > 0 && floatval($raw[$f]) < 100) {
                $discount = round(floatval($raw[$f]));
                break;
            }
        }
        
        // Calculate discount if not provided
        if ($discount <= 0 && $old_price > floatval($mapped['price']) && floatval($mapped['price']) > 0) {
            $discount = round((($old_price - floatval($mapped['price'])) / $old_price) * 100);
        }
        
        if ($discount > 0) {
            $mapped['discount_percentage'] = $discount;
        }
        
        // Currency
        if (empty($mapped['currency'])) {
            $curr_fields = array('currency', 'currencyId');
            foreach ($curr_fields as $f) {
                if (!empty($raw[$f])) { $mapped['currency'] = $raw[$f]; break; }
            }
            if (empty($mapped['currency'])) {
                $mapped['currency'] = 'EUR';
            }
        }
        
        return $mapped;
    }
    
    /**
     * Check if there are pending feeds in queue
     */
    private function has_pending_feeds($queue) {
        foreach ($queue as $feed) {
            if ($feed['status'] === 'pending' || $feed['status'] === 'processing') {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Before atomic swap, check which active products (used in blocks)
     * are missing from the new building index. Append their old data
     * with status="unavailable" so they show a placeholder.
     * 
     * Uses JSONL streaming — never loads full building index into RAM.
     */
    private function mark_missing_active_products_in_building_index() {
        $upload_dir = wp_upload_dir();
        $active_path = $upload_dir['basedir'] . '/' . MyFeeds_Atomic_Index_Manager::INDEX_FILE_ACTIVE;
        
        if (!file_exists($active_path)) {
            return;
        }
        
        // Get products currently used in blocks/posts
        $active_ids = $this->get_active_product_ids(false);
        if (empty($active_ids)) {
            return;
        }
        
        // Stream building JSONL to collect product IDs (memory-safe: only IDs)
        $building_ids = MyFeeds_Atomic_Index_Manager::get_building_product_ids();
        
        // Find active products missing from building index
        $missing_ids = array();
        foreach ($active_ids as $aid) {
            $aid = (string) $aid;
            if (!isset($building_ids[$aid])) {
                $missing_ids[] = $aid;
            }
        }
        unset($building_ids); // Free memory
        
        if (empty($missing_ids)) {
            return;
        }
        
        // Load old active index to get existing product data for missing IDs
        $old_content = file_get_contents($active_path);
        $old_index = json_decode($old_content, true);
        $old_items = $old_index['items'] ?? array();
        unset($old_content, $old_index); // Free memory — only keep items
        
        // Build unavailable entries
        $unavailable_items = array();
        foreach ($missing_ids as $aid) {
            if (isset($old_items[$aid])) {
                $entry = $old_items[$aid];
                $entry['status'] = 'unavailable';
                $entry['unavailable_since'] = $entry['unavailable_since'] ?? current_time('mysql');
                $unavailable_items[$aid] = $entry;
            } else {
                $unavailable_items[$aid] = array(
                    'id' => $aid,
                    'status' => 'unavailable',
                    'unavailable_since' => current_time('mysql'),
                );
            }
        }
        unset($old_items);
        
        // Append to building JSONL (these entries override any earlier ones during dedup)
        if (!empty($unavailable_items)) {
            MyFeeds_Atomic_Index_Manager::append_unavailable_to_building($unavailable_items);
            MyFeeds_Logger::info("Complete: Appended " . count($unavailable_items) . " unavailable product entries to building JSONL");
        }
    }
    
    /**
     * Complete the import process - atomic swap or DB finalization
     */
    private function complete_import() {
        $status = get_option(self::OPTION_IMPORT_STATUS, array());
        $mode = $status['mode'] ?? self::MODE_FULL;
        $import_type = $status['import_type'] ?? 'full';
        
        MyFeeds_Logger::info('=== IMPORT COMPLETING ===');
        MyFeeds_Logger::info("Complete: mode={$mode}, type={$import_type}, processed={$status['processed_products']}, feeds={$status['processed_feeds']}");
        
        // =====================================================================
        // SINGLE-FEED IMPORT: Simplified completion (no orphan detection)
        // =====================================================================
        if ($import_type === 'single_feed') {
            MyFeeds_Logger::info('Complete: Single-feed import — skipping full import finalization');
            
            // Ensure feed status is set to 'active' (safety net)
            $single_feed_key = $status['current_feed'] ?? null;
            $single_feed_name = '';
            if ($single_feed_key !== null) {
                $feeds = get_option('myfeeds_feeds', array());
                $single_feed_key = intval($single_feed_key);
                if (isset($feeds[$single_feed_key])) {
                    $old_status = $feeds[$single_feed_key]['status'] ?? 'unknown';
                    $feeds[$single_feed_key]['status'] = 'active';
                    $feeds[$single_feed_key]['last_sync'] = current_time('mysql');
                    $single_feed_name = $feeds[$single_feed_key]['name'] ?? '';
                    if (MyFeeds_DB_Manager::is_db_mode()) {
                        $single_stable_id = (int) ($feeds[$single_feed_key]['stable_id'] ?? 0);
                        $feeds[$single_feed_key]['product_count'] = MyFeeds_DB_Manager::get_feed_product_count($single_stable_id);
                        // Fix 1: Calculate mapping quality (same as full import via update_feed_product_counts)
                        $quality = MyFeeds_DB_Manager::calculate_mapping_quality($single_feed_name);
                        $feeds[$single_feed_key]['mapping_confidence'] = $quality['quality'];
                    }
                    update_option('myfeeds_feeds', $feeds);
                    MyFeeds_Logger::info("Complete: Single-feed '{$single_feed_name}' status set to 'active' (was '{$old_status}'), products={$feeds[$single_feed_key]['product_count']}, quality={$feeds[$single_feed_key]['mapping_confidence']}%");
                }
            }
            
            // Fix 8: Record last sync with import type
            $is_reimport = !empty($status['is_reimport']);
            $sync_type = $is_reimport ? 'reimport' : 'new_feed';
            update_option('myfeeds_last_auto_sync', array(
                'type' => $sync_type,
                'time' => current_time('mysql'),
                'timestamp' => time(),
                'feed_name' => $single_feed_name,
            ));
            
            // Clear resolver cache
            if (class_exists('MyFeeds_Product_Resolver')) {
                MyFeeds_Product_Resolver::clear_cache();
            }
            
            // PERF: Backfill search_text for single-feed import
            if (MyFeeds_DB_Manager::is_db_mode()) {
                global $wpdb;
                $table = MyFeeds_DB_Manager::table_name();
                $backfilled = $wpdb->query(
                    "UPDATE {$table} SET search_text = CONCAT_WS(' ',
                        COALESCE(product_name, ''),
                        COALESCE(brand, ''),
                        COALESCE(feed_name, ''),
                        COALESCE(category, ''),
                        COALESCE(colour, '')
                    ) WHERE search_text IS NULL OR search_text = ''"
                );
                if ($backfilled > 0) {
                    MyFeeds_Logger::info("PERF: Bulk-populated search_text for {$backfilled} products after single-feed import");
                }
            }

            // Mark complete
            $status['status'] = 'completed';
            $status['completed_at'] = current_time('mysql');
            update_option(self::OPTION_IMPORT_STATUS, $status, false);
            
            // NOTE: Do NOT run cleanup_orphaned_products() after single-feed imports!
            // It compares ALL products against configured feeds and can incorrectly
            // delete products from other feeds. Orphan cleanup only runs after
            // Full Imports where ALL feeds have been processed.
            
            // Cleanup
            delete_option(self::OPTION_IMPORT_QUEUE);
            delete_option(self::OPTION_BATCH_STATE);
            
            $run_id = md5($status['started_at'] ?? '');
            myfeeds_cleanup_all_feed_caches($run_id);
            
            // Release execution lock
            delete_transient('myfeeds_import_lock_' . self::MODE_FULL);
            
            MyFeeds_Logger::info("=== SINGLE-FEED IMPORT COMPLETE ===");
            return;
        }
        
        $upload_dir = wp_upload_dir();
        
        // =====================================================================
        // MODE_ACTIVE_ONLY: Quick Sync — merges into active index (no full rebuild)
        // =====================================================================
        if ($mode === self::MODE_ACTIVE_ONLY) {
            MyFeeds_Logger::info('Complete: Quick Sync mode - no atomic swap needed (products already written to active index)');
        } else {
            // =====================================================================
            // MODE_FULL: Finalize import
            // =====================================================================
            if (MyFeeds_DB_Manager::is_db_mode()) {
                // DB mode: per-feed orphan detection (auto-discovers imported feeds from DB)
                
                // Determine all_feeds_ok: true when ALL configured feeds were successfully imported.
                // Now that untested feeds are no longer skipped, they participate in the import.
                // Only actual download/parse FAILURES set all_feeds_ok to false.
                $all_feeds = get_option('myfeeds_feeds', array());
                $total_configured_feeds = count($all_feeds);
                $queue = get_option(self::OPTION_IMPORT_QUEUE, array());
                
                // Count successfully imported feeds from queue (skip priority pass duplicates)
                $imported_count = 0;
                $feeds_with_errors = 0;
                foreach ($queue as $q_item) {
                    if (($q_item['phase'] ?? '') === 'priority_active') {
                        continue; // Priority pass entries are duplicates, skip
                    }
                    if (($q_item['status'] ?? '') === 'completed') {
                        $imported_count++;
                    } elseif (($q_item['status'] ?? '') === 'failed') {
                        $feeds_with_errors++;
                    }
                }
                
                // all_feeds_ok = true when:
                // - No import errors
                // - No feeds failed
                // - imported covers all configured feeds
                $all_feeds_ok = empty($status['errors']) 
                    && $feeds_with_errors === 0 
                    && $imported_count >= $total_configured_feeds;
                
                MyFeeds_Logger::info("Complete: all_feeds_ok=" . ($all_feeds_ok ? 'true' : 'false') . ", configured={$total_configured_feeds}, imported={$imported_count}, failed={$feeds_with_errors}");
                
                $active_block_ids = $this->get_active_product_ids(false);
                MyFeeds_DB_Manager::complete_full_import($active_block_ids, $all_feeds_ok);
                
                // Cleanup orphaned products after Full Import (always, regardless of all_feeds_ok)
                $cleanup_count = MyFeeds_DB_Manager::cleanup_orphaned_products();
                if ($cleanup_count > 0) {
                    MyFeeds_Logger::info("Complete: Cleaned up {$cleanup_count} orphaned products with invalid feed_ids");
                }
                
                $db_stats = MyFeeds_DB_Manager::get_stats();
                MyFeeds_Logger::info("Complete: DB import finalized ({$db_stats['active']} active, {$db_stats['unavailable']} unavailable)");
            } else {
                // JSON mode: atomic swap
                MyFeeds_Logger::info('Complete: Full Update mode - performing atomic swap');
                
                $this->mark_missing_active_products_in_building_index();
                
                $swap_result = MyFeeds_Atomic_Index_Manager::complete_atomic_rebuild();
                
                if ($swap_result) {
                    $build_status = MyFeeds_Atomic_Index_Manager::get_build_status();
                    $new_count = $build_status['items_count'] ?? 0;
                    MyFeeds_Logger::info("Complete: Atomic swap successful ({$new_count} products in new active index)");
                } else {
                    MyFeeds_Logger::error('Complete: Atomic swap FAILED - active index unchanged');
                    $status['errors'][] = array(
                        'feed' => 'system',
                        'error' => 'Atomic swap failed — building index was empty or missing',
                    );
                }
            }
        }
        
        // Clear resolver cache
        if (class_exists('MyFeeds_Product_Resolver')) {
            MyFeeds_Product_Resolver::clear_cache();
        }
        
        // REMOVED: update_feed_statistics() overwrites correct DB counts
        
        // Mark complete
        $status['status'] = 'completed';
        $status['completed_at'] = current_time('mysql');
        
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        // =====================================================================
        // CLEANUP: Clear import-related data (but NOT the index!)
        // =====================================================================
        delete_option(self::OPTION_IMPORT_QUEUE);
        delete_option(self::OPTION_ACTIVE_IDS);
        delete_option(self::OPTION_BATCH_STATE);
        
        wp_clear_scheduled_hook(self::CRON_HOOK);
        
        // Clear feed content cache via helper
        $run_id = md5($status['started_at'] ?? '');
        myfeeds_cleanup_all_feed_caches($run_id);
        
        // Clear transient cache for products
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_myfeeds_product_%' 
             OR option_name LIKE '_transient_timeout_myfeeds_product_%'"
        );
        
        $mode_label = ($mode === self::MODE_ACTIVE_ONLY) ? 'Quick Sync' : 'Full Update';
        MyFeeds_Logger::info("=== IMPORT COMPLETE: {$mode_label} - {$status['processed_products']} products ===");
        
        // Fix 8: Record last sync with correct import type (for manual triggers)
        $sync_type = ($mode === self::MODE_ACTIVE_ONLY) ? 'daily_quick_sync' : 'weekly_full_import';
        update_option('myfeeds_last_auto_sync', array(
            'type' => $sync_type,
            'time' => current_time('mysql'),
            'timestamp' => time(),
            'feed_name' => '',
        ));
        
        // Bug 4: Update feed product counts from DB after import
        if (MyFeeds_DB_Manager::is_db_mode()) {
            MyFeeds_DB_Manager::update_feed_product_counts();
        }
        
        // Release execution lock
        delete_transient('myfeeds_import_lock_' . $mode);
        
        do_action('myfeeds_feed_update_complete', $status);
    }
    
    /**
     * Update feed statistics after import
     */
    private function update_feed_statistics() {
        $feeds = get_option('myfeeds_feeds', array());
        $queue = get_option(self::OPTION_IMPORT_QUEUE, array());
        
        foreach ($queue as $q_item) {
            $key = $q_item['feed_key'];
            if (isset($feeds[$key])) {
                $feeds[$key]['product_count'] = $q_item['total_rows'];
                $feeds[$key]['last_updated'] = current_time('mysql');
                $feeds[$key]['last_sync'] = current_time('mysql');
            }
        }
        
        update_option('myfeeds_feeds', $feeds);
    }
    
    /**
     * Clear import status
     */
    public function clear_import_status() {
        delete_option(self::OPTION_IMPORT_STATUS);
        delete_option(self::OPTION_IMPORT_QUEUE);
        delete_option(self::OPTION_BATCH_STATE);  // NEW: Clear Action Scheduler state
        wp_clear_scheduled_hook(self::CRON_HOOK);
        
        // Clear Action Scheduler scheduled actions
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::AS_HOOK_PROCESS_BATCH, array(), self::AS_GROUP);
            as_unschedule_all_actions(self::AS_HOOK_COMPLETE, array(), self::AS_GROUP);
        }
        
        // Abort any in-progress atomic rebuild (removes building index) or DB import
        if (MyFeeds_DB_Manager::is_db_mode()) {
            MyFeeds_DB_Manager::abort_full_import();
        } else {
            MyFeeds_Atomic_Index_Manager::abort_rebuild();
        }
        
        // Release execution locks
        delete_transient('myfeeds_import_lock_' . self::MODE_FULL);
        delete_transient('myfeeds_import_lock_' . self::MODE_ACTIVE_ONLY);
    }
    
    /**
     * Cancel ongoing import
     */
    public function cancel_import() {
        MyFeeds_Logger::info('Cancel Import: Cancelling ongoing import');
        
        $status = get_option(self::OPTION_IMPORT_STATUS, array());
        
        // Bug 3 fix: Restore feed config status from 'importing' to 'active'
        $import_type = $status['import_type'] ?? 'full';
        $feeds = get_option('myfeeds_feeds', array());
        $feeds_updated = false;
        foreach ($feeds as $key => &$f) {
            if (($f['status'] ?? '') === 'importing') {
                $f['status'] = 'active';
                $feeds_updated = true;
                myfeeds_log("Cancel Import: Restored feed '{$f['name']}' (key={$key}) status from 'importing' to 'active'", 'info');
            }
        }
        unset($f);
        if ($feeds_updated) {
            update_option('myfeeds_feeds', $feeds);
        }
        
        if (!empty($status)) {
            $status['status'] = 'cancelled';
            $status['cancelled_at'] = current_time('mysql');
            update_option(self::OPTION_IMPORT_STATUS, $status, false);
        }
        
        delete_option(self::OPTION_IMPORT_QUEUE);
        delete_option(self::OPTION_BATCH_STATE);  // NEW: Clear Action Scheduler state
        wp_clear_scheduled_hook(self::CRON_HOOK);
        
        // Clear Action Scheduler scheduled actions
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::AS_HOOK_PROCESS_BATCH, array(), self::AS_GROUP);
            as_unschedule_all_actions(self::AS_HOOK_COMPLETE, array(), self::AS_GROUP);
            MyFeeds_Logger::info('Cancel Import: Cleared Action Scheduler jobs');
        }
        
        // Abort any in-progress atomic rebuild or DB import
        if (MyFeeds_DB_Manager::is_db_mode()) {
            MyFeeds_DB_Manager::abort_full_import();
        } else {
            MyFeeds_Atomic_Index_Manager::abort_rebuild();
        }
        
        // Clean up feed content caches
        $run_id = md5($status['started_at'] ?? '');
        myfeeds_cleanup_all_feed_caches($run_id);
        
        MyFeeds_Logger::info('Cancel Import: Complete');
        return true;
    }
    
    /**
     * Get current import status with improved progress calculation
     * QUICK SYNC: Prozent basiert auf gefundenen aktiven IDs (5 von 5 = 100%)
     * FULL UPDATE: Prozent basiert auf abgeschlossenen Feeds
     */
    public function get_import_status() {
        $status = get_option(self::OPTION_IMPORT_STATUS, array());
        $queue = get_option(self::OPTION_IMPORT_QUEUE, array());
        
        if (empty($status)) {
            return array(
                'status' => 'idle',
                'mode' => self::MODE_FULL,
                'message' => __('No import in progress', 'myfeeds-affiliate-feed-manager'),
                'progress_percent' => 0,
            );
        }
        
        $mode = $status['mode'] ?? self::MODE_FULL;
        
        // =====================================================================
        // WATCHDOG: Detect and recover stalled imports
        // FIX 3: Reduced timeout to 90 seconds (was 180s).
        // Uses heartbeat_timestamp for precise stall detection.
        // On resume: checks FeedCache, logs with [WATCHDOG] prefix.
        // =====================================================================
        if ($status['status'] === 'running' && $mode === self::MODE_FULL) {
            $heartbeat = isset($status['heartbeat_timestamp']) ? intval($status['heartbeat_timestamp']) : 0;
            $last_activity = isset($status['last_activity']) ? strtotime($status['last_activity']) : 0;
            
            // Use heartbeat_timestamp (set after each batch) if available, else fall back to last_activity
            $last_sign_of_life = max($heartbeat, $last_activity);
            $stale_seconds = time() - $last_sign_of_life;
            
            if ($last_sign_of_life > 0 && $stale_seconds > 90) { // 90 seconds stale
                $batch_state = get_option(self::OPTION_BATCH_STATE, array());
                $resume_feed_index = $batch_state['current_feed_index'] ?? 0;
                $resume_offset = $batch_state['current_offset'] ?? 0;
                
                // Only try to auto-resume if Action Scheduler is available
                if (function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action')) {
                    // Check if there's already a pending batch action
                    $has_pending = as_has_scheduled_action(
                        self::AS_HOOK_PROCESS_BATCH,
                        array(),
                        self::AS_GROUP
                    );
                    
                    if (!$has_pending) {
                        MyFeeds_Logger::info("[WATCHDOG] Import stalled at feed_index={$resume_feed_index}, offset={$resume_offset} — resuming (stale for {$stale_seconds}s)");
                        
                        // Check if FeedCache file still exists for the current feed
                        $run_id = md5($status['started_at'] ?? '');
                        if (function_exists('myfeeds_get_feed_content')) {
                            // Cache MISS is acceptable — the next batch will re-download
                            MyFeeds_Logger::info("[WATCHDOG] FeedCache check: run_id={$run_id}, feed_index={$resume_feed_index} — will re-download on MISS");
                        }
                        
                        // Update last_activity to prevent repeated watchdog triggers
                        $status['last_activity'] = current_time('mysql');
                        $status['heartbeat_timestamp'] = time();
                        $status['watchdog_triggered'] = ($status['watchdog_triggered'] ?? 0) + 1;
                        update_option(self::OPTION_IMPORT_STATUS, $status, false);
                        
                        // Schedule recovery batch
                        $this->schedule_next_batch(array(
                            'feed_index' => $resume_feed_index,
                            'offset' => $resume_offset,
                        ));
                    }
                }
            }
        }
        
        // =====================================================================
        // COMPLETED: Immer 100% zurückgeben
        // =====================================================================
        if ($status['status'] === 'completed') {
            $elapsed_ms = $status['elapsed_ms'] ?? 0;
            $elapsed_text = $elapsed_ms > 0 ? " ({$elapsed_ms}ms)" : '';
            
            // Get final counts from DB
            $final_total = 0;
            $final_feed_counts = array();
            $feed_qualities = array();
            if (MyFeeds_DB_Manager::is_db_mode()) {
                $final_total = MyFeeds_DB_Manager::get_active_product_count();
                $final_feed_counts = MyFeeds_DB_Manager::get_feed_counts();
                
                // Bug 2 fix: Include per-feed mapping quality for post-import UI update
                $all_feeds = get_option('myfeeds_feeds', array());
                foreach ($all_feeds as $f) {
                    $fname = $f['name'] ?? '';
                    if (!empty($fname) && ($final_feed_counts[$fname] ?? 0) > 0) {
                        $q = MyFeeds_DB_Manager::calculate_mapping_quality($fname);
                        $feed_qualities[$fname] = intval($q['quality'] ?? 0);
                    }
                }
            } else {
                $final_total = $status['total_products'] ?? 0;
            }
            
            return array(
                'status' => 'completed',
                'mode' => $mode,
                'phase' => 'done',
                'import_type' => $status['import_type'] ?? 'full',
                'single_feed_name' => $status['single_feed_name'] ?? '',
                'started_at' => $status['started_at'] ?? '',
                'completed_at' => $status['completed_at'] ?? '',
                'total_feeds' => $status['total_feeds'] ?? 0,
                'processed_feeds' => $status['total_feeds'] ?? 0,
                'total_queue_items' => 0,
                'current_feed_name' => '',
                'current_feed_progress' => 100,
                'total_products' => $final_total,
                'header_total_products' => $final_total,
                'active_ids_count' => $status['active_ids_count'] ?? 0,
                'processed_products' => $status['processed_products'] ?? 0,
                'found_products' => $status['found_products'] ?? $status['processed_products'] ?? 0,
                'progress_percent' => 100,
                'elapsed_ms' => $elapsed_ms,
                'feed_product_counts' => $final_feed_counts,
                'feed_qualities' => $feed_qualities,
                'errors' => $status['errors'] ?? array(),
            );
        }
        
        // =====================================================================
        // QUICK SYNC MODE: Prozent = gefundene Produkte / gesuchte Produkte
        // Beispiel: 4 von 5 Produkten gefunden = 80%
        // =====================================================================
        if ($mode === self::MODE_ACTIVE_ONLY) {
            $active_count = $status['active_ids_count'] ?? 1;
            $found_count = $status['found_products'] ?? $status['processed_products'] ?? 0;
            
            // Prozent basiert auf gefundenen aktiven IDs
            $progress = ($active_count > 0) ? round(($found_count / $active_count) * 100) : 0;
            $progress = min(99, $progress); // Max 99% während running
            
            return array(
                'status' => $status['status'],
                'mode' => $mode,
                'phase' => $status['phase'] ?? 'quick_sync',
                'started_at' => $status['started_at'] ?? '',
                'completed_at' => '',
                'total_feeds' => $status['total_feeds'] ?? 0,
                'processed_feeds' => $status['processed_feeds'] ?? 0,
                'total_queue_items' => 0,
                'current_feed_name' => $status['current_feed_name'] ?? '',
                'current_feed_progress' => $progress,
                'total_products' => $active_count,
                'active_ids_count' => $active_count,
                'processed_products' => $found_count,
                'found_products' => $found_count,
                'progress_percent' => $progress,
                'errors' => $status['errors'] ?? array(),
            );
        }
        
        // =====================================================================
        // FULL UPDATE MODE: Progress linear über Feeds
        // Priority-Phase = 0-5%, Full-Pass = 5-99%
        //
        // WICHTIG: Pending Feeds haben total_rows=0 (erst nach erstem Batch bekannt).
        // Deshalb: Fortschritt basiert auf FEED-ANZAHL, nicht auf Zeilen-Summe.
        // Row-Level-Granularität nur für den aktuell verarbeiteten Feed.
        // Formel: base = completed_feeds / total_feeds
        //         sub  = current_feed_offset / current_feed_total (anteilig)
        // =====================================================================
        $total_progress = 0;
        $full_pass_items = 0;
        $full_pass_completed = 0;
        $priority_items = 0;
        $priority_completed = 0;
        $current_feed_progress = 0;
        $current_processing_feed = null;
        $current_feed_sub_progress = 0; // 0.0 - 1.0 fraction of current feed done
        $priority_rows_processed = 0;
        $priority_rows_total = 0;
        
        if (!empty($queue)) {
            foreach ($queue as $feed) {
                $is_priority = (($feed['phase'] ?? '') === 'priority_active');
                
                if ($is_priority) {
                    $priority_items++;
                    $feed_total = $feed['total_rows'] ?? 0;
                    $feed_offset = $feed['offset'] ?? 0;
                    
                    if ($feed['status'] === 'completed' || $feed['status'] === 'failed') {
                        $priority_completed++;
                        $priority_rows_processed += $feed_total;
                    } elseif ($feed['status'] === 'processing') {
                        $priority_rows_processed += $feed_offset;
                    }
                    $priority_rows_total += $feed_total;
                    continue;
                }
                
                // Full-pass entries only
                $full_pass_items++;
                
                if ($feed['status'] === 'completed' || $feed['status'] === 'failed') {
                    $full_pass_completed++;
                } elseif ($feed['status'] === 'processing') {
                    $current_processing_feed = $feed;
                    $feed_total = $feed['total_rows'] ?? 0;
                    $feed_offset = $feed['offset'] ?? 0;
                    if ($feed_total > 0) {
                        $current_feed_progress = round(($feed_offset / $feed_total) * 100);
                        $current_feed_sub_progress = $feed_offset / $feed_total;
                    }
                }
                // Pending feeds: keine Row-Daten nötig (Feed-basierte Berechnung)
            }
            
            $priority_all_done = ($priority_items === 0 || $priority_completed >= $priority_items);
            
            if (!$priority_all_done) {
                // Priority phase: 0-5% based on row-level progress
                if ($priority_rows_total > 0) {
                    $priority_pct = ($priority_rows_processed / $priority_rows_total) * 100;
                    $total_progress = max(1, round($priority_pct * 0.05)); // Scale to 0-5%
                } else {
                    $total_progress = 1;
                }
            } elseif ($full_pass_items > 0) {
                // Full pass phase: 5-99% based on FEED COUNT (not row count)
                // Example: 2 of 5 feeds done + current feed at 50% = (2.5/5) = 50%
                $effective_completed = $full_pass_completed + $current_feed_sub_progress;
                $full_pct = ($effective_completed / $full_pass_items) * 100;
                $total_progress = 5 + round($full_pct * 0.94); // Scale to 5-99%
            }
            
            $total_progress = min(99, max(0, $total_progress));
        }
        
        // Bestimme aktuelle Phase für UI
        $current_phase = $status['phase'] ?? 'import';
        if (!empty($queue)) {
            foreach ($queue as $feed) {
                if ($feed['status'] === 'processing' || $feed['status'] === 'pending') {
                    $current_phase = $feed['phase'] ?? $status['phase'] ?? 'import';
                    break;
                }
            }
        }
        
        // Use unique_products from building index as primary product count.
        // This is the deduplicated count across ALL feeds processed so far.
        $unique_products = $status['unique_products'] ?? 0;
        $processed_rows = $status['processed_rows'] ?? 0;
        
        // =====================================================================
        // Fix 2+3: Smart feed_product_counts during running import
        // - Full Import: Only return counts for feeds that COMPLETED in this run.
        //   Pending/processing feeds keep their old displayed value (JS doesn't update them).
        // - Single-Feed Import: Return empty counts (old values stay until completion).
        // - Total products: sum of completed feeds' new DB count + pending feeds' old config count.
        // =====================================================================
        $db_product_count = 0;
        $feed_product_counts = array();
        if (MyFeeds_DB_Manager::is_db_mode()) {
            $import_type = $status['import_type'] ?? 'full';
            
            if ($import_type === 'single_feed') {
                // Fix: Single-feed import — show only THIS feed's row count, not all feeds.
                // Use queue entry's total_rows (set once processing starts), fallback to config product_count.
                if (!empty($queue)) {
                    $q_entry = $queue[0] ?? array();
                    $q_total_rows = intval($q_entry['total_rows'] ?? 0);
                    if ($q_total_rows > 0) {
                        $db_product_count = $q_total_rows;
                    } else {
                        // Before processing starts, use config count for just this feed
                        $current_feed_key = $status['current_feed'] ?? null;
                        if ($current_feed_key !== null) {
                            $feeds_config = get_option('myfeeds_feeds', array());
                            $db_product_count = intval($feeds_config[$current_feed_key]['product_count'] ?? 0);
                        }
                    }
                }
                // feed_product_counts stays empty → JS keeps all old values
            } else {
                // Fix 2: Full import — only return counts for completed feeds.
                $completed_feed_names = array();
                if (!empty($queue)) {
                    foreach ($queue as $q_item) {
                        if (($q_item['phase'] ?? '') === 'priority_active') continue;
                        if (($q_item['status'] ?? '') === 'completed') {
                            $completed_feed_names[] = $q_item['feed_name'] ?? '';
                        }
                    }
                }
                
                // Get DB counts for all feeds, but only expose completed ones to JS
                $all_db_counts = MyFeeds_DB_Manager::get_feed_counts_cached();
                foreach ($all_db_counts as $fname => $cnt) {
                    if (in_array($fname, $completed_feed_names, true)) {
                        $feed_product_counts[$fname] = $cnt;
                    }
                }
                
                // Total = completed feeds' new DB count + pending feeds' old config count
                $feeds_config = get_option('myfeeds_feeds', array());
                foreach ($feeds_config as $fc) {
                    $fname = $fc['name'] ?? '';
                    if (in_array($fname, $completed_feed_names, true)) {
                        $db_product_count += $all_db_counts[$fname] ?? 0;
                    } else {
                        $db_product_count += intval($fc['product_count'] ?? 0);
                    }
                }
            }
        } else {
            $db_product_count = $unique_products > 0 ? $unique_products : ($status['processed_products'] ?? 0);
        }
        
        // displayed_processed: Smart total (completed new + pending old)
        $displayed_processed = $db_product_count;
        
        // =================================================================
        // POINT D: STATUS_READ - Log what UI will receive
        // =================================================================
        static $status_read_logged = 0;
        // Throttle: only log every 10th call to avoid log spam from polling
        $status_read_logged++;
        if ($status_read_logged % 10 === 1) {
            myfeeds_as_log('status_read', array(
                'displayed_processed' => $displayed_processed,
                'unique_products' => $unique_products,
                'processed_rows' => $processed_rows,
                'progress_percent' => $total_progress,
                'current_phase' => $current_phase,
                'full_pass_completed' => $full_pass_completed,
                'full_pass_items' => $full_pass_items,
                'priority_completed' => $priority_completed,
                'priority_items' => $priority_items,
                'current_feed_name' => $status['current_feed_name'] ?? '',
                'current_feed_progress' => $current_feed_progress,
            ));
        }
        // =================================================================
        
        // Bug 1 fix: Real DB product count for the header kachel (never rows, always products).
        // Matches the per-feed product_count (both are status='active'); unavailable rows are
        // soft-deletes retained for placeholder rendering and should not inflate the header.
        $header_total = 0;
        if (MyFeeds_DB_Manager::is_db_mode()) {
            $header_total = MyFeeds_DB_Manager::get_active_product_count();
        }
        
        return array(
            'status' => $status['status'],
            'mode' => $mode,
            'phase' => $current_phase,
            'import_type' => $status['import_type'] ?? 'full',
            'single_feed_name' => $status['single_feed_name'] ?? '',
            'started_at' => $status['started_at'] ?? '',
            'completed_at' => $status['completed_at'] ?? '',
            'total_feeds' => $status['total_feeds'] ?? 0,
            'processed_feeds' => $full_pass_completed,
            'total_queue_items' => $full_pass_items,
            'current_feed_name' => $status['current_feed_name'] ?? '',
            'current_feed_progress' => $current_feed_progress,
            'total_products' => $displayed_processed,
            'header_total_products' => $header_total,
            'active_ids_count' => $status['active_ids_count'] ?? 0,
            'processed_products' => $displayed_processed,
            'processed_rows' => $processed_rows,
            'unique_products' => $unique_products,
            'progress_percent' => $total_progress,
            'feed_product_counts' => $feed_product_counts,
            'errors' => $status['errors'] ?? array(),
        );
    }
    
    /**
     * Trigger daily update (called by WP-Cron)
     * Daily = Quick Sync (nur aktive Produkte aktualisieren)
     */
    public function trigger_daily_update() {
        // Auto-Sync (daily/weekly) is a Pro-tier feature. The Free plugin
        // keeps these entry points so scheduled hooks from older installs
        // don't fatal, but they never execute an import.
        myfeeds_log('Auto-Sync skipped: Free plan does not include auto-sync', 'info');
    }

    /**
     * Trigger weekly full import (called by WP-Cron)
     */
    public function trigger_weekly_update() {
        myfeeds_log('Auto-Sync skipped: Free plan does not include auto-sync', 'info');
    }
    
    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================
    
    /**
     * AJAX: Start unified rebuild (re-mapping + import) - FULL mode
     * NON-BLOCKING: Returns 202 Accepted immediately, job runs in background via Spawn Worker
     */
    public function ajax_unified_rebuild() {
        MyFeeds_Logger::info('AJAX Request received: myfeeds_unified_rebuild');
        
        check_ajax_referer('myfeeds_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            MyFeeds_Logger::error('Unauthorized access attempt to unified_rebuild');
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $feeds = get_option('myfeeds_feeds', array());
        
        if (empty($feeds)) {
            MyFeeds_Logger::error('No feeds configured');
            wp_send_json_error(array('message' => 'No feeds configured. Please add a feed first.'));
            return;
        }
        
        MyFeeds_Logger::info('Full Update requested - ' . count($feeds) . ' feeds configured');
        
        // Fix 5: If a single-feed import is running, cancel it — Full Import takes over
        $existing_status = get_option(self::OPTION_IMPORT_STATUS, array());
        if (!empty($existing_status['status']) && $existing_status['status'] === 'running'
            && ($existing_status['import_type'] ?? '') === 'single_feed') {
            MyFeeds_Logger::info('Full Update: Cancelling running single-feed import — Full Import takes over');
            $this->clear_import_status();
        }
        
        // Set initial status BEFORE triggering job for instant UI feedback
        $status = array(
            'status' => 'running',
            'mode' => self::MODE_FULL,
            'phase' => 'initializing',
            'started_at' => current_time('mysql'),
            'total_feeds' => count($feeds),
            'processed_feeds' => 0,
            'current_feed' => null,
            'current_feed_name' => 'Initializing...',
            'total_products' => 0,
            'processed_products' => 0,
            'errors' => array(),
        );
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        // Spawn background worker (non-blocking)
        $spawn_result = $this->spawn_background_worker('myfeeds_bg_full_update');
        MyFeeds_Logger::info('Background worker spawn result: ' . ($spawn_result ? 'success' : 'failed'));
        
        // NON-BLOCKING: Return 202 Accepted IMMEDIATELY
        MyFeeds_Logger::info('Sending 202 Accepted response to client');
        wp_send_json_success(array(
            'message' => 'Full update started (Re-Mapping + Import)',
            'status' => $this->get_import_status(),
            'http_status' => 202, // Accepted - job queued
        ));
    }
    
    /**
     * AJAX: Quick Sync for active products only
     * STRICT: Only products from verified MyFeeds blocks/shortcodes!
     * NON-BLOCKING: Returns 202 Accepted immediately, job runs in background via Spawn Worker
     */
    public function ajax_quick_sync_active() {
        MyFeeds_Logger::info('AJAX Request received: myfeeds_quick_sync_active');
        
        check_ajax_referer('myfeeds_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            MyFeeds_Logger::error('Unauthorized access attempt to quick_sync_active');
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        // STRICT: Only IDs from verified MyFeeds blocks/shortcodes
        MyFeeds_Logger::info('Scanning for active product IDs...');
        $active_ids = $this->get_active_product_ids(false);
        
        // ABORT if no active products found
        if (empty($active_ids)) {
            MyFeeds_Logger::info('No active products found - aborting Quick Sync');
            wp_send_json_error(array(
                'message' => 'No active products found on your website. Please add products to your posts/pages first using MyFeeds blocks or shortcodes.',
                'active_count' => 0,
            ));
            return;
        }
        
        MyFeeds_Logger::info("Quick Sync: Found " . count($active_ids) . " active products - spawning worker");
        
        // Set initial status BEFORE triggering job for instant UI feedback
        $status = array(
            'status' => 'running',
            'mode' => self::MODE_ACTIVE_ONLY,
            'phase' => 'quick_sync',
            'started_at' => current_time('mysql'),
            'total_feeds' => count(get_option('myfeeds_feeds', array())),
            'processed_feeds' => 0,
            'current_feed' => null,
            'current_feed_name' => '',
            'total_products' => count($active_ids),
            'active_ids_count' => count($active_ids),
            'processed_products' => 0,
            'found_products' => 0,
            'errors' => array(),
        );
        update_option(self::OPTION_IMPORT_STATUS, $status, false);
        
        // Store active IDs for background worker
        update_option(self::OPTION_ACTIVE_IDS, $active_ids, false);
        
        // Spawn background worker (non-blocking)
        $spawn_result = $this->spawn_background_worker('myfeeds_bg_quick_sync', array(
            'active_ids' => json_encode($active_ids),
        ));
        MyFeeds_Logger::info('Quick Sync worker spawn result: ' . ($spawn_result ? 'success' : 'failed'));
        
        // NON-BLOCKING: Return 202 Accepted IMMEDIATELY
        MyFeeds_Logger::info('Sending 202 Accepted response to client');
        wp_send_json_success(array(
            'message' => sprintf('Quick Sync started for %d active products', count($active_ids)),
            'active_count' => count($active_ids),
            'status' => $this->get_import_status(),
            'http_status' => 202, // Accepted - job queued
        ));
    }
    
    public function ajax_start_batch_import() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'myfeeds-affiliate-feed-manager')));
        }
        
        $feed_key = isset($_POST['feed_key']) ? sanitize_text_field($_POST['feed_key']) : null;
        
        if ($feed_key !== null && $feed_key !== '') {
            $result = $this->start_single_feed_import($feed_key);
        } else {
            // Use central hook for full import
            do_action(self::CENTRAL_HOOK, 'ajax', array('mode' => self::MODE_FULL));
            $result = true;
        }
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => __('Import started', 'myfeeds-affiliate-feed-manager'),
            'status' => $this->get_import_status(),
        ));
    }
    
    public function ajax_get_import_status() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'myfeeds-affiliate-feed-manager')));
        }
        
        try {
            wp_send_json_success($this->get_import_status());
        } catch (\Throwable $e) {
            myfeeds_log('ajax_get_import_status error: ' . $e->getMessage(), 'error');
            wp_send_json_success(array(
                'status' => 'idle',
                'error' => 'Status check failed: ' . $e->getMessage(),
            ));
        }
    }
    
    public function ajax_cancel_import() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'myfeeds-affiliate-feed-manager')));
        }
        
        try {
            // Read import context before cancel clears it
            $status = get_option(self::OPTION_IMPORT_STATUS, array());
            $import_type = $status['import_type'] ?? 'full';
            $feed_key = $status['current_feed'] ?? null;
            
            $this->cancel_import();
            
            // Read feed statuses AFTER cancel (cancel_import restores 'importing' → 'active')
            $feeds_after = get_option('myfeeds_feeds', array());
            $feed_statuses = array();
            foreach ($feeds_after as $fk => $fv) {
                $feed_statuses[$fk] = $fv['status'] ?? 'active';
            }
            
            wp_send_json_success(array(
                'message' => __('Import cancelled', 'myfeeds-affiliate-feed-manager'),
                'import_type' => $import_type,
                'feed_key' => $feed_key,
                'feed_statuses' => $feed_statuses,
            ));
        } catch (\Throwable $e) {
            myfeeds_log('ajax_cancel_import error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => 'Cancel failed: ' . $e->getMessage()), 500);
        }
    }
}

// Register custom cron intervals
add_filter('cron_schedules', array('MyFeeds_Batch_Importer', 'register_cron_intervals'));
