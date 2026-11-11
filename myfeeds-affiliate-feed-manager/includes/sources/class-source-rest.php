<?php
/**
 * REST surface for API sources.
 *
 * Everything here changes site configuration or spends API calls, so
 * every route needs manage_options - except what the block editor asks
 * while an author works: the feeds a search can reach, a search pulled
 * into one of them, and that search's state (edit_posts). Rakuten
 * failures are expectable states - rejected credentials, a throttled
 * minute - and reach the screen as readable messages with a matching
 * status, never as a 500.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Source_REST {

    const NS = 'myfeeds/v1';

    /** Partnership lists change rarely and cost up to a dozen calls. */
    const PARTNERS_TRANSIENT = 'myfeeds_rakuten_partners';
    const PARTNERS_TTL       = 600;

    /** Pages of 200 the advertiser list will walk before giving up. */
    const MAX_PARTNER_PAGES = 25;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/sources/rakuten/status', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'status'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route(self::NS, '/sources/rakuten/connection', array(
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'save_connection'),
                'permission_callback' => array(__CLASS__, 'can_manage'),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array(__CLASS__, 'disconnect'),
                'permission_callback' => array(__CLASS__, 'can_manage'),
            ),
        ));

        register_rest_route(self::NS, '/sources/rakuten/secret', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'reveal_secret'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route(self::NS, '/sources/rakuten/test', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'test'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route(self::NS, '/sources/rakuten/advertisers', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'advertisers'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'args'                => array(
                'refresh' => array('type' => 'boolean', 'default' => false),
            ),
        ));

        register_rest_route(self::NS, '/sources/rakuten/categories', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'categories'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route(self::NS, '/sources/rakuten/count', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'count'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route(self::NS, '/sources/feeds', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'create_feed'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route(self::NS, '/sources/overview', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'overview'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route(self::NS, '/sources/runs/(?P<key>[A-Za-z0-9:_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'run_status'),
            'permission_callback' => array(__CLASS__, 'can_see_run'),
        ));

        register_rest_route(self::NS, '/sources/feeds/(?P<sid>[0-9]+)/refresh', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'refresh_feed'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));

        register_rest_route(self::NS, '/sources/feeds/(?P<sid>[0-9]+)/discover', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'discover'),
            'permission_callback' => array(__CLASS__, 'can_edit'),
        ));

        register_rest_route(self::NS, '/sources/targets', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'targets'),
            'permission_callback' => array(__CLASS__, 'can_edit'),
        ));

        register_rest_route(self::NS, '/sources/feeds/(?P<sid>[0-9]+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'feed'),
                'permission_callback' => array(__CLASS__, 'can_manage'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'update_feed'),
                'permission_callback' => array(__CLASS__, 'can_manage'),
            ),
        ));
    }

    public static function can_manage() {
        return current_user_can('manage_options');
    }

    /**
     * Pulling a search term into a feed is an author's action from the
     * editor, so it needs what the picker needs, not site administration.
     */
    public static function can_edit() {
        return current_user_can('edit_posts');
    }

    /**
     * The state of a search pulled into a feed (<stable id>_d) is what the
     * editor polls after the discover route, which an author may call; any
     * other run is site administration. The poll also takes the step, and
     * for a _d run that is the step discover started.
     */
    public static function can_see_run(WP_REST_Request $request) {
        $key = (string) $request->get_param('key');
        return preg_match('/^[0-9]+_d$/', $key) ? self::can_edit() : self::can_manage();
    }

    /**
     * A new Rakuten source, or a new connection for one, only where the
     * rules let one come (MyFeeds_Source_Rules). The routes are there only
     * then anyway; this holds for a request that was on its way.
     *
     * @return WP_Error|null
     */
    private static function refused() {
        if (class_exists('MyFeeds_Source_Rules') && MyFeeds_Source_Rules::can_connect('api', 'rakuten')) {
            return null;
        }
        return new WP_Error(
            'myfeeds_source_taken',
            class_exists('MyFeeds_Source_Rules') ? MyFeeds_Source_Rules::refusal() : '',
            array('status' => 409)
        );
    }

    // =====================================================================
    // Connection
    // =====================================================================

    public static function status(WP_REST_Request $request) {
        return rest_ensure_response(self::status_payload());
    }

    public static function status_payload() {
        $connection = MyFeeds_Rakuten_Credentials::get();
        $status     = MyFeeds_Rakuten_Credentials::get_status();

        return array(
            'connected'   => MyFeeds_Rakuten_Credentials::is_configured(),
            'state'       => $status['state'],
            'message'     => $status['message'],
            'checked_at'  => (int) $status['checked_at'],
            'client_id'   => $connection['client_id'],
            'sid'         => $connection['sid'],
            'has_secret'  => $connection['client_secret'] !== '',
            'unreadable'  => MyFeeds_Rakuten_Credentials::secret_unreadable(),
            'blocked'     => MyFeeds_Rakuten_Client::outbound_blocked(),
            'calls'       => MyFeeds_Rakuten_Client::calls_this_minute(),
            'wait'        => MyFeeds_Rakuten_Client::seconds_until_allowed(),
        );
    }

    public static function save_connection(WP_REST_Request $request) {
        $refused = self::refused();
        if ($refused !== null) {
            return $refused;
        }

        $result = MyFeeds_Rakuten_Credentials::save(array(
            'client_id'     => (string) $request->get_param('client_id'),
            'client_secret' => (string) $request->get_param('client_secret'),
            'sid'           => (string) $request->get_param('sid'),
        ));

        if (is_wp_error($result)) {
            $result->add_data(array('status' => 400), $result->get_error_code());
            return $result;
        }

        delete_transient(self::PARTNERS_TRANSIENT);

        return rest_ensure_response(array_merge(array('saved' => true), self::status_payload()));
    }

    public static function disconnect(WP_REST_Request $request) {
        MyFeeds_Rakuten_Credentials::disconnect();
        delete_transient(self::PARTNERS_TRANSIENT);

        return rest_ensure_response(array('disconnected' => true));
    }

    /**
     * Hand the stored secret back to an administrator who asks for it.
     *
     * POST on purpose, never printed into the page: the same reasoning as
     * the Amazon route - the site is often the only place the secret can
     * still be read, and the page source is the wrong place to keep it.
     */
    public static function reveal_secret(WP_REST_Request $request) {
        $connection = MyFeeds_Rakuten_Credentials::get();

        if ($connection['client_secret'] === '') {
            return new WP_Error(
                'myfeeds_rakuten_no_secret',
                MyFeeds_Rakuten_Credentials::secret_unreadable()
                    ? __('The stored secret can no longer be decrypted on this site.', 'myfeeds-affiliate-feed-manager')
                    : __('No secret is stored yet.', 'myfeeds-affiliate-feed-manager'),
                array('status' => 404)
            );
        }

        $response = rest_ensure_response(array('secret' => $connection['client_secret']));
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');

        return $response;
    }

    public static function test(WP_REST_Request $request) {
        $result = MyFeeds_Rakuten_Client::test_connection();
        delete_transient(self::PARTNERS_TRANSIENT);

        $result['banner'] = class_exists('MyFeeds_Source_Admin')
            ? MyFeeds_Source_Admin::status_html()
            : '';

        return rest_ensure_response($result);
    }

    // =====================================================================
    // Advertisers and counts
    // =====================================================================

    /**
     * Every advertiser the publisher is actively partnered with. Walks the
     * Partnerships pages once and keeps the list for ten minutes; the
     * dialog re-reads it on every open.
     */
    public static function advertisers(WP_REST_Request $request) {
        $refresh = (bool) $request->get_param('refresh');

        $cached = $refresh ? false : get_transient(self::PARTNERS_TRANSIENT);
        if (is_array($cached)) {
            return rest_ensure_response(array('advertisers' => $cached, 'cached' => true));
        }

        $list = self::load_partners();
        if (is_wp_error($list)) {
            return self::error_response($list);
        }

        set_transient(self::PARTNERS_TRANSIENT, $list, self::PARTNERS_TTL);

        return rest_ensure_response(array('advertisers' => $list, 'cached' => false));
    }

    /**
     * @return array<int,array>|WP_Error
     */
    public static function load_partners() {
        $all  = array();
        $page = 1;

        while ($page <= self::MAX_PARTNER_PAGES) {
            $result = MyFeeds_Rakuten_Client::partnerships($page, 200);
            if (is_wp_error($result)) {
                return $result;
            }

            foreach ($result['partners'] as $partner) {
                $info = MyFeeds_Rakuten_Client::network_info($partner['network']);
                $all[$partner['id']] = array(
                    'id'         => $partner['id'],
                    'name'       => $partner['name'],
                    'network'    => $partner['network'],
                    'market'     => $info['label'],
                    'currency'   => $info['currency'],
                    'categories' => $partner['categories'],
                );
            }

            $limit = max(1, (int) $result['limit']);
            if (count($result['partners']) === 0 || $page * $limit >= (int) $result['total']) {
                break;
            }
            $page++;
        }

        $all = array_values($all);
        usort($all, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $all;
    }

    /**
     * How many products a scope would fetch, before anyone commits to it.
     * One call per term, max=1 - the count is in the envelope, not in
     * the rows.
     */
    public static function count(WP_REST_Request $request) {
        $mid = (int) $request->get_param('mid');
        if ($mid <= 0) {
            return new WP_Error('myfeeds_rakuten_no_mid', __('Choose an advertiser first.', 'myfeeds-affiliate-feed-manager'), array('status' => 400));
        }

        $scope   = MyFeeds_Rakuten_Source::normalize_scope($request->get_param('scope'));
        $queries = MyFeeds_Rakuten_Source::scope_queries($mid, $scope);

        $per_query = array();
        $total     = 0;

        foreach ($queries as $query) {
            $query['max'] = 1;
            $result = MyFeeds_Rakuten_Client::search_products($query);
            if (is_wp_error($result)) {
                return self::error_response($result);
            }

            $term = isset($query['keyword']) ? $query['keyword'] : (isset($query['cat']) ? $query['cat'] : '');
            $per_query[] = array('term' => $term, 'total' => (int) $result['total_matches']);
            $total      += (int) $result['total_matches'];
        }

        return rest_ensure_response(array(
            'mid'       => $mid,
            'scope'     => $scope,
            'total'     => $total,
            'per_term'  => $per_query,
            'cap'       => MyFeeds_Rakuten_Client::RESULT_CAP,
            'over_cap'  => $total > MyFeeds_Rakuten_Client::RESULT_CAP,
        ));
    }

    /**
     * The categories an advertiser actually uses, so a scope can be
     * picked instead of guessed. Rakuten has no category endpoint; the
     * list is the feed's own rows (when a feed exists) merged with five
     * sample pages from the API, so a category the feed does not hold
     * yet is still there to add. Each entry says which side it came from.
     */
    public static function categories(WP_REST_Request $request) {
        $mid = (int) $request->get_param('mid');
        if ($mid <= 0) {
            return new WP_Error('myfeeds_rakuten_no_mid', __('Choose an advertiser first.', 'myfeeds-affiliate-feed-manager'), array('status' => 400));
        }

        $cache_key = 'myfeeds_rakuten_cats_' . $mid;
        $cached    = get_transient($cache_key);
        if (is_array($cached) && !$request->get_param('refresh')) {
            return rest_ensure_response($cached);
        }

        $result = MyFeeds_Rakuten_Source::categories_for($mid);
        if (is_wp_error($result)) {
            return self::error_response($result);
        }

        set_transient($cache_key, $result, 600);

        return rest_ensure_response($result);
    }

    // =====================================================================
    // Feeds
    // =====================================================================

    /**
     * Start fetching a new feed. The feed entry itself is created only
     * once the first snapshot is on disk - see MyFeeds_Source_Fetcher.
     */
    public static function create_feed(WP_REST_Request $request) {
        if (!class_exists('MyFeeds_Source_Fetcher')) {
            return new WP_Error(
                'myfeeds_source_unavailable',
                __('Feed creation from API sources is not installed on this site yet.', 'myfeeds-affiliate-feed-manager'),
                array('status' => 503)
            );
        }

        $refused = self::refused();
        if ($refused !== null) {
            return $refused;
        }

        $result = MyFeeds_Source_Fetcher::start_new(array(
            'network'         => 'rakuten',
            'mid'             => (int) $request->get_param('mid'),
            'advertiser_name' => (string) $request->get_param('advertiser_name'),
            'name'            => (string) $request->get_param('name'),
            'currency'        => (string) $request->get_param('currency'),
            'network_id'      => (int) $request->get_param('network_id'),
            'scope'           => $request->get_param('scope'),
        ));

        if (is_wp_error($result)) {
            return self::error_response($result);
        }

        return rest_ensure_response($result);
    }

    /**
     * Pending sources and every run, for the progress panel.
     */
    public static function overview(WP_REST_Request $request) {
        if (!class_exists('MyFeeds_Source_Fetcher')) {
            return rest_ensure_response(array('pending' => array(), 'runs' => array()));
        }

        MyFeeds_Source_Fetcher::heal_pending();
        MyFeeds_Source_Fetcher::heal_runs();

        return rest_ensure_response(MyFeeds_Source_Fetcher::overview());
    }

    /**
     * One run's state. On a host where Action Scheduler is not moving on
     * its own, the poll that asks for the state also advances it.
     */
    public static function run_status(WP_REST_Request $request) {
        if (!class_exists('MyFeeds_Source_Fetcher')) {
            return new WP_Error('myfeeds_source_unavailable', __('Not installed.', 'myfeeds-affiliate-feed-manager'), array('status' => 404));
        }

        $key   = (string) $request->get_param('key');
        $state = MyFeeds_Source_Fetcher::inline_step($key);

        if ($state === null) {
            return new WP_Error('myfeeds_source_invalid', __('No such run.', 'myfeeds-affiliate-feed-manager'), array('status' => 404));
        }

        return rest_ensure_response($state);
    }

    public static function refresh_feed(WP_REST_Request $request) {
        if (!class_exists('MyFeeds_Source_Fetcher')) {
            return new WP_Error('myfeeds_source_unavailable', __('Not installed.', 'myfeeds-affiliate-feed-manager'), array('status' => 404));
        }

        $result = MyFeeds_Source_Fetcher::refresh_feed((int) $request->get_param('sid'));
        if (is_wp_error($result)) {
            return self::error_response($result);
        }

        return rest_ensure_response($result);
    }

    /**
     * The API feeds a picker search can reach into, with how much of
     * each catalogue the snapshot holds.
     */
    public static function targets(WP_REST_Request $request) {
        $out = array();
        foreach (MyFeeds_Source_Feeds::feeds() as $feed) {
            $summary = class_exists('MyFeeds_Source_Fetcher') ? MyFeeds_Source_Fetcher::summary_for_feed($feed) : array();
            $sid     = isset($feed['stable_id']) ? (int) $feed['stable_id'] : 0;
            $out[] = array(
                'sid'        => $sid,
                'name'       => (string) $feed['name'],
                'advertiser' => isset($feed['api']['advertiser_name']) ? (string) $feed['api']['advertiser_name'] : '',
                'products'   => isset($summary['products']) ? (int) $summary['products'] : 0,
                'coverage'   => isset($summary['coverage']) && $summary['coverage'] !== null ? (float) $summary['coverage'] : null,
                'total'      => isset($summary['total']) ? (int) $summary['total'] : 0,
                'busy'       => class_exists('MyFeeds_Source_Fetcher') ? MyFeeds_Source_Fetcher::is_busy($sid) : false,
                'discovered' => MyFeeds_Source_Feeds::discovered($feed),
            );
        }

        return rest_ensure_response(array(
            'connected' => MyFeeds_Rakuten_Credentials::is_configured(),
            'feeds'     => $out,
        ));
    }

    public static function discover(WP_REST_Request $request) {
        if (!class_exists('MyFeeds_Source_Fetcher')) {
            return new WP_Error('myfeeds_source_unavailable', __('Not installed.', 'myfeeds-affiliate-feed-manager'), array('status' => 404));
        }

        $result = MyFeeds_Source_Fetcher::discover((int) $request->get_param('sid'), (string) $request->get_param('term'));
        if (is_wp_error($result)) {
            return self::error_response($result);
        }

        return rest_ensure_response($result);
    }

    public static function feed(WP_REST_Request $request) {
        $feed = MyFeeds_Source_Feeds::feed_for_stable_id((int) $request->get_param('sid'));
        if ($feed === null || !MyFeeds_Source_Feeds::is_api_feed($feed)) {
            return new WP_Error('myfeeds_source_invalid', __('This feed is not an API source.', 'myfeeds-affiliate-feed-manager'), array('status' => 404));
        }

        return rest_ensure_response(array(
            'stable_id'  => (int) $feed['stable_id'],
            'name'       => (string) $feed['name'],
            'api'        => $feed['api'],
            'discovered' => MyFeeds_Source_Feeds::discovered($feed),
            'summary'    => class_exists('MyFeeds_Source_Fetcher') ? MyFeeds_Source_Fetcher::summary_for_feed($feed) : array(),
        ));
    }

    /**
     * Name and scope can change; the advertiser cannot (that is another
     * feed). A changed scope is fetched right away.
     */
    public static function update_feed(WP_REST_Request $request) {
        $sid  = (int) $request->get_param('sid');
        $feed = MyFeeds_Source_Feeds::update_entry($sid, (string) $request->get_param('name'), $request->get_param('scope'));
        if (is_wp_error($feed)) {
            return self::error_response($feed);
        }

        $discovered = $request->get_param('discovered');
        if (is_array($discovered)) {
            MyFeeds_Source_Feeds::set_discovered($sid, array_map('strval', $discovered));
        } elseif (is_string($discovered)) {
            MyFeeds_Source_Feeds::set_discovered($sid, array_map('trim', explode(',', $discovered)));
        }

        $refresh = class_exists('MyFeeds_Source_Fetcher') ? MyFeeds_Source_Fetcher::refresh_feed($sid) : null;

        return rest_ensure_response(array(
            'saved'   => true,
            'name'    => $feed['name'],
            'api'     => $feed['api'],
            'refresh' => is_wp_error($refresh) ? $refresh->get_error_message() : $refresh,
        ));
    }

    // =====================================================================
    // Errors
    // =====================================================================

    /**
     * @param WP_Error $error
     * @return WP_Error
     */
    public static function error_response(WP_Error $error) {
        $status = 502;

        switch ($error->get_error_code()) {
            case 'myfeeds_rakuten_not_configured':
            case 'myfeeds_rakuten_no_partners':
            case 'myfeeds_source_unavailable':
                $status = 409;
                break;
            case 'myfeeds_rakuten_unauthorized':
                $status = 401;
                break;
            case 'myfeeds_rakuten_throttled':
                $status = 429;
                break;
            case 'myfeeds_rakuten_no_mid':
            case 'myfeeds_source_invalid':
            case 'myfeeds_source_duplicate':
                $status = 400;
                break;
            case 'myfeeds_source_busy':
            case 'myfeeds_source_taken':
                $status = 409;
                break;
        }

        $error->add_data(array('status' => $status), $error->get_error_code());

        return $error;
    }
}
