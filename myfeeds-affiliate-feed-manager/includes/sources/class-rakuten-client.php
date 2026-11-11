<?php
/**
 * HTTP client for the Rakuten Advertising publisher APIs.
 *
 * Product Search (XML, 5,000 results per query, 100 calls a minute),
 * Partnerships (JSON) and the token endpoint. Auth is a bearer token
 * that lives about an hour; the token is cached site-wide and fetched
 * again on a 401, once.
 *
 * Docs: https://developers.rakutenadvertising.com/guides/product_search,
 * /guides/access_tokens, /guides/partnerships (read 2026-09-07).
 *
 * The transport is a swappable callable so the whole class can be
 * exercised without a network: tests hand in canned responses and count
 * the calls.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Rakuten_Client {

    const API_BASE        = 'https://api.linksynergy.com';
    const TOKEN_TRANSIENT = 'myfeeds_rakuten_token';
    const GATE_OPTION     = 'myfeeds_rakuten_rate_gate';

    /** Documented ceiling for every publisher endpoint, tokens included. */
    const CALLS_PER_MINUTE = 100;

    /** 100 a minute is one every 600 ms; a little slack keeps us under. */
    const MIN_INTERVAL_MS = 650;

    /** Product Search caps a page at 100; the default would be 20. */
    const PAGE_SIZE = 100;

    /** Product Search never returns more than this many results per query. */
    const RESULT_CAP = 5000;

    const TIMEOUT       = 10;
    const TOKEN_TIMEOUT = 15;

    /** @var callable|null */
    private static $transport = null;

    /** @var callable|null */
    private static $sleeper = null;

    /**
     * @param callable|null $transport function($method, $url, array $args): array{code:int,body:string,headers:array}|WP_Error
     */
    public static function set_transport($transport) {
        self::$transport = is_callable($transport) ? $transport : null;
    }

    /**
     * @param callable|null $sleeper function(int $milliseconds): void
     */
    public static function set_sleeper($sleeper) {
        self::$sleeper = is_callable($sleeper) ? $sleeper : null;
    }

    // =====================================================================
    // Operations
    // =====================================================================

    /**
     * One page of Product Search.
     *
     * @param array  $params   keyword, exact, one, none, cat, language, mid, sort, sorttype, max, pagenumber.
     * @param string $strategy Id strategy handed to the row flattener.
     * @return array|WP_Error See MyFeeds_Rakuten_Rows::parse().
     */
    public static function search_products(array $params, $strategy = MyFeeds_Rakuten_Rows::STRATEGY_SKU) {
        $query = self::search_query($params);

        $response = self::request('GET', '/productsearch/1.0', $query, 'application/xml');
        if (is_wp_error($response)) {
            return $response;
        }

        return MyFeeds_Rakuten_Rows::parse($response['body'], $strategy);
    }

    /**
     * Only the parameters the API documents, clamped where it clamps.
     *
     * @param array $params
     * @return array<string,string>
     */
    public static function search_query(array $params) {
        $query = array();

        foreach (array('keyword', 'exact', 'one', 'none', 'cat', 'language', 'mid') as $key) {
            if (isset($params[$key]) && trim((string) $params[$key]) !== '') {
                $query[$key] = trim((string) $params[$key]);
            }
        }

        if (!empty($params['sort'])) {
            $sort = (string) $params['sort'];
            if (in_array($sort, array('retailprice', 'productname', 'categoryname', 'mid'), true)) {
                $query['sort']     = $sort;
                $query['sorttype'] = (isset($params['sorttype']) && $params['sorttype'] === 'dsc') ? 'dsc' : 'asc';
            }
        }

        $max = isset($params['max']) ? (int) $params['max'] : self::PAGE_SIZE;
        $query['max'] = (string) max(1, min(self::PAGE_SIZE, $max));

        $page = isset($params['pagenumber']) ? (int) $params['pagenumber'] : 1;
        $query['pagenumber'] = (string) max(1, $page);

        return $query;
    }

    /**
     * Advertisers this publisher is actively partnered with. This is the
     * list a feed can be built from: /v2/advertisers would list every
     * advertiser on the network, partnered or not.
     *
     * @param int $page
     * @param int $limit Up to 200.
     * @return array{total:int,page:int,limit:int,partners:array<int,array>}|WP_Error
     */
    public static function partnerships($page = 1, $limit = 200) {
        $response = self::request('GET', '/v1/partnerships', array(
            'partner_status'    => 'active',
            'advertiser_status' => 'active',
            'limit'             => (string) max(1, min(200, (int) $limit)),
            'page'              => (string) max(1, (int) $page),
        ), 'application/json');

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode((string) $response['body'], true);
        if (!is_array($body) || !isset($body['partnerships']) || !is_array($body['partnerships'])) {
            return new WP_Error(
                'myfeeds_rakuten_error',
                __('Rakuten did not return a partnership list.', 'myfeeds-affiliate-feed-manager')
            );
        }

        $meta = isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata']
            : (isset($body['_metadata']) && is_array($body['_metadata']) ? $body['_metadata'] : array());

        $partners = array();
        foreach ($body['partnerships'] as $entry) {
            if (!is_array($entry) || !isset($entry['advertiser']) || !is_array($entry['advertiser'])) {
                continue;
            }
            $advertiser = $entry['advertiser'];
            if (!isset($advertiser['id'])) {
                continue;
            }
            $partners[] = array(
                'id'         => (int) $advertiser['id'],
                'name'       => isset($advertiser['name']) ? (string) $advertiser['name'] : '',
                'network'    => isset($advertiser['network']) ? (int) $advertiser['network'] : 0,
                'status'     => isset($advertiser['status']) ? (string) $advertiser['status'] : '',
                'categories' => isset($advertiser['categories']) && is_array($advertiser['categories'])
                    ? array_values(array_map('strval', $advertiser['categories']))
                    : array(),
            );
        }

        return array(
            'total'    => isset($meta['total']) ? (int) $meta['total'] : count($partners),
            'page'     => isset($meta['page']) ? (int) $meta['page'] : (int) $page,
            'limit'    => isset($meta['limit']) ? (int) $meta['limit'] : (int) $limit,
            'partners' => $partners,
        );
    }

    /**
     * Rakuten's network numbers, as documented on the Partnerships guide,
     * and the currency each one settles in.
     *
     * @param int $network
     * @return array{label:string,currency:string}
     */
    public static function network_info($network) {
        $map = array(
            1  => array('label' => 'US', 'currency' => 'USD'),
            3  => array('label' => 'UK', 'currency' => 'GBP'),
            5  => array('label' => 'CA', 'currency' => 'CAD'),
            7  => array('label' => 'FR', 'currency' => 'EUR'),
            8  => array('label' => 'BR', 'currency' => 'BRL'),
            9  => array('label' => 'DE', 'currency' => 'EUR'),
            41 => array('label' => 'AU', 'currency' => 'AUD'),
        );

        $network = (int) $network;
        return isset($map[$network]) ? $map[$network] : array('label' => '', 'currency' => '');
    }

    /**
     * The connection test: a token, then the cheapest real call. Three
     * outcomes a person can act on - rejected credentials, no active
     * partnerships, or working.
     *
     * @return array{ok:bool,state:string,message:string,partners:int}
     */
    public static function test_connection() {
        if (!MyFeeds_Rakuten_Credentials::is_configured()) {
            return array(
                'ok'       => false,
                'state'    => MyFeeds_Rakuten_Credentials::STATE_DISCONNECTED,
                'message'  => __('Enter the client ID, client secret and SID first.', 'myfeeds-affiliate-feed-manager'),
                'partners' => 0,
            );
        }

        self::forget_token();

        $result = self::partnerships(1, 1);
        if (is_wp_error($result)) {
            MyFeeds_Rakuten_Credentials::record_error($result);
            $status = MyFeeds_Rakuten_Credentials::get_status();
            return array(
                'ok'       => false,
                'state'    => $status['state'],
                'message'  => $result->get_error_message(),
                'partners' => 0,
            );
        }

        if ($result['total'] === 0) {
            $error = new WP_Error(
                'myfeeds_rakuten_no_partners',
                __('The credentials work, but this account has no active advertiser partnerships yet. Apply to advertisers in the Rakuten dashboard first.', 'myfeeds-affiliate-feed-manager')
            );
            MyFeeds_Rakuten_Credentials::record_error($error);
            return array(
                'ok'       => false,
                'state'    => MyFeeds_Rakuten_Credentials::STATE_NO_PARTNERS,
                'message'  => $error->get_error_message(),
                'partners' => 0,
            );
        }

        $message = sprintf(
            /* translators: %d: number of advertisers */
            _n('Connected. %d active advertiser partnership.', 'Connected. %d active advertiser partnerships.', $result['total'], 'myfeeds-affiliate-feed-manager'),
            $result['total']
        );
        MyFeeds_Rakuten_Credentials::set_status(MyFeeds_Rakuten_Credentials::STATE_OK, $message);

        return array(
            'ok'       => true,
            'state'    => MyFeeds_Rakuten_Credentials::STATE_OK,
            'message'  => $message,
            'partners' => $result['total'],
        );
    }

    // =====================================================================
    // Transport
    // =====================================================================

    /**
     * One authenticated call, with the gate, the token and the single
     * 401 retry in front of it.
     *
     * @param string $method
     * @param string $path   Absolute path under API_BASE.
     * @param array  $query
     * @param string $accept
     * @return array{code:int,body:string,headers:array}|WP_Error
     */
    public static function request($method, $path, array $query, $accept = 'application/xml') {
        if (!MyFeeds_Rakuten_Credentials::is_configured()) {
            return new WP_Error(
                'myfeeds_rakuten_not_configured',
                __('Rakuten is not connected yet.', 'myfeeds-affiliate-feed-manager')
            );
        }

        if (self::outbound_blocked()) {
            return self::blocked_error();
        }

        $gate = self::consume();
        if (is_wp_error($gate)) {
            return $gate;
        }

        $token = self::access_token();
        if (is_wp_error($token)) {
            MyFeeds_Rakuten_Credentials::record_error($token);
            return $token;
        }

        $url = self::API_BASE . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');

        $attempt = self::send($method, $url, $token, $accept);
        if (is_wp_error($attempt)) {
            MyFeeds_Rakuten_Credentials::record_error($attempt);
            return $attempt;
        }

        // A cached token reused right at its boundary comes back 401.
        // One silent retry with a fresh token beats a failure the user
        // cannot act on; a second 401 is a real rejection.
        if ($attempt['code'] === 401) {
            self::forget_token();
            $token = self::access_token();
            if (is_wp_error($token)) {
                MyFeeds_Rakuten_Credentials::record_error($token);
                return $token;
            }

            $attempt = self::send($method, $url, $token, $accept);
            if (is_wp_error($attempt)) {
                MyFeeds_Rakuten_Credentials::record_error($attempt);
                return $attempt;
            }
        }

        if ($attempt['code'] >= 200 && $attempt['code'] < 300) {
            return $attempt;
        }

        $error = self::to_error($attempt['code'], $attempt['body'], $attempt['headers']);
        MyFeeds_Rakuten_Credentials::record_error($error);

        return $error;
    }

    /**
     * One HTTP round trip with the bearer token. Kept separate so the
     * retry cannot drift from the first attempt.
     *
     * @return array{code:int,body:string,headers:array}|WP_Error
     */
    private static function send($method, $url, $token, $accept) {
        self::space_requests();

        $response = self::http($method, $url, array(
            'timeout' => self::TIMEOUT,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => $accept,
            ),
        ));

        if (is_wp_error($response)) {
            return self::transport_error($response);
        }

        return $response;
    }

    /**
     * The one place a request leaves the process. Returns a normalised
     * shape so callers, and tests, never touch WP's response object.
     *
     * @return array{code:int,body:string,headers:array}|WP_Error
     */
    private static function http($method, $url, array $args) {
        if (self::$transport !== null) {
            $result = call_user_func(self::$transport, $method, $url, $args);
            if (is_wp_error($result)) {
                return $result;
            }
            return array(
                'code'    => isset($result['code']) ? (int) $result['code'] : 0,
                'body'    => isset($result['body']) ? (string) $result['body'] : '',
                'headers' => isset($result['headers']) && is_array($result['headers']) ? $result['headers'] : array(),
            );
        }

        $args['method'] = $method;
        // A fixed, public API host compiled into the plugin - not an
        // operator-supplied address, so it does not go through the feed
        // fetch guard.
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $headers = wp_remote_retrieve_headers($response);
        $retry   = '';
        if (is_array($headers) || $headers instanceof ArrayAccess) {
            $retry = isset($headers['retry-after']) ? (string) $headers['retry-after'] : '';
        }

        return array(
            'code'    => (int) wp_remote_retrieve_response_code($response),
            'body'    => (string) wp_remote_retrieve_body($response),
            'headers' => array('retry-after' => $retry),
        );
    }

    /**
     * Sites that set WP_HTTP_BLOCK_EXTERNAL fail every call with a message
     * that says nothing about what to change. Name the constant and the
     * host instead.
     */
    private static function transport_error(WP_Error $error) {
        if (self::outbound_blocked()) {
            return self::blocked_error();
        }

        return new WP_Error('myfeeds_rakuten_transport', $error->get_error_message());
    }

    private static function blocked_error() {
        return new WP_Error(
            'myfeeds_rakuten_blocked',
            sprintf(
                /* translators: 1: PHP constant name, 2: host name */
                __('This site blocks outgoing requests (%1$s). Allow %2$s so MyFeeds can reach Rakuten.', 'myfeeds-affiliate-feed-manager'),
                'WP_HTTP_BLOCK_EXTERNAL',
                self::required_host()
            )
        );
    }

    public static function required_host() {
        $host = wp_parse_url(self::API_BASE, PHP_URL_HOST);
        return is_string($host) ? $host : 'api.linksynergy.com';
    }

    /**
     * True when WordPress is configured to block outgoing HTTP and our
     * host is not on the allow list.
     */
    public static function outbound_blocked() {
        if (!defined('WP_HTTP_BLOCK_EXTERNAL') || !WP_HTTP_BLOCK_EXTERNAL) {
            return false;
        }

        $accessible = defined('WP_ACCESSIBLE_HOSTS') ? strtolower((string) WP_ACCESSIBLE_HOSTS) : '';

        return strpos($accessible, strtolower(self::required_host())) === false;
    }

    /**
     * Failures become codes the UI and the fetcher can act on.
     *
     * @param int    $code
     * @param string $body
     * @param array  $headers
     * @return WP_Error
     */
    private static function to_error($code, $body, array $headers) {
        $message = self::message_from_body($body);

        if ($code === 401 || $code === 403) {
            return new WP_Error(
                'myfeeds_rakuten_unauthorized',
                $message !== '' ? $message : __('Rakuten rejected the credentials.', 'myfeeds-affiliate-feed-manager')
            );
        }

        if ($code === 429) {
            $retry = isset($headers['retry-after']) ? (int) $headers['retry-after'] : 0;
            if ($retry <= 0) {
                $retry = 60;
            }
            self::back_off($retry);

            return new WP_Error(
                'myfeeds_rakuten_throttled',
                sprintf(
                    /* translators: %d: seconds */
                    __('Rakuten is rate limiting this account. Try again in %d seconds.', 'myfeeds-affiliate-feed-manager'),
                    $retry
                ),
                array('retry_after' => $retry)
            );
        }

        if ($code >= 500) {
            return new WP_Error(
                'myfeeds_rakuten_server',
                $message !== '' ? $message : sprintf(
                    /* translators: %d: HTTP status */
                    __('Rakuten returned a server error (HTTP %d).', 'myfeeds-affiliate-feed-manager'),
                    $code
                )
            );
        }

        return new WP_Error(
            'myfeeds_rakuten_error',
            $message !== '' ? $message : sprintf(
                /* translators: %d: HTTP status */
                __('Rakuten rejected the request (HTTP %d).', 'myfeeds-affiliate-feed-manager'),
                $code
            )
        );
    }

    /**
     * Rakuten answers errors as JSON on the JSON endpoints and as XML or
     * plain text on Product Search. Take whichever sentence is there.
     *
     * @param string $body
     * @return string
     */
    private static function message_from_body($body) {
        $body = trim((string) $body);
        if ($body === '') {
            return '';
        }

        $json = json_decode($body, true);
        if (is_array($json)) {
            foreach (array('error_description', 'message', 'error', 'description') as $key) {
                if (isset($json[$key]) && is_string($json[$key]) && trim($json[$key]) !== '') {
                    return trim($json[$key]);
                }
            }
            if (isset($json['errors']) && is_array($json['errors'])) {
                $first = reset($json['errors']);
                if (is_array($first) && isset($first['message'])) {
                    return trim((string) $first['message']);
                }
            }
            return '';
        }

        $text = trim(wp_strip_all_tags($body));
        $text = preg_replace('/\s+/', ' ', $text);
        return function_exists('mb_substr') ? mb_substr($text, 0, 200) : substr($text, 0, 200);
    }

    // =====================================================================
    // Token
    // =====================================================================

    /**
     * One token request, in the shape Rakuten documents: the client id
     * and secret base64-encoded behind "Bearer", with `scope=<account
     * id>` in the body.
     *
     * There used to be a second attempt with Basic auth and
     * grant_type=client_credentials, because other Rakuten material
     * describes the call that way and it was not clear which one an
     * account would take. The live account answers the documented shape
     * ("token issued via bearer-key", 2026-09-08), so the second attempt
     * only ever cost a wasted request out of a rate-limited allowance
     * and pushed the real error message one round further away.
     *
     * @return string|WP_Error
     */
    public static function access_token() {
        $connection = MyFeeds_Rakuten_Credentials::get();

        $cached = get_transient(self::token_key($connection));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (!MyFeeds_Rakuten_Credentials::is_configured()) {
            return new WP_Error(
                'myfeeds_rakuten_not_configured',
                __('Rakuten is not connected yet.', 'myfeeds-affiliate-feed-manager')
            );
        }

        if (MyFeeds_Rakuten_Credentials::secret_unreadable()) {
            return new WP_Error(
                'myfeeds_rakuten_unauthorized',
                __('The stored client secret can no longer be read. This happens when the site\'s security keys were rotated or the server lost the encryption library. Enter the secret again.', 'myfeeds-affiliate-feed-manager')
            );
        }

        $gate = self::consume();
        if (is_wp_error($gate)) {
            return $gate;
        }

        self::space_requests();

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Rakuten's token endpoint takes base64(client id:secret), its documented key.
        $pair = base64_encode($connection['client_id'] . ':' . $connection['client_secret']);

        $response = self::http('POST', self::API_BASE . '/token', array(
            'timeout' => self::TOKEN_TIMEOUT,
            'headers' => array(
                'Authorization' => 'Bearer ' . $pair,
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Accept'        => 'application/json',
            ),
            'body' => 'scope=' . rawurlencode($connection['sid']),
        ));

        if (is_wp_error($response)) {
            return self::transport_error($response);
        }

        $body = json_decode((string) $response['body'], true);

        if ($response['code'] === 200 && is_array($body) && !empty($body['access_token'])) {
            $expires = isset($body['expires_in']) ? (int) $body['expires_in'] : 3600;
            set_transient(self::token_key($connection), (string) $body['access_token'], max(60, $expires - 300));
            self::log('token issued, expires in ' . $expires . ' s', 'info');
            return (string) $body['access_token'];
        }

        if ($response['code'] === 429) {
            $retry = isset($response['headers']['retry-after']) ? (int) $response['headers']['retry-after'] : 60;
            self::back_off($retry > 0 ? $retry : 60);
            return new WP_Error(
                'myfeeds_rakuten_throttled',
                __('Rakuten is rate limiting token requests. This clears itself within a minute.', 'myfeeds-affiliate-feed-manager')
            );
        }

        $description = self::message_from_body($response['body']);

        return new WP_Error(
            'myfeeds_rakuten_unauthorized',
            $description !== '' ? $description : __('Rakuten did not accept the client ID, client secret and SID.', 'myfeeds-affiliate-feed-manager')
        );
    }

    public static function forget_token() {
        $connection = MyFeeds_Rakuten_Credentials::get();
        delete_transient(self::token_key($connection));
    }

    private static function token_key($connection) {
        return self::TOKEN_TRANSIENT . '_' . substr(md5($connection['client_id'] . '|' . $connection['sid']), 0, 12);
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
            'minute'        => '',
            'count'         => 0,
            'last_ms'       => 0,
            'blocked_until' => 0,
        ), $gate);
    }

    private static function save_gate(array $gate) {
        update_option(self::GATE_OPTION, $gate, false);
    }

    /**
     * Take one call out of this minute's allowance, or say how long to
     * wait. The fetcher turns the wait into a rescheduled job instead of
     * sleeping through it.
     *
     * @return true|WP_Error
     */
    private static function consume() {
        $gate   = self::gate();
        $now    = time();
        $minute = gmdate('YmdHi', $now);

        if ((int) $gate['blocked_until'] > $now) {
            return self::wait_error((int) $gate['blocked_until'] - $now);
        }

        if ($gate['minute'] !== $minute) {
            $gate['minute'] = $minute;
            $gate['count']  = 0;
        }

        if ((int) $gate['count'] >= self::CALLS_PER_MINUTE) {
            return self::wait_error(60 - ($now % 60));
        }

        $gate['count'] = (int) $gate['count'] + 1;
        self::save_gate($gate);

        return true;
    }

    private static function wait_error($seconds) {
        $seconds = max(1, (int) $seconds);

        return new WP_Error(
            'myfeeds_rakuten_throttled',
            sprintf(
                /* translators: %d: seconds */
                __('Rakuten allows 100 calls a minute; waiting %d seconds.', 'myfeeds-affiliate-feed-manager'),
                $seconds
            ),
            array('retry_after' => $seconds)
        );
    }

    /**
     * A 429 means the account is over its allowance right now. Holding off
     * for the window Rakuten asks for is cheaper than collecting more 429s.
     */
    private static function back_off($seconds) {
        $gate = self::gate();
        $gate['blocked_until'] = time() + max(1, (int) $seconds);
        self::save_gate($gate);
    }

    public static function is_backed_off() {
        $gate = self::gate();
        return (int) $gate['blocked_until'] > time();
    }

    /**
     * Seconds until the next call may go out; 0 when it may go now.
     */
    public static function seconds_until_allowed() {
        $gate = self::gate();
        $now  = time();

        if ((int) $gate['blocked_until'] > $now) {
            return (int) $gate['blocked_until'] - $now;
        }

        if ($gate['minute'] === gmdate('YmdHi', $now) && (int) $gate['count'] >= self::CALLS_PER_MINUTE) {
            return 60 - ($now % 60);
        }

        return 0;
    }

    public static function calls_this_minute() {
        $gate = self::gate();
        return $gate['minute'] === gmdate('YmdHi') ? (int) $gate['count'] : 0;
    }

    /**
     * Keeps consecutive calls MIN_INTERVAL_MS apart. Bounded on purpose: a
     * request must never stall for longer than a single tick.
     */
    private static function space_requests() {
        $gate = self::gate();
        $now  = (int) round(microtime(true) * 1000);

        $wait = self::MIN_INTERVAL_MS - ($now - (int) $gate['last_ms']);
        if ($wait > 0 && $wait <= self::MIN_INTERVAL_MS) {
            if (self::$sleeper !== null) {
                call_user_func(self::$sleeper, $wait);
            } else {
                usleep($wait * 1000);
            }
            $now += $wait;
        }

        $gate['last_ms'] = $now;
        self::save_gate($gate);
    }

    private static function log($message, $level = 'info') {
        if (function_exists('myfeeds_log')) {
            myfeeds_log('Source[rakuten] ' . $message, $level);
        }
    }
}
