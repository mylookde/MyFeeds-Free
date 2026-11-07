<?php
/**
 * Sample products, so a fresh install has something to look at.
 *
 * The plugin's whole value is visual - a searchable catalogue and cards
 * that look like the blog wrote them - and none of that is visible until
 * a feed has been imported. Someone who installs from the directory
 * usually does not have a feed URL to hand, so the first screen they meet
 * is a form asking for one. This gives them a second door: seven products
 * that behave exactly like imported ones.
 *
 * Nothing here runs on its own. The products are written only when the
 * user asks for them, which is also what keeps this on the right side of
 * the directory guidelines - a plugin does not put rows in someone's
 * database because it was activated.
 *
 * Everything ships with the plugin. No request leaves the site to load
 * the demo, because "the frontend never calls an external service" has to
 * stay true for the sample data too.
 *
 * Both MyFeeds plugins carry this file, and the two copies differ in the
 * text domain only. So nothing in here knows what else a site runs; what
 * a plugin does on top hangs off the two actions load() and remove() fire.
 *
 * The samples are a feed entry like any other, marked is_demo, and they
 * last until the first real source arrives. Both save paths in
 * MyFeeds_Feed_Manager call remove_if_active() before they write the new
 * entry - a save path that merges into the existing entry would otherwise
 * give a real feed the demo's stable id and its products - and every
 * other way a source arrives does the same.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Demo_Content {

    /**
     * Read here rather than through MyFeeds_Feed_Manager::OPTION_KEY, so
     * the code that only asks "are the samples loaded?" does not need the
     * whole feeds screen loaded to answer.
     */
    const OPTION_KEY = 'myfeeds_feeds';

    /**
     * Where a demo card goes. Not a merchant and not an affiliate link -
     * a page that explains what the reader just clicked.
     */
    const LINK_TARGET = 'https://myfeeds.site/demo-product/';

    /**
     * Non-empty on purpose. get_displayable_feeds() drops any entry with
     * an empty url and rewrites the option, so a feed without one would
     * vanish on the next page load. The scheme is not http, so a path
     * that tries to fetch it anyway fails locally instead of reaching
     * out to some real host.
     */
    const FEED_URL  = 'demo://sample-products';

    const FEED_NAME = 'Demo products (sample data)';

    /**
     * Words that are certainly in product_name, so the suggestion in the
     * next-steps card cannot come back empty. search_text is a FULLTEXT
     * column, so a term has to be a whole word and at least three
     * characters - "bag" would miss the two products filed under "Bags".
     */
    const SEARCH_HINTS = array('sneaker', 'hoodie', 'backpack');

    public static function init() {
        add_action('admin_post_myfeeds_load_demo', array(__CLASS__, 'handle_load'));
        add_action('admin_post_myfeeds_remove_demo', array(__CLASS__, 'handle_remove'));
        add_action('admin_post_myfeeds_demo_draft', array(__CLASS__, 'handle_draft'));
    }

    // =====================================================================
    // State
    // =====================================================================

    public static function is_demo_feed($feed) {
        return is_array($feed) && !empty($feed['is_demo']);
    }

    /**
     * The configured feeds without the samples, as a list: what counts as
     * a feed of the site, and what an import may fetch.
     *
     * @param mixed $feeds The myfeeds_feeds option.
     * @return array
     */
    public static function without_samples($feeds) {
        if (!is_array($feeds)) {
            return array();
        }

        $real = array();
        foreach ($feeds as $feed) {
            if (!self::is_demo_feed($feed)) {
                $real[] = $feed;
            }
        }

        return $real;
    }

    /**
     * True while sample products are loaded, whatever else is configured.
     * The question behind "may they be removed" and "does a sample card
     * still have anything behind it".
     */
    public static function is_loaded() {
        $feeds = get_option(self::OPTION_KEY, array());
        if (!is_array($feeds)) {
            return false;
        }

        foreach ($feeds as $feed) {
            if (self::is_demo_feed($feed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the samples are all that is configured: there is no feed
     * to fetch, sync or re-import. Every caller that would do one of those
     * asks this rather than looking at the url, so the marker stays in one
     * place.
     *
     * With one feed, this is "that feed is the demo". The samples never
     * share the list with a real feed - the real one replaces them - but
     * if they ever did, the real one must still sync. That is why this
     * asks "is there nothing else" and not "are the samples there".
     */
    public static function is_active() {
        $feeds = get_option(self::OPTION_KEY, array());
        if (!is_array($feeds) || empty($feeds)) {
            return false;
        }

        return self::is_loaded() && count(self::without_samples($feeds)) === 0;
    }

    /**
     * Whether the offer should be shown at all. One feed means the user
     * is past this point, whether that feed is real or the demo itself -
     * which is why no "already dismissed" flag is needed. The absence of
     * a feed is the state, and it cannot go stale across an update.
     */
    public static function can_offer() {
        $feeds = get_option(self::OPTION_KEY, array());
        return !is_array($feeds) || empty($feeds);
    }

    /**
     * Whether a link is the one every sample card carries.
     *
     * The samples belong to no network and name no merchant, so once a
     * block holds its own copy of one, the link is all that still says
     * what it was.
     *
     * @param string $url
     * @return bool
     */
    public static function is_sample_link($url) {
        $url = strtolower(trim((string) $url));
        if ($url === '') {
            return false;
        }

        return rtrim($url, '/') === rtrim(self::LINK_TARGET, '/');
    }

    /**
     * Whether an id is one of the seven samples.
     *
     * A Look point saves no link, and the samples' rows leave with them,
     * so for a point the id is the only thing left that says it was a
     * sample.
     *
     * @param string $id
     * @return bool
     */
    public static function is_sample_id($id) {
        $id = (string) $id;
        if (strpos($id, 'demo-') !== 0) {
            return false;
        }
        foreach (self::products() as $product) {
            if ($product['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    // =====================================================================
    // Actions
    // =====================================================================

    public static function handle_load() {
        self::guard('myfeeds_load_demo');

        if (!self::can_offer()) {
            self::redirect_back(__('A feed is already configured, so the samples were not loaded.', 'myfeeds-affiliate-feed-manager'), true);
        }

        if (class_exists('MyFeeds_Storage_Job') && !MyFeeds_Storage_Job::writers_may_proceed()) {
            self::redirect_back(__('The products table is being rebuilt right now. Try again when the storage job has finished.', 'myfeeds-affiliate-feed-manager'), true);
        }

        $count = self::load();

        if ($count > 0) {
            self::redirect_back(sprintf(
                /* translators: %d: number of sample products written */
                __('%d sample products loaded. Open any post and add the MyFeeds Product Picker block to try them.', 'myfeeds-affiliate-feed-manager'),
                $count
            ));
        } else {
            self::redirect_back(__('The sample products could not be written. Nothing was changed.', 'myfeeds-affiliate-feed-manager'), true);
        }
    }

    public static function handle_remove() {
        self::guard('myfeeds_remove_demo');

        if (!self::is_loaded()) {
            self::redirect_back(__('There are no sample products to remove.', 'myfeeds-affiliate-feed-manager'), true);
        }

        if (!self::remove()) {
            self::redirect_back(__('The sample products could not be removed right now. Try again in a few minutes.', 'myfeeds-affiliate-feed-manager'), true);
        }

        self::redirect_back(__('Sample products removed. Add your own feed whenever you are ready.', 'myfeeds-affiliate-feed-manager'));
    }

    /**
     * Opens the editor on a draft that already contains the block.
     *
     * Finding it by hand means knowing that the thing you want is a
     * block, that it is called Product Picker, and that MyFeeds is what
     * you type to reach it. That is three pieces of knowledge a first-time
     * user has no way to have, and it sits between them and the only
     * screen where the plugin does anything visible.
     *
     * A draft rather than a published post: it is scaffolding, and the
     * user throws it away or keeps writing in it.
     */
    public static function handle_draft() {
        self::guard('myfeeds_demo_draft');

        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have permission to create posts.', 'myfeeds-affiliate-feed-manager'));
        }

        $post_id = wp_insert_post(array(
            'post_title'   => __('MyFeeds sample post', 'myfeeds-affiliate-feed-manager'),
            'post_content' => '<!-- wp:myfeeds/product-picker /-->',
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ), true);

        if (is_wp_error($post_id) || !$post_id) {
            self::redirect_back(__('The draft could not be created. Add the MyFeeds Product Picker block to a post yourself instead.', 'myfeeds-affiliate-feed-manager'), true);
        }

        wp_safe_redirect(admin_url('post.php?post=' . (int) $post_id . '&action=edit'));
        exit;
    }

    private static function guard($nonce_action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'myfeeds-affiliate-feed-manager'));
        }
        check_admin_referer($nonce_action);
    }

    /**
     * Uses the notice parameters the feeds screen already reads in
     * display_admin_messages(), rather than adding a second way to say
     * the same thing.
     */
    private static function redirect_back($message, $is_error = false) {
        wp_safe_redirect(add_query_arg(
            $is_error ? 'myfeeds_error' : 'myfeeds_success',
            rawurlencode($message),
            admin_url('admin.php?page=myfeeds-feeds')
        ));
        exit;
    }

    // =====================================================================
    // Load and remove
    // =====================================================================

    /**
     * Writes the feed entry first, so that a failure while inserting
     * products still leaves something the user can remove from the UI
     * rather than an invisible half-state.
     *
     * Only into an empty list: the entry replaces the whole option, which
     * is right for exactly one state, the one the offer is shown in.
     *
     * @return int products written
     */
    public static function load() {
        if (!self::can_offer()) {
            return 0;
        }

        // While the storage job copies the table, nothing may change it.
        if (class_exists('MyFeeds_Storage_Job') && !MyFeeds_Storage_Job::writers_may_proceed()) {
            return 0;
        }

        // 'active', not a status of its own: the feeds table knows four,
        // and anything else reads "Unknown" on the row.
        $entry = array(
            'name'               => self::FEED_NAME,
            'url'                => self::FEED_URL,
            'format_hint'        => '',
            'network_hint'       => '',
            'detected_network'   => 'demo',
            'detected_format'    => 'demo',
            'mapping'            => array(),
            'mapping_confidence' => 100,
            'status'             => 'active',
            'is_demo'            => true,
            'created_at'         => current_time('mysql'),
            'last_updated'       => current_time('mysql'),
        );

        $feed_id = MyFeeds_DB_Manager::assign_stable_id($entry);
        update_option(self::OPTION_KEY, array($entry));

        $products = array();
        foreach (self::products() as $product) {
            $products[$product['id']] = $product;
        }

        MyFeeds_DB_Manager::upsert_batch($products, $feed_id, self::FEED_NAME);

        // Counted rather than taken from the return value: upsert_batch
        // reports affected rows, and MySQL scores an updated row as two,
        // so that number answers a different question than "is it there".
        global $wpdb;
        $table   = MyFeeds_DB_Manager::table_name();
        $written = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE feed_id = %d",
            $feed_id
        ));

        // The row reads its count from the entry; nothing imports the
        // samples, so nothing else would ever write it.
        $entry['product_count'] = $written;
        update_option(self::OPTION_KEY, array($entry));

        self::forget_cached_answers();

        /**
         * The samples are in the catalogue.
         *
         * @param int $feed_id Stable id of the sample feed.
         * @param int $written Products written.
         */
        do_action('myfeeds_demo_loaded', $feed_id, $written);

        if (function_exists('myfeeds_log')) {
            myfeeds_log("Demo content loaded: {$written} products on feed {$feed_id}", 'info');
        }

        return $written;
    }

    /**
     * Products go before the feed entry: the other order would briefly
     * leave rows whose feed is already gone, which is exactly what the
     * orphan cleanup deletes on sight - and, for a row a post still shows,
     * keeps as a frozen card for good.
     *
     * Every sample row goes, a placed one included. Real products a post
     * shows are kept when their feed is deleted, so the post can still say
     * what it lost. A sample has nothing to lose: a post that still holds
     * one keeps its own copy, and that copy's link is enough to recognise
     * it by (is_sample_link()).
     *
     * Only the samples are taken out of the list; anything else in it
     * stays where it is.
     *
     * @return bool True when samples were removed.
     */
    public static function remove() {
        global $wpdb;

        // While the storage job copies the table, nothing may change it.
        if (class_exists('MyFeeds_Storage_Job') && !MyFeeds_Storage_Job::writers_may_proceed()) {
            return false;
        }

        $feeds = get_option(self::OPTION_KEY, array());
        if (!is_array($feeds)) {
            return false;
        }

        $feed_ids = array();
        $has_samples = false;
        foreach ($feeds as $feed) {
            if (!self::is_demo_feed($feed)) {
                continue;
            }
            $has_samples = true;
            if (!empty($feed['stable_id'])) {
                $feed_ids[] = (int) $feed['stable_id'];
            }
        }
        if (!$has_samples) {
            return false;
        }

        $product_ids = array();
        if (!empty($feed_ids)) {
            $table = MyFeeds_DB_Manager::table_name();
            $ph    = implode(',', array_fill(0, count($feed_ids), '%d'));
            $product_ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$table} WHERE feed_id IN ({$ph})",
                $feed_ids
            )));
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE feed_id IN ({$ph})", $feed_ids));
        }

        update_option(self::OPTION_KEY, self::without_samples($feeds));

        self::forget_cached_answers();

        /**
         * The samples are gone from the catalogue.
         *
         * @param int[] $product_ids Primary keys of the rows that were deleted.
         * @param int[] $feed_ids    Stable ids of the sample feeds.
         */
        do_action('myfeeds_demo_removed', $product_ids, $feed_ids);

        if (function_exists('myfeeds_log')) {
            myfeeds_log('Demo content removed (feed ' . implode(',', $feed_ids) . ', ' . count($product_ids) . ' products)', 'info');
        }

        return true;
    }

    /**
     * Called from the save paths when a real feed replaces the demo, so
     * the demo's rows do not survive under a feed that never imported
     * them.
     *
     * @return bool True when samples were removed.
     */
    public static function remove_if_active() {
        if (!self::is_loaded()) {
            return false;
        }

        return self::remove();
    }

    /**
     * Answers that are kept for a few minutes would otherwise outlive the
     * change: a picker search that found nothing before the samples were
     * loaded would go on finding nothing, and one that found them would go
     * on offering them after they are gone.
     */
    private static function forget_cached_answers() {
        delete_transient('myfeeds_active_count_cache');
        delete_transient('myfeeds_feed_counts_cache');

        if (class_exists('MyFeeds_Search_Engine') && method_exists('MyFeeds_Search_Engine', 'flush_query_caches')) {
            MyFeeds_Search_Engine::flush_query_caches();
        }
        if (class_exists('MyFeeds_Product_Resolver') && method_exists('MyFeeds_Product_Resolver', 'clear_cache')) {
            MyFeeds_Product_Resolver::clear_cache();
        }
    }

    // =====================================================================
    // The products
    // =====================================================================

    /**
     * Invented brands, invented merchants, invented prices. None of this
     * may look like a real listing: real product photos would not be ours
     * to ship, and a real affiliate link would put our tracking into
     * someone else's posts.
     *
     * The link goes to a page that says it is a demo, rather than to '#'.
     * A dead anchor hides the last third of what the plugin does - the
     * card is a link, it opens in a new tab, it carries rel="nofollow
     * sponsored" - and a reader who lands on a published demo post from
     * anywhere gets an explanation instead of a page that does nothing.
     *
     * Two are on sale and the colours differ, because the discount badge
     * and the colour filter are among the things worth seeing before you
     * decide whether the plugin is for you.
     */
    public static function products() {
        $img = MYFEEDS_PLUGIN_URL . 'assets/demo/';

        return array(
            array(
                'id'             => 'demo-sneaker-white-lowtop',
                'title'          => 'Low-Top Sneaker White',
                'brand'          => 'Nordic Trail',
                'category'       => 'Shoes',
                'colour'         => 'White',
                'price'          => 59.90,
                'old_price'      => 79.90,
                'currency'       => 'USD',
                'image_url'      => $img . 'sneaker-white-lowtop.webp',
                'affiliate_link' => self::LINK_TARGET,
                'in_stock'       => 1,
                'merchant'       => 'Stockroom',
            ),
            array(
                'id'             => 'demo-sneaker-black-hightop',
                'title'          => 'High-Top Sneaker Black',
                'brand'          => 'Nordic Trail',
                'category'       => 'Shoes',
                'colour'         => 'Black',
                'price'          => 84.90,
                'currency'       => 'USD',
                'image_url'      => $img . 'sneaker-black-hightop.webp',
                'affiliate_link' => self::LINK_TARGET,
                'in_stock'       => 1,
                'merchant'       => 'Stockroom',
            ),
            array(
                'id'             => 'demo-hoodie-heather-grey',
                'title'          => 'Cotton Hoodie Heather Grey',
                'brand'          => 'Studio Form',
                'category'       => 'Hoodies',
                'colour'         => 'Grey',
                'price'          => 89.00,
                'currency'       => 'USD',
                'image_url'      => $img . 'hoodie-heather-grey.webp',
                'affiliate_link' => self::LINK_TARGET,
                'in_stock'       => 1,
                'merchant'       => 'Outlet24',
            ),
            array(
                'id'             => 'demo-tote-canvas-natural',
                'title'          => 'Canvas Tote Natural',
                'brand'          => 'Atelier',
                'category'       => 'Bags',
                'colour'         => 'Natural',
                'price'          => 48.00,
                'currency'       => 'USD',
                'image_url'      => $img . 'tote-canvas-natural.webp',
                'affiliate_link' => self::LINK_TARGET,
                'in_stock'       => 1,
                'merchant'       => 'Stockroom',
            ),
            array(
                'id'             => 'demo-beanie-knit-navy',
                'title'          => 'Knit Beanie Rib Navy',
                'brand'          => 'Bergen',
                'category'       => 'Accessories',
                'colour'         => 'Navy',
                'price'          => 29.00,
                'currency'       => 'USD',
                'image_url'      => $img . 'beanie-knit-navy.webp',
                'affiliate_link' => self::LINK_TARGET,
                'in_stock'       => 1,
                'merchant'       => 'Nord Store',
            ),
            array(
                'id'             => 'demo-backpack-trail',
                'title'          => 'Trail Backpack 24L',
                'brand'          => 'Field & Co',
                'category'       => 'Bags',
                'colour'         => 'Olive',
                'price'          => 119.00,
                'old_price'      => 149.00,
                'currency'       => 'USD',
                'image_url'      => $img . 'backpack-trail.webp',
                'affiliate_link' => self::LINK_TARGET,
                'in_stock'       => 1,
                'merchant'       => 'Outlet24',
            ),
            array(
                'id'             => 'demo-tee-boxy-offwhite',
                'title'          => 'Boxy Tee Off-White',
                'brand'          => 'Maison Rue',
                'category'       => 'T-Shirts',
                'colour'         => 'Off-White',
                'price'          => 39.00,
                'currency'       => 'USD',
                'image_url'      => $img . 'tee-boxy-offwhite.webp',
                'affiliate_link' => self::LINK_TARGET,
                'in_stock'       => 1,
                'merchant'       => 'Nord Store',
            ),
        );
    }
}
