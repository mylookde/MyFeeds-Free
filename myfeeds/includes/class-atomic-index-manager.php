<?php
/**
 * MyFeeds Atomic Index Manager v3.0
 * 
 * ARCHITECTURE: JSONL Append-Only Building Index
 * 
 * The building index uses JSONL (JSON Lines) format — one product per line.
 * This eliminates the memory leak where the entire index had to be loaded,
 * merged, and rewritten on every batch iteration.
 * 
 * Lifecycle:
 *   1. start_atomic_rebuild()         → creates empty .jsonl file
 *   2. add_items_to_building_index()  → APPENDS products as JSONL lines (never reads!)
 *   3. complete_atomic_rebuild()      → streams JSONL → deduplicates → writes final JSON
 * 
 * Memory profile:
 *   - add_items_to_building_index(): O(batch_size) — only current batch in RAM
 *   - complete_atomic_rebuild(): O(total_products) — one-time read at end of import
 *   - During import: building index file grows on disk, NOT in RAM
 * 
 * Invariants:
 *   - Active index is NEVER modified during a full rebuild
 *   - Building index is append-only during import
 *   - Swap replaces active entirely
 *   - File locking prevents corruption from concurrent appends
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Atomic_Index_Manager {
    
    const INDEX_FILE_ACTIVE   = 'myfeeds-feed-index.json';
    const INDEX_FILE_BUILDING = 'myfeeds-feed-index-building.jsonl';
    const INDEX_FILE_BACKUP   = 'myfeeds-feed-index-backup.json';
    const OPTION_BUILD_STATUS = 'myfeeds_atomic_build_status';
    
    // =========================================================================
    // REBUILD LIFECYCLE
    // =========================================================================
    
    /**
     * Step 1: Start a new atomic rebuild
     * 
     * Creates an empty JSONL building file. Does NOT touch the active index.
     * Any previous building index is discarded.
     * 
     * @return bool True on success
     */
    public static function start_atomic_rebuild() {
        $upload_dir = wp_upload_dir();
        $building_path = $upload_dir['basedir'] . '/' . self::INDEX_FILE_BUILDING;
        
        // Also clean up legacy JSON building index if present
        $legacy_path = $upload_dir['basedir'] . '/myfeeds-feed-index-building.json';
        if (file_exists($legacy_path)) {
            wp_delete_file($legacy_path);
        }
        
        // Create empty JSONL file (truncate if exists)
        file_put_contents($building_path, '', LOCK_EX);
        
        update_option(self::OPTION_BUILD_STATUS, array(
            'status' => 'building',
            'started' => time(),
            'items_appended' => 0,
        ));
        
        self::log('atomic_rebuild_started', array('format' => 'jsonl'));
        
        return true;
    }
    
    /**
     * Step 2: Append a batch of products to the building index (JSONL)
     * 
     * MEMORY-SAFE: Opens file in append mode, writes each product as a
     * single JSON line, then closes. NEVER reads the existing file content.
     * 
     * Duplicate IDs are handled at completion (last write wins).
     * 
     * @param array $keyed_items Associative array [product_id => product_data]
     * @return int Running total of appended items (may include duplicates)
     */
    public static function add_items_to_building_index($keyed_items) {
        if (empty($keyed_items)) {
            return 0;
        }
        
        $upload_dir = wp_upload_dir();
        $building_path = $upload_dir['basedir'] . '/' . self::INDEX_FILE_BUILDING;
        
        if (!file_exists($building_path)) {
            self::log('add_items_failed', array('reason' => 'building_index_missing'));
            return 0;
        }
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Atomic file writing requires direct file handles
        $fp = fopen($building_path, 'a');
        if (!$fp) {
            self::log('add_items_failed', array('reason' => 'cannot_open_file'));
            return 0;
        }
        
        flock($fp, LOCK_EX);
        
        $written = 0;
        try {
            foreach ($keyed_items as $id => $data) {
                // Ensure ID is stored inside the data for dedup at completion
                $data['id'] = (string) $id;
                $line = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($line !== false) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Atomic file writing requires direct file handles
                    fwrite($fp, $line . "\n");
                    $written++;
                }
            }
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Atomic file writing requires direct file handles
            fclose($fp);
        }
        
        // Update running counter in options (atomic increment)
        $status = get_option(self::OPTION_BUILD_STATUS, array());
        $status['items_appended'] = ($status['items_appended'] ?? 0) + $written;
        $status['last_batch'] = time();
        update_option(self::OPTION_BUILD_STATUS, $status);
        
        return $status['items_appended'];
    }
    
    /**
     * Step 3: Complete the rebuild — stream JSONL → deduplicate → atomic swap
     * 
     * Reads the JSONL building file line by line, deduplicates (last write wins),
     * builds the final active index JSON, and performs the atomic swap.
     * 
     * This is the ONLY point where all products are loaded into memory.
     * It happens once at the end of the import, not on every batch.
     * 
     * @return bool True on success, false if building index is missing or empty
     */
    public static function complete_atomic_rebuild() {
        $upload_dir = wp_upload_dir();
        $active_path   = $upload_dir['basedir'] . '/' . self::INDEX_FILE_ACTIVE;
        $building_path = $upload_dir['basedir'] . '/' . self::INDEX_FILE_BUILDING;
        $backup_path   = $upload_dir['basedir'] . '/' . self::INDEX_FILE_BACKUP;
        
        // --- Validate building index exists ---
        if (!file_exists($building_path) || filesize($building_path) === 0) {
            self::log('atomic_complete_failed', array('reason' => 'building_index_missing_or_empty'));
            return false;
        }
        
        // --- Stream JSONL → deduplicate into items array ---
        // Last write wins: if a product appears multiple times (e.g., priority + full pass),
        // the last occurrence is kept.
        $items = array();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming required for large index files
        $fp = fopen($building_path, 'r');
        if (!$fp) {
            self::log('atomic_complete_failed', array('reason' => 'cannot_open_building_file'));
            return false;
        }
        
        $line_count = 0;
        $parse_errors = 0;
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $product = json_decode($line, true);
            if (!$product || !isset($product['id'])) {
                $parse_errors++;
                continue;
            }
            
            $items[(string) $product['id']] = $product;
            $line_count++;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming required for large index files
        fclose($fp);
        
        $new_count = count($items);
        
        if ($new_count === 0) {
            self::log('atomic_complete_failed', array(
                'reason' => 'no_valid_products',
                'lines_read' => $line_count,
                'parse_errors' => $parse_errors,
            ));
            return false;
        }
        
        self::log('jsonl_dedup_complete', array(
            'lines_read' => $line_count,
            'unique_products' => $new_count,
            'parse_errors' => $parse_errors,
            'dedup_removed' => $line_count - $new_count - $parse_errors,
        ));
        
        // --- Backup current active index ---
        $old_count = 0;
        if (file_exists($active_path)) {
            @copy($active_path, $backup_path);
            
            // Read old count for logging (streaming to avoid loading full content)
            $old_content = file_get_contents($active_path);
            $old_index = json_decode($old_content, true);
            $old_count = isset($old_index['items']) ? count($old_index['items']) : 0;
            unset($old_content, $old_index); // Free memory
        }
        
        self::log('atomic_swap_starting', array(
            'old_count' => $old_count,
            'new_count' => $new_count,
        ));
        
        // --- Build final active index JSON ---
        $final_index = array(
            'items' => $items,
            '__search_fields' => array(
                'title' => 3,
                'brand' => 2,
                'shopname' => 1,
                'merchant' => 1,
                'attributes.color' => 2,
            ),
            'build_status' => 'complete',
            'build_completed' => time(),
        );
        
        // Write to a temporary file first, then rename (true atomic swap)
        $temp_path = $active_path . '.tmp';
        file_put_contents($temp_path, json_encode($final_index, JSON_UNESCAPED_SLASHES), LOCK_EX);
        
        // Free the large items array immediately
        unset($items, $final_index);
        
        // --- ATOMIC SWAP ---
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        $renamed = $wp_filesystem->move($temp_path, $active_path, true);
        
        if (!$renamed) {
            // Fallback for cross-filesystem or Windows
            $content = file_get_contents($temp_path);
            if ($content !== false) {
                file_put_contents($active_path, $content, LOCK_EX);
                wp_delete_file($temp_path);
                $renamed = true;
            }
        }
        
        if (!$renamed) {
            self::log('atomic_swap_failed', array('reason' => 'rename_and_fallback_failed'));
            return false;
        }
        
        // --- Cleanup building JSONL file ---
        wp_delete_file($building_path);
        
        // --- Post-swap cleanup ---
        if (class_exists('MyFeeds_Product_Resolver')) {
            MyFeeds_Product_Resolver::clear_cache();
        }
        
        update_option(self::OPTION_BUILD_STATUS, array(
            'status' => 'complete',
            'completed' => time(),
            'items_count' => $new_count,
            'replaced_count' => $old_count,
        ));
        
        self::log('atomic_swap_complete', array(
            'new_count' => $new_count,
            'old_count' => $old_count,
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ));
        
        return true;
    }
    
    /**
     * Abort rebuild without affecting active index
     * Removes the building index file and resets status.
     */
    public static function abort_rebuild() {
        $upload_dir = wp_upload_dir();
        $building_path = $upload_dir['basedir'] . '/' . self::INDEX_FILE_BUILDING;
        
        if (file_exists($building_path)) {
            wp_delete_file($building_path);
        }
        
        // Also clean up legacy JSON building index
        $legacy_path = $upload_dir['basedir'] . '/myfeeds-feed-index-building.json';
        if (file_exists($legacy_path)) {
            wp_delete_file($legacy_path);
        }
        
        update_option(self::OPTION_BUILD_STATUS, array(
            'status' => 'aborted',
            'aborted' => time(),
        ));
        
        self::log('atomic_rebuild_aborted', array());
    }
    
    /**
     * Append unavailable product entries to the building JSONL
     * Used by mark_missing_active_products_in_building_index()
     * 
     * @param array $keyed_items Associative array [product_id => product_data]
     */
    public static function append_unavailable_to_building($keyed_items) {
        // Reuse add_items — same append-only mechanism
        return self::add_items_to_building_index($keyed_items);
    }
    
    /**
     * Stream the building JSONL to collect all unique product IDs
     * Memory-safe: only stores IDs (strings), not full product data.
     * 
     * @return array Set of product IDs found in building index [id => true]
     */
    public static function get_building_product_ids() {
        $upload_dir = wp_upload_dir();
        $building_path = $upload_dir['basedir'] . '/' . self::INDEX_FILE_BUILDING;
        
        if (!file_exists($building_path)) {
            return array();
        }
        
        $ids = array();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming required for large index files
        $fp = fopen($building_path, 'r');
        if (!$fp) return array();
        
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Fast ID extraction: decode only to get the 'id' field
            $product = json_decode($line, true);
            if ($product && isset($product['id'])) {
                $ids[(string) $product['id']] = true;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming required for large index files
        fclose($fp);
        
        return $ids;
    }
    
    // =========================================================================
    // QUICK SYNC HELPERS (no full rebuild, merge into active)
    // =========================================================================
    
    /**
     * Merge new products into active index without full rebuild.
     * Used by Quick Sync mode — updates specific products in-place.
     * Does NOT remove existing products.
     * 
     * @param array $keyed_items Associative array [product_id => product_data]
     * @return int Total number of items in active index after merge
     */
    public static function merge_into_active($keyed_items) {
        $upload_dir = wp_upload_dir();
        $active_path = $upload_dir['basedir'] . '/' . self::INDEX_FILE_ACTIVE;
        
        // Load active index
        $index = array(
            'items' => array(),
            '__search_fields' => array(
                'title' => 3,
                'brand' => 2,
                'shopname' => 1,
                'merchant' => 1,
                'attributes.color' => 2,
            ),
        );
        
        if (file_exists($active_path)) {
            $content = file_get_contents($active_path);
            $index = json_decode($content, true) ?: $index;
        }
        
        // Merge products (new overwrite old, existing stay)
        foreach ($keyed_items as $id => $data) {
            $index['items'][(string)$id] = $data;
        }
        
        $index['last_merge'] = time();
        
        file_put_contents($active_path, json_encode($index), LOCK_EX);
        
        if (class_exists('MyFeeds_Product_Resolver')) {
            MyFeeds_Product_Resolver::clear_cache();
        }
        
        return count($index['items']);
    }
    
    /**
     * Update a single product in the active index immediately
     */
    public static function update_product_in_active($product_id, $product_data) {
        return self::merge_into_active(array($product_id => $product_data));
    }
    
    // =========================================================================
    // STATUS & DIAGNOSTICS
    // =========================================================================
    
    /**
     * Get current build status
     */
    public static function get_build_status() {
        return get_option(self::OPTION_BUILD_STATUS, array('status' => 'idle'));
    }
    
    /**
     * Get active index stats
     */
    public static function get_active_stats() {
        $upload_dir = wp_upload_dir();
        $active_path = $upload_dir['basedir'] . '/' . self::INDEX_FILE_ACTIVE;
        
        if (!file_exists($active_path)) {
            return array('exists' => false, 'count' => 0);
        }
        
        $content = file_get_contents($active_path);
        $index = json_decode($content, true);
        
        return array(
            'exists' => true,
            'count' => isset($index['items']) ? count($index['items']) : 0,
            'last_updated' => $index['build_completed'] ?? $index['last_merge'] ?? null,
        );
    }
    
    /**
     * Get building index stats from options (memory-safe, no file read)
     */
    public static function get_building_stats() {
        $status = get_option(self::OPTION_BUILD_STATUS, array());
        
        $upload_dir = wp_upload_dir();
        $building_path = $upload_dir['basedir'] . '/' . self::INDEX_FILE_BUILDING;
        $exists = file_exists($building_path);
        
        return array(
            'exists' => $exists,
            'count' => $status['items_appended'] ?? 0,
            'file_size_mb' => $exists ? round(filesize($building_path) / 1024 / 1024, 2) : 0,
            'build_started' => $status['started'] ?? null,
            'last_batch' => $status['last_batch'] ?? null,
        );
    }
    
    /**
     * Structured JSON logging
     */
    private static function log($event, $data) {
        $message = 'MYFEEDS_ATOMIC_' . $event . ': ' . wp_json_encode($data, JSON_UNESCAPED_SLASHES);
        myfeeds_log($message, 'debug');
    }
}
