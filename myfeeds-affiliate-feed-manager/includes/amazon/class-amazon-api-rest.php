<?php
/**
 * REST surface of Amazon's API (the Creators API).
 *
 * Saving the Amazon site and the partner tag is MyFeeds_Amazon_REST, the
 * links' own route. The routes here are Amazon's API. Those that reach
 * Amazon - search, browse, items, live prices - are registered only where
 * the API runs (MyFeeds_Amazon_Api::active()); the connection's own -
 * status, secret, test - also where the API may be connected now
 * (MyFeeds_Source_Rules::can_connect()). Where it runs the shared save
 * route also takes the credentials, and answers with the connection's
 * state.
 *
 * Editor routes need edit_posts; connection routes need manage_options.
 * The one public route serves live prices to cached pages — see the note
 * on live_prices() for why that has to exist.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Api_REST {

    /** The two filters of the save route are hung on by MyFeeds_Amazon_Api::filters(). */
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        $runs = MyFeeds_Amazon_Api::active();
        if (!$runs && !(class_exists('MyFeeds_Source_Rules') && MyFeeds_Source_Rules::can_connect('amazon_api'))) {
            return;
        }

        // Removing the connection deletes the credentials and Amazon's
        // cached data. Not while they are in use: where Amazon's API is
        // the site's product source, the way off it is to change the
        // source, which says first what leaves the posts.
        if (MyFeeds_Source_Rules::can_disconnect('amazon_api')) {
            register_rest_route(MyFeeds_Amazon_REST::NS, '/amazon/connection', array(
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array(__CLASS__, 'disconnect'),
                    'permission_callback' => array('MyFeeds_Amazon_REST', 'can_manage'),
                ),
            ));
        }

        register_rest_route(MyFeeds_Amazon_REST::NS, '/amazon/status', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'status'),
            'permission_callback' => array('MyFeeds_Amazon_REST', 'can_edit'),
        ));

        register_rest_route(MyFeeds_Amazon_REST::NS, '/amazon/secret', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'reveal_secret'),
            'permission_callback' => array('MyFeeds_Amazon_REST', 'can_manage'),
        ));

        register_rest_route(MyFeeds_Amazon_REST::NS, '/amazon/test', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'test'),
            'permission_callback' => array('MyFeeds_Amazon_REST', 'can_manage'),
        ));

        if (!$runs) {
            return;
        }

        register_rest_route(MyFeeds_Amazon_REST::NS, '/amazon/search', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'search'),
            'permission_callback' => array('MyFeeds_Amazon_REST', 'can_edit'),
        ));

        register_rest_route(MyFeeds_Amazon_REST::NS, '/amazon/browse', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'browse'),
            'permission_callback' => array('MyFeeds_Amazon_REST', 'can_edit'),
        ));

        register_rest_route(MyFeeds_Amazon_REST::NS, '/amazon/items', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'items'),
            'permission_callback' => array('MyFeeds_Amazon_REST', 'can_edit'),
        ));

        register_rest_route(MyFeeds_Amazon_REST::NS, '/amazon/live', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'live_prices'),
            'permission_callback' => '__return_true',
            'args'                => array(
                'ids' => array('required' => true),
            ),
        ));
    }

    // =====================================================================
    // Connection
    // =====================================================================

    /**
     * The connection's state. The editor asks it too (edit_posts), so what
     * only the settings window needs - the credential, whether a secret is
     * stored, the version, the request budget, the cache - goes to
     * administrators alone.
     */
    public static function status(WP_REST_Request $request) {
        $connection = MyFeeds_Amazon_Credentials::get();
        $status     = MyFeeds_Amazon_Credentials::get_status();
        $marketplace = $connection['marketplace'];

        $data = array(
            'connected'        => MyFeeds_Amazon_Credentials::is_configured(),
            'state'            => $status['state'],
            'message'          => $status['message'],
            'checked_at'       => (int) $status['checked_at'],
            'marketplace'      => $marketplace,
            'marketplace_label' => MyFeeds_Amazon_Locales::label($marketplace),
            'partner_tag'      => MyFeeds_Amazon_Credentials::partner_tag($marketplace),
            'marketplaces'     => MyFeeds_Amazon_Locales::all(),
            'available'        => MyFeeds_Amazon_Credentials::available_marketplaces(),
            'search_indices'   => MyFeeds_Amazon_Api_Locales::search_indices($marketplace),
            'creators_api_url' => MyFeeds_Amazon_Api_Locales::creators_api_url($marketplace),
            'license_url'      => MyFeeds_Amazon_Locales::license_url($marketplace),
            'expected_version' => MyFeeds_Amazon_Api_Locales::expected_version($marketplace),
            'badge_id'         => MyFeeds_Amazon_Credentials::badge_id(),
        );
        if (current_user_can('manage_options')) {
            $data['credential_id'] = $connection['credential_id'];
            $data['has_secret']    = $connection['credential_secret'] !== '';
            $data['version']       = $connection['credential_version'];
            $data['budget']        = array(
                'used'  => MyFeeds_Amazon_Client::calls_used_today(),
                'total' => MyFeeds_Amazon_Client::daily_budget(),
            );
            $data['cached_rows']   = MyFeeds_Amazon_Cache::count_rows();
        }

        return rest_ensure_response($data);
    }

    /**
     * The shared save route, three ways:
     *   - Amazon's API runs here: the whole form.
     *   - The door that makes Amazon's API the site's product source
     *     (source_choice = amazon_api): the whole form, if the site may
     *     connect it now and the chosen Amazon site has a partner tag; the
     *     API becomes the source once it is set up.
     *   - Otherwise the request is not read beyond the tag. A site where
     *     the API does not run may still hold credentials saved earlier,
     *     and the link window's fields come prefilled: a request without
     *     the credential fields, or with them empty, must leave those as
     *     they are, and must not make Amazon's API the source.
     *
     * @param true|WP_Error|null $saved What earlier filters did.
     */
    public static function save_credentials($saved, $request) {
        if ($saved !== null) {
            return $saved;
        }
        if (MyFeeds_Amazon_Api::active()) {
            return MyFeeds_Amazon_Credentials::save(self::credential_fields($request));
        }
        if ((string) $request->get_param('source_choice') !== 'amazon_api' || !class_exists('MyFeeds_Source_Rules')) {
            return $saved;
        }

        if (!MyFeeds_Source_Rules::can_connect('amazon_api')) {
            return new WP_Error('myfeeds_source_taken', MyFeeds_Source_Rules::refusal(), array('status' => 409));
        }

        $fields      = self::credential_fields($request);
        $marketplace = is_string($fields['marketplace']) && $fields['marketplace'] !== ''
            ? sanitize_text_field($fields['marketplace'])
            : MyFeeds_Amazon_Credentials::marketplace();
        $tag = is_string($fields['partner_tag']) ? sanitize_text_field($fields['partner_tag']) : MyFeeds_Amazon_Credentials::partner_tag($marketplace);
        if ($tag === '') {
            return new WP_Error(
                'myfeeds_amazon_tag_needed',
                __('Add your partner tag for this Amazon site first. Amazon\'s API needs it in every request.', 'myfeeds-affiliate-feed-manager'),
                array('status' => 400)
            );
        }

        $result = MyFeeds_Amazon_Credentials::save($fields);
        if (is_wp_error($result)) {
            return $result;
        }
        MyFeeds_Amazon_Cache::maybe_create_table();
        if (MyFeeds_Amazon_Credentials::is_configured()) {
            MyFeeds_Source_Rules::choose('amazon_api');
        }
        return $result;
    }

    /** The form's fields as MyFeeds_Amazon_Credentials::save() takes them. */
    private static function credential_fields($request) {
        return array(
            'credential_id'      => $request->get_param('credential_id'),
            'credential_secret'  => $request->get_param('credential_secret'),
            'credential_version' => $request->get_param('credential_version'),
            'marketplace'        => $request->get_param('marketplace'),
            'partner_tag'        => $request->get_param('partner_tag'),
        );
    }

    /**
     * With the API the save route answers with the connection's state;
     * without it with the settings of the links and nothing about the API
     * - no credential id, no budget, no cache.
     */
    public static function connection_response($data, $request) {
        if (!MyFeeds_Amazon_Api::active()) {
            return $data;
        }
        return self::status($request)->get_data();
    }

    /**
     * Forgets the API's part of the connection and Amazon's cached data.
     * The Amazon site and the partner tags stay: every Amazon link on the
     * site is built from them.
     */
    public static function disconnect(WP_REST_Request $request) {
        MyFeeds_Amazon_Credentials::forget_api();
        MyFeeds_Amazon_Cache::purge_all();
        MyFeeds_Source_Rules::release('amazon_api', 'not_chosen');

        return self::status($request);
    }

    /**
     * A real call is the only honest connection test: credentials can be
     * valid while the account is still short of the sales threshold, and
     * only Amazon can tell us which of the two is true.
     */
    public static function test(WP_REST_Request $request) {
        $marketplace = MyFeeds_Amazon_Credentials::marketplace();

        if (!MyFeeds_Amazon_Credentials::is_configured($marketplace)) {
            return new WP_Error(
                'myfeeds_amazon_not_configured',
                __('Enter the credential id, secret and partner tag first.', 'myfeeds-affiliate-feed-manager'),
                array('status' => 400)
            );
        }

        $result = MyFeeds_Amazon_Products::search(array(
            'keywords'   => 'gift',
            'item_count' => 1,
        ), $marketplace);

        if (is_wp_error($result)) {
            $result->add_data(array('status' => 200), $result->get_error_code());
            return rest_ensure_response(array(
                'ok'      => false,
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'status'  => self::status($request)->get_data(),
                // Rendered server-side so the banner and the line under
                // the buttons can never tell the user two different things.
                'banner'  => MyFeeds_Amazon_Api_Admin::status_html(true),
            ));
        }

        return rest_ensure_response(array(
            'ok'      => true,
            'message' => __('Amazon answered. The connection works.', 'myfeeds-affiliate-feed-manager'),
            'status'  => self::status($request)->get_data(),
            'banner'  => MyFeeds_Amazon_Api_Admin::status_html(true),
        ));
    }

    /**
     * Hand the stored secret back to an administrator who asks for it.
     *
     * Amazon shows a secret exactly once, so a site that stores it is
     * often the only place a user can read it again. That is worth
     * supporting, but not worth printing into every page load: embedded
     * in the markup it would sit in the page source, in screenshots and
     * in anything a screen share or a browser extension can see. Fetching
     * it on demand keeps it out of all of those until someone deliberately
     * asks, and the response is marked never to be stored.
     *
     * POST rather than GET on purpose: no caching layer or access log
     * treats it as a fetchable resource.
     */
    public static function reveal_secret(WP_REST_Request $request) {
        $connection = MyFeeds_Amazon_Credentials::get();

        if ($connection['credential_secret'] === '') {
            return new WP_Error(
                'myfeeds_amazon_no_secret',
                MyFeeds_Amazon_Credentials::secret_unreadable()
                    ? __('The stored secret can no longer be decrypted on this site.', 'myfeeds-affiliate-feed-manager')
                    : __('No secret is stored yet.', 'myfeeds-affiliate-feed-manager'),
                array('status' => 404)
            );
        }

        $response = rest_ensure_response(array('secret' => $connection['credential_secret']));
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');

        return $response;
    }

    // =====================================================================
    // Catalogue
    // =====================================================================

    public static function search(WP_REST_Request $request) {
        $marketplace = self::resolve_marketplace($request);

        $args = array(
            'keywords'             => (string) $request->get_param('q'),
            'brand'                => (string) $request->get_param('brand'),
            'title'                => (string) $request->get_param('title'),
            'search_index'         => (string) $request->get_param('search_index'),
            'browse_node_id'       => (string) $request->get_param('browse_node_id'),
            'sort_by'              => self::sanitize_sort($request->get_param('sort_by')),
            'item_page'            => (int) $request->get_param('page') ?: 1,
            'item_count'           => (int) $request->get_param('per_page') ?: MyFeeds_Amazon_Client::MAX_ITEM_COUNT,
            'min_price'            => $request->get_param('min_price'),
            'max_price'            => $request->get_param('max_price'),
            'min_reviews_rating'   => $request->get_param('min_rating'),
            'min_saving_percent'   => $request->get_param('min_saving'),
            'include_out_of_stock' => (bool) $request->get_param('include_out_of_stock'),
        );

        // SearchItems needs a value for at least one of keywords, brand or
        // title; a category or browse node only narrows a search, it cannot
        // be one. Answering empty here keeps that from becoming an opaque
        // validation error from Amazon.
        if ($args['keywords'] === '' && $args['brand'] === '' && $args['title'] === '') {
            return rest_ensure_response(array(
                'products'        => array(),
                'total_matches'   => 0,
                'reachable_total' => 0,
                'page'            => 1,
                'max_page'        => 0,
                'marketplace'     => $marketplace,
                'needs_query'     => true,
            ));
        }

        $result = MyFeeds_Amazon_Products::search($args, $marketplace);
        if (is_wp_error($result)) {
            return self::error_response($result);
        }

        return rest_ensure_response($result);
    }

    public static function browse(WP_REST_Request $request) {
        $marketplace = self::resolve_marketplace($request);
        $node_id     = (string) $request->get_param('node_id');

        if ($node_id === '' || !ctype_digit($node_id)) {
            return new WP_Error(
                'myfeeds_amazon_bad_node',
                __('A numeric browse node id is required.', 'myfeeds-affiliate-feed-manager'),
                array('status' => 400)
            );
        }

        $node = MyFeeds_Amazon_Products::browse_node($node_id, $marketplace);
        if (is_wp_error($node)) {
            return self::error_response($node);
        }

        return rest_ensure_response($node);
    }

    /**
     * Rehydrate already-selected tiles in the editor.
     */
    public static function items(WP_REST_Request $request) {
        $body = $request->get_json_params();
        $ids  = isset($body['ids']) && is_array($body['ids']) ? $body['ids'] : array();

        if (empty($ids)) {
            return rest_ensure_response(array());
        }

        $ids      = array_slice(array_map('sanitize_text_field', $ids), 0, 50);
        $products = MyFeeds_Amazon_Products::get_by_ids($ids);

        return rest_ensure_response(array_values($products));
    }

    /**
     * Prices for a set of Amazon cards, fetched by the visitor's browser.
     *
     * Server-rendered prices would freeze inside a page cache: WP Rocket
     * and friends serve stored HTML without running PHP, so a card would
     * keep showing an hour-old price for days while its timestamp claimed
     * otherwise. Reading the price from here keeps the displayed value and
     * its stamp honest no matter how long the HTML has been cached.
     */
    public static function live_prices(WP_REST_Request $request) {
        $raw = (string) $request->get_param('ids');
        $ids = array_slice(array_filter(array_map('trim', explode(',', $raw))), 0, 50);

        if (empty($ids)) {
            return rest_ensure_response(array('items' => array()));
        }

        $products = MyFeeds_Amazon_Products::get_by_ids($ids, 'known');
        $items    = array();

        foreach ($products as $id => $product) {
            $marketplace = $product['marketplace'];

            // Formatted server-side with the very function the card render
            // uses. Letting the browser format in its own locale instead
            // would swap "59,49 €" for "€59.49" the moment the script runs,
            // which reads as the price changing under the reader.
            $currency = $product['currency'] ?: 'EUR';

            $items[] = array(
                'id'                => $id,
                'price'             => $product['price'],
                'old_price'         => $product['old_price'],
                'price_display'     => $product['price'] > 0
                    ? myfeeds_format_price($product['price'], $currency)
                    : '',
                'old_price_display' => $product['old_price'] > $product['price']
                    ? myfeeds_format_price($product['old_price'], $currency)
                    : '',
                'in_stock'          => $product['in_stock'],
                'url'               => $product['affiliate_link'],
                'stamp'             => MyFeeds_Amazon_Stamp::format_stamp($marketplace, $product['price_fetched_at']),
            );
        }

        $response = rest_ensure_response(array('items' => $items));
        $response->header('Cache-Control', 'public, max-age=300');

        return $response;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private static function resolve_marketplace(WP_REST_Request $request) {
        $requested = (string) $request->get_param('marketplace');

        if ($requested !== '' && MyFeeds_Amazon_Locales::exists($requested)) {
            return $requested;
        }

        return MyFeeds_Amazon_Credentials::marketplace();
    }

    private static function sanitize_sort($value) {
        $allowed = array('Relevance', 'Featured', 'NewestArrivals', 'AvgCustomerReviews', 'Price:LowToHigh', 'Price:HighToLow');
        $value   = (string) $value;

        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * Amazon failures are expectable states, not server faults: an account
     * below the sales threshold or a throttled minute must reach the editor
     * as a readable message, not as a 500.
     */
    private static function error_response(WP_Error $error) {
        $status = 502;

        switch ($error->get_error_code()) {
            case 'myfeeds_amazon_not_eligible':
            case 'myfeeds_amazon_not_configured':
                $status = 409;
                break;
            case 'myfeeds_amazon_unauthorized':
            case 'myfeeds_amazon_invalid_tag':
                $status = 401;
                break;
            case 'myfeeds_amazon_throttled':
            case 'myfeeds_amazon_budget':
                $status = 429;
                break;
        }

        $error->add_data(array('status' => $status), $error->get_error_code());

        return $error;
    }
}
