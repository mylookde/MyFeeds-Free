<?php
/**
 * HTTP client for the Amazon Creators API.
 *
 * Creators API replaced the Product Advertising API on 2026-05-15; PA-API
 * endpoints now answer 403 for everyone. Auth is OAuth2 client_credentials
 * against a regional Login-with-Amazon endpoint, which means no request
 * signing and no SDK dependency: wp_remote_post is enough.
 *
 * Docs: https://affiliate-program.amazon.com/creatorsapi/docs/en-us/get-started/using-curl
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Client {

    const TOKEN_TRANSIENT = 'myfeeds_amazon_token';
    const GATE_OPTION     = 'myfeeds_amazon_rate_gate';

    /**
     * Amazon starts every account at one transaction per second. Going over
     * it earns a 429, so requests are spaced rather than fired in parallel.
     */
    const MIN_INTERVAL_MS = 1100;

    /**
     * Amazon's documented opening allocation is 8640 calls a day, and it
     * grows with shipped revenue — but nothing in the API reports what an
     * account is actually allowed. So this is a starting assumption that
     * stops short of the documented figure, and retune() moves it from
     * there based on what the account tolerates in practice.
     */
    const DAILY_BUDGET = 8000;

    /** Floor, so a burst of per-second throttles cannot strangle the site. */
    const MIN_BUDGET = 500;

    /** Ceiling, well past what ten transactions per second could reach. */
    const MAX_BUDGET = 250000;

    const MAX_ITEM_IDS    = 10;
    const MAX_BROWSE_IDS  = 10;
    const MAX_ITEM_COUNT  = 10;
    const MAX_ITEM_PAGE   = 10;

    // =====================================================================
    // Operations
    // =====================================================================

    public static function search_items(array $args, $marketplace = null) {
        $marketplace = $marketplace ?: MyFeeds_Amazon_Credentials::marketplace();

        $payload = array_filter(array(
            'keywords'    => isset($args['keywords']) ? (string) $args['keywords'] : '',
            'brand'       => isset($args['brand']) ? (string) $args['brand'] : '',
            'title'       => isset($args['title']) ? (string) $args['title'] : '',
            'searchIndex' => isset($args['search_index']) ? (string) $args['search_index'] : '',
            'browseNodeId' => isset($args['browse_node_id']) ? (string) $args['browse_node_id'] : '',
            'sortBy'      => isset($args['sort_by']) ? (string) $args['sort_by'] : '',
        ), static function ($value) {
            return $value !== '';
        });

        $item_count = isset($args['item_count']) ? (int) $args['item_count'] : self::MAX_ITEM_COUNT;
        $item_page  = isset($args['item_page']) ? (int) $args['item_page'] : 1;

        $payload['itemCount'] = max(1, min(self::MAX_ITEM_COUNT, $item_count));
        $payload['itemPage']  = max(1, min(self::MAX_ITEM_PAGE, $item_page));

        if (!empty($args['min_price'])) {
            $payload['minPrice'] = (int) round($args['min_price'] * 100);
        }
        if (!empty($args['max_price'])) {
            $payload['maxPrice'] = (int) round($args['max_price'] * 100);
        }
        // Amazon takes 1 to 4 ("a positive integer less than 5") and
        // rejects anything else; out of range means no filter.
        $rating = isset($args['min_reviews_rating']) ? (int) $args['min_reviews_rating'] : 0;
        if ($rating >= 1 && $rating <= 4) {
            $payload['minReviewsRating'] = $rating;
        }
        if (!empty($args['min_saving_percent'])) {
            $payload['minSavingPercent'] = (int) $args['min_saving_percent'];
        }
        if (!empty($args['include_out_of_stock'])) {
            $payload['availability'] = 'IncludeOutOfStock';
        }

        $payload['resources'] = self::default_resources();

        return self::request('searchItems', $payload, $marketplace);
    }

    public static function get_items(array $asins, $marketplace = null) {
        $marketplace = $marketplace ?: MyFeeds_Amazon_Credentials::marketplace();

        $asins = array_values(array_unique(array_filter(array_map('strtoupper', $asins), array('MyFeeds_Amazon_Compliance', 'is_valid_asin'))));
        if (empty($asins)) {
            return new WP_Error('myfeeds_amazon_no_asins', __('No valid ASINs given.', 'myfeeds-affiliate-feed-manager'));
        }

        if (count($asins) > self::MAX_ITEM_IDS) {
            return new WP_Error(
                'myfeeds_amazon_too_many_asins',
                /* translators: %d: how many ASINs one request may name */
                sprintf(__('GetItems accepts at most %d ASINs per call.', 'myfeeds-affiliate-feed-manager'), self::MAX_ITEM_IDS)
            );
        }

        return self::request('getItems', array(
            'itemIds'    => $asins,
            'itemIdType' => 'ASIN',
            'resources'  => self::default_resources(),
        ), $marketplace);
    }

    public static function get_browse_nodes(array $node_ids, $marketplace = null) {
        $marketplace = $marketplace ?: MyFeeds_Amazon_Credentials::marketplace();

        $node_ids = array_values(array_unique(array_filter(array_map('strval', $node_ids), static function ($id) {
            return ctype_digit($id);
        })));

        if (empty($node_ids)) {
            return new WP_Error('myfeeds_amazon_no_nodes', __('No valid browse node ids given.', 'myfeeds-affiliate-feed-manager'));
        }

        return self::request('getBrowseNodes', array(
            'browseNodeIds' => array_slice($node_ids, 0, self::MAX_BROWSE_IDS),
            'resources'     => array('browseNodes.ancestor', 'browseNodes.children'),
        ), $marketplace);
    }

    /**
     * Everything the card renderer can display. Amazon charges latency for
     * unused resources, so this list is deliberately the card's needs and
     * nothing more.
     */
    private static function default_resources() {
        return array(
            'itemInfo.title',
            'itemInfo.byLineInfo',
            'itemInfo.features',
            'itemInfo.productInfo',
            'images.primary.large',
            'images.primary.medium',
            'offersV2.listings.price',
            'offersV2.listings.availability',
            'offersV2.listings.condition',
            'offersV2.listings.merchantInfo',
            'offersV2.listings.isBuyBoxWinner',
        );
    }

    // =====================================================================
    // Transport
    // =====================================================================

    public static function request($operation, array $payload, $marketplace) {
        // Whether the API runs before the credentials. A site where it
        // does not run may still hold credentials it saved; they are kept,
        // unused. Refused here, at the one place every call to Amazon
        // passes, so that no caller can forget to ask - and before the
        // budget and the connection state are touched, so both stay as
        // they were.
        if (!class_exists('MyFeeds_Amazon_Api') || !MyFeeds_Amazon_Api::active()) {
            return new WP_Error(
                'myfeeds_amazon_not_used',
                __('Amazon\'s API is not switched on for this site.', 'myfeeds-affiliate-feed-manager')
            );
        }

        if (!MyFeeds_Amazon_Credentials::is_configured($marketplace)) {
            return new WP_Error(
                'myfeeds_amazon_not_configured',
                __('Amazon is not connected yet.', 'myfeeds-affiliate-feed-manager')
            );
        }

        $budget = self::consume_budget();
        if (is_wp_error($budget)) {
            return $budget;
        }

        $token = self::access_token();
        if (is_wp_error($token)) {
            // Wrong credentials fail here, before any catalogue call. Without
            // recording it the settings screen would fall back to its generic
            // "last call failed" instead of naming the actual cause, which is
            // the single most common thing to get wrong.
            MyFeeds_Amazon_Credentials::record_error($token);
            return $token;
        }

        $payload['marketplace'] = $marketplace;
        $payload['partnerTag']  = MyFeeds_Amazon_Credentials::partner_tag($marketplace);

        $language = MyFeeds_Amazon_Locales::language($marketplace);
        if ($language !== '') {
            $payload['languagesOfPreference'] = array($language);
        }

        $attempt = self::send($operation, $payload, $marketplace, $token);
        if (is_wp_error($attempt)) {
            MyFeeds_Amazon_Credentials::record_error($attempt);
            return $attempt;
        }

        // An expired token can happen mid-flight when a cached token is
        // reused right at its boundary. One silent retry beats surfacing a
        // failure the user cannot act on.
        if ($attempt['code'] === 401 && self::reason($attempt['body']) === 'TokenExpired') {
            self::forget_token();
            $token = self::access_token();
            if (is_wp_error($token)) {
                MyFeeds_Amazon_Credentials::record_error($token);
                return $token;
            }

            $attempt = self::send($operation, $payload, $marketplace, $token);
            if (is_wp_error($attempt)) {
                MyFeeds_Amazon_Credentials::record_error($attempt);
                return $attempt;
            }
        }

        if ($attempt['code'] === 200 && is_array($attempt['body'])) {
            MyFeeds_Amazon_Credentials::set_status(MyFeeds_Amazon_Credentials::STATE_OK);
            return $attempt['body'];
        }

        $error = self::to_error($attempt['code'], $attempt['body']);
        MyFeeds_Amazon_Credentials::record_error($error);

        return $error;
    }

    /**
     * One HTTP round trip. Kept separate so the token retry cannot drift
     * from the first attempt.
     *
     * @return array{code:int,body:mixed}|WP_Error
     */
    private static function send($operation, array $payload, $marketplace, $token) {
        self::space_requests();

        $response = wp_remote_post(MyFeeds_Amazon_Api_Locales::API_BASE . '/catalog/v1/' . $operation, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'x-marketplace' => $marketplace,
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return self::transport_error($response);
        }

        return array(
            'code' => (int) wp_remote_retrieve_response_code($response),
            'body' => json_decode(wp_remote_retrieve_body($response), true),
        );
    }

    /**
     * Sites that set WP_HTTP_BLOCK_EXTERNAL, and security plugins that do
     * the same thing, fail every call with a message that says nothing
     * about what to change. Name the constant and the hosts instead.
     */
    private static function transport_error(WP_Error $error) {
        if (self::outbound_blocked()) {
            return new WP_Error(
                'myfeeds_amazon_blocked',
                sprintf(
                    /* translators: 1: PHP constant name, 2: list of host names */
                    __('This site blocks outgoing requests (%1$s). Allow %2$s so MyFeeds can reach Amazon.', 'myfeeds-affiliate-feed-manager'),
                    'WP_HTTP_BLOCK_EXTERNAL',
                    self::required_hosts_text()
                )
            );
        }

        return new WP_Error('myfeeds_amazon_transport', $error->get_error_message());
    }

    /**
     * True when WordPress is configured to block outgoing HTTP and our
     * hosts are not on the allow list.
     */
    public static function outbound_blocked() {
        if (!defined('WP_HTTP_BLOCK_EXTERNAL') || !WP_HTTP_BLOCK_EXTERNAL) {
            return false;
        }

        $accessible = defined('WP_ACCESSIBLE_HOSTS') ? strtolower((string) WP_ACCESSIBLE_HOSTS) : '';

        foreach (self::required_hosts() as $host) {
            if (strpos($accessible, strtolower($host)) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hosts an install has to be able to reach. The token endpoint depends
     * on the credential's region, so all three are listed.
     */
    public static function required_hosts() {
        $hosts = array('creatorsapi.amazon');

        foreach (MyFeeds_Amazon_Api_Locales::REGIONS as $region) {
            $host = wp_parse_url($region['token_endpoint'], PHP_URL_HOST);
            if (is_string($host)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    public static function required_hosts_text() {
        return implode(', ', self::required_hosts());
    }

    private static function reason($body) {
        return is_array($body) && isset($body['reason']) ? (string) $body['reason'] : '';
    }

    /**
     * Amazon's error shape is documented and stable, so failures become
     * codes the UI can act on rather than opaque strings.
     */
    private static function to_error($code, $body) {
        $reason  = self::reason($body);
        $message = is_array($body) && isset($body['message']) ? (string) $body['message'] : '';

        if ($reason === 'AssociateNotEligible') {
            return new WP_Error(
                'myfeeds_amazon_not_eligible',
                __('Amazon has not unlocked the Creators API for this account yet. It needs 10 qualifying sales in the last 30 days.', 'myfeeds-affiliate-feed-manager')
            );
        }

        if (in_array($reason, array('InvalidPartnerTag', 'InvalidAssociate'), true)) {
            return new WP_Error(
                'myfeeds_amazon_invalid_tag',
                __('The partner tag does not belong to this credential on this marketplace.', 'myfeeds-affiliate-feed-manager')
            );
        }

        if ($code === 401) {
            return new WP_Error(
                'myfeeds_amazon_unauthorized',
                $message ?: __('Amazon rejected the credentials.', 'myfeeds-affiliate-feed-manager')
            );
        }

        if ($code === 429) {
            $retry = is_array($body) && isset($body['retryAfterSeconds']) ? (int) $body['retryAfterSeconds'] : 60;
            self::back_off($retry);

            return new WP_Error(
                'myfeeds_amazon_throttled',
                /* translators: %d: seconds until Amazon takes requests again */
                sprintf(__('Amazon is rate limiting this account. Try again in %d seconds.', 'myfeeds-affiliate-feed-manager'), $retry)
            );
        }

        if ($code === 404) {
            return new WP_Error('myfeeds_amazon_not_found', $message ?: __('Amazon has no data for this item.', 'myfeeds-affiliate-feed-manager'));
        }

        if ($code >= 500) {
            return new WP_Error('myfeeds_amazon_server', $message ?: __('Amazon returned a server error.', 'myfeeds-affiliate-feed-manager'));
        }

        return new WP_Error('myfeeds_amazon_error', $message ?: __('Amazon rejected the request.', 'myfeeds-affiliate-feed-manager'));
    }

    // =====================================================================
    // Token
    // =====================================================================

    /**
     * Tokens live one hour and the token endpoint rate-limits clients that
     * fetch one per call, so the cached token is shared site-wide.
     */
    public static function access_token() {
        $connection = MyFeeds_Amazon_Credentials::get();

        $cached = get_transient(self::token_key($connection));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $endpoint = MyFeeds_Amazon_Api_Locales::token_endpoint($connection['marketplace']);

        $response = wp_remote_post($endpoint, array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'grant_type'    => 'client_credentials',
                'client_id'     => $connection['credential_id'],
                'client_secret' => $connection['credential_secret'],
                'scope'         => 'creatorsapi::default',
            )),
        ));

        if (is_wp_error($response)) {
            return self::transport_error($response);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || !is_array($body) || empty($body['access_token'])) {
            if ($code === 429) {
                return new WP_Error(
                    'myfeeds_amazon_throttled',
                    __('Amazon is rate limiting token requests. This clears itself within a few minutes.', 'myfeeds-affiliate-feed-manager')
                );
            }

            $description = is_array($body) && isset($body['error_description'])
                ? (string) $body['error_description']
                : __('Amazon did not accept the credential id and secret.', 'myfeeds-affiliate-feed-manager');

            return new WP_Error('myfeeds_amazon_unauthorized', $description);
        }

        $expires = isset($body['expires_in']) ? (int) $body['expires_in'] : 3600;
        set_transient(self::token_key($connection), $body['access_token'], max(60, $expires - 300));

        return $body['access_token'];
    }

    public static function forget_token() {
        $connection = MyFeeds_Amazon_Credentials::get();
        delete_transient(self::token_key($connection));
    }

    private static function token_key($connection) {
        return self::TOKEN_TRANSIENT . '_' . substr(md5($connection['credential_id'] . '|' . MyFeeds_Amazon_Api_Locales::region($connection['marketplace'])), 0, 12);
    }

    // =====================================================================
    // Rate control
    // =====================================================================

    private static function gate() {
        $gate = get_option(self::GATE_OPTION, array());
        if (!is_array($gate)) {
            $gate = array();
        }

        return array_merge(array(
            'day'           => '',
            'count'         => 0,
            'last_ms'       => 0,
            'blocked_until' => 0,
            'limit'         => self::DAILY_BUDGET,
            'throttled'     => 0,
        ), $gate);
    }

    private static function save_gate($gate) {
        update_option(self::GATE_OPTION, $gate, false);
    }

    /**
     * A 429 means the account is over its allocation right now. Holding off
     * for the window Amazon asks for is cheaper than collecting more 429s.
     *
     * Amazon sends 429 both for the per-second rate and for the daily
     * allowance, and does not say which. A throttle late in the day is
     * treated as the daily ceiling and teaches the budget; an early one is
     * treated as a burst and only pauses us. Getting that backwards would
     * shrink the budget every time two calls landed in the same second.
     */
    private static function back_off($seconds) {
        $gate = self::gate();
        $gate['blocked_until'] = time() + max(1, (int) $seconds);
        $gate['throttled']     = 1;

        $limit = (int) $gate['limit'];
        if ($gate['count'] >= (int) ($limit * 0.5)) {
            $gate['limit'] = max(self::MIN_BUDGET, (int) $gate['count']);
        }

        self::save_gate($gate);
    }

    /**
     * Amazon publishes no endpoint for an account's allowance. The only
     * signal is whether calls get through, so the budget starts under the
     * documented opening allocation and moves with what the account
     * actually tolerates: up after a day that used nearly all of it
     * without a single throttle, down to the count where a late throttle
     * arrived.
     */
    private static function retune(array $gate) {
        $limit = (int) $gate['limit'];

        if (empty($gate['throttled']) && $gate['count'] >= (int) ($limit * 0.9)) {
            $limit = min(self::MAX_BUDGET, (int) round($limit * 1.25));
        }

        $gate['limit']     = max(self::MIN_BUDGET, $limit);
        $gate['throttled'] = 0;

        return $gate;
    }

    private static function consume_budget() {
        $gate  = self::gate();
        $today = gmdate('Y-m-d');

        if ($gate['blocked_until'] > time()) {
            return new WP_Error(
                'myfeeds_amazon_throttled',
                sprintf(
                    /* translators: %d: seconds until Amazon takes requests again */
                    __('Amazon is rate limiting this account. Try again in %d seconds.', 'myfeeds-affiliate-feed-manager'),
                    $gate['blocked_until'] - time()
                )
            );
        }

        if ($gate['day'] !== $today) {
            // Learn from the day that just ended before clearing its count.
            if ($gate['day'] !== '') {
                $gate = self::retune($gate);
            }
            $gate['day']   = $today;
            $gate['count'] = 0;
        }

        if ($gate['count'] >= self::daily_budget()) {
            return new WP_Error(
                'myfeeds_amazon_budget',
                __('The daily Amazon request budget for this site is used up. It resets at midnight UTC.', 'myfeeds-affiliate-feed-manager')
            );
        }

        $gate['count']++;
        self::save_gate($gate);

        return true;
    }

    /**
     * The site's own safety limit, not a number Amazon reports.
     */
    public static function daily_budget() {
        $gate = self::gate();
        return (int) apply_filters('myfeeds_amazon_daily_budget', (int) $gate['limit']);
    }

    /**
     * True once the budget has moved away from its starting value, which
     * is what lets the UI say whether the number is still an assumption.
     */
    public static function budget_is_learned() {
        $gate = self::gate();
        return (int) $gate['limit'] !== self::DAILY_BUDGET;
    }

    public static function calls_used_today() {
        $gate = self::gate();
        return $gate['day'] === gmdate('Y-m-d') ? (int) $gate['count'] : 0;
    }

    /**
     * Keeps consecutive calls at least one second apart. Bounded on purpose:
     * a web request must never stall for longer than a single tick, and the
     * batching callers do keeps the number of ticks small.
     */
    private static function space_requests() {
        $gate = self::gate();
        $now  = (int) round(microtime(true) * 1000);

        $wait = self::MIN_INTERVAL_MS - ($now - (int) $gate['last_ms']);
        if ($wait > 0 && $wait <= self::MIN_INTERVAL_MS) {
            usleep($wait * 1000);
            $now += $wait;
        }

        $gate['last_ms'] = $now;
        self::save_gate($gate);
    }
}
