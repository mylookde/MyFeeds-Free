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
 * Free is single-feed, so the demo occupies the one slot. That is
 * deliberate: it shows what a configured feed looks like, and the moment
 * a real feed arrives the demo has done its job and is cleared out. See
 * MyFeeds_Feed_Manager::handle_save_feed(), which has to remove it
 * explicitly - the save path merges into the existing entry, so without
 * that step a real feed would inherit the demo's stable id and adopt its
 * products.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Demo_Content {

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
     * True when the configured feed is the demo. Every caller that would
     * fetch, sync or re-import asks this rather than looking at the url,
     * so the marker stays in one place.
     */
    public static function is_active() {
        $feeds = get_option(MyFeeds_Feed_Manager::OPTION_KEY, array());
        if (!is_array($feeds) || empty($feeds)) {
            return false;
        }
        return self::is_demo_feed(reset($feeds));
    }

    /**
     * Whether the offer should be shown at all. One feed means the user
     * is past this point, whether that feed is real or the demo itself -
     * which is why no "already dismissed" flag is needed. The absence of
     * a feed is the state, and it cannot go stale across an update.
     */
    public static function can_offer() {
        $feeds = get_option(MyFeeds_Feed_Manager::OPTION_KEY, array());
        return !is_array($feeds) || empty($feeds);
    }

    // =====================================================================
    // Actions
    // =====================================================================

    public static function handle_load() {
        self::guard('myfeeds_load_demo');

        if (!self::can_offer()) {
            self::redirect_back(__('A feed is already configured, so the samples were not loaded.', 'myfeeds-affiliate-feed-manager'), true);
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

        if (!self::is_active()) {
            self::redirect_back(__('There are no sample products to remove.', 'myfeeds-affiliate-feed-manager'), true);
        }

        self::remove();
        self::redirect_back(__('Sample products removed. The feed slot is free again.', 'myfeeds-affiliate-feed-manager'));
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
     * @return int products written
     */
    public static function load() {
        $entry = array(
            'name'               => self::FEED_NAME,
            'url'                => self::FEED_URL,
            'format_hint'        => '',
            'network_hint'       => '',
            'detected_network'   => 'demo',
            'detected_format'    => 'demo',
            'mapping'            => array(),
            'mapping_confidence' => 100,
            'status'             => 'ok',
            'is_demo'            => true,
            'created_at'         => current_time('mysql'),
            'last_updated'       => current_time('mysql'),
        );

        $feed_id = MyFeeds_DB_Manager::assign_stable_id($entry);
        update_option(MyFeeds_Feed_Manager::OPTION_KEY, array($entry));

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

        if (function_exists('myfeeds_log')) {
            myfeeds_log("Demo content loaded: {$written} products on feed {$feed_id}", 'info');
        }

        return $written;
    }

    /**
     * Products go before the feed entry: the other order would briefly
     * leave rows whose feed is already gone, which is exactly what the
     * orphan cleanup deletes on sight.
     */
    public static function remove() {
        global $wpdb;

        $feeds = get_option(MyFeeds_Feed_Manager::OPTION_KEY, array());
        $feed  = (is_array($feeds) && !empty($feeds)) ? reset($feeds) : null;

        if (!self::is_demo_feed($feed)) {
            return;
        }

        $feed_id = isset($feed['stable_id']) ? (int) $feed['stable_id'] : 0;
        if ($feed_id > 0) {
            $table = MyFeeds_DB_Manager::table_name();
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE feed_id = %d", $feed_id));
        }

        update_option(MyFeeds_Feed_Manager::OPTION_KEY, array());

        if (function_exists('myfeeds_log')) {
            myfeeds_log("Demo content removed (feed {$feed_id})", 'info');
        }
    }

    /**
     * Called from the save path when a real feed replaces the demo, so
     * the demo's rows do not survive under a feed that never imported
     * them.
     */
    public static function remove_if_active() {
        if (self::is_active()) {
            self::remove();
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
