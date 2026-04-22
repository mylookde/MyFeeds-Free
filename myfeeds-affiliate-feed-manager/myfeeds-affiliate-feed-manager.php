<?php
/**
 * Plugin Name: MyFeeds — Affiliate Product Feed Manager
 * Plugin URI: https://myfeeds.site
 * Description: Import and manage affiliate product feeds from any network. Smart search, auto-mapping, and a Gutenberg Product Picker for bloggers.
 * Version: 1.0.0
 * Author: Marlon Weber
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: myfeeds-affiliate-feed-manager
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Network: true
 */

/*
MyFeeds — Affiliate Product Feed Manager is free software: you can redistribute
it and/or modify it under the terms of the GNU General Public License as
published by the Free Software Foundation, either version 2 of the License, or
any later version.

MyFeeds — Affiliate Product Feed Manager is distributed in the hope that it
will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty
of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
Public License for more details.

You should have received a copy of the GNU General Public License along with
this program. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// PLUGIN INITIALIZATION
// =============================================================================

// Define plugin constants
define('MYFEEDS_VERSION', '1.0.0');
define('MYFEEDS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MYFEEDS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MYFEEDS_PLUGIN_FILE', __FILE__);

// =============================================================================
// CENTRAL LOG-LEVEL SYSTEM
// Levels: 'error' (only errors), 'info' (+ summaries), 'debug' (everything)
// Set via MyFeeds → Settings → Log Level. Default for new installs: 'info'
// =============================================================================
function myfeeds_log($message, $level = 'debug') {
    static $current_level = null;
    
    if ($current_level === null) {
        $current_level = get_option('myfeeds_log_level', 'info');
    }
    
    $levels = array('error' => 0, 'info' => 1, 'debug' => 2);
    $msg_priority = $levels[$level] ?? 2;
    $threshold = $levels[$current_level] ?? 1;
    
    if ($msg_priority <= $threshold) {
        error_log('MYFEEDS [' . strtoupper($level) . ']: ' . $message);
    }
}

// =============================================================================
// ONE-TIME NAMING MIGRATION: mylook_* → myfeeds_*
// Migrates options and scheduled hooks for existing installations.
// Also updates block names in post_content so existing pages keep working.
// =============================================================================
function myfeeds_run_naming_migration_v1() {
    global $wpdb;

    $migrations = array(
        'mylook_app_feeds'          => 'myfeeds_feeds',
        'mylook_app_version'        => 'myfeeds_version',
        'mylook_app_settings'       => 'myfeeds_settings',
        'mylook_active_product_ids' => 'myfeeds_active_product_ids',
        'mylook_import_status'      => 'myfeeds_import_status',
        'mylook_import_queue'       => 'myfeeds_import_queue',
        'mylook_batch_state'        => 'myfeeds_batch_state',
        'mylook_affiliate_feeds'    => 'myfeeds_affiliate_feeds',
        'mylook_api_keys'           => 'myfeeds_api_keys',
        'mylook_mapping_templates'  => 'myfeeds_mapping_templates',
        'mylook_general_settings'   => 'myfeeds_general_settings',
        'mylook_index_version'      => 'myfeeds_index_version',
        'mylook_build_status'       => 'myfeeds_build_status',
        'mylook_atomic_build_status'=> 'myfeeds_atomic_build_status',
        'mylook_last_auto_sync'     => 'myfeeds_last_auto_sync',
        'mylook_next_feed_id'       => 'myfeeds_next_feed_id',
        'mylook_db_import_status'   => 'myfeeds_db_import_status',
    );

    foreach ($migrations as $old_key => $new_key) {
        $old_value = get_option($old_key);
        if ($old_value !== false) {
            update_option($new_key, $old_value);
            delete_option($old_key);
            myfeeds_log("Migrated option: {$old_key} -> {$new_key}", 'info');
        }
    }

    // Migrate scheduled hooks
    $old_hooks = array(
        'mylook_daily_feed_index'   => 'myfeeds_daily_feed_index',
        'mylook_check_import_queue' => 'myfeeds_check_import_queue',
        'mylook_process_batch'      => 'myfeeds_process_batch',
    );
    foreach ($old_hooks as $old_hook => $new_hook) {
        $timestamp = wp_next_scheduled($old_hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $old_hook);
            myfeeds_log("Unscheduled old hook: {$old_hook}", 'info');
        }
    }

    // Migrate block names in post_content: wp:mylook/product-picker → wp:myfeeds/product-picker
    $updated_posts = $wpdb->query(
        "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, 'wp:mylook/product-picker', 'wp:myfeeds/product-picker') WHERE post_content LIKE '%wp:mylook/product-picker%'"
    );
    if ($updated_posts > 0) {
        myfeeds_log("Migrated block names in {$updated_posts} posts (mylook → myfeeds)", 'info');
    }

    // Also migrate shortcode patterns [mylook → [myfeeds
    $updated_shortcodes = $wpdb->query(
        "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, '[mylook', '[myfeeds') WHERE post_content LIKE '%[mylook%'"
    );
    if ($updated_shortcodes > 0) {
        myfeeds_log("Migrated shortcodes in {$updated_shortcodes} posts (mylook → myfeeds)", 'info');
    }

    update_option('myfeeds_naming_migrated', true);
    myfeeds_log("Naming migration completed (mylook -> myfeeds)", 'info');
}
if (!get_option('myfeeds_naming_migrated')) {
    myfeeds_run_naming_migration_v1();
}

// =============================================================================
// ONE-TIME NAMING MIGRATION v2: Catch ALL remaining mylook_* options
// =============================================================================
function myfeeds_run_naming_migration_v2() {
    global $wpdb;

    $remaining = $wpdb->get_results(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'mylook_%'",
        ARRAY_A
    );
    foreach ($remaining as $row) {
        $old_name = $row['option_name'];
        $new_name = str_replace('mylook_', 'myfeeds_', $old_name);
        $old_value = get_option($old_name);
        if ($old_value !== false) {
            update_option($new_name, $old_value);
            delete_option($old_name);
            myfeeds_log("Migrated option v2: {$old_name} -> {$new_name}", 'info');
        }
    }
    update_option('myfeeds_naming_migrated_v2', true);
    myfeeds_log("Naming migration v2 completed", 'info');
}
if (!get_option('myfeeds_naming_migrated_v2')) {
    myfeeds_run_naming_migration_v2();
}

// =============================================================================
// ONE-TIME SINGLE-FEED MIGRATION
// The Free plugin only ever manages one feed. Installs that previously ran a
// multi-feed Pro build can end up with leftover feeds in the myfeeds_feeds
// option and their imported products sitting in the DB. This migration keeps
// only the first feed in the visible slot, archives the rest under
// myfeeds_feeds_archive (so a future Pro upgrade can restore them), and
// purges orphan product rows via the existing cleanup helper.
// =============================================================================
function myfeeds_run_single_feed_migration_v1() {
    $feeds = get_option('myfeeds_feeds', array());

    if (is_array($feeds) && count($feeds) > 1) {
        $first_key  = array_key_first($feeds);
        $kept       = array($first_key => $feeds[$first_key]);
        $archived   = $feeds;
        unset($archived[$first_key]);

        update_option('myfeeds_feeds_archive', $archived);
        update_option('myfeeds_feeds', $kept);

        myfeeds_log('Single-feed migration: kept "' . ($kept[$first_key]['name'] ?? $first_key) . '", archived ' . count($archived) . ' extra feed(s) to myfeeds_feeds_archive', 'info');
    }

    if (class_exists('MyFeeds_DB_Manager') && method_exists('MyFeeds_DB_Manager', 'cleanup_orphaned_products')) {
        $deleted = MyFeeds_DB_Manager::cleanup_orphaned_products();
        if ($deleted > 0) {
            myfeeds_log("Single-feed migration: removed {$deleted} orphan product row(s) from archived feeds", 'info');
        }
    }

    update_option('myfeeds_single_feed_migrated_v1', true);
}
add_action('plugins_loaded', function () {
    if (!get_option('myfeeds_single_feed_migrated_v1')) {
        myfeeds_run_single_feed_migration_v1();
    }
}, 30);

// Ensure DB mode option is set on load (for fresh installs or upgrades)
add_action('admin_init', function() {
    if (get_option('myfeeds_use_db') === false) {
        update_option('myfeeds_use_db', true);
    }
    if (get_option('myfeeds_log_level') === false) {
        add_option('myfeeds_log_level', 'info');
    }
    // v2.1 schema upgrade: add colour column + backfill from raw_data
    if (class_exists('MyFeeds_DB_Manager')) {
        $db_version = get_option('myfeeds_db_schema_version', '1.0');
        if (version_compare($db_version, '2.1', '<')) {
            MyFeeds_DB_Manager::create_table(); // dbDelta adds new column
            MyFeeds_DB_Manager::backfill_colour_column();
            update_option('myfeeds_db_schema_version', '2.1');
        }
        
        // v2.2 schema upgrade: add search_text column + FULLTEXT index + backfill
        if (version_compare($db_version, '2.2', '<')) {
            MyFeeds_DB_Manager::create_table(); // dbDelta adds search_text column + FULLTEXT index
            if (!get_option('myfeeds_search_text_v1')) {
                MyFeeds_DB_Manager::backfill_search_text();
                update_option('myfeeds_search_text_v1', true);
            }
            update_option('myfeeds_db_schema_version', '2.2');
        }
        
        // Stable feed_id migration: Run once to assign stable_ids and clean orphans
        if (!get_option('myfeeds_stable_id_migrated')) {
            MyFeeds_DB_Manager::migrate_to_stable_feed_ids();
        }
    }
}, 1);

// =============================================================================
// ACTION SCHEDULER - Robust Background Job Processing
// Bundled dependency for reliable, resumable batch imports
// =============================================================================
function myfeeds_load_includes() {
    $action_scheduler_path = MYFEEDS_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
    if (file_exists($action_scheduler_path)) {
        require_once $action_scheduler_path;
    }

    // Load all include files
    $includes_dir = MYFEEDS_PLUGIN_DIR . 'includes/';
    $include_files = array(
        'class-feed-reader.php' => 'Universal Feed Reader (CSV/TSV/XML/JSON)',
        'class-settings-manager.php' => 'Settings Manager Class',
        'class-db-manager.php' => 'Database Manager Class',
        'class-search-engine.php' => 'Search Engine (FULLTEXT + Synonyms)',
        'class-batch-importer.php' => 'Batch Importer Class',
        'class-universal-mapper-ui.php' => 'Universal Mapper UI Class',
        'class-smart-mapper.php' => 'Smart Mapper Class',
        'class-network-handlers.php' => 'Network Handlers Class', 
        'class-feed-manager.php' => 'Feed Manager Class',
        'class-contact-page.php' => 'Custom Contact Page',
        'class-upsell.php'       => 'Free-to-Pro Upsell Surfaces',
        'class-product-picker.php' => 'Product Picker Class',
        'class-product-resolver.php' => 'Product Resolver (Multi-Source Fallback)',
        'class-atomic-index-manager.php' => 'Atomic Index Manager',
    );

    // Load standalone helper functions FIRST (no class dependencies)
    $helpers_file = $includes_dir . 'myfeeds-feed-cache.php';
    if (file_exists($helpers_file)) {
        require_once $helpers_file;
    }

    foreach ($include_files as $file => $description) {
        $file_path = $includes_dir . $file;
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
}
myfeeds_load_includes();

// Initialize the plugin
class MyFeeds_Affiliate_Product_Picker {
    
    private static $instance = null;
    private $smart_mapper;
    private $feed_manager;
    private $product_picker;
    private $batch_importer;
    private $mapper_ui;
    
    public static function get_instance() {
        myfeeds_log("get_instance() called");
        if (null === self::$instance) {
            myfeeds_log("Creating new plugin instance");
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        myfeeds_log("Plugin constructor started");
        
        // Initialize components directly — plugins_loaded priority 10 may
        // already have finished by the time this constructor runs.
        $this->init_components();
        
        add_action('plugins_loaded', array($this, 'init'), 20);
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        myfeeds_log("Plugin constructor completed");
    }
    
    public function init_components() {
        myfeeds_log("Initializing components");
        
        try {
            if (class_exists('MyFeeds_Smart_Mapper')) {
                myfeeds_log("Creating MyFeeds_Smart_Mapper instance");
                $this->smart_mapper = new MyFeeds_Smart_Mapper();
                myfeeds_log("Smart Mapper created successfully");
            } else {
                myfeeds_log("ERROR: MyFeeds_Smart_Mapper class not found");
            }
            
            if (class_exists('MyFeeds_Feed_Manager')) {
                myfeeds_log("Creating MyFeeds_Feed_Manager instance");
                if ($this->smart_mapper) {
                    myfeeds_log("Smart Mapper available - creating Feed Manager with dependency");
                    $this->feed_manager = new MyFeeds_Feed_Manager($this->smart_mapper);  
                } else {
                    myfeeds_log("Smart Mapper not available - creating Feed Manager without dependency");
                    $this->feed_manager = new MyFeeds_Feed_Manager(null);
                }
                myfeeds_log("Feed Manager created successfully");
            } else {
                myfeeds_log("ERROR: MyFeeds_Feed_Manager class not found");
            }
            
            if (class_exists('MyFeeds_Product_Picker')) {
                myfeeds_log("Creating MyFeeds_Product_Picker instance");
                $this->product_picker = new MyFeeds_Product_Picker();
                myfeeds_log("Product Picker created successfully");
            } else {
                myfeeds_log("ERROR: MyFeeds_Product_Picker class not found");
            }
            
            // Initialize Batch Importer
            if (class_exists('MyFeeds_Batch_Importer')) {
                myfeeds_log("Creating MyFeeds_Batch_Importer instance");
                $this->batch_importer = new MyFeeds_Batch_Importer($this->smart_mapper);
                // Connect to Feed Manager for using its process_critical_fields method
                if ($this->feed_manager) {
                    $this->batch_importer->set_feed_manager($this->feed_manager);
                }
                myfeeds_log("Batch Importer created successfully");
            } else {
                myfeeds_log("ERROR: MyFeeds_Batch_Importer class not found");
            }
            
            // Initialize Custom Contact Page
            if (class_exists('MyFeeds_Contact_Page')) {
                $contact_page = new MyFeeds_Contact_Page();
                $contact_page->init();
                myfeeds_log("Custom Contact Page initialized");
            }
            
            // Initialize Free-to-Pro Upsell Surfaces
            if (class_exists('MyFeeds_Upsell')) {
                $upsell = new MyFeeds_Upsell();
                $upsell->init();
                myfeeds_log("Upsell surfaces initialized");
            }

            // Initialize Universal Mapper UI
            if (class_exists('MyFeeds_Universal_Mapper_UI')) {
                myfeeds_log("Creating MyFeeds_Universal_Mapper_UI instance");
                $this->mapper_ui = new MyFeeds_Universal_Mapper_UI();
                myfeeds_log("Universal Mapper UI created successfully");
            } else {
                myfeeds_log("ERROR: MyFeeds_Universal_Mapper_UI class not found");
            }
            
            myfeeds_log("All components initialized successfully");
        } catch (Exception $e) {
            myfeeds_log("EXCEPTION in init_components: " . $e->getMessage());
        }
    }
    
    public function init() {
        myfeeds_log("Plugin init() started");
        
        try {
            // Fallback Menü-Registrierung - läuft NACH Feed Manager (Priorität 99)
            // Nur aktiv wenn Feed Manager sein Menü nicht registriert hat
            add_action('admin_menu', array($this, 'register_admin_menu_fallback'), 99);
            
            // Initialize components
            if ($this->feed_manager && method_exists($this->feed_manager, 'init')) {
                myfeeds_log("Initializing feed_manager");
                $this->feed_manager->init();
            }
            
            if ($this->product_picker && method_exists($this->product_picker, 'init')) {
                myfeeds_log("Initializing product_picker");
                $this->product_picker->init();
            }
            
            // Initialize Batch Importer
            if ($this->batch_importer && method_exists($this->batch_importer, 'init')) {
                myfeeds_log("Initializing batch_importer");
                $this->batch_importer->init();
            }
            
            // Ensure weekly cron is scheduled (for existing installations that
            // were activated before the weekly job was added)
            if (!wp_next_scheduled('myfeeds_weekly_full_import')) {
                $timezone = wp_timezone();
                $now = new DateTime('now', $timezone);
                $weekly_target = new DateTime('sunday 03:00', $timezone);
                if ($now > $weekly_target) {
                    $weekly_target->modify('+7 days');
                }
                wp_schedule_event($weekly_target->getTimestamp(), 'weekly', 'myfeeds_weekly_full_import');
            }
            
            // Ensure daily cron points to 02:00 (may still be 03:00 from old version)
            // Only re-register if not scheduled at all
            if (!wp_next_scheduled('myfeeds_daily_feed_index')) {
                $timezone = wp_timezone();
                $now = new DateTime('now', $timezone);
                $daily_target = new DateTime('today 02:00', $timezone);
                if ($now > $daily_target) {
                    $daily_target->modify('+1 day');
                }
                wp_schedule_event($daily_target->getTimestamp(), 'daily', 'myfeeds_daily_feed_index');
            }
            
            // Initialize Universal Mapper UI
            if ($this->mapper_ui && method_exists($this->mapper_ui, 'init')) {
                myfeeds_log("Initializing mapper_ui");
                $this->mapper_ui->init();
            }
            
            // One-time DB cleanup: Remove orphaned products from legacy feed_id=0 bug
            if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode() && !get_option('myfeeds_cleanup_v1')) {
                $cleanup_count = MyFeeds_DB_Manager::cleanup_orphaned_products();
                update_option('myfeeds_cleanup_v1', true);
                if ($cleanup_count > 0) {
                    myfeeds_log("One-time migration cleanup: Removed {$cleanup_count} orphaned products", 'info');
                }
            }
            
            myfeeds_log("Plugin init() completed successfully");
        } catch (Exception $e) {
            myfeeds_log("EXCEPTION in init(): " . $e->getMessage());
        }
    }
    
    /**
     * Fallback menu registration - runs with priority 99 (late) to check if menu exists
     * Only creates menu if Feed Manager failed to register it
     */
    public function register_admin_menu_fallback() {
        global $menu;
        
        // Prüfe ob das Menü bereits existiert (von Feed Manager registriert)
        $menu_exists = false;
        if (is_array($menu)) {
            foreach ($menu as $item) {
                if (isset($item[2]) && $item[2] === 'myfeeds-feeds') {
                    $menu_exists = true;
                    break;
                }
            }
        }
        
        // Nur registrieren wenn Feed Manager es NICHT registriert hat
        if (!$menu_exists) {
            myfeeds_log("FALLBACK: Feed Manager hat kein Menü registriert - erstelle Fallback");
            add_menu_page(
                'MyFeeds',
                'MyFeeds',
                'manage_options',
                'myfeeds-feeds',
                array($this, 'render_fallback_page'),
                'dashicons-rss',
                60
            );
        }
    }
    
    /**
     * Fallback page render - only used if Feed Manager failed
     */
    public function render_fallback_page() {
        if ($this->feed_manager && method_exists($this->feed_manager, 'render_feeds_page')) {
            $this->feed_manager->render_feeds_page();
        } else {
            echo '<div class="wrap"><h1>MyFeeds</h1>';
            echo '<div class="notice notice-warning"><p>Feed Manager wird geladen... Bitte Seite neu laden.</p></div>';
            echo '</div>';
        }
    }
    
    public function activate() {
        myfeeds_log("Plugin activation started");
        
        try {
            $this->create_database_tables();
            $this->set_default_options();
            
            // Schedule daily feed update at 03:00 AM
            $this->schedule_daily_cron();
            
            // Schedule active products sync check
            if (!wp_next_scheduled('myfeeds_check_import_queue')) {
                wp_schedule_event(time(), 'every_minute', 'myfeeds_check_import_queue');
            }
            
            flush_rewrite_rules();
            myfeeds_log("Plugin activation completed successfully");
        } catch (Exception $e) {
            myfeeds_log("EXCEPTION in activate(): " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Schedule daily cron job for 02:00 AM local time (Quick Sync)
     * and weekly cron job for Sunday 03:00 AM (Full Import)
     */
    private function schedule_daily_cron() {
        $timezone = wp_timezone();
        $now = new DateTime('now', $timezone);
        
        // ── Daily Quick Sync at 02:00 AM ──
        wp_clear_scheduled_hook('myfeeds_daily_feed_index');
        
        $daily_target = new DateTime('today 02:00', $timezone);
        if ($now > $daily_target) {
            $daily_target->modify('+1 day');
        }
        
        wp_schedule_event($daily_target->getTimestamp(), 'daily', 'myfeeds_daily_feed_index');
        myfeeds_log("Daily Quick Sync scheduled for: " . $daily_target->format('Y-m-d H:i:s T'));
        
        // ── Weekly Full Import on Sunday at 03:00 AM ──
        wp_clear_scheduled_hook('myfeeds_weekly_full_import');
        
        $weekly_target = new DateTime('sunday 03:00', $timezone);
        // If it's already past Sunday 03:00 this week, schedule for next Sunday
        if ($now > $weekly_target) {
            $weekly_target->modify('+7 days');
        }
        
        wp_schedule_event($weekly_target->getTimestamp(), 'weekly', 'myfeeds_weekly_full_import');
        myfeeds_log("Weekly Full Import scheduled for: " . $weekly_target->format('Y-m-d H:i:s T'));
    }
    
    public function deactivate() {
        myfeeds_log("Plugin deactivation started");
        
        // Clear all scheduled hooks
        wp_clear_scheduled_hook('myfeeds_daily_feed_index');
        wp_clear_scheduled_hook('myfeeds_weekly_full_import');
        wp_clear_scheduled_hook('myfeeds_check_import_queue');
        wp_clear_scheduled_hook('myfeeds_process_batch');
        
        // Clear any ongoing import status
        delete_option('myfeeds_import_status');
        delete_option('myfeeds_import_queue');
        
        flush_rewrite_rules();
        myfeeds_log("Plugin deactivation completed - all cron jobs cleared");
    }
    
    private function create_database_tables() {
        myfeeds_log("Creating database tables");
        
        if (class_exists('MyFeeds_DB_Manager')) {
            MyFeeds_DB_Manager::create_table();
            myfeeds_log("MyFeeds products table created/updated via dbDelta");
            
            // Ensure DB mode is always on (JSON mode removed)
            update_option('myfeeds_use_db', true);
            
            // Set default log level for fresh installs
            if (get_option('myfeeds_log_level') === false) {
                add_option('myfeeds_log_level', 'info');
            }
            
            // One-time migration: if JSON index exists and DB is empty, migrate
            if (MyFeeds_DB_Manager::table_exists() && MyFeeds_DB_Manager::get_product_count() === 0) {
                $migrated = MyFeeds_DB_Manager::migrate_from_json();
                if ($migrated > 0) {
                    myfeeds_log("Migrated {$migrated} products from JSON to DB");
                }
            }
            
            // One-time cleanup: Remove "(Priorität)" suffix from feed_name in DB
            MyFeeds_DB_Manager::cleanup_priority_suffix();
            
            // v2.1: Backfill colour column from raw_data for existing products
            $db_version = get_option('myfeeds_db_schema_version', '1.0');
            if (version_compare($db_version, '2.1', '<')) {
                MyFeeds_DB_Manager::backfill_colour_column();
                update_option('myfeeds_db_schema_version', '2.1');
                myfeeds_log("DB schema updated to v2.1 (colour column backfilled)");
            }
            
            // v2.2: Add search_text column + FULLTEXT index + backfill
            $db_version = get_option('myfeeds_db_schema_version', '1.0');
            if (version_compare($db_version, '2.2', '<')) {
                if (!get_option('myfeeds_search_text_v1')) {
                    MyFeeds_DB_Manager::backfill_search_text();
                    update_option('myfeeds_search_text_v1', true);
                }
                update_option('myfeeds_db_schema_version', '2.2');
                myfeeds_log("DB schema updated to v2.2 (search_text column + FULLTEXT index)");
            }
        }
    }
    
    private function set_default_options() {
        myfeeds_log("Setting default options");
        
        $default_options = array(
            'myfeeds_feeds' => array(),
            'myfeeds_version' => MYFEEDS_VERSION,
            'myfeeds_settings' => array(
                'cache_duration' => 3600,
                'max_products_per_feed' => 10000,
                'enable_logging' => true,  // Enable logging by default
                'auto_rebuild_index' => true,
                'modal_top_offset' => 96,
                'modal_side_offset' => 40,
                'modal_bottom_offset' => 48,
                'modal_grid_columns' => 5,
                'frontend_columns' => 4
            )
        );
        
        foreach ($default_options as $option_name => $default_value) {
            if (false === get_option($option_name)) {
                add_option($option_name, $default_value);
                myfeeds_log("Added option: " . $option_name);
            }
        }
    }
    
    public static function log($message, $level = 'info') {
        myfeeds_log($message, $level);
    }
    
    /**
     * Get Feed Manager instance
     */
    public function get_feed_manager() {
        return $this->feed_manager;
    }
}

// myfeeds_log merged into myfeeds_log — see line ~214

// Initialize safely with debug logging
function myfeeds_app_init() {
    myfeeds_log("myfeeds_app_init() called");
    
    if (class_exists('MyFeeds_Affiliate_Product_Picker')) {
        myfeeds_log("Initializing plugin instance");
        return MyFeeds_Affiliate_Product_Picker::get_instance();
    }
    
    myfeeds_log("ERROR: MyFeeds_Affiliate_Product_Picker class not found");
    return false;
}

function myfeeds_app() {
    return myfeeds_app_init();
}

if (defined('ABSPATH')) {
    add_action('plugins_loaded', 'myfeeds_app_init', 10);
    myfeeds_log("Plugin initialization scheduled");
    
    // =========================================================================
    // ACTION SCHEDULER CONFIGURATION
    // AS has an internal time limit of 30s per queue run — independent of PHP's
    // time_limit. Our feeds can take minutes to process. Increase to 5 min (300s).
    // Batch size 1 = run one AS job per queue tick, preventing parallel execution.
    // =========================================================================
    add_filter('action_scheduler_queue_runner_time_limit', function() {
        return 300; // 5 minutes instead of 30 seconds
    });
    add_filter('action_scheduler_queue_runner_batch_size', function() {
        return 1; // One AS job per queue run — prevents parallel execution
    });

    // Allow font file uploads in WordPress Media Library
    add_filter('upload_mimes', function($mimes) {
        $mimes['woff']  = 'font/woff';
        $mimes['woff2'] = 'font/woff2';
        $mimes['ttf']   = 'font/ttf';
        return $mimes;
    });

    // Fix MIME type detection for font files (WP 5.3+)
    add_filter('wp_check_filetype_and_ext', function($data, $file, $filename, $mimes) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (in_array($ext, array('woff', 'woff2', 'ttf'))) {
            $data['ext'] = $ext;
            $data['type'] = 'font/' . $ext;
        }
        return $data;
    }, 10, 4);
}

// =============================================================================
// FALLBACK MENÜ-REGISTRIERUNG (Deaktiviert - Feed Manager übernimmt)
// Diese läuft nur, wenn Feed Manager nicht geladen wurde
// =============================================================================
// HINWEIS: Die Menü-Registrierung erfolgt jetzt ausschließlich über 
// MyFeeds_Feed_Manager::register_admin_pages() um Duplikate zu vermeiden.
// Der Fallback in register_admin_menu_fallback() der Hauptklasse greift nur,
// wenn Feed Manager nicht verfügbar ist.

myfeeds_log("=== PLUGIN FILE PROCESSING COMPLETED ===");

// =============================================================================
// ONE-TIME RESOLVER INTEGRITY TEST (Runs once per day for admins)
// Tests whether get_product() can find the active block product IDs in the DB.
// =============================================================================
add_action('admin_init', 'myfeeds_resolver_integrity_test');

function myfeeds_resolver_integrity_test() {
    // Only for admins, only once per day
    if (!current_user_can('manage_options')) return;
    if (get_transient('myfeeds_resolver_test_done')) return;
    set_transient('myfeeds_resolver_test_done', true, DAY_IN_SECONDS);
    
    // Only in DB mode
    if (!class_exists('MyFeeds_DB_Manager') || !MyFeeds_DB_Manager::is_db_mode()) return;
    
    global $wpdb;
    $table = MyFeeds_DB_Manager::table_name();
    
    myfeeds_log('RESOLVER_TEST: === Starting Integrity Test ===', 'info');
    myfeeds_log('RESOLVER_TEST: table_name=' . $table, 'debug');
    myfeeds_log('RESOLVER_TEST: wpdb_prefix=' . $wpdb->prefix, 'debug');
    
    // Collect product IDs from Gutenberg blocks (Discovery)
    $test_ids = myfeeds_discover_block_product_ids();
    
    if (empty($test_ids)) {
        myfeeds_log('RESOLVER_TEST: No product IDs found in Gutenberg blocks. Skipping test.', 'debug');
        return;
    }
    
    myfeeds_log('RESOLVER_TEST: Found ' . count($test_ids) . ' product IDs in blocks: ' . implode(', ', $test_ids), 'debug');
    
    foreach ($test_ids as $ext_id) {
        $ext_id = (string) $ext_id;
        
        // Test 1: Direct get_product() call
        $product = MyFeeds_DB_Manager::get_product($ext_id);
        $found = ($product !== null);
        
        if ($found) {
            myfeeds_log('RESOLVER_TEST: external_id=' . $ext_id
                . ', found=true'
                . ', has_price=' . (!empty($product['price']) && floatval($product['price']) > 0 ? 'true' : 'false')
                . ', has_image=' . (!empty($product['image_url']) ? 'true' : 'false')
                . ', has_brand=' . (!empty($product['brand']) ? 'true' : 'false')
                . ', has_affiliate_link=' . (!empty($product['affiliate_link']) ? 'true' : 'false')
                . ', price=' . ($product['price'] ?? 0)
                . ', brand=' . ($product['brand'] ?? '(empty)')
            , 'debug');
        } else {
            myfeeds_log('RESOLVER_TEST: external_id=' . $ext_id . ', found=false', 'debug');
            
            // Diagnostic: exact count
            $exact_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE external_id = %s",
                $ext_id
            ));
            myfeeds_log('RESOLVER_TEST_DIAG: SELECT COUNT(*) WHERE external_id = \'' . $ext_id . '\' => ' . $exact_count, 'debug');
            
            // Diagnostic: LIKE search
            $like_results = $wpdb->get_results($wpdb->prepare(
                "SELECT external_id, feed_name, status FROM {$table} WHERE external_id LIKE %s LIMIT 5",
                '%' . $wpdb->esc_like($ext_id) . '%'
            ), ARRAY_A);
            
            if (!empty($like_results)) {
                foreach ($like_results as $lr) {
                    myfeeds_log('RESOLVER_TEST_DIAG: LIKE match => external_id=' . $lr['external_id']
                        . ', feed_name=' . $lr['feed_name']
                        . ', status=' . $lr['status']
                    , 'debug');
                }
            } else {
                myfeeds_log('RESOLVER_TEST_DIAG: No LIKE matches for ' . $ext_id, 'debug');
                
                // Extra: show sample IDs from DB for format comparison
                $sample_ids = $wpdb->get_col("SELECT external_id FROM {$table} LIMIT 5");
                myfeeds_log('RESOLVER_TEST_DIAG: Sample DB external_ids=[' . implode(', ', $sample_ids) . ']', 'debug');
                myfeeds_log('RESOLVER_TEST_DIAG: block_id_format=\'' . $ext_id . '\' (len=' . strlen($ext_id) . ', hex=' . bin2hex(substr($ext_id, 0, 20)) . ')', 'debug');
                if (!empty($sample_ids[0])) {
                    myfeeds_log('RESOLVER_TEST_DIAG: db_id_format=\'' . $sample_ids[0] . '\' (len=' . strlen($sample_ids[0]) . ', hex=' . bin2hex(substr($sample_ids[0], 0, 20)) . ')', 'debug');
                }
            }
        }
    }
    
    myfeeds_log('RESOLVER_TEST: === Integrity Test Complete ===', 'debug');
}

/**
 * Discover product IDs used in Gutenberg blocks across published posts/pages.
 * Scans post_content for myfeeds/product-picker blocks and extracts selectedProducts IDs.
 */
function myfeeds_discover_block_product_ids() {
    global $wpdb;
    
    // Find posts containing our block
    $posts = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} 
         WHERE post_status = 'publish' 
         AND post_content LIKE '%myfeeds/product-picker%' 
         LIMIT 20"
    );
    
    if (empty($posts)) {
        return array();
    }
    
    $all_ids = array();
    
    foreach ($posts as $post_id) {
        $content = get_post_field('post_content', $post_id);
        $blocks = parse_blocks($content);
        
        foreach ($blocks as $block) {
            myfeeds_extract_block_ids($block, $all_ids);
        }
    }
    
    return array_unique($all_ids);
}

/**
 * Recursively extract product IDs from a block and its inner blocks.
 */
function myfeeds_extract_block_ids($block, &$all_ids) {
    if (isset($block['blockName']) && $block['blockName'] === 'myfeeds/product-picker') {
        $selected = $block['attrs']['selectedProducts'] ?? array();
        foreach ($selected as $p) {
            if (is_array($p) && !empty($p['id'])) {
                $all_ids[] = (string) $p['id'];
            }
        }
    }
    
    // Recurse into inner blocks
    if (!empty($block['innerBlocks'])) {
        foreach ($block['innerBlocks'] as $inner) {
            myfeeds_extract_block_ids($inner, $all_ids);
        }
    }
}
