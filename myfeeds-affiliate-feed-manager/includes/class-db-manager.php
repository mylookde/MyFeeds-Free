<?php
/**
 * MyFeeds Database Manager
 * 
 * Manages the wp_myfeeds_products custom table.
 * Replaces the JSON/JSONL file-based index with a proper database table.
 * 
 * Feature Flag: get_option('myfeeds_use_db', false)
 *   - false (default): Plugin uses JSON files as before
 *   - true: Plugin uses this DB table for all product storage
 * 
 * Table: {$wpdb->prefix}myfeeds_products
 * 
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_DB_Manager {

    /**
     * Get the full table name with prefix
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'myfeeds_products';
    }

    /**
     * DB mode is now the ONLY mode. JSON mode toggle has been removed.
     * Method signature kept for backward compatibility with code paths
     * that check is_db_mode() — they will always get true now.
     */
    public static function is_db_mode() {
        return true;
    }

    /**
     * Enable DB mode (no-op, DB mode is always on)
     */
    public static function enable_db_mode() {
        update_option('myfeeds_use_db', true);
    }

    /**
     * Disable DB mode — kept as no-op for safety.
     * JSON mode is no longer available via UI.
     */
    public static function disable_db_mode() {
        // No-op: DB mode is the only mode now
    }

    // =========================================================================
    // TABLE CREATION & MIGRATION
    // =========================================================================

    /**
     * Create the products table using dbDelta()
     * Safe to call multiple times — dbDelta handles existing tables gracefully.
     */
    public static function create_table() {
        global $wpdb;

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            external_id VARCHAR(100) NOT NULL,
            feed_id INT NOT NULL,
            feed_name VARCHAR(255),
            product_name VARCHAR(500),
            price DECIMAL(10,2),
            original_price DECIMAL(10,2),
            currency VARCHAR(10),
            image_url TEXT,
            affiliate_link TEXT,
            brand VARCHAR(200),
            category VARCHAR(200),
            colour VARCHAR(200) DEFAULT NULL,
            in_stock TINYINT(1) DEFAULT 1,
            status VARCHAR(20) DEFAULT 'active',
            last_updated DATETIME,
            raw_data LONGTEXT,
            search_text TEXT DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY external_feed (external_id, feed_id),
            KEY idx_external_id (external_id),
            KEY idx_status (status),
            KEY idx_brand (brand),
            KEY idx_last_updated (last_updated),
            KEY idx_colour (colour),
            KEY idx_name_colour (product_name(100), colour(100)),
            KEY idx_feed_status (feed_id, status),
            KEY idx_feed_name_status (feed_name, status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // dbDelta can't create FULLTEXT indexes – add manually
        $ft_exists = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name = 'ft_search'");
        if (!$ft_exists) {
            $wpdb->query("ALTER TABLE {$table} ADD FULLTEXT KEY ft_search (search_text)");
            myfeeds_log("FULLTEXT index ft_search created on {$table}", 'info');
        }

        self::log('table_created', array('table' => $table));
    }

    /**
     * Check if the products table exists
     */
    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }

    /**
     * Get table row count
     */
    public static function get_product_count() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Get active product count
     */
    public static function get_active_product_count() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active'");
    }

    // =========================================================================
    // STABLE FEED ID SYSTEM
    // Each feed gets a permanent, auto-increment ID that never changes.
    // =========================================================================

    /**
     * Migrate existing feeds to use stable_id.
     * Assigns stable_ids, updates DB products, deletes orphans.
     */
    public static function migrate_to_stable_feed_ids() {
        global $wpdb;
        $table = self::table_name();

        $feeds = get_option('myfeeds_feeds', array());
        if (empty($feeds)) return;

        $next_id = (int) get_option('myfeeds_next_feed_id', 1);
        $updated_any = false;

        foreach ($feeds as $key => &$feed) {
            if (empty($feed['stable_id'])) {
                $feed['stable_id'] = $next_id;
                $next_id++;
                $updated_any = true;
            }

            // Update all products with this feed_name to use the stable_id
            $name = $feed['name'] ?? '';
            if (!empty($name)) {
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET feed_id = %d WHERE feed_name = %s",
                    (int) $feed['stable_id'], $name
                ));
                myfeeds_log("MIGRATION: Feed '{$name}' gets stable_id={$feed['stable_id']}, updated {$updated} products", 'info');
            }
        }
        unset($feed);

        if ($updated_any) {
            update_option('myfeeds_feeds', $feeds);
            update_option('myfeeds_next_feed_id', $next_id);
        }

        // Delete orphan products (feed_name not in any configured feed)
        $configured_names = array_filter(array_column($feeds, 'name'));
        if (!empty($configured_names)) {
            $deleted = self::delete_orphaned_products($configured_names);
            myfeeds_log("MIGRATION: Deleted {$deleted} orphan products (feed_name not in configured feeds)", 'info');
        }

        update_option('myfeeds_stable_id_migrated', true);
        myfeeds_log("MIGRATION: Stable feed_id migration complete. Next ID = {$next_id}", 'info');
    }

    /**
     * Get the stable_id for a feed by its array key.
     * Returns 0 if feed not found or no stable_id assigned.
     */
    public static function get_stable_id($feed_key) {
        $feeds = get_option('myfeeds_feeds', array());
        if (isset($feeds[$feed_key]) && !empty($feeds[$feed_key]['stable_id'])) {
            return (int) $feeds[$feed_key]['stable_id'];
        }
        return 0;
    }

    /**
     * Assign a stable_id to a feed if it doesn't have one.
     * Returns the stable_id.
     */
    public static function assign_stable_id(&$feed) {
        if (empty($feed['stable_id'])) {
            $next_id = (int) get_option('myfeeds_next_feed_id', 1);
            $feed['stable_id'] = $next_id;
            update_option('myfeeds_next_feed_id', $next_id + 1);
        }
        return (int) $feed['stable_id'];
    }

    /**
     * Get active product counts per feed_name.
     * Returns associative array: ['Feed Name' => count, ...]
     */
    public static function get_feed_counts() {
        global $wpdb;
        $table = self::table_name();
        
        // Fix 4 (Phase 12.8): Only count products belonging to currently configured feeds
        $configured_feeds = get_option('myfeeds_feeds', array());
        $configured_names = array_filter(array_column($configured_feeds, 'name'));
        
        if (empty($configured_names)) {
            return array();
        }
        
        $name_placeholders = implode(',', array_fill(0, count($configured_names), '%s'));
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT feed_name, COUNT(*) as cnt FROM {$table} WHERE status = 'active' AND feed_name IN ({$name_placeholders}) GROUP BY feed_name",
            ...$configured_names
        ), ARRAY_A);
        
        $counts = array();
        foreach ($results as $row) {
            $counts[$row['feed_name']] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Get active product count for a specific feed by feed_id.
     *
     * @param int $feed_id The feed's key/index (stored as feed_id in DB)
     * @return int Number of active products for this feed
     */
    public static function get_feed_product_count($feed_id) {
        global $wpdb;
        $table = self::table_name();
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE feed_id = %d AND status = 'active'",
            intval($feed_id)
        ));
    }

    /**
     * Delete all products belonging to a specific feed — by both feed_id AND feed_name.
     * Uses both criteria to catch products from the feed_id bug (Phase 12.7 Fix 2).
     * Returns the number of deleted rows.
     */
    public static function delete_products_by_feed_id($feed_id, $feed_name = '') {
        global $wpdb;
        $table = self::table_name();
        
        // DIAG LOG 7: Log feed deletion with product count before delete
        if (!empty($feed_name)) {
            $count_before = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE feed_name = %s", $feed_name));
        } else {
            $count_before = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE feed_id = %d", intval($feed_id)));
        }
        myfeeds_log("DIAG feed_delete: feed_id={$feed_id}, feed_name={$feed_name}, products_found={$count_before}", 'info');
        
        if (!empty($feed_name)) {
            // Delete by feed_name (catches all products regardless of wrong feed_id)
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE feed_name = %s",
                $feed_name
            ));
        } else {
            // Fallback: delete by feed_id only
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE feed_id = %d",
                intval($feed_id)
            ));
        }
        
        // Clear count caches
        delete_transient('myfeeds_active_count_cache');
        delete_transient('myfeeds_feed_counts_cache');
        
        myfeeds_log("DELETE_FEED_PRODUCTS: feed_id={$feed_id}, feed_name={$feed_name}, deleted_rows={$deleted}", 'info');
        return (int) $deleted;
    }

    /**
     * Cleanup orphaned products whose feed_id does not match any configured feed's stable_id.
     * Safe: Does nothing if no feeds are configured (prevents accidental full wipe).
     * Returns number of deleted rows.
     */
    public static function cleanup_orphaned_products() {
        global $wpdb;
        $table = self::table_name();
        
        if (!self::table_exists()) {
            return 0;
        }
        
        $feeds = get_option('myfeeds_feeds', array());
        if (empty($feeds)) {
            myfeeds_log("DB Cleanup: Skipped — no feeds configured (safety check)", 'info');
            return 0;
        }
        
        $valid_ids = array();
        foreach ($feeds as $f) {
            $sid = intval($f['stable_id'] ?? 0);
            if ($sid > 0) {
                $valid_ids[] = $sid;
            }
        }
        
        if (empty($valid_ids)) {
            myfeeds_log("DB Cleanup: Skipped — no valid stable_ids found (safety check)", 'info');
            return 0;
        }
        
        $placeholders = implode(',', array_fill(0, count($valid_ids), '%d'));
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE feed_id NOT IN ({$placeholders})",
            ...$valid_ids
        ));
        
        // Clear count caches
        delete_transient('myfeeds_active_count_cache');
        delete_transient('myfeeds_feed_counts_cache');
        
        if ($deleted > 0) {
            myfeeds_log("DB Cleanup: Deleted {$deleted} orphaned products with invalid feed_ids (valid ids: " . implode(',', $valid_ids) . ")", 'info');
        } else {
            myfeeds_log("DB Cleanup: No orphaned products found (valid ids: " . implode(',', $valid_ids) . ")", 'info');
        }
        
        return (int) $deleted;
    }

    /**
     * Find orphaned products that belong to feed_ids NOT in the given list of configured feed keys,
     * OR whose feed_name does not match any configured feed name.
     * Returns array with 'count', 'by_feed_name', 'by_feed_id' for preview.
     */
    public static function find_orphaned_products($configured_feed_keys, $configured_feed_names) {
        global $wpdb;
        $table = self::table_name();
        
        // Strategy: products are orphaned if their feed_name is NOT in the configured list
        // This catches both wrong feed_ids AND deleted feeds
        if (empty($configured_feed_names)) {
            // No feeds configured — everything is orphaned
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            return array('count' => $total, 'by_feed_name' => array(), 'by_feed_id' => array());
        }
        
        $name_placeholders = implode(',', array_fill(0, count($configured_feed_names), '%s'));
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT feed_name, feed_id, COUNT(*) as cnt FROM {$table} 
             WHERE feed_name NOT IN ({$name_placeholders})
             GROUP BY feed_name, feed_id ORDER BY cnt DESC",
            ...$configured_feed_names
        ), ARRAY_A);
        
        $total = 0;
        $by_feed_name = array();
        $by_feed_id = array();
        foreach ($results as $row) {
            $total += (int) $row['cnt'];
            $name = $row['feed_name'] ?: '(empty)';
            $by_feed_name[$name] = ($by_feed_name[$name] ?? 0) + (int) $row['cnt'];
            $by_feed_id[$row['feed_id']] = ($by_feed_id[$row['feed_id']] ?? 0) + (int) $row['cnt'];
        }
        
        return array('count' => $total, 'by_feed_name' => $by_feed_name, 'by_feed_id' => $by_feed_id);
    }
    
    /**
     * Delete all orphaned products whose feed_name is NOT in the configured list.
     * Returns number of deleted rows.
     */
    public static function delete_orphaned_products($configured_feed_names) {
        global $wpdb;
        $table = self::table_name();
        
        // DIAG LOG 4: Show what's being kept and what will be deleted
        if (!empty($configured_feed_names)) {
            $name_placeholders_diag = implode(',', array_fill(0, count($configured_feed_names), '%s'));
            $to_delete_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE feed_name NOT IN ({$name_placeholders_diag})",
                ...$configured_feed_names
            ));
            $deleting_names = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT feed_name FROM {$table} WHERE feed_name NOT IN ({$name_placeholders_diag})",
                ...$configured_feed_names
            ));
            myfeeds_log("DIAG orphan_delete: keeping_feed_names=[" . implode(', ', $configured_feed_names) . "], deleting_feed_names=[" . implode(', ', $deleting_names) . "], products_to_delete={$to_delete_count}", 'info');
        } else {
            $to_delete_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            myfeeds_log("DIAG orphan_delete: keeping_feed_names=[], deleting ALL, products_to_delete={$to_delete_count}", 'info');
        }
        
        if (empty($configured_feed_names)) {
            $deleted = $wpdb->query("DELETE FROM {$table}");
        } else {
            $name_placeholders = implode(',', array_fill(0, count($configured_feed_names), '%s'));
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE feed_name NOT IN ({$name_placeholders})",
                ...$configured_feed_names
            ));
        }
        
        // Clear caches
        delete_transient('myfeeds_active_count_cache');
        delete_transient('myfeeds_feed_counts_cache');
        
        myfeeds_log("DELETE_ORPHANED_PRODUCTS: deleted={$deleted}, configured_feeds=" . implode(', ', $configured_feed_names), 'info');
        return (int) $deleted;
    }

    /**
     * Get cached active product count (DB query max every 10 seconds).
     * Uses a transient to avoid hammering the DB during polling.
     */
    public static function get_active_product_count_cached() {
        $cached = get_transient('myfeeds_active_count_cache');
        if ($cached !== false) {
            return (int) $cached;
        }
        $count = self::get_active_product_count();
        set_transient('myfeeds_active_count_cache', $count, 10);
        return $count;
    }

    /**
     * Get cached feed counts (DB query max every 10 seconds).
     */
    public static function get_feed_counts_cached() {
        $cached = get_transient('myfeeds_feed_counts_cache');
        if ($cached !== false) {
            return $cached;
        }
        $counts = self::get_feed_counts();
        set_transient('myfeeds_feed_counts_cache', $counts, 10);
        return $counts;
    }

    /**
     * Calculate mapping quality for a feed based on NORMALIZED DB column completeness.
     * 
     * ARCHITECTURE PRINCIPLE: This method is PLATFORM-AGNOSTIC.
     * It knows NOTHING about AWIN, Amazon, CJ, or any other platform.
     * It ONLY checks the normalized columns in wp_myfeeds_products.
     * If a column is empty, it's a mapper problem — NOT a quality calculation problem.
     * 
     * Field tiers:
     *   Required:  product_name, price, image_url, affiliate_link
     *   Important: brand, original_price
     *   Optional:  category, currency, in_stock
     * 
     * Quality % = products with ALL Required fields filled / total * 100
     * 
     * @param string $feed_name Feed name
     * @return array ['quality' => int, 'total' => int, 'complete' => int, 'fields' => [...]]
     */
    public static function calculate_mapping_quality($feed_name) {
        global $wpdb;
        $table = self::table_name();
        
        // Single aggregated query replaces 10 separate COUNT(*) calls. One full table
        // scan (bounded by the feed_name + status filter) returns every counter we need,
        // so the mapping-quality card stops costing ~10 round trips per feed.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN product_name IS NOT NULL AND product_name != ''
                          AND price IS NOT NULL AND price > 0
                          AND image_url IS NOT NULL AND image_url != ''
                          AND affiliate_link IS NOT NULL AND affiliate_link != ''
                         THEN 1 ELSE 0 END) AS complete,
                SUM(CASE WHEN product_name IS NULL OR product_name = '' THEN 1 ELSE 0 END) AS missing_product_name,
                SUM(CASE WHEN price IS NULL OR price = 0 THEN 1 ELSE 0 END) AS missing_price,
                SUM(CASE WHEN image_url IS NULL OR image_url = '' THEN 1 ELSE 0 END) AS missing_image,
                SUM(CASE WHEN affiliate_link IS NULL OR affiliate_link = '' THEN 1 ELSE 0 END) AS missing_link,
                SUM(CASE WHEN brand IS NULL OR brand = '' THEN 1 ELSE 0 END) AS missing_brand,
                SUM(CASE WHEN original_price IS NULL OR original_price = 0 THEN 1 ELSE 0 END) AS missing_original_price,
                SUM(CASE WHEN category IS NULL OR category = '' THEN 1 ELSE 0 END) AS missing_category,
                SUM(CASE WHEN currency IS NULL OR currency = '' THEN 1 ELSE 0 END) AS missing_currency,
                SUM(CASE WHEN in_stock IS NULL THEN 1 ELSE 0 END) AS missing_in_stock
             FROM {$table} WHERE feed_name = %s AND status = 'active'",
            $feed_name
        ), ARRAY_A);

        $total = (int) ($row['total'] ?? 0);
        if ($total === 0) {
            return array('quality' => 0, 'total' => 0, 'complete' => 0, 'fields' => array());
        }

        // QUALITY_DEBUG: Log a sample product so the user can see exactly where data lives
        self::log_quality_debug_sample($feed_name, $table);

        $complete               = (int) $row['complete'];
        $missing_product_name   = (int) $row['missing_product_name'];
        $missing_price          = (int) $row['missing_price'];
        $missing_image          = (int) $row['missing_image'];
        $missing_link           = (int) $row['missing_link'];
        $missing_brand          = (int) $row['missing_brand'];
        $missing_original_price = (int) $row['missing_original_price'];
        $missing_category       = (int) $row['missing_category'];
        $missing_currency       = (int) $row['missing_currency'];
        $missing_in_stock       = (int) $row['missing_in_stock'];

        $quality = round(($complete / $total) * 100);
        
        return array(
            'quality' => $quality,
            'total' => $total,
            'complete' => $complete,
            'fields' => array(
                // Required
                'product_name'   => array('tier' => 'required',  'missing' => $missing_product_name),
                'price'          => array('tier' => 'required',  'missing' => $missing_price),
                'image_url'      => array('tier' => 'required',  'missing' => $missing_image),
                'affiliate_link' => array('tier' => 'required',  'missing' => $missing_link),
                // Important
                'brand'          => array('tier' => 'important', 'missing' => $missing_brand),
                'original_price' => array('tier' => 'important', 'missing' => $missing_original_price),
                // Optional
                'category'       => array('tier' => 'optional',  'missing' => $missing_category),
                'currency'       => array('tier' => 'optional',  'missing' => $missing_currency),
                'in_stock'       => array('tier' => 'optional',  'missing' => $missing_in_stock),
            ),
        );
    }

    /**
     * QUALITY_DEBUG: Log a sample product from the feed showing exact column values
     * and raw_data keys. Helps diagnose mapper vs. quality-calc issues.
     * 
     * @param string $feed_name Feed name
     * @param string $table Full table name
     */
    private static function log_quality_debug_sample($feed_name, $table) {
        global $wpdb;

        // $table is built from $wpdb->prefix + a constant string, not user input.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sample = $wpdb->get_row($wpdb->prepare(
            "SELECT external_id, product_name, price, original_price, image_url, affiliate_link,
                    brand, category, currency, in_stock, raw_data
             FROM {$table}
             WHERE feed_name = %s AND status = 'active'
             LIMIT 1",
            $feed_name
        ), ARRAY_A);
        
        if (!$sample) {
        myfeeds_log('QUALITY_DEBUG: feed=' . $feed_name . ', NO SAMPLE FOUND', 'debug');
            return;
        }
        
        $raw_keys = array();
        if (!empty($sample['raw_data'])) {
            $raw = json_decode($sample['raw_data'], true);
            if (is_array($raw)) {
                $raw_keys = array_keys($raw);
            }
        }
        
        myfeeds_log('QUALITY_DEBUG: feed=' . $feed_name
            . ', sample_product_id=' . ($sample['external_id'] ?? 'NULL')
            . ', product_name=' . mb_substr($sample['product_name'] ?? 'NULL', 0, 50)
            . ', brand_column=' . ($sample['brand'] !== null && $sample['brand'] !== '' ? $sample['brand'] : '(EMPTY)')
            . ', original_price_column=' . ($sample['original_price'] !== null && floatval($sample['original_price']) > 0 ? $sample['original_price'] : '(ZERO/NULL)')
            . ', price_column=' . ($sample['price'] ?? 'NULL')
            . ', category_column=' . ($sample['category'] !== null && $sample['category'] !== '' ? $sample['category'] : '(EMPTY)')
            . ', currency_column=' . ($sample['currency'] ?? 'NULL')
            . ', raw_data_keys=[' . implode(', ', $raw_keys) . ']'
        , 'debug');
    }

    /**
     * Get top N products with the most missing fields for a feed.
     * Platform-agnostic: only checks normalized DB columns.
     * 
     * @param string $feed_name Feed name
     * @param int $limit Number of products to return
     * @return array List of products with missing field info
     */
    public static function get_worst_mapped_products($feed_name, $limit = 3) {
        global $wpdb;
        $table = self::table_name();
        
        // Score: count of empty normalized columns (Required weighted 2x, Important 1x, Optional 0.5x)
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT external_id, product_name, price, image_url, affiliate_link, brand, original_price, category, currency, in_stock
             FROM {$table}
             WHERE feed_name = %s AND status = 'active'
             ORDER BY (
                (CASE WHEN product_name IS NULL OR product_name = '' THEN 2 ELSE 0 END) +
                (CASE WHEN price IS NULL OR price = 0 THEN 2 ELSE 0 END) +
                (CASE WHEN image_url IS NULL OR image_url = '' THEN 2 ELSE 0 END) +
                (CASE WHEN affiliate_link IS NULL OR affiliate_link = '' THEN 2 ELSE 0 END) +
                (CASE WHEN brand IS NULL OR brand = '' THEN 1 ELSE 0 END) +
                (CASE WHEN original_price IS NULL OR original_price = 0 THEN 1 ELSE 0 END) +
                (CASE WHEN category IS NULL OR category = '' THEN 1 ELSE 0 END)
             ) DESC
             LIMIT %d",
            $feed_name,
            $limit
        ), ARRAY_A);
        
        $products = array();
        foreach ($results as $row) {
            $missing = array();
            if (empty($row['product_name']))                             $missing[] = 'product_name';
            if ($row['price'] === null || floatval($row['price']) == 0)  $missing[] = 'price';
            if (empty($row['image_url']))                                $missing[] = 'image_url';
            if (empty($row['affiliate_link']))                           $missing[] = 'affiliate_link';
            if (empty($row['brand']))                                    $missing[] = 'brand';
            if ($row['original_price'] === null || floatval($row['original_price']) == 0) $missing[] = 'original_price';
            if (empty($row['category']))                                 $missing[] = 'category';
            
            $products[] = array(
                'external_id' => $row['external_id'],
                'product_name' => $row['product_name'] ?: '(empty)',
                'missing_fields' => $missing,
                'missing_count' => count($missing),
            );
        }
        
        return $products;
    }

    // =========================================================================
    // SINGLE PRODUCT OPERATIONS
    // =========================================================================

    /**
     * Resolve a single product by external_id
     * Returns the product as an associative array matching the old JSON format,
     * or null if not found.
     * 
     * The returned array always contains 'id' matching external_id — this is
     * the key invariant for Gutenberg block compatibility.
     *
     * @param string $external_id The product ID (same as selectedProducts[x]['id'] in blocks)
     * @return array|null Product data array or null
     */
    public static function get_product($external_id) {
        global $wpdb;
        $table = self::table_name();
        $external_id = (string) $external_id;

        // Debug: Log what we're searching for
        self::log('get_product_query', array(
            'external_id' => $external_id,
            'table' => $table,
            'id_length' => strlen($external_id),
            'id_hex' => bin2hex(substr($external_id, 0, 20)),
        ), 'debug');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE external_id = %s AND status = 'active' LIMIT 1",
            $external_id
        ), ARRAY_A);

        if (!$row) {
            // Also check unavailable products (for placeholder rendering)
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE external_id = %s LIMIT 1",
                $external_id
            ), ARRAY_A);
            
            if (!$row) {
                // Debug: check if ANY row exists with similar ID
                $like_check = $wpdb->get_var($wpdb->prepare(
                    "SELECT external_id FROM {$table} WHERE external_id LIKE %s LIMIT 1",
                    '%' . $wpdb->esc_like(substr($external_id, 0, 10)) . '%'
                ));
                self::log('get_product_not_found', array(
                    'searched_id' => $external_id,
                    'similar_id_in_db' => $like_check ?: 'NONE',
                    'total_rows' => $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
                    'sample_ids' => $wpdb->get_col("SELECT external_id FROM {$table} LIMIT 5"),
                    'wpdb_error' => $wpdb->last_error,
                ));
                return null;
            } else {
                self::log('get_product_found_non_active', array(
                    'external_id' => $external_id,
                    'status' => $row['status'],
                ));
            }
        }

        return self::row_to_product($row);
    }

    /**
     * Get multiple products by external_ids
     * 
     * @param array $external_ids Array of product IDs
     * @return array Associative array [external_id => product_data]
     */
    public static function get_products($external_ids) {
        global $wpdb;
        $table = self::table_name();

        if (empty($external_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($external_ids), '%s'));
        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE external_id IN ({$placeholders})",
            ...$external_ids
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query built via $wpdb->prepare() above
        $rows = $wpdb->get_results($query, ARRAY_A);
        $products = array();

        foreach ($rows as $row) {
            $products[$row['external_id']] = self::row_to_product($row);
        }

        return $products;
    }

    // =========================================================================
    // SEARCH
    // =========================================================================

    /**
     * Search products by query string with size-variant deduplication.
     * 
     * Searches in product_name, brand, and category fields.
     * Deduplication logic:
     *   - Products with same product_name AND same colour (non-empty): show ONE (first by id)
     *   - Products with same name but DIFFERENT colour: separate results (different images)
     *   - Products where colour IS NULL or empty: NO dedup, show each individually
     * 
     * @param string $query Search query
     * @param int $limit Max results (default 50)
     * @return array Array of product data arrays
     */
    public static function search_products($query, $limit = 50, $offset = 0) {
        // Phase 16.1: Delegate to FULLTEXT-based search engine
        if (class_exists('MyFeeds_Search_Engine')) {
            return MyFeeds_Search_Engine::search($query, $limit, $offset);
        }

        // Fallback: old LIKE search (should never happen if class-search-engine.php is loaded)
        global $wpdb;
        $table = self::table_name();

        $like = '%' . $wpdb->esc_like($query) . '%';

        // Check if colour column exists (handles pre-migration state)
        $has_colour = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'colour'");

        if ($has_colour) {
            // Deduplicated search: merge size variants by product_name + colour
            $sql = "SELECT p.* FROM {$table} p
                INNER JOIN (
                    SELECT MIN(id) as id FROM {$table}
                    WHERE status = 'active' AND colour IS NOT NULL AND colour != ''
                    AND (product_name LIKE %s OR brand LIKE %s OR category LIKE %s)
                    GROUP BY product_name, colour
                    UNION ALL
                    SELECT id FROM {$table}
                    WHERE status = 'active' AND (colour IS NULL OR colour = '')
                    AND (product_name LIKE %s OR brand LIKE %s OR category LIKE %s)
                ) AS dedup ON p.id = dedup.id
                ORDER BY p.product_name
                LIMIT %d";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic query with $wpdb->prepare(), table name is internal constant
            $rows = $wpdb->get_results($wpdb->prepare($sql, $like, $like, $like, $like, $like, $like, $limit), ARRAY_A);
        } else {
            // Fallback: no dedup, but with multi-field search (category added)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic query with safe internal values
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} 
                 WHERE status = 'active'
                 AND (product_name LIKE %s OR brand LIKE %s OR category LIKE %s)
                 ORDER BY product_name
                 LIMIT %d",
                $like, $like, $like, $limit
            ), ARRAY_A);
        }

        $products = array();
        foreach ($rows as $row) {
            $product = self::row_to_product($row);
            $products[$product['id']] = $product;
        }

        return $products;
    }

    /**
     * Get all available sizes for a product_name + colour combination.
     * Used in the detail view to show all size variants of a deduplicated result.
     * 
     * @param string $product_name Exact product name
     * @param string $colour Colour value (can be empty)
     * @return array List of size strings
     */
    public static function get_available_sizes($product_name, $colour = '') {
        global $wpdb;
        $table = self::table_name();

        if (!empty($colour)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT raw_data FROM {$table} WHERE product_name = %s AND colour = %s AND status = 'active'",
                $product_name, $colour
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT raw_data FROM {$table} WHERE product_name = %s AND status = 'active' AND (colour IS NULL OR colour = '')",
                $product_name
            ), ARRAY_A);
        }

        $sizes = array();
        foreach ($rows as $row) {
            if (empty($row['raw_data'])) continue;
            $raw = json_decode($row['raw_data'], true);
            if (!is_array($raw)) continue;

            $size = '';
            // Check direct size field
            if (!empty($raw['size'])) {
                $size = is_array($raw['size']) ? ($raw['size'][0] ?? '') : (string) $raw['size'];
            }
            // Check attributes.size
            if (empty($size) && isset($raw['attributes']['size'])) {
                $s = $raw['attributes']['size'];
                $size = is_array($s) ? ($s[0] ?? '') : (string) $s;
            }
            // Check Fashion:size (AWIN)
            if (empty($size) && !empty($raw['Fashion:size'])) {
                $size = (string) $raw['Fashion:size'];
            }

            if (!empty($size) && !in_array($size, $sizes, true)) {
                $sizes[] = $size;
            }
        }

        // Sort sizes logically (numbers first, then clothing sizes)
        usort($sizes, function($a, $b) {
            $a_num = is_numeric($a) ? floatval($a) : null;
            $b_num = is_numeric($b) ? floatval($b) : null;
            if ($a_num !== null && $b_num !== null) return $a_num - $b_num;
            if ($a_num !== null) return -1;
            if ($b_num !== null) return 1;
            $order = array('XXS' => 0, 'XS' => 1, 'S' => 2, 'M' => 3, 'L' => 4, 'XL' => 5, 'XXL' => 6, 'XXXL' => 7);
            $a_idx = $order[strtoupper($a)] ?? 99;
            $b_idx = $order[strtoupper($b)] ?? 99;
            if ($a_idx !== 99 || $b_idx !== 99) return $a_idx - $b_idx;
            return strcmp($a, $b);
        });

        return $sizes;
    }

    // =========================================================================
    // WRITE OPERATIONS (Import)
    // =========================================================================

    /**
     * Upsert a single product (INSERT ... ON DUPLICATE KEY UPDATE)
     * 
     * @param array $product Product data with 'id' as external_id
     * @param int $feed_id Feed identifier (numeric index)
     * @param string $feed_name Feed name for display
     * @return bool Success
     */
    public static function upsert_product($product, $feed_id, $feed_name = '') {
        global $wpdb;
        $table = self::table_name();

        $external_id = (string) ($product['id'] ?? '');
        if (empty($external_id)) {
            return false;
        }

        $db_row = self::product_to_row($product, $feed_id, $feed_name);

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} 
                (external_id, feed_id, feed_name, product_name, price, original_price, 
                 currency, image_url, affiliate_link, brand, category, colour, in_stock, status, 
                 last_updated, raw_data, search_text)
             VALUES (%s, %d, %s, %s, %f, %f, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                feed_name = VALUES(feed_name),
                product_name = VALUES(product_name),
                price = VALUES(price),
                original_price = VALUES(original_price),
                currency = VALUES(currency),
                image_url = VALUES(image_url),
                affiliate_link = VALUES(affiliate_link),
                brand = VALUES(brand),
                category = VALUES(category),
                colour = VALUES(colour),
                in_stock = VALUES(in_stock),
                status = VALUES(status),
                last_updated = VALUES(last_updated),
                raw_data = VALUES(raw_data),
                search_text = VALUES(search_text)",
            $db_row['external_id'],
            $db_row['feed_id'],
            $db_row['feed_name'],
            $db_row['product_name'],
            $db_row['price'],
            $db_row['original_price'],
            $db_row['currency'],
            $db_row['image_url'],
            $db_row['affiliate_link'],
            $db_row['brand'],
            $db_row['category'],
            $db_row['colour'],
            $db_row['in_stock'],
            $db_row['status'],
            $db_row['last_updated'],
            $db_row['raw_data'],
            $db_row['search_text']
        ));

        return $result !== false;
    }

    /**
     * Upsert a batch of products efficiently.
     * Chunks into sub-batches of 50. Failed chunks are retried with binary split
     * (50 → 2×25 → 5er → individual) to isolate bad rows.
     * 
     * @param array $products Associative array [product_id => product_data]
     * @param int $feed_id Feed identifier
     * @param string $feed_name Feed name
     * @return int Number of affected rows
     */
    public static function upsert_batch($products, $feed_id, $feed_name = '') {
        if (empty($products)) {
            return 0;
        }

        // DIAG LOG 1: Log every upsert_batch call with feed_id
        $first_key = array_key_first($products);
        $first_product_name = isset($products[$first_key]['product_name']) ? $products[$first_key]['product_name'] : 'N/A';
        myfeeds_log("DIAG upsert_batch: feed_id={$feed_id}, batch_size=" . count($products) . ", first_product_name={$first_product_name}", 'info');

        $total_affected = 0;
        $chunks = array_chunk($products, 100, true);

        // Fix 1: Detailed entry logging
        myfeeds_log('UPSERT_START: chunks=' . count($chunks) . ', products=' . count($products) . ', feed=' . $feed_name, 'info');

        foreach ($chunks as $chunk_idx => $chunk) {
            myfeeds_log('UPSERT_CHUNK: idx=' . $chunk_idx . '/' . (count($chunks) - 1) . ', size=' . count($chunk), 'debug');

            $affected = self::upsert_batch_chunk($chunk, $feed_id, $feed_name);
            if ($affected >= 0) {
                $total_affected += $affected;
            } else {
                // Chunk failed — binary split retry
                myfeeds_log('UPSERT_CHUNK_FAILED: idx=' . $chunk_idx . ', starting binary split retry', 'error');
                $total_affected += self::retry_with_split($chunk, $feed_id, $feed_name, 100, $chunk_idx);
            }
        }

        myfeeds_log('UPSERT_COMPLETE: affected=' . $total_affected . ', feed=' . $feed_name, 'info');
        return $total_affected;
    }

    /**
     * Binary split retry: 100 → 2×50 → 10er → individual.
     * Isolates bad products without losing the entire chunk.
     */
    private static function retry_with_split($products, $feed_id, $feed_name, $original_size, $chunk_idx) {
        $count = count($products);
        $total_affected = 0;

        // Level 1: Split into halves (~25 each)
        $halves = array_chunk($products, (int) ceil($count / 2), true);
        foreach ($halves as $half_idx => $half) {
            $affected = self::upsert_batch_chunk($half, $feed_id, $feed_name);
            if ($affected >= 0) {
                $total_affected += $affected;
                continue;
            }

            // Level 2: Split failing half into ~5er chunks
            $small_chunks = array_chunk($half, 10, true);
            foreach ($small_chunks as $small_idx => $small) {
                $affected = self::upsert_batch_chunk($small, $feed_id, $feed_name);
                if ($affected >= 0) {
                    $total_affected += $affected;
                    continue;
                }

                // Level 3: Individual products — log failures
                foreach ($small as $pid => $product) {
                    $single = array($pid => $product);
                    $affected = self::upsert_batch_chunk($single, $feed_id, $feed_name);
                    if ($affected >= 0) {
                        $total_affected += $affected;
                    } else {
                        // This single product is the problem — log and skip
                        self::log('product_sanitize_failed', array(
                            'external_id' => (string) $pid,
                            'feed_id' => $feed_id,
                            'feed_name' => $feed_name,
                            'chunk_idx' => $chunk_idx,
                            'product_name' => mb_substr($product['title'] ?? $product['product_name'] ?? '?', 0, 80),
                        ));
                    }
                }
            }
        }

        return $total_affected;
    }

    /**
     * Internal: Upsert a single chunk of products.
     * Returns affected rows on success, -1 on failure.
     * 
     * Includes: Fix 1 (detailed logging), Fix 2 (query timeout), Fix 3 (table lock check).
     */
    private static function upsert_batch_chunk($products, $feed_id, $feed_name) {
        global $wpdb;
        $table = self::table_name();

        $chunk_size = count($products);
        $first_id = array_key_first($products);

        // Fix 1: Log sanitize phase start
        myfeeds_log('UPSERT_SANITIZE: chunk_size=' . $chunk_size . ', first_id=' . $first_id, 'debug');

        $values = array();
        $placeholders = array();

        foreach ($products as $pid => $product) {
            $product['id'] = (string) $pid;
            $db_row = self::product_to_row($product, $feed_id, $feed_name);

            $placeholders[] = '(%s, %d, %s, %s, %f, %f, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s)';
            $values[] = $db_row['external_id'];
            $values[] = $db_row['feed_id'];
            $values[] = $db_row['feed_name'];
            $values[] = $db_row['product_name'];
            $values[] = $db_row['price'];
            $values[] = $db_row['original_price'];
            $values[] = $db_row['currency'];
            $values[] = $db_row['image_url'];
            $values[] = $db_row['affiliate_link'];
            $values[] = $db_row['brand'];
            $values[] = $db_row['category'];
            $values[] = $db_row['colour'];
            $values[] = $db_row['in_stock'];
            $values[] = $db_row['status'];
            $values[] = $db_row['last_updated'];
            $values[] = $db_row['raw_data'];
            $values[] = $db_row['search_text'];
        }

        // Fix 1: Log sanitize phase done
        myfeeds_log('UPSERT_SANITIZE_DONE: chunk_size=' . $chunk_size . ', values=' . count($values), 'debug');

        $placeholders_str = implode(', ', $placeholders);

        $sql = "INSERT INTO {$table} 
                (external_id, feed_id, feed_name, product_name, price, original_price,
                 currency, image_url, affiliate_link, brand, category, colour, in_stock, status,
                 last_updated, raw_data, search_text)
                VALUES {$placeholders_str}
                ON DUPLICATE KEY UPDATE
                    feed_name = VALUES(feed_name),
                    product_name = VALUES(product_name),
                    price = VALUES(price),
                    original_price = VALUES(original_price),
                    currency = VALUES(currency),
                    image_url = VALUES(image_url),
                    affiliate_link = VALUES(affiliate_link),
                    brand = VALUES(brand),
                    category = VALUES(category),
                    colour = VALUES(colour),
                    in_stock = VALUES(in_stock),
                    status = VALUES(status),
                    last_updated = VALUES(last_updated),
                    raw_data = VALUES(raw_data),
                    search_text = VALUES(search_text)";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Bulk upsert query built with individual prepare() calls per row
        $prepared = $wpdb->prepare($sql, ...$values);
        $query_length = strlen($prepared);

        // Fix 3: Table lock prevention — verify table responds before heavy INSERT
        if (!self::check_table_accessible($table)) {
            myfeeds_log('UPSERT_TABLE_LOCKED: chunk_size=' . $chunk_size . ', feed_id=' . $feed_id . ', skipping chunk', 'error');
            self::log('table_locked', array(
                'chunk_size' => $chunk_size,
                'feed_id' => $feed_id,
                'first_id' => $first_id,
            ));
            return -1; // Signal failure for binary split retry
        }

        // Fix 2: Set session timeouts as safety net against infinite MySQL waits
        // wait_timeout: idle connection timeout (catches zombied connections)
        // innodb_lock_wait_timeout: max wait for row locks (catches deadlocks/lock contention)
        $wpdb->query("SET SESSION wait_timeout = 30");
        $wpdb->query("SET SESSION innodb_lock_wait_timeout = 30");

        // Fix 1: Log before query execution
        myfeeds_log('UPSERT_QUERY: length=' . $query_length . ', chunk_size=' . $chunk_size, 'debug');

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared via $wpdb->prepare() above
        $result = $wpdb->query($prepared);

        // Fix 1: Log after query execution
        myfeeds_log('UPSERT_QUERY_DONE: result=' . ($result === false ? 'FALSE(' . $wpdb->last_error . ')' : $result) . ', chunk_size=' . $chunk_size, $result === false ? 'error' : 'debug');

        // Fix 2: Reset session timeouts to MySQL defaults
        $wpdb->query("SET SESSION wait_timeout = 28800");
        $wpdb->query("SET SESSION innodb_lock_wait_timeout = 50");

        if ($result === false) {
            self::log('upsert_batch_error', array(
                'chunk_size' => $chunk_size,
                'feed_id' => $feed_id,
                'wpdb_error' => $wpdb->last_error,
                'query_length' => $query_length,
                'first_external_id' => $first_id,
            ));
            return -1; // Signal failure to caller for retry
        }

        return $result;
    }

    /**
     * Fix 3: Check if the table is accessible (not locked by another process).
     * 
     * Executes a lightweight SELECT 1 query with a 5-second lock wait timeout.
     * Retries up to 3 times with 2-second pauses between attempts.
     * 
     * @param string $table Full table name
     * @return bool True if table responded, false if locked/unreachable
     */
    private static function check_table_accessible($table) {
        global $wpdb;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            // Set short lock wait timeout for the check query
            $wpdb->query("SET SESSION innodb_lock_wait_timeout = 5");

            $check_start = microtime(true);
            // $table is built from $wpdb->prefix + a constant string, not user input.
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            $check = $wpdb->query("SELECT 1 FROM {$table} LIMIT 1");
            $check_duration = round((microtime(true) - $check_start) * 1000);

            // Reset to normal timeout
            $wpdb->query("SET SESSION innodb_lock_wait_timeout = 50");

            if ($check !== false) {
                if ($attempt > 1) {
                    myfeeds_log('TABLE_LOCK_RESOLVED: attempt=' . $attempt . ', duration_ms=' . $check_duration, 'info');
                }
                return true;
            }

            myfeeds_log('TABLE_LOCK_CHECK: attempt=' . $attempt . '/3, duration_ms=' . $check_duration . ', error=' . $wpdb->last_error, 'error');

            if ($attempt < 3) {
                sleep(2);
            }
        }

        // Table still locked after 3 attempts
        self::log('table_locked_final', array(
            'table' => $table,
            'attempts' => 3,
        ));
        return false;
    }

    /**
     * Quick Sync: Update only price/stock fields for known products.
     * Does NOT change product_name, brand, category, or raw_data.
     * 
     * @param array $product Product data with at least 'id'
     * @param int $feed_id Feed identifier
     * @return bool Success
     */
    public static function quick_sync_product($product, $feed_id) {
        global $wpdb;
        $table = self::table_name();

        $external_id = (string) ($product['id'] ?? '');
        if (empty($external_id)) {
            return false;
        }

        $price = floatval($product['price'] ?? 0);
        $original_price = floatval($product['old_price'] ?? $product['original_price'] ?? 0);
        $in_stock = isset($product['in_stock']) ? (int) $product['in_stock'] : 1;
        $image_url = $product['image_url'] ?? '';
        $affiliate_link = $product['affiliate_link'] ?? '';

        $result = $wpdb->update(
            $table,
            array(
                'price' => $price,
                'original_price' => $original_price,
                'in_stock' => $in_stock,
                'image_url' => $image_url,
                'affiliate_link' => $affiliate_link,
                'last_updated' => current_time('mysql'),
            ),
            array(
                'external_id' => $external_id,
            ),
            array('%f', '%f', '%d', '%s', '%s', '%s'),
            array('%s')
        );

        return $result !== false;
    }

    // =========================================================================
    // FULL IMPORT LIFECYCLE (replaces AtomicIndexManager for DB mode)
    // =========================================================================

    /**
     * Start a full import.
     * 
     * SAFE DESIGN: We do NOT mark existing products as 'importing' here.
     * Instead, we record a timestamp. During import, upserted products get
     * a fresh last_updated. At completion, we compare last_updated against
     * the import start time to find orphaned products.
     * 
     * If the import crashes at feed 3/5, existing products from feed 4/5
     * remain untouched with status='active' — no data loss.
     */
    public static function start_full_import() {
        $import_started = current_time('mysql');

        update_option('myfeeds_db_import_status', array(
            'status' => 'building',
            'started' => time(),
            'started_at' => $import_started,
            'items_written' => 0,
        ));

        self::log('db_full_import_started', array('started_at' => $import_started));
    }

    /**
     * Complete a full import with PER-FEED orphan detection.
     * 
     * ARCHITECTURE FIX (Feb 2026): Orphan detection is now feed-scoped.
     * - Only products belonging to feeds that were ACTUALLY IMPORTED are evaluated.
     *   Imported feed_ids are auto-discovered from the DB via timestamp comparison.
     * - Products from skipped/failed/deleted feeds are NEVER touched.
     * - Products are NEVER hard-deleted during a normal import — only marked 'unavailable'.
     * - Safety threshold: abort if >50% of active products would be affected.
     * - If all_feeds_ok is false (skipped/failed feeds exist), orphan detection is skipped entirely.
     * 
     * @param array $active_block_ids Product IDs currently used in Gutenberg blocks (unused but kept for API compat)
     * @param bool $all_feeds_ok True only if ALL configured feeds completed without errors/skips
     */
    public static function complete_full_import($active_block_ids = array(), $all_feeds_ok = true) {
        global $wpdb;
        $table = self::table_name();

        $import_status = get_option('myfeeds_db_import_status', array());
        $import_started_at = $import_status['started_at'] ?? '';

        $marked_unavailable = 0;

        // DIAG LOG 3: DB state BEFORE orphan cleanup
        $diag_db_state = $wpdb->get_results("SELECT feed_id, feed_name, COUNT(*) as cnt, status FROM {$table} GROUP BY feed_id, feed_name, status");
        myfeeds_log("DIAG db_state: " . json_encode($diag_db_state), 'info');
        $diag_total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        // ALWAYS RUN: Auto-cleanup products from deleted/non-configured feeds.
        // This runs BEFORE per-feed orphan detection (which may early-return) to ensure
        // products from deleted feeds are always cleaned up after a full import.
        $cleanup_feeds = get_option('myfeeds_feeds', array());
        $cleanup_feed_names = array_filter(array_column($cleanup_feeds, 'name'));

        // DIAG LOG 3b: Orphan check context
        $diag_per_feed = $wpdb->get_results("SELECT feed_id, COUNT(*) as cnt FROM {$table} GROUP BY feed_id");
        $diag_per_feed_map = array();
        foreach ($diag_per_feed as $row) {
            $diag_per_feed_map[$row->feed_id] = $row->cnt;
        }
        myfeeds_log("DIAG orphan_check: configured_feed_ids=[" . implode(',', array_keys($cleanup_feeds)) . "], total_products_in_db={$diag_total}, products_per_feed_id=" . json_encode($diag_per_feed_map), 'info');

        if (!empty($cleanup_feed_names)) {
            myfeeds_log("Orphan cleanup: configured feed_ids=[" . implode(',', array_keys($cleanup_feeds)) . "], deleting products with feed_name NOT IN [" . implode(', ', $cleanup_feed_names) . "]", 'info');
            $deleted_orphans = self::delete_orphaned_products($cleanup_feed_names);
            myfeeds_log("Orphan cleanup: deleted {$deleted_orphans} products", 'info');
        }

        // SAFETY GUARD 1: Need valid import timestamp
        if (empty($import_started_at)) {
            self::log('db_complete_skip_unavailable', array(
                'reason' => 'no_start_timestamp',
            ));
            self::_finalize_import_status($import_status, 0, 0, $all_feeds_ok);
            return;
        }

        // SAFETY GUARD 2: If not all feeds completed successfully, skip ALL orphan detection.
        // When feeds were skipped (untested), failed, or errored, we cannot know which
        // products are truly orphaned vs. simply belonging to a non-imported feed.
        if (!$all_feeds_ok) {
            self::log('db_complete_skip_unavailable', array(
                'reason' => 'not_all_feeds_ok',
                'all_feeds_ok' => false,
            ));
            myfeeds_log("MYFEEDS [INFO]: Orphan detection SKIPPED — not all configured feeds were imported.", 'info');
            myfeeds_log("Orphan detection SKIPPED — not all configured feeds were imported.", 'info');
            self::_finalize_import_status($import_status, 0, 0, false);
            return;
        }

        // =====================================================================
        // PER-FEED ORPHAN DETECTION (only when ALL feeds imported successfully)
        // 
        // Auto-discover which feed_ids were updated during this import run
        // by checking which feed_ids have products with last_updated >= import_started_at.
        // Then for EACH of those feeds, find products that were NOT updated.
        // Products from feeds NOT in this list (deleted/removed) are NEVER touched.
        // =====================================================================
        $imported_feed_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT feed_id FROM {$table} WHERE last_updated >= %s",
            $import_started_at
        ));

        if (empty($imported_feed_ids)) {
            self::log('db_complete_skip_unavailable', array(
                'reason' => 'no_feeds_found_in_db_for_this_run',
            ));
            self::_finalize_import_status($import_status, 0, 0, $all_feeds_ok);
            return;
        }

        // Build placeholders for feed_id IN clause
        $feed_placeholders = implode(',', array_fill(0, count($imported_feed_ids), '%d'));
        $query_args = array_map('intval', $imported_feed_ids);
        $query_args[] = $import_started_at;

        // Find orphans ONLY within successfully imported feeds
        $orphaned = $wpdb->get_results($wpdb->prepare(
            "SELECT external_id, feed_id FROM {$table} 
             WHERE status = 'active' 
             AND feed_id IN ({$feed_placeholders})
             AND last_updated < %s",
            ...$query_args
        ), ARRAY_A);

        $orphan_count = count($orphaned);

        // SAFETY GUARD 3: Threshold check — abort if >50% of products FROM CONFIGURED FEEDS would be affected
        // Fix 2 (Phase 12.8): Only count products belonging to currently configured feeds, not orphans from deleted feeds
        $configured_feeds = get_option('myfeeds_feeds', array());
        $configured_feed_names = array_filter(array_column($configured_feeds, 'name'));
        
        if (!empty($configured_feed_names)) {
            $name_placeholders = implode(',', array_fill(0, count($configured_feed_names), '%s'));
            $total_active = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND feed_name IN ({$name_placeholders})",
                ...$configured_feed_names
            ));
        } else {
            $total_active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active'");
        }

        if ($total_active > 0 && $orphan_count > ($total_active * 0.5)) {
            $pct = round(($orphan_count / $total_active) * 100, 1);
            myfeeds_log("MYFEEDS [ERROR]: SAFETY ABORT — Would mark {$orphan_count} of {$total_active} active products as unavailable ({$pct}% > 50%). This likely indicates a configuration change, not actual product removal. Skipping orphan marking.", 'error');
            self::log('db_complete_safety_abort', array(
                'orphan_count' => $orphan_count,
                'total_active' => $total_active,
                'percent' => $pct,
                'imported_feed_ids' => $imported_feed_ids,
            ));
            self::_finalize_import_status($import_status, 0, 0, $all_feeds_ok, 'safety_abort');
            return;
        }

        // Mark orphans as 'unavailable' — NEVER hard-delete during normal import.
        // Single bulk UPDATE replaces a per-row $wpdb->update() loop. The AND status='active'
        // guard preserves the original semantics: a product that someone else already
        // flipped to 'unavailable' between the SELECT above and now is left untouched.
        if (!empty($orphaned)) {
            $eids = array_map(function ($row) { return $row['external_id']; }, $orphaned);
            $placeholders = implode(',', array_fill(0, count($eids), '%s'));
            $now = current_time('mysql');
            $marked_unavailable = (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'unavailable', last_updated = %s
                 WHERE status = 'active' AND external_id IN ({$placeholders})",
                $now,
                ...$eids
            ));
        }

        if ($marked_unavailable > 0) {
            myfeeds_log("MYFEEDS [INFO]: Orphan detection (per-feed): {$marked_unavailable} products marked unavailable across " . count($imported_feed_ids) . " imported feed(s).", 'debug');
        } else {
            myfeeds_log("MYFEEDS [INFO]: Orphan detection: 0 orphans found across " . count($imported_feed_ids) . " imported feed(s).", 'debug');
        }

        // PERF: Bulk-populate search_text that was skipped during import.
        // Single UPDATE is orders of magnitude faster than per-row FULLTEXT updates.
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
            myfeeds_log("PERF: Bulk-populated search_text for {$backfilled} products after import", 'info');
        }

        self::_finalize_import_status($import_status, $marked_unavailable, 0, $all_feeds_ok);

        // Note: Orphan cleanup for deleted feeds now runs at the TOP of this function (before early returns).

        // Note: feed product counts are updated by the batch importer after complete_import()
    }

    /**
     * Helper: Write final import status to DB option.
     * 
     * @param array $import_status Previous import status
     * @param int $marked_unavailable Count of products marked unavailable
     * @param int $deleted Always 0 now (no hard deletes in normal import)
     * @param bool $all_feeds_ok Whether all feeds completed successfully
     * @param string $warning Optional warning flag (e.g. 'safety_abort')
     */
    private static function _finalize_import_status($import_status, $marked_unavailable, $deleted, $all_feeds_ok, $warning = '') {
        $final_status = array(
            'status' => empty($warning) ? 'complete' : 'completed_with_warnings',
            'completed' => time(),
            'items_written' => $import_status['items_written'] ?? 0,
            'marked_unavailable' => $marked_unavailable,
            'deleted_orphans' => $deleted,
            'all_feeds_ok' => $all_feeds_ok,
        );

        if (!empty($warning)) {
            $final_status['warning'] = $warning;
        }

        update_option('myfeeds_db_import_status', $final_status);

        self::log('db_full_import_completed', array(
            'marked_unavailable' => $marked_unavailable,
            'deleted_orphans' => $deleted,
            'all_feeds_ok' => $all_feeds_ok,
            'warning' => $warning,
        ));
    }

    /**
     * Update the product_count for each feed in myfeeds_feeds option.
     * Counts active products per feed_name from the DB.
     */
    public static function update_feed_product_counts() {
        global $wpdb;
        $table = self::table_name();

        $feeds = get_option('myfeeds_feeds', array());
        if (empty($feeds)) return;

        // Get counts per feed_name from DB
        $count_map = self::get_feed_counts();
        $total_db = self::get_active_product_count();
        $sum_feeds = array_sum($count_map);

        // PRODUCT_COUNT_VERIFY: Debug log for verification
        myfeeds_log('PRODUCT_COUNT_VERIFY: total_db=' . $total_db . ', sum_feeds=' . $sum_feeds . ', match=' . ($total_db === $sum_feeds ? 'YES' : 'NO'), 'debug');

        $updated = false;
        foreach ($feeds as $key => &$feed) {
            $name = $feed['name'] ?? '';
            if (isset($count_map[$name])) {
                $feed['product_count'] = $count_map[$name];
                $updated = true;
            }
            
            // Calculate real mapping quality per feed (only in DB mode)
            if (self::is_db_mode() && !empty($name)) {
                $quality = self::calculate_mapping_quality($name);
                $feed['mapping_confidence'] = $quality['quality'];
            }
        }
        unset($feed);

        if ($updated) {
            update_option('myfeeds_feeds', $feeds);
            self::log('feed_counts_updated', $count_map);
        }
    }

    /**
     * Abort a full import — no cleanup needed.
     * 
     * Because we never marked existing products as 'importing',
     * all existing products remain 'active' and unaffected.
     * Products that were upserted during the partial import also
     * remain with status='active' — they simply got fresher data.
     */
    public static function abort_full_import() {
        update_option('myfeeds_db_import_status', array(
            'status' => 'aborted',
            'aborted' => time(),
        ));

        self::log('db_full_import_aborted', array(), 'debug');
    }

    /**
     * Get all external_ids currently in the DB (for mark_missing logic)
     * Returns associative array [external_id => true] for O(1) lookup
     */
    public static function get_all_external_ids() {
        global $wpdb;
        $table = self::table_name();

        $ids = $wpdb->get_col("SELECT external_id FROM {$table} WHERE status IN ('active', 'importing')");
        return array_flip($ids);
    }

    /**
     * Get DB import status (equivalent to AtomicIndexManager::get_build_status)
     */
    public static function get_import_status() {
        return get_option('myfeeds_db_import_status', array('status' => 'idle'));
    }

    /**
     * Get DB stats (equivalent to AtomicIndexManager::get_active_stats)
     */
    public static function get_stats() {
        global $wpdb;
        $table = self::table_name();

        if (!self::table_exists()) {
            return array('exists' => false, 'count' => 0);
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active'");
        $unavailable = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'unavailable'");
        $last_updated = $wpdb->get_var("SELECT MAX(last_updated) FROM {$table}");

        return array(
            'exists' => true,
            'count' => $active,
            'total' => $total,
            'active' => $active,
            'unavailable' => $unavailable,
            'last_updated' => $last_updated,
        );
    }

    // =========================================================================
    // MIGRATION: JSON → DB
    // =========================================================================

    /**
     * Migrate products from active-index.json into the DB table.
     * Called once during plugin update if JSON exists and DB is empty.
     * 
     * @return int Number of products migrated
     */
    public static function migrate_from_json() {
        $upload_dir = wp_upload_dir();
        $json_path = $upload_dir['basedir'] . '/myfeeds-feed-index.json';

        if (!file_exists($json_path)) {
            self::log('migration_skipped', array('reason' => 'json_not_found'));
            return 0;
        }

        $content = file_get_contents($json_path);
        $index = json_decode($content, true);
        unset($content);

        if (!is_array($index) || empty($index['items'])) {
            self::log('migration_skipped', array('reason' => 'json_empty'));
            return 0;
        }

        $items = $index['items'];
        unset($index);

        $migrated = 0;
        $batch = array();
        $batch_size = 500;

        foreach ($items as $pid => $product) {
            $product['id'] = (string) $pid;
            $batch[$pid] = $product;

            if (count($batch) >= $batch_size) {
                self::upsert_batch($batch, 0, 'migrated');
                $migrated += count($batch);
                $batch = array();
            }
        }

        // Final batch
        if (!empty($batch)) {
            self::upsert_batch($batch, 0, 'migrated');
            $migrated += count($batch);
        }

        unset($items);

        self::log('migration_complete', array('migrated' => $migrated));
        return $migrated;
    }

    // =========================================================================
    // DATA CONVERSION HELPERS
    // =========================================================================

    /**
     * Column max lengths for truncation.
     * Must match CREATE TABLE definition exactly.
     */
    private static $column_limits = array(
        'external_id'    => 100,
        'feed_name'      => 255,
        'product_name'   => 500,
        'currency'       => 10,
        'brand'          => 200,
        'category'       => 200,
        'status'         => 20,
        // TEXT columns: 65535 bytes, practically unlimited for our use
        'image_url'      => 65000,
        'affiliate_link' => 65000,
    );

    /**
     * Sanitize a single string value for safe DB insertion.
     * 
     * - Removes null bytes (\0)
     * - Forces valid UTF-8 (strips invalid sequences)
     * - Truncates to column max length
     * 
     * Does NOT use sanitize_text_field() — that strips legitimate HTML.
     * 
     * @param string $value Raw string value
     * @param int $max_length Maximum byte length for this column
     * @return string Sanitized string
     */
    private static function sanitize_string($value, $max_length = 0) {
        if (!is_string($value)) {
            $value = (string) $value;
        }

        // Remove null bytes
        $value = str_replace("\0", '', $value);

        // Force valid UTF-8: encode then decode strips invalid sequences
        if (function_exists('mb_convert_encoding')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        // Remove other control characters (except \n \r \t)
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        // Truncate to column max length (by chars, not bytes, to avoid mid-char cut)
        if ($max_length > 0 && mb_strlen($value, 'UTF-8') > $max_length) {
            $value = mb_substr($value, 0, $max_length, 'UTF-8');
        }

        return $value;
    }

    /**
     * Sanitize all fields of a product array before DB insertion.
     * Applied to EVERY product BEFORE it enters any batch or single upsert.
     * 
     * - String fields: null-byte removal, UTF-8 enforcement, truncation
     * - Numeric fields: cast to float/int
     * - raw_data accumulator: not touched here (handled in product_to_row)
     * 
     * INVARIANT: $product['id'] (external_id) is NEVER modified beyond
     * null-byte/UTF-8 cleanup — the mapping to block attributes must stay 1:1.
     * 
     * @param array $product Raw product data from feed mapping
     * @return array Sanitized product data
     */
    public static function sanitize_product_data($product) {
        if (!is_array($product)) {
            return array();
        }

        // Sanitize known string fields with their column limits
        $string_fields = array(
            'id'             => 100,   // external_id
            'title'          => 500,   // product_name
            'product_name'   => 500,
            'currency'       => 10,
            'brand'          => 200,
            'category'       => 200,
            'status'         => 20,
            'merchant'       => 255,   // feed_name
            'feed_name'      => 255,
            'image_url'      => 65000,
            'affiliate_link' => 65000,
        );

        foreach ($string_fields as $field => $limit) {
            if (isset($product[$field]) && is_string($product[$field])) {
                $product[$field] = self::sanitize_string($product[$field], $limit);
            }
        }

        // Sanitize all remaining string values (go into raw_data)
        foreach ($product as $key => &$value) {
            if (is_string($value) && !isset($string_fields[$key])) {
                $value = self::sanitize_string($value, 65000);
            }
        }
        unset($value);

        // Force numeric fields
        if (isset($product['price'])) {
            $product['price'] = floatval($product['price']);
        }
        if (isset($product['old_price'])) {
            $product['old_price'] = floatval($product['old_price']);
        }
        if (isset($product['original_price'])) {
            $product['original_price'] = floatval($product['original_price']);
        }

        return $product;
    }

    /**
     * Convert a DB row to the product array format used by the frontend/resolver.
     * 
     * CRITICAL: The 'id' field in the returned array MUST equal the external_id
     * in the database, which MUST equal the selectedProducts[x]['id'] in Gutenberg blocks.
     */
    private static function row_to_product($row) {
        $product = array(
            'id'                  => $row['external_id'],
            'title'               => $row['product_name'] ?? '',
            'price'               => floatval($row['price'] ?? 0),
            'old_price'           => floatval($row['original_price'] ?? 0),
            'currency'            => $row['currency'] ?? 'EUR',
            'image_url'           => $row['image_url'] ?? '',
            'affiliate_link'      => $row['affiliate_link'] ?? '',
            'brand'               => $row['brand'] ?? '',
            'category'            => $row['category'] ?? '',
            'colour'              => $row['colour'] ?? '',
            'in_stock'            => (int) ($row['in_stock'] ?? 1),
            'status'              => $row['status'] ?? 'active',
            'merchant'            => $row['feed_name'] ?? '',
            'last_updated'        => $row['last_updated'] ?? '',
        );

        // Merge raw_data fields back into the product array
        if (!empty($row['raw_data'])) {
            $raw = json_decode($row['raw_data'], true);
            if (is_array($raw)) {
                // raw_data fields go underneath explicit columns (explicit wins)
                $product = array_merge($raw, $product);
            }
        }

        return $product;
    }

    /**
     * Convert a product array (from feed mapping) to a DB row.
     * 
     * Explicit columns: external_id, feed_id, feed_name, product_name, price,
     * original_price, currency, image_url, affiliate_link, brand, category,
     * in_stock, status, last_updated.
     * 
     * Everything else goes into raw_data as JSON.
     */
    private static function product_to_row($product, $feed_id, $feed_name = '') {
        // Sanitize ALL product data before building the DB row
        $product = self::sanitize_product_data($product);

        // Fields that have their own columns
        $explicit_keys = array(
            'id', 'title', 'product_name', 'price', 'old_price', 'original_price',
            'currency', 'image_url', 'affiliate_link', 'brand', 'category',
            'colour', 'color',
            'in_stock', 'status', 'merchant', 'feed_name', 'feed_id',
            'last_updated', 'unavailable_since',
        );

        // Build raw_data from all remaining fields
        $raw_data = array();
        foreach ($product as $key => $value) {
            if (!in_array($key, $explicit_keys, true)) {
                $raw_data[$key] = $value;
            }
        }

        $price = floatval($product['price'] ?? 0);
        $original_price = floatval($product['old_price'] ?? $product['original_price'] ?? 0);

        // Resolve best available image URL (largest/highest quality first)
        $best_image = '';
        if (!empty($product['large_image'])) {
            $best_image = $product['large_image'];
        } elseif (!empty($product['merchant_image_url'])) {
            $best_image = $product['merchant_image_url'];
        } elseif (!empty($product['aw_image_url'])) {
            $best_image = $product['aw_image_url'];
        } elseif (!empty($product['image_url'])) {
            $best_image = $product['image_url'];
        } elseif (!empty($product['aw_thumb_url'])) {
            $best_image = $product['aw_thumb_url'];
        }
        // Ensure it's a string
        if (is_array($best_image)) {
            $best_image = $best_image[0] ?? '';
        }

        // Extract colour from various possible locations
        $colour = '';
        if (!empty($product['colour'])) {
            $colour = is_array($product['colour']) ? ($product['colour'][0] ?? '') : (string) $product['colour'];
        } elseif (!empty($product['color'])) {
            $colour = is_array($product['color']) ? ($product['color'][0] ?? '') : (string) $product['color'];
        }
        if (empty($colour) && isset($product['attributes']['color'])) {
            $c = $product['attributes']['color'];
            $colour = is_array($c) ? ($c[0] ?? '') : (string) $c;
        }
        if (empty($colour) && isset($product['attributes']['colour'])) {
            $c = $product['attributes']['colour'];
            $colour = is_array($c) ? ($c[0] ?? '') : (string) $c;
        }
        if (empty($colour) && !empty($product['Fashion:colour'])) {
            $colour = (string) $product['Fashion:colour'];
        }
        if (empty($colour) && !empty($product['Fashion:color'])) {
            $colour = (string) $product['Fashion:color'];
        }

        // Sanitize raw_data JSON for invalid UTF-8
        $raw_json = null;
        if (!empty($raw_data)) {
            $raw_json = wp_json_encode($raw_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($raw_json === false) {
                // Fallback: encode with lossy flag
                $raw_json = wp_json_encode($raw_data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            if ($raw_json === false) {
                // Last resort: skip raw_data entirely
                $raw_json = null;
                self::log('raw_data_encode_failed', array(
                    'external_id' => (string) ($product['id'] ?? ''),
                ));
            }
        }

        $db_row = array(
            'external_id'    => (string) ($product['id'] ?? ''),
            'feed_id'        => (int) $feed_id,
            'feed_name'      => self::sanitize_string($feed_name ?: ($product['merchant'] ?? $product['feed_name'] ?? ''), 255),
            'product_name'   => $product['title'] ?? $product['product_name'] ?? '',
            'price'          => $price,
            'original_price' => $original_price,
            'currency'       => $product['currency'] ?? 'EUR',
            'image_url'      => $best_image,
            'affiliate_link' => $product['affiliate_link'] ?? '',
            'brand'          => $product['brand'] ?? '',
            'category'       => $product['category'] ?? '',
            'colour'         => self::sanitize_string($colour, 200),
            'in_stock'       => isset($product['in_stock']) ? (int) $product['in_stock'] : 1,
            'status'         => $product['status'] ?? 'active',
            'last_updated'   => current_time('mysql'),
            'raw_data'       => $raw_json,
        );

        // PERF: During full imports, skip search_text to avoid expensive
        // per-row FULLTEXT index updates. Bulk-populated after import.
        $import_status = get_option('myfeeds_db_import_status', array());
        $is_building = (($import_status['status'] ?? '') === 'building');

        if ($is_building) {
            $db_row['search_text'] = null;
        } else {
            // Normal mode: build search_text for immediate FULLTEXT indexing
            $db_row['search_text'] = implode(' ', array_filter([
                $db_row['product_name'],
                $db_row['brand'],
                $db_row['feed_name'],
                $db_row['category'],
                $db_row['colour'],
            ]));
        }

        return $db_row;
    }

    // =========================================================================
    // ONE-TIME BACKFILL: Extract colour from raw_data into dedicated column
    // =========================================================================

    /**
     * One-time backfill: Populate the new `colour` column from raw_data JSON.
     * Processes in batches of 500 to avoid memory issues.
     */
    public static function backfill_colour_column() {
        global $wpdb;
        $table = self::table_name();

        // Only run if column exists and has no data yet
        $col_check = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'colour'");
        if (!$col_check) return;

        $has_colours = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE colour IS NOT NULL AND colour != ''");
        if ($has_colours > 0) return; // Already has colour data

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE raw_data IS NOT NULL AND raw_data != ''");
        if ($total === 0) return;

        $offset = 0;
        $batch = 500;
        $updated = 0;

        while ($offset < $total) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, raw_data FROM {$table} WHERE raw_data IS NOT NULL AND raw_data != '' LIMIT %d OFFSET %d",
                $batch, $offset
            ), ARRAY_A);

            if (empty($rows)) break;

            foreach ($rows as $row) {
                $raw = json_decode($row['raw_data'], true);
                if (!is_array($raw)) continue;

                $colour = '';
                // attributes.color (most common for AWIN)
                if (isset($raw['attributes']['color'])) {
                    $c = $raw['attributes']['color'];
                    $colour = is_array($c) ? ($c[0] ?? '') : (string) $c;
                }
                if (empty($colour) && isset($raw['attributes']['colour'])) {
                    $c = $raw['attributes']['colour'];
                    $colour = is_array($c) ? ($c[0] ?? '') : (string) $c;
                }
                // Direct fields
                if (empty($colour) && !empty($raw['colour'])) {
                    $colour = is_array($raw['colour']) ? ($raw['colour'][0] ?? '') : (string) $raw['colour'];
                }
                if (empty($colour) && !empty($raw['color'])) {
                    $colour = is_array($raw['color']) ? ($raw['color'][0] ?? '') : (string) $raw['color'];
                }
                // AWIN Fashion fields
                if (empty($colour) && !empty($raw['Fashion:colour'])) {
                    $colour = (string) $raw['Fashion:colour'];
                }

                if (!empty($colour)) {
                    $wpdb->update($table, array('colour' => substr($colour, 0, 200)), array('id' => $row['id']), array('%s'), array('%d'));
                    $updated++;
                }
            }

            $offset += $batch;
        }

        if ($updated > 0) {
            myfeeds_log('BACKFILL_COLOUR: Updated ' . $updated . ' products with colour from raw_data', 'info');
        }
    }

    // =========================================================================
    // ONE-TIME BACKFILL: Populate search_text for existing products
    // =========================================================================

    /**
     * One-time backfill: Populate the new `search_text` column from existing columns.
     * Uses a single UPDATE query with CONCAT_WS for efficiency.
     * 
     * @return int Number of rows updated
     */
    public static function backfill_search_text() {
        global $wpdb;
        $table = self::table_name();
        
        $updated = $wpdb->query(
            "UPDATE {$table} SET search_text = CONCAT_WS(' ',
                COALESCE(product_name, ''),
                COALESCE(brand, ''),
                COALESCE(feed_name, ''),
                COALESCE(category, ''),
                COALESCE(colour, '')
            ) WHERE search_text IS NULL OR search_text = ''"
        );
        
        myfeeds_log("Backfill search_text: {$updated} rows updated", 'info');
        return $updated;
    }

    // =========================================================================
    // ONE-TIME CLEANUP: Strip "(Priorität)" suffix from feed_name
    // =========================================================================

    /**
     * One-time cleanup: Remove "(Priorität)" and "(Priority)" suffix from feed_name.
     * The priority phase is an import property, not a product property.
     * Only runs once (controlled by option flag).
     */
    public static function cleanup_priority_suffix() {
        global $wpdb;
        $table = self::table_name();

        // Check if cleanup already ran
        if (get_option('myfeeds_priority_suffix_cleaned', false)) {
            return;
        }

        if (!self::table_exists()) {
            return;
        }

        // Clean both German and English suffix
        $affected_de = $wpdb->query(
            "UPDATE {$table} SET feed_name = TRIM(REPLACE(feed_name, ' (Priorität)', '')) WHERE feed_name LIKE '% (Priorität)'"
        );
        $affected_en = $wpdb->query(
            "UPDATE {$table} SET feed_name = TRIM(REPLACE(feed_name, ' (Priority)', '')) WHERE feed_name LIKE '% (Priority)'"
        );

        $total = ($affected_de ?: 0) + ($affected_en ?: 0);
        if ($total > 0) {
            self::log('priority_suffix_cleaned', array(
                'affected_de' => $affected_de ?: 0,
                'affected_en' => $affected_en ?: 0,
            ));
        }

        update_option('myfeeds_priority_suffix_cleaned', true);
    }

    // =========================================================================
    // LOGGING
    // =========================================================================

    private static function log($event, $data, $level = 'info') {
        $message = 'MYFEEDS_DB_' . $event . ': ' . wp_json_encode($data, JSON_UNESCAPED_SLASHES);
        myfeeds_log($message, $level);
    }
}
