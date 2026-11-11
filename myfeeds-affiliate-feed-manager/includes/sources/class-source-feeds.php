<?php
/**
 * The feed entry behind an API source, and how it shows in the feeds
 * table.
 *
 * An API feed is an ordinary entry in the `myfeeds_feeds` option whose
 * `url` points at a snapshot file in the upload folder. What makes it
 * an API feed is the `api` block; the `source` field is never read by
 * anything and is overwritten on edit, so it cannot be the identity.
 *
 * The entry is created only when the first snapshot is on disk. An
 * entry whose file does not exist yet counts as a failed feed in the
 * weekly import, and one failed feed switches the unavailable-marking
 * off for every feed on the site (class-db-manager.php, the
 * $all_feeds_ok guard). A feed that is not there yet cannot fail.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Source_Feeds {

    const OPTION_KEY = 'myfeeds_feeds';

    /** Days after which a snapshot that would not refresh is called out. */
    const STALE_DAYS = 8;

    /** Search terms a feed remembers from picker searches. */
    const MAX_DISCOVERED = 60;

    public static function init() {
        add_filter('myfeeds_feed_row_source_html', array(__CLASS__, 'row_source_html'), 10, 3);
        add_action('myfeeds_feed_row_actions', array(__CLASS__, 'row_actions'), 10, 2);
        add_filter('myfeeds_feed_source_kind', array(__CLASS__, 'source_kind'), 10, 2);
        add_action('myfeeds_feed_deleted', array(__CLASS__, 'on_deleted'), 10, 2);
        add_action('update_option_myfeeds_import_status', array(__CLASS__, 'after_import_status'), 10, 2);
    }

    /**
     * A single-feed import ends without firing myfeeds_feed_update_complete,
     * so the picker search keeps its cached candidate sets for up to
     * fifteen minutes - and a search that just pulled products from
     * Rakuten would not show them. Watch the status option instead: when
     * a single-feed import of an API feed completes, drop the caches.
     */
    public static function after_import_status($old, $new) {
        if (!is_array($new) || !isset($new['status']) || $new['status'] !== 'completed') {
            return;
        }
        if (!isset($new['import_type']) || $new['import_type'] !== 'single_feed') {
            return;
        }
        if (is_array($old) && isset($old['status']) && $old['status'] === 'completed') {
            return;
        }

        $name = isset($new['single_feed_name']) ? (string) $new['single_feed_name'] : '';
        $ours = null;
        foreach (self::feeds() as $feed) {
            if (isset($feed['name']) && (string) $feed['name'] === $name) {
                $ours = $feed;
                break;
            }
        }
        if ($ours === null) {
            return;
        }

        // The row's product count comes from the last full fetch. A
        // follow-up search adds products through this very import and
        // never touches that number, so the row understated the feed
        // until the next night.
        if (!empty($ours['stable_id']) && class_exists('MyFeeds_Source_Fetcher')) {
            MyFeeds_Source_Fetcher::refresh_product_count((int) $ours['stable_id']);
        }

        if (class_exists('MyFeeds_Search_Engine') && method_exists('MyFeeds_Search_Engine', 'flush_query_caches')) {
            MyFeeds_Search_Engine::flush_query_caches();
        }
        if (class_exists('MyFeeds_Product_Resolver') && method_exists('MyFeeds_Product_Resolver', 'clear_cache')) {
            MyFeeds_Product_Resolver::clear_cache();
        }
    }

    // =====================================================================
    // Lookups
    // =====================================================================

    /**
     * @param mixed $feed
     * @return bool
     */
    public static function is_api_feed($feed) {
        return is_array($feed) && !empty($feed['api']) && is_array($feed['api']);
    }

    /**
     * @return array<int,array> feed_key => entry, API feeds only.
     */
    public static function feeds() {
        $all = get_option(self::OPTION_KEY, array());
        if (!is_array($all)) {
            return array();
        }

        $out = array();
        foreach ($all as $key => $feed) {
            if (self::is_api_feed($feed)) {
                $out[$key] = $feed;
            }
        }

        return $out;
    }

    /**
     * @param int $stable_id
     * @return int|null feed_key, or null when no entry carries the id.
     */
    public static function key_for_stable_id($stable_id) {
        $all = get_option(self::OPTION_KEY, array());
        if (!is_array($all)) {
            return null;
        }

        foreach ($all as $key => $feed) {
            if (is_array($feed) && isset($feed['stable_id']) && (int) $feed['stable_id'] === (int) $stable_id) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param int $stable_id
     * @return array|null
     */
    public static function feed_for_stable_id($stable_id) {
        $key = self::key_for_stable_id($stable_id);
        if ($key === null) {
            return null;
        }
        $all = get_option(self::OPTION_KEY, array());

        return isset($all[$key]) ? $all[$key] : null;
    }

    /**
     * Feed names must stay unique: product counts are looked up by name
     * (class-batch-importer.php), and the feeds table tells feeds apart
     * by it.
     *
     * @param string $name
     * @param int    $except_stable_id Entry allowed to carry the name already.
     * @return bool
     */
    public static function name_exists($name, $except_stable_id = 0, $except_token = '') {
        $name = trim((string) $name);
        $all  = get_option(self::OPTION_KEY, array());
        if (is_array($all)) {
            foreach ($all as $feed) {
                if (!is_array($feed) || !isset($feed['name'])) {
                    continue;
                }
                if ((int) $except_stable_id > 0 && isset($feed['stable_id']) && (int) $feed['stable_id'] === (int) $except_stable_id) {
                    continue;
                }
                if (strcasecmp(trim((string) $feed['name']), $name) === 0) {
                    return true;
                }
            }
        }

        if (class_exists('MyFeeds_Source_Fetcher')) {
            foreach (MyFeeds_Source_Fetcher::pending() as $token => $pending) {
                if ($except_token !== '' && (string) $token === (string) $except_token) {
                    continue;
                }
                if (isset($pending['name']) && strcasecmp(trim((string) $pending['name']), $name) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    // =====================================================================
    // Entry
    // =====================================================================

    /**
     * The mapping an API feed is created with. Written explicitly rather
     * than left to the Smart Mapper: the snapshot URL carries no network
     * hint, so the mapper would have only the `mid` column to recognise
     * Rakuten by. Every source name here is one the mapper's own Rakuten
     * schema lists first for that field, so a later "Regenerate mappings"
     * lands on the same result - a test pins that.
     *
     * `colour` and `size` are deliberately absent: the importer reads
     * those straight off the row by name, so a mapping entry would be a
     * second, competing answer for the same column.
     *
     * @return array<string,string>
     */
    public static function mapping() {
        return array(
            'id'             => 'id',
            'title'          => 'productname',
            'price'          => 'price',
            'old_price'      => 'retail_price',
            'brand'          => 'brand',
            'merchant'       => 'merchant_name',
            'image_url'      => 'imageurl',
            'affiliate_link' => 'linkurl',
            'description'    => 'description',
            'category'       => 'primarycategory',
            'currency'       => 'currency',
        );
    }

    /**
     * Append the entry for a source whose first snapshot has just landed.
     *
     * The one place a new Rakuten entry is written, so the one place that
     * asks whether a new source may come (MyFeeds_Source_Rules): a fetch
     * that started while the site had no other source must not make
     * Rakuten its source once it has one. Whoever called this drops the
     * pending source on myfeeds_source_taken.
     *
     * @param array $pending {network, mid, advertiser_name, name, currency, network_id, scope, file}
     * @param array $run     Completed run state (strategy, totals).
     * @return array{key:int,stable_id:int}|WP_Error
     */
    public static function create_entry(array $pending, array $run) {
        if (!class_exists('MyFeeds_Source_Rules') || !MyFeeds_Source_Rules::can_connect('api', 'rakuten')) {
            return new WP_Error(
                'myfeeds_source_taken',
                class_exists('MyFeeds_Source_Rules') ? MyFeeds_Source_Rules::refusal() : '',
                array('status' => 409)
            );
        }

        // A real feed replaces the sample products, the same as one added
        // in the Add Feed dialog. Before the list is read, so the entry
        // below is appended to the list without them.
        if (class_exists('MyFeeds_Demo_Content')) {
            MyFeeds_Demo_Content::remove_if_active();
        }

        $all = get_option(self::OPTION_KEY, array());
        if (!is_array($all)) {
            $all = array();
        }

        $name = trim((string) $pending['name']);
        if ($name === '') {
            $name = trim((string) $pending['advertiser_name']);
        }
        if ($name === '') {
            $name = 'Rakuten ' . (int) $pending['mid'];
        }
        // The pending record of this very source still carries the name.
        if (self::name_exists($name, 0, isset($pending['token']) ? (string) $pending['token'] : '')) {
            $name .= ' (' . (int) $pending['mid'] . ')';
        }

        $scope = MyFeeds_Rakuten_Source::normalize_scope(isset($pending['scope']) ? $pending['scope'] : null);
        $now   = current_time('mysql');

        $entry = array(
            'name'               => $name,
            'url'                => MyFeeds_Source_Snapshot::url_for((string) $pending['file']),
            'format_hint'        => 'json_lines',
            'detected_format'    => 'json_lines',
            'network_hint'       => 'rakuten',
            'detected_network'   => 'rakuten',
            'detected_fields'    => MyFeeds_Rakuten_Rows::COLUMNS,
            'source'             => 'api',
            'uploaded_at'        => '',
            'uploaded_name'      => '',
            'mapping'            => self::mapping(),
            'mapping_confidence' => 100,
            'mapping_source'     => 'api',
            'mapping_updated'    => $now,
            'status'             => 'active',
            'created_at'         => $now,
            'last_updated'       => $now,
            'last_test'          => $now,
            'api'                => array(
                'network'         => 'rakuten',
                'mid'             => (int) $pending['mid'],
                'advertiser_name' => (string) $pending['advertiser_name'],
                'network_id'      => isset($pending['network_id']) ? (int) $pending['network_id'] : 0,
                'currency'        => isset($pending['currency']) ? strtoupper((string) $pending['currency']) : '',
                'scope'           => $scope,
                'id_strategy'     => isset($run['strategy']) && $run['strategy'] === MyFeeds_Rakuten_Rows::STRATEGY_LINKID
                    ? MyFeeds_Rakuten_Rows::STRATEGY_LINKID
                    : MyFeeds_Rakuten_Rows::STRATEGY_SKU,
                'created_run'     => isset($run['run']) ? (string) $run['run'] : '',
            ),
        );

        // The advertiser's settlement currency is the right default for a
        // feed whose rows all carry it anyway; the column still wins.
        if ($entry['api']['currency'] !== '' && preg_match('/^[A-Z]{3}$/', $entry['api']['currency'])) {
            $entry['default_currency'] = $entry['api']['currency'];
        }

        MyFeeds_DB_Manager::assign_stable_id($entry);

        $all[] = $entry;
        update_option(self::OPTION_KEY, $all);

        $key = array_key_last($all);

        // The importer rewrites this option from its own read at feed
        // boundaries; a write that lands inside that window is lost. Read
        // back and append once more rather than trusting the write.
        $check = get_option(self::OPTION_KEY, array());
        if (!is_array($check) || self::key_for_stable_id($entry['stable_id']) === null) {
            $check   = is_array($check) ? $check : array();
            $check[] = $entry;
            update_option(self::OPTION_KEY, $check);
            $key = array_key_last($check);
        }

        return array('key' => $key, 'stable_id' => (int) $entry['stable_id']);
    }

    /**
     * Change what can change on an API feed: the name and the scope.
     *
     * @param int    $stable_id
     * @param string $name
     * @param mixed  $scope
     * @return array|WP_Error The updated entry.
     */
    public static function update_entry($stable_id, $name, $scope) {
        $key = self::key_for_stable_id($stable_id);
        if ($key === null) {
            return new WP_Error('myfeeds_source_invalid', __('This feed no longer exists.', 'myfeeds-affiliate-feed-manager'));
        }

        $all  = get_option(self::OPTION_KEY, array());
        $feed = $all[$key];
        if (!self::is_api_feed($feed)) {
            return new WP_Error('myfeeds_source_invalid', __('This feed is not an API source.', 'myfeeds-affiliate-feed-manager'));
        }

        $name = trim(sanitize_text_field((string) $name));
        if ($name === '') {
            return new WP_Error('myfeeds_source_invalid', __('The feed needs a name.', 'myfeeds-affiliate-feed-manager'));
        }
        if (self::name_exists($name, $stable_id)) {
            return new WP_Error('myfeeds_source_duplicate', __('Another feed already uses that name.', 'myfeeds-affiliate-feed-manager'));
        }

        $feed['name']         = $name;
        $feed['api']['scope'] = MyFeeds_Rakuten_Source::normalize_scope($scope);
        $feed['last_updated'] = current_time('mysql');

        $all[$key] = $feed;
        update_option(self::OPTION_KEY, $all);

        return $feed;
    }

    /**
     * Terms that picker searches have pulled into this feed.
     *
     * @param array $feed
     * @return array<int,string>
     */
    public static function discovered(array $feed) {
        if (!isset($feed['api']['discovered']) || !is_array($feed['api']['discovered'])) {
            return array();
        }
        $out = array();
        foreach ($feed['api']['discovered'] as $entry) {
            $term = is_array($entry) ? (isset($entry['term']) ? (string) $entry['term'] : '') : (string) $entry;
            if ($term !== '') {
                $out[] = $term;
            }
        }
        return $out;
    }

    /**
     * Remember a term on a feed. Returns false when it was there already.
     *
     * @param int    $stable_id
     * @param string $term      Already cleaned.
     * @param int    $total     TotalMatches the term had, when known.
     * @return bool|WP_Error
     */
    public static function add_discovered($stable_id, $term, $total = 0) {
        $key = self::key_for_stable_id($stable_id);
        if ($key === null) {
            return new WP_Error('myfeeds_source_invalid', __('This feed no longer exists.', 'myfeeds-affiliate-feed-manager'));
        }

        $all  = get_option(self::OPTION_KEY, array());
        $feed = $all[$key];
        if (!self::is_api_feed($feed)) {
            return new WP_Error('myfeeds_source_invalid', __('This feed is not an API source.', 'myfeeds-affiliate-feed-manager'));
        }

        $list = isset($feed['api']['discovered']) && is_array($feed['api']['discovered']) ? $feed['api']['discovered'] : array();
        foreach ($list as $entry) {
            $existing = is_array($entry) ? (isset($entry['term']) ? $entry['term'] : '') : $entry;
            if (strcasecmp((string) $existing, $term) === 0) {
                return false;
            }
        }

        if (count($list) >= self::MAX_DISCOVERED) {
            return new WP_Error('myfeeds_source_invalid', sprintf(
                /* translators: %d: maximum number of remembered search terms */
                __('This feed already remembers %d search terms. Remove some in the feed settings first.', 'myfeeds-affiliate-feed-manager'),
                self::MAX_DISCOVERED
            ));
        }

        $list[] = array('term' => $term, 'added_at' => time(), 'total' => (int) $total);
        $feed['api']['discovered'] = $list;
        $all[$key] = $feed;
        update_option(self::OPTION_KEY, $all);

        return true;
    }

    /**
     * Replace the remembered terms (the feed settings dialog edits them).
     *
     * @param int   $stable_id
     * @param array $terms
     * @return bool
     */
    public static function set_discovered($stable_id, array $terms) {
        $key = self::key_for_stable_id($stable_id);
        if ($key === null) {
            return false;
        }
        $all  = get_option(self::OPTION_KEY, array());
        $feed = $all[$key];
        if (!self::is_api_feed($feed)) {
            return false;
        }

        $kept = array();
        $old  = isset($feed['api']['discovered']) && is_array($feed['api']['discovered']) ? $feed['api']['discovered'] : array();
        foreach ($terms as $term) {
            $term = MyFeeds_Rakuten_Source::clean_term($term);
            if ($term === '') {
                continue;
            }
            $entry = array('term' => $term, 'added_at' => time(), 'total' => 0);
            foreach ($old as $previous) {
                if (is_array($previous) && isset($previous['term']) && strcasecmp($previous['term'], $term) === 0) {
                    $entry = $previous;
                    break;
                }
            }
            $kept[] = $entry;
            if (count($kept) >= self::MAX_DISCOVERED) {
                break;
            }
        }

        $feed['api']['discovered'] = $kept;
        $all[$key] = $feed;
        update_option(self::OPTION_KEY, $all);

        return true;
    }

    /**
     * @param array $feed
     * @return string Absolute snapshot path, or '' when the URL is not ours.
     */
    public static function snapshot_path(array $feed) {
        if (!isset($feed['url'])) {
            return '';
        }
        $name = basename((string) wp_parse_url((string) $feed['url'], PHP_URL_PATH));
        if ($name === '' || strpos($name, '..') !== false) {
            return '';
        }

        return MyFeeds_Source_Snapshot::path_for($name);
    }

    // =====================================================================
    // Feeds table
    // =====================================================================

    /**
     * What the source column shows instead of "uploaded file, does not
     * refresh", which is what the snapshot URL would otherwise read as.
     */
    public static function row_source_html($html, $feed, $key) {
        if (!self::is_api_feed($feed)) {
            return $html;
        }

        $summary = class_exists('MyFeeds_Source_Fetcher')
            ? MyFeeds_Source_Fetcher::summary_for_feed($feed)
            : array();

        $label = __('Rakuten', 'myfeeds-affiliate-feed-manager');
        if (!empty($feed['api']['advertiser_name'])) {
            $label .= ' · ' . $feed['api']['advertiser_name'];
        }

        $scope = isset($feed['api']['scope']) ? MyFeeds_Rakuten_Source::describe_scope(MyFeeds_Rakuten_Source::normalize_scope($feed['api']['scope'])) : '';
        $kind  = isset($summary['kind']) ? $summary['kind'] : 'info';
        if (self::paused()) {
            // A run that was under way when the fetcher stopped does not
            // go on, and "fetching" would say it does.
            $summary['fetching'] = false;
            $known = self::describe_summary($summary);
            $line  = __('paused', 'myfeeds-affiliate-feed-manager') . ($known !== '' && !empty($summary) ? ' · ' . $known : '');
            $kind  = 'info';
        } else {
            $line = self::describe_summary($summary);
        }

        ob_start();
        ?>
        <span class="myfeeds-feed-url myfeeds-feed-url-api" title="<?php echo esc_attr($scope); ?>">
            <?php echo esc_html($label); ?>
        </span>
        <span class="myfeeds-feed-frozen myfeeds-feed-api-state is-<?php echo esc_attr($kind); ?>"
              data-sid="<?php echo esc_attr(isset($feed['stable_id']) ? (int) $feed['stable_id'] : 0); ?>"
              title="<?php echo esc_attr($scope); ?>">
            <?php echo esc_html($line); ?>
        </span>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array $summary From MyFeeds_Source_Fetcher::summary_for_feed().
     * @return string
     */
    public static function describe_summary(array $summary) {
        if (empty($summary)) {
            return __('not fetched yet', 'myfeeds-affiliate-feed-manager');
        }

        if (!empty($summary['fetching'])) {
            return sprintf(
                /* translators: %s: number of rows fetched so far (one row per size or colour; products are counted at the end) */
                __('fetching… %s rows so far', 'myfeeds-affiliate-feed-manager'),
                number_format_i18n((int) $summary['fetched'])
            );
        }

        $parts = array();

        if (!empty($summary['fetched_at'])) {
            $parts[] = sprintf(
                /* translators: %s: human readable time difference */
                __('refreshed %s ago', 'myfeeds-affiliate-feed-manager'),
                human_time_diff((int) $summary['fetched_at'], time())
            );
        }

        if (isset($summary['products'])) {
            $parts[] = sprintf(
                /* translators: %s: number of products */
                __('%s products', 'myfeeds-affiliate-feed-manager'),
                number_format_i18n((int) $summary['products'])
            );
        }

        if (isset($summary['coverage']) && $summary['coverage'] < 0.999) {
            $parts[] = sprintf(
                /* translators: %s: percentage */
                __('%s%% reached', 'myfeeds-affiliate-feed-manager'),
                number_format_i18n(round($summary['coverage'] * 100))
            );
        }

        if (!empty($summary['error'])) {
            $parts[] = sprintf(
                /* translators: %s: error message */
                __('last refresh failed: %s', 'myfeeds-affiliate-feed-manager'),
                $summary['error']
            );
        }

        if (!empty($summary['stale'])) {
            $parts[] = __('snapshot is more than a week old', 'myfeeds-affiliate-feed-manager');
        }

        return implode(' · ', $parts);
    }

    public static function row_actions($feed, $key) {
        if (!self::is_api_feed($feed) || self::paused()) {
            return;
        }

        $sid      = isset($feed['stable_id']) ? (int) $feed['stable_id'] : 0;
        $fetching = class_exists('MyFeeds_Source_Fetcher') && MyFeeds_Source_Fetcher::is_fetching($sid);
        ?>
        <button type="button" class="button button-small myfeeds-source-refresh-btn"
            data-sid="<?php echo esc_attr($sid); ?>"
            data-feed-name="<?php echo esc_attr($feed['name']); ?>"
            data-testid="refresh-source-<?php echo esc_attr($key); ?>"
            <?php if ($fetching) : ?>disabled title="<?php esc_attr_e('Fetch already running', 'myfeeds-affiliate-feed-manager'); ?>"<?php endif; ?>>
            <?php esc_html_e('Refresh now', 'myfeeds-affiliate-feed-manager'); ?>
        </button>
        <?php
    }

    /**
     * Where Rakuten does not run (MyFeeds_Source_Rules) the fetcher is not
     * wired, and nothing behind a Refresh button would answer.
     */
    private static function paused() {
        return class_exists('MyFeeds_Source_Rules') && !MyFeeds_Source_Rules::runs('api', 'rakuten');
    }

    public static function source_kind($kind, $feed) {
        return self::is_api_feed($feed) ? 'api' : $kind;
    }

    /**
     * A deleted feed takes its run and its part file with it; the
     * snapshot itself goes through the upload class like any upload.
     */
    public static function on_deleted($feed, $key = null) {
        if (!self::is_api_feed($feed)) {
            return;
        }

        if (class_exists('MyFeeds_Source_Fetcher') && isset($feed['stable_id'])) {
            MyFeeds_Source_Fetcher::forget_run((int) $feed['stable_id']);
        }

        $path = self::snapshot_path($feed);
        if ($path !== '') {
            MyFeeds_Source_Snapshot::discard($path);
            MyFeeds_Source_Snapshot::discard($path . '.discover');
        }
    }
}
