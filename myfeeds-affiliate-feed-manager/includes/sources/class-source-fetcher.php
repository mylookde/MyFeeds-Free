<?php
/**
 * Fetches an API source into its snapshot, one budgeted step at a time.
 *
 * A run is a queue of Product Search queries. Each Action Scheduler job
 * works through pages until its time budget is spent, saves where it
 * got to, and schedules the next job with the same run id. When the
 * queue is empty the part file is committed, the feed entry is created
 * if this was a source's first run, and the ordinary single-feed import
 * is started.
 *
 * Shared hosting is the design constraint. set_time_limit cannot be
 * relied on, so the budget is measured with microtime and kept short;
 * the fetcher never sleeps through a rate-limit wait, it reschedules;
 * and hosts that never run Action Scheduler on their own get a step from
 * the dialog's status poll, the way the importer takes over from a dead
 * loopback.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Source_Fetcher {

    const HOOK        = 'myfeeds_source_fetch';
    const RETRY_HOOK  = 'myfeeds_source_import_retry';
    const CRON_HOOK   = 'myfeeds_source_refresh';
    const GROUP       = 'myfeeds';

    const OPTION_RUNS    = 'myfeeds_source_runs';
    const OPTION_PENDING = 'myfeeds_source_pending';

    const LOCK = 'myfeeds_source_fetch';

    /** Seconds of API work per job under WP-Cron. */
    const BUDGET_CRON = 20;
    /** Ceiling and floor for a job that runs inside a web request. */
    const BUDGET_WEB_MAX = 12;
    const BUDGET_MIN     = 6;

    /** A step this old lets the status poll run one inline. */
    const STALE_STEP = 30;

    /** A run nobody has touched for this long is treated as abandoned. */
    const ABANDONED_AFTER = 3600;

    /**
     * A fetching run whose last step is older than this and that has no
     * job queued gets one. Action Scheduler can drop a follow-up (a
     * dedup that misfired, a queue runner that died mid-way), and a chain
     * that stops silently is a feed that never appears.
     */
    const STALLED_AFTER = 120;

    /** Consecutive API failures before a run gives up. */
    const MAX_ERRORS = 3;

    /** Calls one run may spend; a runaway query ends with coverage, not forever. */
    const MAX_CALLS = 6000;

    /** Distinct categories a capped query is sliced by. */
    const MAX_CATEGORIES = 60;

    const IMPORT_RETRIES     = 6;
    const IMPORT_RETRY_DELAY = 900;

    /** @var callable|null Replaces the import trigger in tests. */
    private static $import_trigger = null;

    /** @var array<int,array> What schedule_job() last scheduled, for tests. */
    public static $scheduled = array();

    /** @var bool unschedule_jobs() waits for Action Scheduler's store. */
    private static $unschedule_waits = false;

    public static function init() {
        add_action(self::HOOK, array(__CLASS__, 'run_job'));
        add_action(self::RETRY_HOOK, array(__CLASS__, 'retry_import'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'refresh_all'));
        add_action('init', array(__CLASS__, 'ensure_cron'), 20);
    }

    /**
     * Nightly at 00:30 site time, self-healing like the plugin's other
     * crons: registered whenever it is missing, not only on activation.
     */
    public static function ensure_cron() {
        if (!function_exists('wp_next_scheduled') || wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $now      = new DateTime('now', $timezone);
        $target   = new DateTime('today 00:30', $timezone);
        if ($now > $target) {
            $target->modify('+1 day');
        }

        wp_schedule_event($target->getTimestamp(), 'daily', self::CRON_HOOK);
    }

    public static function deactivate() {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
        self::unschedule_jobs();
    }

    /**
     * Every queued step and import retry, whatever its arguments. Action
     * Scheduler cancels a hook as a whole only when it is given neither
     * arguments nor a group; with the group and empty arguments it looks
     * for jobs whose arguments are empty, and every job here carries its
     * key and run, so nothing was cancelled.
     *
     * Before its store is ready (init 1) Action Scheduler cancels nothing
     * and reports the call as made too early. stop_all() can run sooner,
     * on init 0, so the cancel then waits for action_scheduler_init.
     */
    public static function unschedule_jobs() {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }
        if (class_exists('ActionScheduler') && method_exists('ActionScheduler', 'is_initialized')
            && !ActionScheduler::is_initialized()) {
            if (!self::$unschedule_waits) {
                self::$unschedule_waits = true;
                add_action('action_scheduler_init', array(__CLASS__, 'unschedule_jobs'));
            }
            return;
        }
        as_unschedule_all_actions(self::HOOK);
        as_unschedule_all_actions(self::RETRY_HOOK);
    }

    /**
     * Ends every fetch, for when this network's API is not (or no longer)
     * the site's product source: the runs and the pending new sources are
     * forgotten, their part files and the snapshots no feed entry took
     * over are thrown away, and the queued jobs are cancelled. A snapshot
     * a feed entry points at stays, also one of a feed that is set aside.
     * The nightly cron stays: it does nothing without runs or feeds.
     *
     * @return int Runs and pending sources that were forgotten.
     */
    public static function stop_all() {
        $runs    = self::runs();
        $pending = self::pending();

        foreach ($runs as $state) {
            if (is_array($state) && isset($state['file'])) {
                MyFeeds_Source_Snapshot::discard(self::work_path((string) $state['file'], !empty($state['partial'])));
            }
        }
        foreach ($pending as $source) {
            if (is_array($source)) {
                self::forget_files_of($source);
            }
        }

        delete_option(self::OPTION_RUNS);
        delete_option(self::OPTION_PENDING);
        self::unschedule_jobs();

        $count = count($runs) + count($pending);
        if ($count > 0) {
            self::log('every fetch stopped (' . count($runs) . ' runs, ' . count($pending) . ' new sources): Rakuten is not this site\'s product source', 'info');
        }

        return $count;
    }

    /**
     * @param callable|null $trigger function(int $feed_key, int $stable_id): bool
     */
    public static function set_import_trigger($trigger) {
        self::$import_trigger = is_callable($trigger) ? $trigger : null;
    }

    // =====================================================================
    // State
    // =====================================================================

    /**
     * @return array<string,array>
     */
    public static function runs() {
        $runs = get_option(self::OPTION_RUNS, array());
        return is_array($runs) ? $runs : array();
    }

    private static function save_runs(array $runs) {
        update_option(self::OPTION_RUNS, $runs, false);
    }

    /**
     * Drops this request's copies of the runs, the pending sources and
     * the feed list. A request keeps every option it read, and a fetch
     * step runs for seconds, Action Scheduler several in one request: a
     * delete, "Change source", a site's first feed or a stop finished by
     * another request meanwhile is not in that copy, and a decision or a
     * write made from it undoes what that request did. The fetcher's
     * options are stored without autoload and cached under their name;
     * the feed list is autoloaded and read through 'alloptions'.
     */
    private static function forget_cached() {
        wp_cache_delete(self::OPTION_RUNS, 'options');
        wp_cache_delete(self::OPTION_PENDING, 'options');
        wp_cache_delete(MyFeeds_Source_Feeds::OPTION_KEY, 'options');
        wp_cache_delete('alloptions', 'options');
    }

    /** @return array<string,array> The runs as stored now, for a write. */
    private static function stored_runs() {
        wp_cache_delete(self::OPTION_RUNS, 'options');
        return self::runs();
    }

    /** @return array<string,array> The pending sources as stored now, for a write. */
    private static function stored_pending() {
        wp_cache_delete(self::OPTION_PENDING, 'options');
        return self::pending();
    }

    /**
     * @param string|int $key stable_id, or 'p:<token>' for a source with no entry yet.
     * @return array|null
     */
    public static function run_state($key) {
        $runs = self::runs();
        $key  = (string) $key;
        return isset($runs[$key]) && is_array($runs[$key]) ? $runs[$key] : null;
    }

    /**
     * Writes one run into the runs as stored now, never into this
     * request's copy: that would bring back runs another request ended
     * meanwhile. A run is written only while it is still the one stored
     * under its key (the same run id); one that was ended or replaced
     * elsewhere is not written back. Only start_run() creates one.
     *
     * @param string|int $key
     * @param array|null $state  Null forgets the run.
     * @param bool       $create The run is new.
     * @return bool Whether the run's state is stored (for null: it is gone).
     */
    private static function set_run_state($key, $state, $create = false) {
        $runs = self::stored_runs();
        $key  = (string) $key;
        if ($state === null) {
            if (array_key_exists($key, $runs)) {
                unset($runs[$key]);
                self::save_runs($runs);
            }
            return true;
        }
        if (!$create) {
            $stored = isset($runs[$key]) && is_array($runs[$key]) && isset($runs[$key]['run']) ? (string) $runs[$key]['run'] : null;
            if ($stored === null || $stored !== (isset($state['run']) ? (string) $state['run'] : '')) {
                return false;
            }
        }
        $runs[$key] = $state;
        self::save_runs($runs);
        return true;
    }

    /**
     * Bring the count shown in the feeds table back in line with the
     * catalogue.
     *
     * A follow-up search imports its finds through the ordinary
     * single-feed path, which does not touch the run state - so the row
     * kept showing the product count of the last full fetch while the
     * table already held more. Measured on mylook.com.de: the row said
     * 23,231 where the feed had 24,900.
     *
     * @param int $stable_id
     * @return int|null The new count, or null when there was nothing to update.
     */
    public static function refresh_product_count($stable_id) {
        $stable_id = (int) $stable_id;
        $state = self::run_state($stable_id);
        if ($state === null || !class_exists('MyFeeds_DB_Manager')
            || !method_exists('MyFeeds_DB_Manager', 'get_feed_product_count')) {
            return null;
        }

        $count = (int) MyFeeds_DB_Manager::get_feed_product_count($stable_id);
        if ($count <= 0 || $count === (int) (isset($state['products']) ? $state['products'] : -1)) {
            return null;
        }

        $state['products'] = $count;
        self::set_run_state($stable_id, $state);

        return $count;
    }

    public static function forget_run($key) {
        self::set_run_state($key, null);
        if (ctype_digit((string) $key)) {
            self::set_run_state(self::discovery_key($key), null);
            self::set_run_state((string) (int) $key . ':d', null);
        }
    }

    /**
     * @return array<string,array> token => pending source.
     */
    public static function pending() {
        $pending = get_option(self::OPTION_PENDING, array());
        return is_array($pending) ? $pending : array();
    }

    private static function save_pending(array $pending) {
        update_option(self::OPTION_PENDING, $pending, false);
    }

    public static function is_fetching($key) {
        $state = self::run_state($key);
        return $state !== null && $state['status'] === 'fetching';
    }

    /**
     * Key of the run that pulls single search terms into a feed. Kept
     * apart from the feed's own key so a discovery never overwrites the
     * summary the table shows for the last full refresh.
     */
    public static function discovery_key($stable_id) {
        // No colon: the key travels in a REST path, and an encoded colon
        // (%3A) does not match the route.
        return (string) (int) $stable_id . '_d';
    }

    /**
     * Anything fetching for this feed - a refresh or a discovery. Without
     * a feed: anything fetching at all that is still moving and may go on
     * (a run nobody has touched for an hour, or one run_belongs() turns
     * down, is not under way any more).
     *
     * @param int|null $stable_id
     * @return bool
     */
    public static function is_busy($stable_id = null) {
        if ($stable_id !== null) {
            return self::is_fetching($stable_id) || self::is_fetching(self::discovery_key($stable_id));
        }
        foreach (self::runs() as $key => $state) {
            if (is_array($state) && isset($state['status']) && $state['status'] === 'fetching'
                && (time() - (int) $state['step_at']) < self::ABANDONED_AFTER
                && self::run_belongs($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * May this run go on asking Rakuten? Only for what the site holds or
     * asked for: a feed entry it refreshes (the key is its stable id), a
     * search pulled into such a feed (<stable id>_d), or a new source
     * that is pending and may still become one. Any other run was left
     * behind - by the other MyFeeds plugin, or by a feed that was set
     * aside or deleted while it ran - and would spend the site's Rakuten
     * allowance on a catalogue no entry reads, or bring in a source
     * nobody chose.
     *
     * @param string|int $key
     * @return bool
     */
    private static function run_belongs($key) {
        $key = (string) $key;
        if (strpos($key, 'p:') === 0) {
            $pending = self::pending();
            return isset($pending[$key]) && self::may_connect();
        }
        if (!preg_match('/^([0-9]+)(?:_d|:d)?$/', $key, $m)) {
            return false;
        }
        return MyFeeds_Source_Feeds::is_api_feed(MyFeeds_Source_Feeds::feed_for_stable_id((int) $m[1]));
    }

    /** May a new Rakuten source come in now (MyFeeds_Source_Rules)? */
    private static function may_connect() {
        return class_exists('MyFeeds_Source_Rules') && MyFeeds_Source_Rules::can_connect('api', 'rakuten');
    }

    /** What a request for a new source the rules turn down is told. */
    private static function taken() {
        $message = class_exists('MyFeeds_Source_Rules') ? (string) MyFeeds_Source_Rules::refusal() : '';
        return new WP_Error('myfeeds_source_taken', $message, array('status' => 409));
    }

    /**
     * Ends a run that may not go on (run_belongs()), before it asks
     * Rakuten anything. Only what the run wrote goes: its part and its id
     * list. A pending new source goes with its run.
     *
     * @param string|int $key
     * @param array      $state
     * @param string     $why   For the log.
     */
    private static function drop_run($key, array $state, $why) {
        $key = (string) $key;
        if (isset($state['file'])) {
            MyFeeds_Source_Snapshot::discard(self::work_path((string) $state['file'], !empty($state['partial'])));
        }
        self::set_run_state($key, null);
        if (strpos($key, 'p:') === 0) {
            self::drop_pending($key);
        }
        self::log('run ' . (isset($state['run']) ? $state['run'] : '-') . ' for ' . (isset($state['name']) ? $state['name'] : $key)
            . ' (' . $key . ') ended before it asked Rakuten again: ' . $why, 'info');
    }

    /**
     * Another request ended this run while it fetched (a delete, a stop,
     * "Change source"): its state is not written back and it asks Rakuten
     * nothing more. The rows it appended since are thrown away with the
     * part, unless a new run stands under the key and writes there.
     *
     * @param string|int $key
     * @param array      $state
     */
    private static function ended_elsewhere($key, array $state) {
        $key  = (string) $key;
        $runs = self::stored_runs();
        if (!isset($runs[$key]) && isset($state['file'])) {
            MyFeeds_Source_Snapshot::discard(self::work_path((string) $state['file'], !empty($state['partial'])));
        }
        self::log('run ' . (isset($state['run']) ? $state['run'] : '-') . ' for ' . (isset($state['name']) ? $state['name'] : $key)
            . ' (' . $key . ') was ended by another request while it fetched; it asks Rakuten nothing more', 'info');
    }

    /**
     * A new source that will not become a feed: its pending record goes,
     * and with it the files it wrote.
     *
     * @param string $token
     */
    private static function drop_pending($token) {
        $pending = self::stored_pending();
        if (!isset($pending[$token])) {
            return;
        }
        $source = $pending[$token];
        unset($pending[$token]);
        self::save_pending($pending);
        if (is_array($source)) {
            self::forget_files_of($source);
        }
    }

    /**
     * A pending source's files: its part, and the snapshot it committed if
     * no entry took that over. One an entry points at - in the feed list
     * or among the feeds set aside - is that feed's and stays.
     *
     * @param array $source A pending record.
     */
    private static function forget_files_of(array $source) {
        $file = isset($source['file']) ? basename((string) $source['file']) : '';
        if ($file === '' || strpos($file, '..') !== false) {
            return;
        }
        $path = MyFeeds_Source_Snapshot::path_for($file);
        if (self::snapshot_in_use($file)) {
            MyFeeds_Source_Snapshot::discard($path);
        } else {
            MyFeeds_Source_Snapshot::delete($path);
        }
    }

    /**
     * @param string $file Snapshot file name.
     * @return bool Whether a feed entry, listed or set aside, reads it.
     */
    private static function snapshot_in_use($file) {
        foreach (array(MyFeeds_Source_Feeds::OPTION_KEY, 'myfeeds_feeds_set_aside') as $option) {
            $list = get_option($option, array());
            foreach (is_array($list) ? $list : array() as $entry) {
                if (is_array($entry) && isset($entry['url'])
                    && basename((string) wp_parse_url((string) $entry['url'], PHP_URL_PATH)) === $file) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * A new source the rules turn down: its run and its pending record go,
     * with the snapshot no entry took over. The log says why.
     *
     * @param string $token
     * @param string $why
     */
    private static function turn_down($token, $why) {
        $state = self::run_state($token);
        $name  = '';
        $pending = self::pending();
        if (isset($pending[$token]['name'])) {
            $name = (string) $pending[$token]['name'];
        }
        if ($state !== null) {
            MyFeeds_Source_Snapshot::discard(self::work_path((string) $state['file'], !empty($state['partial'])));
            self::set_run_state($token, null);
        }
        self::drop_pending($token);
        self::log('new source ' . $name . ' (' . $token . ') not created, its fetch thrown away: ' . $why, 'info');
    }

    // =====================================================================
    // Starting
    // =====================================================================

    /**
     * A new source: validated, recorded as pending, first run started.
     * The feed entry comes later, from complete().
     *
     * @param array $args {network, mid, advertiser_name, name, currency, network_id, scope}
     * @return array{pending:string,state:array}|WP_Error
     */
    public static function start_new(array $args) {
        if (!self::may_connect()) {
            return self::taken();
        }

        if (!MyFeeds_Rakuten_Credentials::is_configured()) {
            return new WP_Error('myfeeds_rakuten_not_configured', __('Rakuten is not connected yet.', 'myfeeds-affiliate-feed-manager'));
        }

        $mid = isset($args['mid']) ? (int) $args['mid'] : 0;
        if ($mid <= 0) {
            return new WP_Error('myfeeds_source_invalid', __('Choose an advertiser first.', 'myfeeds-affiliate-feed-manager'));
        }

        $advertiser = isset($args['advertiser_name']) ? trim(sanitize_text_field((string) $args['advertiser_name'])) : '';
        $name       = isset($args['name']) ? trim(sanitize_text_field((string) $args['name'])) : '';
        if ($name === '') {
            $name = $advertiser !== '' ? $advertiser : 'Rakuten ' . $mid;
        }
        if (MyFeeds_Source_Feeds::name_exists($name)) {
            return new WP_Error('myfeeds_source_duplicate', __('A feed with that name already exists. Choose another name.', 'myfeeds-affiliate-feed-manager'));
        }

        $scope = MyFeeds_Rakuten_Source::normalize_scope(isset($args['scope']) ? $args['scope'] : null);
        $token = 'p:' . substr(md5(uniqid((string) $mid, true)), 0, 12);
        $file  = MyFeeds_Source_Snapshot::new_filename('rakuten', $name);

        $pending = self::pending();
        $pending[$token] = array(
            'token'           => $token,
            'network'         => 'rakuten',
            'mid'             => $mid,
            'advertiser_name' => $advertiser,
            'name'            => $name,
            'currency'        => isset($args['currency']) ? strtoupper(sanitize_text_field((string) $args['currency'])) : '',
            'network_id'      => isset($args['network_id']) ? (int) $args['network_id'] : 0,
            'scope'           => $scope,
            'file'            => $file,
            'created_at'      => time(),
            'committed'       => false,
        );
        self::save_pending($pending);

        $state = self::start_run($token, array(
            'mid'      => $mid,
            'scope'    => $scope,
            'file'     => $file,
            'name'     => $name,
            'strategy' => '',
            'first'    => true,
        ));

        if (is_wp_error($state)) {
            unset($pending[$token]);
            self::save_pending($pending);
            return $state;
        }

        self::log('new source ' . $name . ' (mid ' . $mid . ') started as ' . $token, 'info');

        return array('pending' => $token, 'state' => self::public_state($state));
    }

    /**
     * Refresh an existing feed now.
     *
     * @param int $stable_id
     * @return array|WP_Error
     */
    public static function refresh_feed($stable_id) {
        $feed = MyFeeds_Source_Feeds::feed_for_stable_id($stable_id);
        if ($feed === null || !MyFeeds_Source_Feeds::is_api_feed($feed)) {
            return new WP_Error('myfeeds_source_invalid', __('This feed is not an API source.', 'myfeeds-affiliate-feed-manager'));
        }

        $current = self::run_state($stable_id);
        if ($current !== null && $current['status'] === 'fetching' && (time() - (int) $current['step_at']) < self::ABANDONED_AFTER) {
            return array('state' => self::public_state($current), 'already' => true);
        }

        if (self::is_fetching(self::discovery_key($stable_id))) {
            return new WP_Error('myfeeds_source_busy', __('A search is being pulled into this feed right now. Try again in a minute.', 'myfeeds-affiliate-feed-manager'));
        }

        $state = self::start_run($stable_id, array(
            'mid'      => (int) $feed['api']['mid'],
            'scope'    => MyFeeds_Rakuten_Source::normalize_scope(isset($feed['api']['scope']) ? $feed['api']['scope'] : null),
            'terms'    => MyFeeds_Source_Feeds::discovered($feed),
            'file'     => basename((string) wp_parse_url((string) $feed['url'], PHP_URL_PATH)),
            'name'     => (string) $feed['name'],
            'strategy' => isset($feed['api']['id_strategy']) ? (string) $feed['api']['id_strategy'] : MyFeeds_Rakuten_Rows::STRATEGY_SKU,
            'first'    => false,
        ));

        if (is_wp_error($state)) {
            return $state;
        }

        return array('state' => self::public_state($state), 'already' => false);
    }

    /**
     * Pull one search term into an existing feed, now.
     *
     * A picker search that found little in the snapshot asks Rakuten for
     * the same words; the rows land in the snapshot and are imported, and
     * the term is remembered so the nightly refresh keeps them current.
     * The rows go through the same file, but a discovery has its own
     * part and its own run key, so it never disturbs a refresh's summary.
     *
     * @param int    $stable_id
     * @param string $term
     * @return array{term:string,queued:bool,state:array|null}|WP_Error
     */
    public static function discover($stable_id, $term) {
        if (!MyFeeds_Rakuten_Credentials::is_configured()) {
            return new WP_Error('myfeeds_rakuten_not_configured', __('Rakuten is not connected yet.', 'myfeeds-affiliate-feed-manager'));
        }

        $feed = MyFeeds_Source_Feeds::feed_for_stable_id($stable_id);
        if ($feed === null || !MyFeeds_Source_Feeds::is_api_feed($feed)) {
            return new WP_Error('myfeeds_source_invalid', __('This feed is not an API source.', 'myfeeds-affiliate-feed-manager'));
        }

        $term = MyFeeds_Rakuten_Source::clean_term($term);
        if ($term === '') {
            return new WP_Error('myfeeds_source_invalid', __('Type what you are looking for first.', 'myfeeds-affiliate-feed-manager'));
        }

        $added = MyFeeds_Source_Feeds::add_discovered($stable_id, $term);
        if (is_wp_error($added)) {
            return $added;
        }

        $dkey = self::discovery_key($stable_id);

        // A full refresh is running: it reads the remembered terms when it
        // starts, so this one is picked up by the next refresh instead.
        if (self::is_fetching($stable_id)) {
            return array('term' => $term, 'queued' => true, 'state' => null);
        }

        $current = self::run_state($dkey);
        if ($current !== null && $current['status'] === 'fetching' && (time() - (int) $current['step_at']) < self::ABANDONED_AFTER) {
            return new WP_Error('myfeeds_source_busy', sprintf(
                /* translators: %s: the search term being fetched */
                __('Still pulling "%s" from Rakuten. Try again in a minute.', 'myfeeds-affiliate-feed-manager'),
                isset($current['term']) ? $current['term'] : ''
            ));
        }

        $scope = MyFeeds_Rakuten_Source::normalize_scope(isset($feed['api']['scope']) ? $feed['api']['scope'] : null);

        $state = self::start_run($dkey, array(
            'mid'      => (int) $feed['api']['mid'],
            'scope'    => $scope,
            'queries'  => array(MyFeeds_Rakuten_Source::term_query((int) $feed['api']['mid'], $scope, $term)),
            'file'     => basename((string) wp_parse_url((string) $feed['url'], PHP_URL_PATH)),
            'name'     => (string) $feed['name'] . ': ' . $term,
            'strategy' => isset($feed['api']['id_strategy']) ? (string) $feed['api']['id_strategy'] : MyFeeds_Rakuten_Rows::STRATEGY_SKU,
            'first'    => false,
            'partial'  => true,
            'term'     => $term,
        ));

        if (is_wp_error($state)) {
            return $state;
        }

        self::log('discovery "' . $term . '" for feed ' . $stable_id . ' started', 'info');

        return array('term' => $term, 'queued' => false, 'state' => self::public_state($state));
    }

    /**
     * The nightly pass: every API feed, plus any pending source whose
     * run was abandoned, plus the self-heal for a snapshot that went
     * missing from disk.
     */
    public static function refresh_all() {
        if (!MyFeeds_Rakuten_Credentials::is_configured()) {
            return;
        }

        self::heal_pending();
        self::heal_runs();

        foreach (MyFeeds_Source_Feeds::feeds() as $feed) {
            $sid = isset($feed['stable_id']) ? (int) $feed['stable_id'] : 0;
            if ($sid <= 0) {
                continue;
            }
            $result = self::refresh_feed($sid);
            if (is_wp_error($result)) {
                self::log('nightly refresh of feed ' . $sid . ' not started: ' . $result->get_error_message(), 'error');
            }
        }
    }

    /**
     * Every fetching run that has stopped moving gets its next job again.
     * Cheap enough to run from the dialog's poll and from the nightly
     * cron: one option read, and a job only where none is queued.
     *
     * @return int Runs rescheduled.
     */
    public static function heal_runs() {
        $kicked = 0;

        foreach (self::runs() as $key => $state) {
            if (!is_array($state) || $state['status'] !== 'fetching') {
                continue;
            }

            if (!self::run_belongs($key)) {
                self::drop_run($key, $state, 'nothing on this site is waiting for it');
                continue;
            }

            $idle = time() - (int) $state['step_at'];
            $wait = isset($state['wait']) ? (int) $state['wait'] : 0;
            if ($idle < max(self::STALLED_AFTER, $wait + 30)) {
                continue;
            }

            if (self::job_is_queued($key, $state['run'])) {
                continue;
            }

            $hop = isset($state['hop']) ? (int) $state['hop'] : 0;
            self::schedule_job($key, $state['run'], 5, $hop);
            self::log('run ' . $state['run'] . ' for ' . $state['name'] . ' had stalled for ' . $idle . ' s; next job queued again', 'info');
            $kicked++;
        }

        return $kicked;
    }

    /**
     * Is any job for this run still queued or running?
     */
    private static function job_is_queued($key, $run) {
        if (!function_exists('as_get_scheduled_actions')) {
            return false;
        }

        foreach (array('pending', 'in-progress') as $status) {
            $actions = as_get_scheduled_actions(array(
                'hook'     => self::HOOK,
                'status'   => $status,
                'per_page' => 50,
            ), 'ids');
            if (empty($actions)) {
                continue;
            }
            // Args are not queryable through the API, so the handful of
            // actions is read back and compared.
            foreach ($actions as $action_id) {
                $action = ActionScheduler::store()->fetch_action($action_id);
                if (!$action) {
                    continue;
                }
                $args = $action->get_args();
                $inner = isset($args['args']) && is_array($args['args']) ? $args['args'] : $args;
                if (isset($inner['key'], $inner['run']) && (string) $inner['key'] === (string) $key && (string) $inner['run'] === (string) $run) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Pending sources whose snapshot was committed but whose entry never
     * made it into the option (a write lost to the importer's rewrite),
     * and pending runs nobody has touched for an hour.
     */
    public static function heal_pending() {
        $pending = self::pending();
        if (empty($pending)) {
            return;
        }

        foreach ($pending as $token => $source) {
            if (!empty($source['committed'])) {
                self::take_over($token);
                continue;
            }

            $state = self::run_state($token);

            if (!self::may_connect()) {
                self::turn_down($token, self::taken()->get_error_message());
                continue;
            }

            if ($state === null) {
                // A pending source with no run at all: nothing will ever
                // finish it. Start it over.
                self::start_run($token, array(
                    'mid'      => (int) $source['mid'],
                    'scope'    => MyFeeds_Rakuten_Source::normalize_scope($source['scope']),
                    'file'     => (string) $source['file'],
                    'name'     => (string) $source['name'],
                    'strategy' => '',
                    'first'    => true,
                ));
                continue;
            }

            if ($state['status'] === 'fetching' && (time() - (int) $state['step_at']) >= self::ABANDONED_AFTER) {
                self::schedule_job($token, $state['run'], 5, (int) $state['hop'] + 1);
            }
        }
    }

    /**
     * A committed snapshot whose entry was not taken over: complete()'s
     * request ended between the commit and the takeover, or lost its entry
     * to the importer's rewrite. Asked of what is stored now, since that
     * request may still be at it or have finished meanwhile: what it took
     * over, or what was deleted after, must not come back as a second entry.
     *
     * @param string $token
     */
    private static function take_over($token) {
        $pending = self::stored_pending();
        if (!isset($pending[$token]) || empty($pending[$token]['committed'])) {
            return;
        }
        $runs  = self::stored_runs();
        $state = isset($runs[$token]) && is_array($runs[$token]) ? $runs[$token] : array();
        // The fetch's last step wrote this moments ago: the request that
        // committed the snapshot is still writing the entry and takes it over.
        if (isset($state['status'], $state['step_at']) && $state['status'] === 'fetching'
            && (time() - (int) $state['step_at']) < self::STALLED_AFTER) {
            return;
        }

        $created = MyFeeds_Source_Feeds::create_entry($pending[$token], $state);
        if (is_wp_error($created) && $created->get_error_code() === 'myfeeds_source_taken') {
            self::turn_down($token, $created->get_error_message());
        } elseif (!is_wp_error($created)) {
            self::adopt_entry($token, $created['key'], $created['stable_id'], $state);
        }
    }

    /**
     * @param string|int $key
     * @param array      $source {mid, scope, file, name, strategy, first}
     * @return array|WP_Error The new run state.
     */
    public static function start_run($key, array $source) {
        $partial = !empty($source['partial']);
        $path    = self::work_path((string) $source['file'], $partial);

        $fh = MyFeeds_Source_Snapshot::open_part($path, true);
        if (is_wp_error($fh)) {
            return $fh;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the part is a stream the snapshot class opened (MyFeeds_Source_Snapshot).
        fclose($fh);

        $queue = isset($source['queries']) && is_array($source['queries'])
            ? array_values($source['queries'])
            : MyFeeds_Rakuten_Source::scope_queries(
                (int) $source['mid'],
                $source['scope'],
                isset($source['terms']) && is_array($source['terms']) ? $source['terms'] : array()
            );
        $planned = array();
        foreach ($queue as $query) {
            $planned[MyFeeds_Rakuten_Source::query_key($query)] = 1;
        }

        $state = array(
            'run'        => substr(md5(uniqid((string) $key, true)), 0, 10),
            'key'        => (string) $key,
            'status'     => 'fetching',
            'name'       => (string) $source['name'],
            'mid'        => (int) $source['mid'],
            'file'       => (string) $source['file'],
            'first'      => !empty($source['first']),
            'partial'    => $partial,
            'term'       => isset($source['term']) ? (string) $source['term'] : '',
            'strategy'   => (string) $source['strategy'],
            'queue'      => $queue,
            'base_count' => count($queue),
            'planned'    => $planned,
            'index'      => 0,
            'page'       => 1,
            'hop'        => 0,
            'categories' => array(),
            'total'      => 0,
            'fetched'    => 0,
            'skipped'    => 0,
            'calls'      => 0,
            'errors'     => 0,
            'wait'       => 0,
            'last_error' => '',
            'started_at' => time(),
            'step_at'    => time(),
        );

        self::set_run_state($key, $state, true);
        self::schedule_job($key, $state['run'], 5, 0);

        return $state;
    }

    /**
     * Where a run writes. A discovery works next to the snapshot, not on
     * it, so its part cannot collide with a refresh's.
     */
    private static function work_path($file, $partial) {
        $path = MyFeeds_Source_Snapshot::path_for((string) $file);
        return $partial ? $path . '.discover' : $path;
    }

    // =====================================================================
    // Scheduling
    // =====================================================================

    /**
     * One deterministic job per (key, run, hop): Action Scheduler where it
     * exists, WP-Cron otherwise. Deduped by our own lookup, like the
     * importer, because AS's unique flag is unreliable in 3.9.
     *
     * The hop counter is what keeps the chain alive: the next job is
     * scheduled from inside the running one, and as_has_scheduled_action()
     * counts an in-progress action as scheduled. With identical arguments
     * the follow-up looked like a duplicate and was never queued - on the
     * first real run the fetch only moved while the dialog's status poll
     * was driving it.
     */
    public static function schedule_job($key, $run, $delay, $hop = 0) {
        $args  = array('args' => array('key' => (string) $key, 'run' => (string) $run, 'hop' => (int) $hop));
        $delay = max(1, (int) $delay);

        self::$scheduled[] = array('key' => (string) $key, 'run' => (string) $run, 'hop' => (int) $hop, 'delay' => $delay);

        if (function_exists('as_schedule_single_action')) {
            if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::HOOK, $args, self::GROUP)) {
                return;
            }
            as_schedule_single_action(time() + $delay, self::HOOK, $args, self::GROUP, false);
            return;
        }

        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + $delay, self::HOOK, array($args));
        }
    }

    /**
     * Action Scheduler entry point.
     *
     * @param array $args {key, run}
     */
    public static function run_job($args) {
        $args = is_array($args) ? $args : array();
        $key  = isset($args['key']) ? (string) $args['key'] : '';
        $run  = isset($args['run']) ? (string) $args['run'] : '';

        $state = self::run_state($key);
        if ($state === null || $state['run'] !== $run || $state['status'] !== 'fetching') {
            // A job from a run that was cancelled or replaced.
            return;
        }

        if (!self::acquire_lock()) {
            self::schedule_job($key, $run, 15, isset($args['hop']) ? (int) $args['hop'] : 0);
            return;
        }

        try {
            self::step($key, self::budget());
        } finally {
            self::release_lock();
        }
    }

    /**
     * Run from the dialog's status poll when Action Scheduler is not
     * moving on its own.
     *
     * @param string|int $key
     * @return array|null Public state after the step.
     */
    public static function inline_step($key) {
        $state = self::run_state($key);
        if ($state === null || $state['status'] !== 'fetching') {
            return $state === null ? null : self::public_state($state);
        }

        if ((time() - (int) $state['step_at']) < self::STALE_STEP) {
            return self::public_state($state);
        }
        if ((int) $state['wait'] > 0 && (time() - (int) $state['step_at']) < (int) $state['wait']) {
            return self::public_state($state);
        }

        if (!self::acquire_lock()) {
            return self::public_state($state);
        }

        try {
            $state = self::step($key, self::BUDGET_MIN);
        } finally {
            self::release_lock();
        }

        return $state === null ? null : self::public_state($state);
    }

    /**
     * Seconds of API work a job may do in this context.
     */
    public static function budget() {
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return self::BUDGET_CRON;
        }

        $limit = (int) ini_get('max_execution_time');
        if ($limit <= 0) {
            $limit = 30;
        }

        return max(self::BUDGET_MIN, min(self::BUDGET_WEB_MAX, (int) floor($limit * 0.4)));
    }

    // =====================================================================
    // The work
    // =====================================================================

    /**
     * Work through pages until the budget is spent, then decide what
     * happens next: another job, a wait, completion or failure.
     *
     * @param string|int $key
     * @param int        $budget Seconds.
     * @return array|null The run state afterwards.
     */
    public static function step($key, $budget) {
        // Action Scheduler runs several jobs in one request; what the
        // others did since it began counts here.
        self::forget_cached();
        $state = self::run_state($key);
        if ($state === null || $state['status'] !== 'fetching') {
            return $state;
        }

        if (!self::run_belongs($key)) {
            self::drop_run($key, $state, 'nothing on this site is waiting for it');
            return null;
        }

        $path     = self::work_path($state['file'], !empty($state['partial']));
        $deadline = microtime(true) + max(1, (int) $budget);
        $state['wait'] = 0;
        if (!isset($state['hop'])) {
            $state['hop'] = 0;
        }

        // A run started before the id set moved into its sidecar file.
        if (!empty($state['seen']) && is_array($state['seen'])) {
            MyFeeds_Source_Snapshot::append_ids($path, array_keys($state['seen']));
            $state['seen'] = array();
        }

        while (microtime(true) < $deadline) {
            if ($state['index'] >= count($state['queue'])) {
                break;
            }
            if ($state['calls'] >= self::MAX_CALLS) {
                self::log('run ' . $state['run'] . ' stopped at the call ceiling after ' . $state['fetched'] . ' rows', 'info');
                $state['index'] = count($state['queue']);
                break;
            }

            $query  = $state['queue'][$state['index']];
            $params = $query;
            $params['max']        = MyFeeds_Rakuten_Client::PAGE_SIZE;
            $params['pagenumber'] = $state['page'];

            $strategy = $state['strategy'] !== '' ? $state['strategy'] : MyFeeds_Rakuten_Rows::STRATEGY_SKU;
            $result   = MyFeeds_Rakuten_Client::search_products($params, $strategy);
            $state['calls']++;

            if (is_wp_error($result)) {
                $handled = self::handle_error($state, $result);
                if (!self::set_run_state($key, $state)) {
                    self::ended_elsewhere($key, $state);
                    return null;
                }
                if ($handled === 'failed') {
                    self::fail($key, $state, $result->get_error_message());
                    return self::run_state($key);
                }
                // A wait: leave the loop, reschedule after it.
                break;
            }

            $state['errors'] = 0;

            // First page of a query: its size, and for the very first
            // page of a new source the id strategy.
            if ($state['page'] === 1) {
                if ($state['index'] < $state['base_count']) {
                    $state['total'] += (int) $result['total_matches'];
                }
                if ($state['strategy'] === '' && !empty($result['items'])) {
                    $measure = MyFeeds_Rakuten_Rows::measure_strategy($result['items']);
                    $state['strategy'] = $measure['strategy'];
                    if ($measure['strategy'] !== MyFeeds_Rakuten_Rows::STRATEGY_SKU) {
                        foreach ($result['items'] as $i => $row) {
                            $result['items'][$i]['id'] = MyFeeds_Rakuten_Rows::id_for($row['merchant_sku'], $row['linkid'], $measure['strategy']);
                        }
                        $result['items'] = array_values(array_filter($result['items'], static function ($row) {
                            return $row['id'] !== '';
                        }));
                    }
                    self::log(sprintf(
                        'run %s id strategy %s (duplicate skus %.1f%%, empty %.1f%%)',
                        $state['run'],
                        $measure['strategy'],
                        $measure['duplicate_share'] * 100,
                        $measure['empty_share'] * 100
                    ), 'info');
                }
            }

            $rows = $result['items'];
            if (!empty($rows)) {
                $fh = MyFeeds_Source_Snapshot::open_part($path, false);
                if (is_wp_error($fh)) {
                    if (!self::set_run_state($key, $state)) {
                        self::ended_elsewhere($key, $state);
                        return null;
                    }
                    self::fail($key, $state, $fh->get_error_message());
                    return self::run_state($key);
                }
                MyFeeds_Source_Snapshot::append_rows($fh, $rows);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the part is a stream the snapshot class opened (MyFeeds_Source_Snapshot).
                fclose($fh);

                $ids = array();
                foreach ($rows as $row) {
                    $ids[] = $row['id'];
                    if ($row['primarycategory'] !== '' && count($state['categories']) < self::MAX_CATEGORIES) {
                        $state['categories'][$row['primarycategory']] = 1;
                    }
                }
                MyFeeds_Source_Snapshot::append_ids($path, $ids);
            }

            $state['fetched'] += count($rows);
            $state['skipped'] += (int) $result['skipped'];

            $pages = MyFeeds_Rakuten_Source::pages_to_fetch((int) $result['total_pages'], MyFeeds_Rakuten_Client::PAGE_SIZE);

            if ($state['page'] >= $pages || empty($rows)) {
                if ((int) $result['total_matches'] > MyFeeds_Rakuten_Client::RESULT_CAP) {
                    $added = 0;
                    foreach (MyFeeds_Rakuten_Source::expansions($query, array_keys($state['categories'])) as $view) {
                        $k = MyFeeds_Rakuten_Source::query_key($view);
                        if (isset($state['planned'][$k])) {
                            continue;
                        }
                        $state['planned'][$k] = 1;
                        $state['queue'][]     = $view;
                        $added++;
                    }
                    if ($added > 0) {
                        self::log('run ' . $state['run'] . ' query above the cap (' . (int) $result['total_matches'] . ' matches); ' . $added . ' more views planned', 'info');
                    }
                }
                $state['index']++;
                $state['page'] = 1;
            } else {
                $state['page']++;
            }

            $state['step_at'] = time();
            if (!self::set_run_state($key, $state)) {
                // Not one more request for a run another request ended.
                self::ended_elsewhere($key, $state);
                return null;
            }
        }

        $state['step_at'] = time();

        if ($state['index'] >= count($state['queue'])) {
            if (!self::set_run_state($key, $state)) {
                self::ended_elsewhere($key, $state);
                return null;
            }
            self::complete($key, $state);
            return self::run_state($key);
        }

        $state['hop'] = (int) $state['hop'] + 1;
        if (!self::set_run_state($key, $state)) {
            self::ended_elsewhere($key, $state);
            return null;
        }
        self::schedule_job($key, $state['run'], $state['wait'] > 0 ? $state['wait'] : 5, $state['hop']);

        return $state;
    }

    /**
     * @return string 'wait' or 'failed'
     */
    private static function handle_error(array &$state, WP_Error $error) {
        $code = (string) $error->get_error_code();
        $state['last_error'] = $error->get_error_message();

        if ($code === 'myfeeds_rakuten_throttled') {
            $data = $error->get_error_data();
            $state['wait'] = is_array($data) && isset($data['retry_after']) ? max(5, (int) $data['retry_after']) : 60;
            return 'wait';
        }

        if (in_array($code, array('myfeeds_rakuten_unauthorized', 'myfeeds_rakuten_not_configured', 'myfeeds_rakuten_blocked'), true)) {
            return 'failed';
        }

        $state['errors']++;
        if ($state['errors'] >= self::MAX_ERRORS) {
            return 'failed';
        }

        $state['wait'] = 60;
        return 'wait';
    }

    /**
     * The run is over and could not produce a snapshot. The previous one
     * stays; the part is thrown away.
     */
    private static function fail($key, array $state, $message) {
        MyFeeds_Source_Snapshot::discard(self::work_path($state['file'], !empty($state['partial'])));

        $state['status']     = 'failed';
        $state['last_error'] = (string) $message;
        $state['ended_at']   = time();
        $state = self::shrink($state);

        self::set_run_state($key, $state);
        self::log('run ' . $state['run'] . ' for ' . $state['name'] . ' failed: ' . $message, 'error');
    }

    /**
     * Carry over, commit, create the entry on a first run, then import.
     */
    private static function complete($key, array $state) {
        // The feed went (deleted, set aside) or the new source was given up
        // while the last pages came in: nothing becomes a snapshot. Asked
        // of what is stored now, not of what this request read when it
        // began.
        self::forget_cached();
        $is_new = strpos((string) $key, 'p:') === 0;
        if ($is_new ? !array_key_exists((string) $key, self::pending()) : !self::run_belongs($key)) {
            self::drop_run($key, $state, 'what it was fetching for is gone');
            return;
        }

        if (!empty($state['partial'])) {
            self::complete_discovery($key, $state);
            return;
        }

        $path = MyFeeds_Source_Snapshot::path_for($state['file']);
        $seen = MyFeeds_Source_Snapshot::read_ids($path);

        if ($state['fetched'] === 0 || empty($seen)) {
            self::fail($key, $state, __('Rakuten returned no products for this scope, so nothing was changed.', 'myfeeds-affiliate-feed-manager'));
            return;
        }

        $carried = array('carried' => 0, 'dropped' => 0);
        if (!$state['first'] && file_exists($path)) {
            $fh = MyFeeds_Source_Snapshot::open_part($path, false);
            if (!is_wp_error($fh)) {
                $carried = MyFeeds_Source_Snapshot::carry_over($path, $fh, $seen);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the part is a stream the snapshot class opened (MyFeeds_Source_Snapshot).
                fclose($fh);
            }
        }

        $committed = MyFeeds_Source_Snapshot::commit($path);
        if (is_wp_error($committed)) {
            self::fail($key, $state, $committed->get_error_message());
            return;
        }

        $seen_count = count($seen);
        unset($seen);

        $state['status']     = 'done';
        $state['ended_at']   = time();
        $state['fetched_at'] = time();
        $state['products']   = $seen_count + (int) $carried['carried'];
        $state['seen_count'] = $seen_count;
        $state['coverage']   = MyFeeds_Rakuten_Source::coverage($seen_count, $state['total']);
        $state['carried']    = (int) $carried['carried'];
        $state['dropped']    = (int) $carried['dropped'];
        $state['last_error'] = '';
        $state = self::shrink($state);

        self::log(sprintf(
            'run %s for %s done: %d products (%d fetched, %d carried, %d dropped, %d skipped), %d of %d matches reached, %d calls in %d s',
            $state['run'],
            $state['name'],
            $state['products'],
            $seen_count,
            $state['carried'],
            $state['dropped'],
            $state['skipped'],
            $seen_count,
            $state['total'],
            $state['calls'],
            time() - (int) $state['started_at']
        ), 'info');

        // Carrying a large snapshot over takes seconds: the entry to
        // import into is looked up in the list as it is stored now.
        self::forget_cached();
        $feed_key  = null;
        $stable_id = 0;

        if (strpos((string) $key, 'p:') === 0) {
            $pending = self::pending();
            if (!isset($pending[$key])) {
                self::set_run_state($key, $state);
                self::log('run ' . $state['run'] . ' completed for a pending source that no longer exists', 'error');
                return;
            }

            $pending[$key]['committed'] = true;
            self::save_pending($pending);

            $created = MyFeeds_Source_Feeds::create_entry($pending[$key], $state);
            if (is_wp_error($created) && $created->get_error_code() === 'myfeeds_source_taken') {
                self::turn_down($key, $created->get_error_message());
                return;
            }
            if (is_wp_error($created)) {
                self::set_run_state($key, $state);
                self::log('feed entry for ' . $state['name'] . ' could not be created: ' . $created->get_error_message(), 'error');
                return;
            }

            $feed_key  = $created['key'];
            $stable_id = $created['stable_id'];
            self::adopt_entry($key, $feed_key, $stable_id, $state);
        } else {
            $stable_id = (int) $key;
            $feed_key  = MyFeeds_Source_Feeds::key_for_stable_id($stable_id);
            self::set_run_state($key, $state);
        }

        if ($feed_key === null) {
            self::log('run ' . $state['run'] . ' done but feed ' . $stable_id . ' has no entry to import into', 'error');
            return;
        }

        self::trigger_import($feed_key, $stable_id, 0);
    }

    /**
     * A discovery ends by appending its rows to the snapshot. No rename,
     * no carry-over: the snapshot stays complete, it only grows. The
     * next full refresh rebuilds it with the term in its queue.
     */
    private static function complete_discovery($key, array $state) {
        $snapshot = MyFeeds_Source_Snapshot::path_for($state['file']);
        $work     = self::work_path($state['file'], true);
        $seen     = MyFeeds_Source_Snapshot::read_ids($work);

        if ($state['fetched'] === 0 || empty($seen)) {
            self::fail($key, $state, sprintf(
                /* translators: %s: search term */
                __('Rakuten has nothing for "%s" from this advertiser.', 'myfeeds-affiliate-feed-manager'),
                $state['term']
            ));
            return;
        }

        $appended = MyFeeds_Source_Snapshot::append_part_to($work, $snapshot);
        if (is_wp_error($appended)) {
            self::fail($key, $state, $appended->get_error_message());
            return;
        }

        $stable_id = (int) $key;
        $state['status']     = 'done';
        $state['ended_at']   = time();
        $state['fetched_at'] = time();
        $state['products']   = count($seen);
        $state['seen_count'] = count($seen);
        $state['coverage']   = MyFeeds_Rakuten_Source::coverage(count($seen), $state['total']);
        $state['last_error'] = '';
        $state = self::shrink($state);
        self::set_run_state($key, $state);

        MyFeeds_Source_Feeds::add_discovered($stable_id, $state['term'], $state['total']);

        self::log(sprintf(
            'discovery "%s" for feed %d done: %d products appended (%d of %d matches), %d calls',
            $state['term'],
            $stable_id,
            $state['products'],
            $state['products'],
            $state['total'],
            $state['calls']
        ), 'info');

        $feed_key = MyFeeds_Source_Feeds::key_for_stable_id($stable_id);
        if ($feed_key !== null) {
            self::trigger_import($feed_key, $stable_id, 0);
        }
    }

    /**
     * Move a pending source's run under its new stable id and forget the
     * pending record. A run no longer stored under the token was moved by
     * another request already; the state it wrote under the stable id stays.
     */
    private static function adopt_entry($token, $feed_key, $stable_id, array $state) {
        $runs = self::stored_runs();
        if (array_key_exists((string) $token, $runs)) {
            $state['key'] = (string) $stable_id;
            unset($runs[(string) $token]);
            $runs[(string) $stable_id] = $state;
            self::save_runs($runs);
        }

        $pending = self::stored_pending();
        unset($pending[(string) $token]);
        self::save_pending($pending);

        self::log('feed ' . $stable_id . ' (' . (isset($state['name']) ? $state['name'] : $token) . ') created from ' . $token, 'info');
    }

    /**
     * The completed snapshot goes through the ordinary single-feed
     * import. If a site-wide import is running the importer refuses;
     * then we come back in a quarter of an hour, a few times.
     */
    public static function trigger_import($feed_key, $stable_id, $attempt) {
        $ok = false;

        if (self::$import_trigger !== null) {
            $ok = (bool) call_user_func(self::$import_trigger, $feed_key, $stable_id);
        } elseif (class_exists('MyFeeds_Batch_Importer')) {
            $importer = new MyFeeds_Batch_Importer();
            $ok = (bool) $importer->schedule_new_feed_import($feed_key, true);
        }

        if ($ok) {
            self::log('import started for feed ' . $stable_id, 'info');
            return true;
        }

        if ($attempt >= self::IMPORT_RETRIES) {
            self::log('import for feed ' . $stable_id . ' could not be started after ' . $attempt . ' retries; the nightly sync will pick the snapshot up', 'error');
            return false;
        }

        $args = array('args' => array('sid' => (int) $stable_id, 'attempt' => (int) $attempt + 1));
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + self::IMPORT_RETRY_DELAY, self::RETRY_HOOK, $args, self::GROUP, false);
        } elseif (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + self::IMPORT_RETRY_DELAY, self::RETRY_HOOK, array($args));
        }
        self::log('import for feed ' . $stable_id . ' deferred (another import is running), retry ' . ($attempt + 1), 'info');

        return false;
    }

    public static function retry_import($args) {
        $args    = is_array($args) ? $args : array();
        $sid     = isset($args['sid']) ? (int) $args['sid'] : 0;
        $attempt = isset($args['attempt']) ? (int) $args['attempt'] : 0;

        $feed_key = MyFeeds_Source_Feeds::key_for_stable_id($sid);
        if ($feed_key === null) {
            return;
        }

        self::trigger_import($feed_key, $sid, $attempt);
    }

    // =====================================================================
    // Reporting
    // =====================================================================

    /**
     * What the feeds table and the dialog show for a feed.
     *
     * @param array $feed
     * @return array
     */
    public static function summary_for_feed(array $feed) {
        $sid   = isset($feed['stable_id']) ? (int) $feed['stable_id'] : 0;
        $state = $sid > 0 ? self::run_state($sid) : null;
        if ($state === null) {
            return array();
        }

        return self::public_state($state);
    }

    /**
     * The run state without its working set, for screens and REST.
     *
     * @param array $state
     * @return array
     */
    public static function public_state(array $state) {
        $fetching = $state['status'] === 'fetching';
        $stale    = !$fetching
            && !empty($state['fetched_at'])
            && (time() - (int) $state['fetched_at']) > MyFeeds_Source_Feeds::STALE_DAYS * DAY_IN_SECONDS;

        $kind = 'ok';
        if ($fetching) {
            $kind = 'info';
        } elseif ($state['status'] === 'failed' || $stale) {
            $kind = 'error';
        } elseif (isset($state['coverage']) && $state['coverage'] < 0.999) {
            $kind = 'warn';
        }

        return array(
            'key'        => (string) $state['key'],
            'run'        => (string) $state['run'],
            'name'       => (string) $state['name'],
            'status'     => (string) $state['status'],
            'fetching'   => $fetching,
            'kind'       => $kind,
            'fetched'    => (int) $state['fetched'],
            'seen'       => isset($state['seen_count']) ? (int) $state['seen_count'] : 0,
            'products'   => isset($state['products']) ? (int) $state['products'] : null,
            'total'      => (int) $state['total'],
            'coverage'   => isset($state['coverage']) ? (float) $state['coverage'] : null,
            'queries'    => is_array($state['queue']) ? count($state['queue']) : (int) $state['queue'],
            'query'      => (int) $state['index'] + 1,
            'page'       => (int) $state['page'],
            'hop'        => isset($state['hop']) ? (int) $state['hop'] : 0,
            'calls'      => (int) $state['calls'],
            'strategy'   => (string) $state['strategy'],
            'wait'       => (int) $state['wait'],
            'error'      => (string) $state['last_error'],
            'started_at' => (int) $state['started_at'],
            'step_at'    => (int) $state['step_at'],
            'fetched_at' => isset($state['fetched_at']) ? (int) $state['fetched_at'] : 0,
            'stale'      => $stale,
            'carried'    => isset($state['carried']) ? (int) $state['carried'] : 0,
            'dropped'    => isset($state['dropped']) ? (int) $state['dropped'] : 0,
            'partial'    => !empty($state['partial']),
            'term'       => isset($state['term']) ? (string) $state['term'] : '',
        );
    }

    /**
     * Everything the feeds page needs to draw progress: pending sources
     * and every run.
     */
    public static function overview() {
        $out = array('pending' => array(), 'runs' => array());

        foreach (self::pending() as $token => $source) {
            $state = self::run_state($token);
            $out['pending'][] = array(
                'token'  => $token,
                'name'   => (string) $source['name'],
                'mid'    => (int) $source['mid'],
                'state'  => $state === null ? null : self::public_state($state),
            );
        }

        foreach (self::runs() as $key => $state) {
            if (strpos((string) $key, 'p:') === 0) {
                continue;
            }
            $out['runs'][(string) $key] = self::public_state($state);
        }

        return $out;
    }

    /**
     * Drop the working set once a run has ended; the option should not
     * carry fifty thousand ids forever.
     */
    private static function shrink(array $state) {
        unset($state['seen']);
        $state['planned'] = array();
        $state['queue']   = is_array($state['queue']) ? count($state['queue']) : (int) $state['queue'];
        return $state;
    }

    // =====================================================================
    // Lock and log
    // =====================================================================

    private static function acquire_lock() {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return true;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock is no data: nothing to cache, no API for it.
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::LOCK));
        return $got === null || (int) $got === 1;
    }

    private static function release_lock() {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'query') || !method_exists($wpdb, 'prepare')) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock is no data: nothing to cache, no API for it.
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK));
    }

    private static function log($message, $level = 'info') {
        if (function_exists('myfeeds_log')) {
            myfeeds_log('Source[rakuten] ' . $message, $level);
        }
    }
}
