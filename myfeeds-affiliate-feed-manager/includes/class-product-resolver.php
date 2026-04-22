<?php
/**
 * MyFeeds Product Resolver
 * Multi-source fallback resolver with DB or JSON index strategy.
 * 
 * Feature Flag: MyFeeds_DB_Manager::is_db_mode()
 *   - true:  DB query per product (no static cache, no full-index load)
 *   - false: JSON file-based resolver (original behaviour)
 * 
 * Fallback chain (both modes):
 *   DB/Active Index → Building Index (JSON only) → Raw Feed → CPT → null
 * 
 * INVARIANT: returned array['id'] === the external_id stored in Gutenberg blocks
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Product_Resolver {
    
    const INDEX_FILE_ACTIVE = 'myfeeds-feed-index.json';
    const INDEX_FILE_BUILDING = 'myfeeds-feed-index-building.jsonl';
    const OPTION_INDEX_VERSION = 'myfeeds_index_version';
    const OPTION_BUILD_STATUS = 'myfeeds_build_status';
    
    /**
     * Static cache for JSON mode ONLY.
     * In DB mode this is never used — each resolve() is a direct DB query.
     */
    private static $active_index_cache = null;
    
    /**
     * Resolve a product by ID with multi-source fallback.
     * NEVER returns null if ANY data source has the product.
     * 
     * @param string $product_id Product ID to resolve (= external_id = block attr id)
     * @param array $hints Optional hints (color, image_url) for variant matching
     * @return array|null Product data or null only if ALL sources fail
     */
    public static function resolve($product_id, $hints = array()) {
        $product_id = (string) $product_id;
        
        // =====================================================================
        // DB MODE: Single query, no static cache, no full-index load
        // =====================================================================
        if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode()) {
            return self::resolve_from_db($product_id, $hints);
        }
        
        // =====================================================================
        // JSON MODE: Original multi-source fallback
        // =====================================================================
        return self::resolve_from_json($product_id, $hints);
    }
    
    /**
     * DB mode resolver: direct query per product.
     * Uses wp_cache (object cache) for per-request deduplication only.
     */
    private static function resolve_from_db($product_id, $hints = array()) {
        // Per-request cache via WP object cache (non-persistent by default)
        $cache_key = 'myfeeds_product_' . $product_id;
        $cached = wp_cache_get($cache_key, 'myfeeds-affiliate-feed-manager');
        if ($cached !== false) {
            return $cached;
        }
        
        // Source 1: DB table
        $product = MyFeeds_DB_Manager::get_product($product_id);
        if ($product) {
            wp_cache_set($cache_key, $product, 'myfeeds', 300);
            return $product;
        }
        
        // Source 2: Raw Feed (direct CSV/XML lookup from cached feed files)
        $product = self::find_in_raw_feeds($product_id, $hints);
        if ($product) {
            // Write to DB for future lookups
            MyFeeds_DB_Manager::upsert_product($product, 0, '');
            wp_cache_set($cache_key, $product, 'myfeeds', 300);
            return $product;
        }
        
        // Source 3: Custom Post Type
        $product = self::find_in_cpt($product_id);
        if ($product) {
            MyFeeds_DB_Manager::upsert_product($product, 0, '');
            wp_cache_set($cache_key, $product, 'myfeeds', 300);
            return $product;
        }
        
        self::log_resolver('resolve_failed_all_sources', array(
            'product_id' => $product_id,
            'mode' => 'db',
        ));
        
        return null;
    }
    
    /**
     * JSON mode resolver: original multi-source fallback with static cache.
     */
    private static function resolve_from_json($product_id, $hints = array()) {
        // Source 1: Active Index (fastest)
        $product = self::find_in_active_index($product_id);
        if ($product) {
            return $product;
        }
        
        // Source 2: Building Index (might have newer data during import)
        $product = self::find_in_building_index($product_id);
        if ($product) {
            self::write_to_active_index($product_id, $product);
            return $product;
        }
        
        // Source 3: Raw Feed (direct CSV/XML lookup)
        $product = self::find_in_raw_feeds($product_id, $hints);
        if ($product) {
            self::write_to_active_index($product_id, $product);
            return $product;
        }
        
        // Source 4: Custom Post Type
        $product = self::find_in_cpt($product_id);
        if ($product) {
            self::write_to_active_index($product_id, $product);
            return $product;
        }
        
        self::log_resolver('resolve_failed_all_sources', array(
            'product_id' => $product_id,
            'mode' => 'json',
        ));
        
        return null;
    }
    
    // =========================================================================
    // JSON MODE HELPERS (unchanged from original)
    // =========================================================================
    
    /**
     * Find product in active JSON index
     */
    private static function find_in_active_index($product_id) {
        $index = self::load_active_index();
        
        if (!$index || !isset($index['items'])) {
            return null;
        }
        
        if (isset($index['items'][$product_id])) {
            return $index['items'][$product_id];
        }
        
        foreach ($index['items'] as $item) {
            if (isset($item['id']) && (string) $item['id'] === $product_id) {
                return $item;
            }
        }
        
        return null;
    }
    
    /**
     * Find product in building index (JSONL streaming, JSON mode only)
     */
    private static function find_in_building_index($product_id) {
        $path = self::get_building_index_path();
        
        if (!file_exists($path) || filesize($path) === 0) {
            return null;
        }
        
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        $product_id = (string) $product_id;
        $found = null;
        
        $contents = $wp_filesystem->get_contents($path);
        if (false === $contents) return null;
        
        $lines = explode("\n", $contents);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (strpos($line, $product_id) === false) continue;
            
            $item = json_decode($line, true);
            if ($item && isset($item['id']) && (string) $item['id'] === $product_id) {
                $found = $item;
            }
        }
        
        return $found;
    }
    
    // =========================================================================
    // SHARED FALLBACK SOURCES (used by both DB and JSON modes)
    // =========================================================================
    
    /**
     * Find product in raw feed files (cached CSV on disk)
     */
    private static function find_in_raw_feeds($product_id, $hints = array()) {
        $feeds = get_option('myfeeds_feeds', array());
        
        if (empty($feeds)) {
            return null;
        }
        
        foreach ($feeds as $feed_key => $feed) {
            $feed_url = $feed['url'] ?? '';
            if (empty($feed_url)) continue;
            
            $product = self::search_feed_for_product($feed_url, $product_id, $feed['mapping'] ?? array());
            if ($product) {
                return $product;
            }
        }
        
        return null;
    }
    
    /**
     * Search a single cached feed file for a product
     */
    private static function search_feed_for_product($feed_url, $product_id, $mapping) {
        $cache_key = 'myfeeds_content_' . md5($feed_url);
        $cache_file = wp_upload_dir()['basedir'] . '/myfeeds-cache/' . $cache_key . '.txt';
        
        $content = null;
        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 3600) {
            $content = file_get_contents($cache_file);
        }
        
        if (!$content) {
            return null;
        }
        
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        if (count($lines) < 2) {
            return null;
        }
        
        $header = str_getcsv($lines[0]);
        $id_field_candidates = array('id', 'product_id', 'sku', 'article_number', 'ID', 'ProductID');
        
        $id_col = -1;
        foreach ($id_field_candidates as $candidate) {
            $idx = array_search($candidate, $header);
            if ($idx !== false) {
                $id_col = $idx;
                break;
            }
        }
        
        if ($id_col === -1) {
            return null;
        }
        
        for ($i = 1; $i < count($lines); $i++) {
            $fields = str_getcsv($lines[$i]);
            if (count($fields) <= $id_col) continue;
            
            if ((string) $fields[$id_col] === $product_id) {
                $raw = array_combine($header, $fields);
                return self::map_raw_to_product($raw, $mapping);
            }
        }
        
        return null;
    }
    
    /**
     * Map raw feed data to product format
     */
    private static function map_raw_to_product($raw, $mapping) {
        $product = array();
        
        foreach ($mapping as $target => $source) {
            if (isset($raw[$source])) {
                $product[$target] = $raw[$source];
            }
        }
        
        if (!isset($product['id'])) {
            $id_candidates = array('id', 'product_id', 'sku', 'ID');
            foreach ($id_candidates as $candidate) {
                if (isset($raw[$candidate])) {
                    $product['id'] = $raw[$candidate];
                    break;
                }
            }
        }
        
        return !empty($product['id']) ? $product : null;
    }
    
    /**
     * Find product in Custom Post Type
     */
    private static function find_in_cpt($product_id) {
        if (!post_type_exists('myfeeds_product')) {
            return null;
        }
        
        $posts = get_posts(array(
            'post_type' => 'myfeeds_product',
            'meta_key' => 'product_id',
            'meta_value' => $product_id,
            'posts_per_page' => 1,
        ));
        
        if (empty($posts)) {
            return null;
        }
        
        $post = $posts[0];
        $meta = get_post_meta($post->ID);
        
        return array(
            'id' => $product_id,
            'title' => $post->post_title,
            'image_url' => $meta['image_url'][0] ?? '',
            'affiliate_link' => $meta['affiliate_link'][0] ?? '',
            'price' => $meta['price'][0] ?? 0,
            'brand' => $meta['brand'][0] ?? '',
        );
    }
    
    // =========================================================================
    // JSON MODE WRITE HELPERS
    // =========================================================================
    
    /**
     * Write product to active JSON index (JSON mode only)
     */
    public static function write_to_active_index($product_id, $product) {
        // In DB mode, writing is handled by DB_Manager directly
        if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode()) {
            return;
        }
        
        $index = self::load_active_index();
        
        if (!$index) {
            $index = array(
                'items' => array(),
                '__search_fields' => array('title' => 3, 'brand' => 2),
            );
        }
        
        $index['items'][$product_id] = $product;
        $index['last_updated'] = time();
        
        $path = self::get_active_index_path();
        file_put_contents($path, json_encode($index), LOCK_EX);
        
        self::$active_index_cache = null;
    }
    
    /**
     * Load active JSON index (cached in static var, JSON mode only)
     */
    private static function load_active_index() {
        if (self::$active_index_cache !== null) {
            return self::$active_index_cache;
        }
        
        $path = self::get_active_index_path();
        
        if (!file_exists($path)) {
            return null;
        }
        
        $content = file_get_contents($path);
        self::$active_index_cache = json_decode($content, true);
        
        return self::$active_index_cache;
    }
    
    // =========================================================================
    // PATH HELPERS
    // =========================================================================
    
    public static function get_active_index_path() {
        return wp_upload_dir()['basedir'] . '/' . self::INDEX_FILE_ACTIVE;
    }
    
    public static function get_building_index_path() {
        return wp_upload_dir()['basedir'] . '/' . self::INDEX_FILE_BUILDING;
    }
    
    /**
     * Clear resolver cache (use after index swap or import complete)
     */
    public static function clear_cache() {
        self::$active_index_cache = null;
        
        // Also flush WP object cache group for DB mode
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('myfeeds');
        }
    }
    
    /**
     * Logging helper
     */
    private static function log_resolver($event, $data) {
        $message = 'MYFEEDS_RESOLVER_' . $event . ': ' . wp_json_encode($data, JSON_UNESCAPED_SLASHES);
        myfeeds_log($message, 'debug');
    }
}
