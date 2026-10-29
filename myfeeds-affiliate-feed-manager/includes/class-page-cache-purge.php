<?php
/**
 * After an import, the cached copies of the posts that show products are
 * dropped - those posts, and nothing else.
 *
 * A product card is rendered on the server. A page cache therefore keeps
 * the price and the stock of the moment it stored the page, and a product
 * MyFeeds already leaves out of the post (sold out, gone) stays on the
 * cached copy until the cache expires on its own. That can be long: four
 * BigScoots sites sent cdn-cache-control: max-age=31536000 (a year) on
 * 29.10.2026, the oldest copy measured was 13 days old. Before this file
 * no import cleared a single cached post.
 *
 * Every call below was read in the cache plugin's own source on
 * 29.10.2026 (BigScoots: its developer documentation, the code is not
 * public); the comment names version and file. Each one empties the post
 * and nothing more. The obvious per-post helpers of several plugins are
 * deliberately NOT used, because they take the front page, archives and
 * feeds along, and through Varnish or Cloudflare sometimes the whole site:
 * wpfc_clear_post_cache_by_id, w3tc_flush_post, litespeed_purge_post,
 * the purge_post_cache action of Breeze, purge_post() of Nginx Helper,
 * and wphb_clear_page_cache.
 *
 * Nothing here is a requirement. Each block runs only when its plugin is
 * loaded and does nothing otherwise; without any cache plugin the class
 * does not even ask the database.
 *
 * Shared with Free byte for byte.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Page_Cache_Purge {

    /**
     * BigScoots splits its own purges into batches of this size
     * (bs_cache_purge_queue_max). Above it a site has more product posts
     * than one night should push through every cache at once; the newest
     * go first and the log says how many waited.
     */
    const MAX_POSTS = 2000;

    public static function init() {
        add_action('myfeeds_feed_update_complete', array(__CLASS__, 'purge_product_posts'), 30, 0);
        // A single-feed import (Add Feed, Reimport, a refreshed API source)
        // ends without myfeeds_feed_update_complete. Same transition the
        // shop and the source module watch: running -> completed, once.
        add_action('update_option_myfeeds_import_status', array(__CLASS__, 'after_single_feed_import'), 10, 2);
    }

    public static function after_single_feed_import($old, $new) {
        if (!is_array($new) || ($new['status'] ?? '') !== 'completed') {
            return;
        }
        if (($new['import_type'] ?? '') !== 'single_feed') {
            return;
        }
        if (is_array($old) && ($old['status'] ?? '') === 'completed') {
            return;
        }
        self::purge_product_posts();
    }

    /**
     * @return string[] The caches that were asked, for the log and tests.
     */
    public static function purge_product_posts() {
        $caches = self::active_caches();
        if (empty($caches)) {
            return array();
        }

        $ids = self::product_post_ids();
        $waiting = 0;
        if (count($ids) > self::MAX_POSTS) {
            $waiting = count($ids) - self::MAX_POSTS;
            $ids = array_slice($ids, 0, self::MAX_POSTS);
        }

        $urls = array();
        foreach ($ids as $id) {
            $url = self::post_url($id);
            if ($url !== '') {
                $urls[$id] = $url;
            }
        }
        if (empty($urls)) {
            return array();
        }

        self::purge($urls, $caches);

        if (function_exists('myfeeds_log')) {
            $line = sprintf(
                'Page cache: cleared the cached copies of %d posts with products after the import (%s)',
                count($urls),
                implode(', ', $caches)
            );
            if ($waiting > 0) {
                $line .= sprintf('; %d more wait for the next import', $waiting);
            }
            myfeeds_log($line, 'info');
        }
        return $caches;
    }

    /**
     * The cache plugins on this site that the purge below knows how to
     * reach. Detected by what each one defines; a disabled plugin defines
     * nothing.
     *
     * @return string[]
     */
    public static function active_caches() {
        $caches = array();
        if (class_exists('BigScoots_Cache') && method_exists('BigScoots_Cache', 'clear_cache')) {
            $caches[] = 'bigscoots';
        }
        if (function_exists('rocket_clean_files')) {
            $caches[] = 'wp-rocket';
        }
        if (defined('LSCWP_V')) {
            $caches[] = 'litespeed';
        }
        if (function_exists('sg_cachepress_purge_cache')) {
            $caches[] = 'sg-optimizer';
        }
        // The advanced-cache.php drop-in loads the API file even while the
        // plugin itself is off; only the plugin sets w3tc_root.
        if (function_exists('w3tc_flush_url') && isset($GLOBALS['w3tc_root'])) {
            $caches[] = 'w3-total-cache';
        }
        // Same drop-in problem: wpsc_delete_url_cache() comes with the
        // drop-in, wpsc_delete_post_cache() only with the plugin.
        if (function_exists('wpsc_delete_post_cache') && function_exists('wpsc_delete_url_cache')) {
            $caches[] = 'wp-super-cache';
        }
        if (function_exists('wpfc_clear_cache_by_url')) {
            $caches[] = 'wp-fastest-cache';
        }
        if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_page_cache_by_post')) {
            $caches[] = 'cache-enabler';
        }
        if (function_exists('breeze_purge_local_cache_for_urls') && function_exists('breeze_get_cache_base_path')) {
            $caches[] = 'breeze';
        }
        if (self::nginx_purger() !== null) {
            $caches[] = 'nginx-helper';
        }
        if (self::hummingbird_module() !== null) {
            $caches[] = 'hummingbird';
        }
        if (class_exists('WPO_Page_Cache') && method_exists('WPO_Page_Cache', 'delete_single_post_cache')) {
            $caches[] = 'wp-optimize';
        }
        return $caches;
    }

    /**
     * Published posts of every type a block can be published in that
     * carry a MyFeeds block or shortcode, newest first - the same markers
     * Quick Sync reads.
     *
     * Synced patterns, templates and template parts have no address of
     * their own and sit on many pages at once; clearing those pages would
     * mean clearing most of the site every night, so they are left to the
     * cache's own expiry. The storefront has its own, more exact purge
     * (MyFeeds_Cache_Compat::purge_storefront() in Pro).
     *
     * @return int[]
     */
    public static function product_post_ids() {
        global $wpdb;
        if (!isset($wpdb) || !function_exists('myfeeds_discovery_post_types')) {
            return array();
        }
        $types = array_diff(myfeeds_discovery_post_types(), array('wp_block', 'wp_template', 'wp_template_part'));
        if (empty($types)) {
            return array();
        }
        $types_sql = "'" . implode("', '", $types) . "'";

        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND post_type IN ({$types_sql})
             AND (
                 post_content LIKE '%wp:myfeeds%'
                 OR post_content LIKE '%[myfeeds%'
                 OR post_content LIKE '%wp:product-picker%'
             )
             ORDER BY ID DESC
             LIMIT " . (self::MAX_POSTS + 1000)
        );

        $storefront = function_exists('get_option') ? (int) get_option('myfeeds_shop_storefront_page_id', 0) : 0;
        $out = array();
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($id > 0 && $id !== $storefront) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * The post's address, or '' when it must not be handed to a cache.
     *
     * Never the front page: SG Optimizer and BigScoots clear a URL and
     * everything beneath it, and beneath the front page is the whole
     * site - SG Optimizer even falls back to the front page for an empty
     * URL. Never an address with a query string: WP Super Cache rejects
     * it and Cache Enabler reads it as its own arguments. Never another
     * host.
     */
    public static function post_url($id) {
        $url = function_exists('get_permalink') ? get_permalink((int) $id) : '';
        if (!is_string($url) || $url === '' || strpos($url, '?') !== false || strpos($url, '#') !== false) {
            return '';
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return '';
        }
        if (!in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            return '';
        }
        $home = wp_parse_url(home_url('/'));
        if (!is_array($home) || empty($home['host']) || strtolower($parts['host']) !== strtolower($home['host'])) {
            return '';
        }
        $path = trim($parts['path'] ?? '', '/');
        $home_path = trim($home['path'] ?? '', '/');
        if ($path === '' || $path === $home_path) {
            return '';
        }
        return $url;
    }

    /**
     * @param array<int,string> $urls Post id => address, already cleaned.
     * @param string[]          $caches From active_caches().
     */
    private static function purge(array $urls, array $caches) {
        $ids = array_keys($urls);

        foreach ($caches as $cache) {
            try {
                switch ($cache) {
                    case 'bigscoots':
                        // Developer docs "Customizing BigScoots Cache":
                        // clear_cache([ids]) without a second argument
                        // clears the posts only, `true` would add the front
                        // page and archives. Takes many ids in one call.
                        $result = \BigScoots_Cache::clear_cache(array_map('intval', $ids));
                        if (is_array($result) && empty($result['success']) && function_exists('myfeeds_log')) {
                            myfeeds_log('Page cache: BigScoots did not clear the posts: ' . (string) ($result['message'] ?? ''), 'warning');
                        }
                        break;

                    case 'wp-rocket':
                        // rocket_clean_files() removes the cache folder of
                        // each URL - the post and its /page/2/ copies - and
                        // tells Rocket's preload and CDN integrations
                        // (inc/functions/files.php, 3.23.3.3).
                        rocket_clean_files(array_values($urls));
                        break;

                    case 'litespeed':
                        // The tag 'Po.<id>' sits on that one post
                        // (src/tag.cls.php, 7.9.2). From cron or WP-CLI
                        // LiteSpeed only queues the purge for the next web
                        // request; kick_purge_queue() sends it now, as its
                        // own `wp litespeed-purge url` does.
                        foreach ($ids as $id) {
                            do_action('litespeed_purge', 'Po.' . (int) $id);
                        }
                        if (defined('LITESPEED_CLI') && method_exists('\LiteSpeed\Core', 'kick_purge_queue')) {
                            \LiteSpeed\Core::kick_purge_queue();
                        }
                        break;

                    case 'sg-optimizer':
                        // The URL and every path below it, dynamic and file
                        // cache (helpers/helpers.php, 7.8.5).
                        foreach ($urls as $url) {
                            sg_cachepress_purge_cache($url);
                        }
                        break;

                    case 'w3-total-cache':
                        // Exactly this URL in all its variants
                        // (w3-total-cache-api.php, 2.11.0). The deletion is
                        // queued until shutdown, which a long Action
                        // Scheduler run may never reach in time - so the
                        // queue is run here.
                        foreach ($urls as $url) {
                            w3tc_flush_url($url);
                        }
                        if (class_exists('\W3TC\Dispatcher', false) && method_exists('\W3TC\Dispatcher', 'component')) {
                            $flush = \W3TC\Dispatcher::component('CacheFlush');
                            if (is_object($flush) && method_exists($flush, 'execute_delayed_operations')) {
                                $flush->execute_delayed_operations();
                            }
                        }
                        break;

                    case 'wp-super-cache':
                        // The post's folder and its /page/ copies
                        // (wp-cache-phase2.php, 3.1.4).
                        foreach ($urls as $url) {
                            wpsc_delete_url_cache($url);
                        }
                        break;

                    case 'wp-fastest-cache':
                        // Exactly the two files of this URL, desktop and
                        // mobile (wpFastestCache.php, 1.5.3).
                        foreach ($urls as $url) {
                            wpfc_clear_cache_by_url($url);
                        }
                        break;

                    case 'cache-enabler':
                        // The post and its paginated copies, nothing else
                        // (inc/cache_enabler.class.php, 1.8.17).
                        foreach ($ids as $id) {
                            \Cache_Enabler::clear_page_cache_by_post((int) $id, 'pagination');
                        }
                        break;

                    case 'breeze':
                        // The local file cache of these URLs only
                        // (inc/helpers.php, 2.6.3). Breeze's Varnish purge
                        // is left out: its post hook clears all of Varnish.
                        breeze_purge_local_cache_for_urls(array_values($urls), breeze_get_cache_base_path());
                        break;

                    case 'nginx-helper':
                        // purge_url($url, false): this URL, no feeds
                        // (admin/class-purger.php, 2.4.1).
                        $purger = self::nginx_purger();
                        foreach ($urls as $url) {
                            $purger->purge_url($url, false);
                        }
                        break;

                    case 'hummingbird':
                        // The post's own folder; an empty path would clear
                        // the whole cache, which post_url() rules out
                        // (core/modules/class-page-cache.php, 3.21.2).
                        $module = self::hummingbird_module();
                        foreach ($urls as $url) {
                            $path = (string) wp_parse_url($url, PHP_URL_PATH);
                            if (trim($path, '/') !== '') {
                                $module->clear_cache(trailingslashit($path));
                            }
                        }
                        break;

                    case 'wp-optimize':
                        // All variants of this one URL
                        // (cache/class-wpo-page-cache.php, 4.7.0).
                        foreach ($ids as $id) {
                            \WPO_Page_Cache::delete_single_post_cache((int) $id);
                        }
                        break;
                }
            } catch (\Throwable $e) {
                if (function_exists('myfeeds_log')) {
                    myfeeds_log('Page cache: ' . $cache . ' failed to clear posts: ' . $e->getMessage(), 'warning');
                }
            }
        }
    }

    /**
     * Nginx Helper's purger object, but only with purging switched on in
     * its settings - purge_url() does not check that itself.
     */
    private static function nginx_purger() {
        if (!defined('NGINX_HELPER_BASENAME')) {
            return null;
        }
        global $nginx_purger, $nginx_helper_admin;
        if (!is_object($nginx_purger) || !method_exists($nginx_purger, 'purge_url')) {
            return null;
        }
        if (!is_object($nginx_helper_admin) || empty($nginx_helper_admin->options['enable_purge'])) {
            return null;
        }
        return $nginx_purger;
    }

    private static function hummingbird_module() {
        if (!defined('WPHB_VERSION') || !class_exists('\Hummingbird\Core\Utils') || !method_exists('\Hummingbird\Core\Utils', 'get_module')) {
            return null;
        }
        $module = \Hummingbird\Core\Utils::get_module('page_cache');
        return (is_object($module) && method_exists($module, 'clear_cache')) ? $module : null;
    }
}

MyFeeds_Page_Cache_Purge::init();
