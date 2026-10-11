<?php
/**
 * MyFeeds — learning how an unknown image CDN asks for a smaller copy.
 *
 * myfeeds-image-helpers.php seeds the grammars of the platforms we can
 * identify from the URL alone: Salesforce Commerce Cloud, Shopify,
 * Cloudinary, Scene7, imgix, BigCommerce, AWIN productserve. That covers
 * a lot of feeds and costs nothing.
 *
 * It does not cover the rest, and the rest is where the damage is. The
 * feed that started this (Jack & Jones, 2026-09-23) sits on a host whose
 * URL says nothing at all — images.jackjones.com/<ids>/<file>.jpg — and
 * shipped an average of 2.02 MB per product, with 3,519 PNGs up to
 * 28.9 MB. Its CDN answers `?width=400` with 24 KB. There was no way to
 * know that from the URL, and no reason a plugin should need a code
 * change to find out.
 *
 * So it asks. Once per host, in the background, for the price of a few
 * bytes: a ranged request returns the full size of a resource in its
 * Content-Range header while transferring one byte, and a transforming
 * CDN reports the size of the TRANSFORMED image. Measuring a 16.7 MB PNG
 * therefore costs one byte, and measuring what `?width=400` would give
 * back costs one more.
 *
 * The verdict is deliberately hard to earn. A grammar is accepted only
 * when width=400 comes back smaller than width=800 (proof the parameter
 * is read, not ignored) AND width=800 is at most 60 % of the original
 * (proof it is worth using). Anything else is remembered as "no", so the
 * host is not asked again for a month.
 *
 * A host that gives no size at all - it is down, it answers a server
 * with 403 because a server is not a browser - gets no verdict. Nothing
 * is known about it, so nothing is stored under its name and the next
 * twelve-hour window asks again. Only the time of its turn is kept, so
 * that the hosts behind it are asked before it is asked a second time.
 *
 * Nothing here is required for the plugin to work. Every failure mode —
 * no outbound HTTP, a host that ignores Range, a CDN with no resizer —
 * ends in the same place: the URL is used exactly as the merchant wrote
 * it, which is what happened before this file existed.
 *
 * The addresses asked here are written by whoever writes the feed, not
 * by the person who runs the site. So every request goes through the
 * guarded door in myfeeds-http.php, which refuses an address on this
 * machine or its private network - on the first hop and on every
 * redirect after it. A host refused that way is not asked, and is
 * rendered like any host nobody has measured.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Image_CDN_Probe {

    /** Background hook. */
    const HOOK = 'myfeeds_image_cdn_probe';

    /** Action Scheduler group, shared with the rest of the plugin. */
    const GROUP = 'myfeeds';

    /** Key inside the recipes option that holds the last run time. */
    const CHECKED_KEY = '_checked_at';

    /**
     * Key inside the recipes option that holds, per waiting host, when
     * it was last asked without giving a size. Not a host and not a
     * verdict: no reader of the map looks a host up under this key.
     */
    const TRIED_KEY = '_tried_at';

    /** Do not sample the products table more often than this. */
    const SAMPLE_INTERVAL = 43200;

    /** Re-ask a host that said no after this long. */
    const TTL_NONE = 2592000;

    /** Re-verify a working grammar after this long. */
    const TTL_FOUND = 15552000;

    /** Hosts per run. Keeps one background job short. */
    const HOSTS_PER_RUN = 3;

    /** Sample URLs measured per host before deciding. */
    const SAMPLES_PER_HOST = 3;

    /** Below this, a host has nothing worth shrinking. */
    const MIN_INTERESTING = 122880;

    /** A sized copy must be at most this share of the original. */
    const MAX_SHARE = 0.6;

    /**
     * Replies of any kind, error codes included, since this request
     * began. run() reads it to tell hosts that say no from a server
     * that reaches nobody.
     *
     * @var int
     */
    private static $replies = 0;

    /**
     * Register the hook and the once-per-session scheduling check.
     */
    public static function init() {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action('admin_init', array(__CLASS__, 'maybe_schedule'));
    }

    /**
     * Queue a run when at least one host in the catalogue has no fresh
     * verdict. Cheap on every call but the first of a twelve-hour
     * window: one option read, no query.
     */
    public static function maybe_schedule() {
        $recipes = self::recipes();
        $checked = isset($recipes[self::CHECKED_KEY]) ? (int) $recipes[self::CHECKED_KEY] : 0;
        if ($checked > 0 && (time() - $checked) < self::SAMPLE_INTERVAL) {
            return;
        }

        // Stamp first, work second. A site whose outbound requests are
        // blocked must not re-sample on every admin page load.
        $recipes[self::CHECKED_KEY] = time();
        self::save($recipes);

        if (self::pending_hosts() === array()) {
            return;
        }
        self::schedule(30);
    }

    /**
     * Put a run on the queue, Action Scheduler if present, WP-Cron
     * otherwise — the same ladder the variant-key backfill climbs.
     *
     * @param int $delay Seconds from now.
     */
    public static function schedule($delay = 30) {
        if (function_exists('as_schedule_single_action')) {
            if (self::run_is_waiting()) {
                return;
            }
            as_schedule_single_action(time() + max(1, (int) $delay), self::HOOK, array(), self::GROUP, false);
            return;
        }
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + max(1, (int) $delay), self::HOOK);
        }
    }

    /**
     * Is a run already waiting in Action Scheduler's queue?
     *
     * Waiting, not running. run() asks for its follow-up from inside the
     * action it follows, and as_has_scheduled_action() answers yes for
     * an action that is in progress: the follow-up looked like a
     * duplicate of the run that was asking for it and was never queued.
     * A run did not come back for the rest; the rest waited for the
     * next admin visit half a day later. (The source fetcher met the
     * same thing on its first real run.)
     *
     * @return bool
     */
    private static function run_is_waiting() {
        if (!function_exists('as_get_scheduled_actions')) {
            return false;
        }
        $waiting = as_get_scheduled_actions(array(
            'hook'     => self::HOOK,
            'group'    => self::GROUP,
            'status'   => 'pending',
            'per_page' => 1,
        ), 'ids');
        return !empty($waiting);
    }

    /**
     * Ask up to HOSTS_PER_RUN hosts, store what they said, and come
     * back for the rest.
     *
     * @return int Hosts decided in this run.
     */
    public static function run() {
        $pending = self::pending_hosts();
        if ($pending === array()) {
            return 0;
        }

        $recipes = self::recipes();
        $tried   = self::tried_at($recipes, $pending);
        $due     = self::due_hosts($pending, $tried);
        $replies = self::$replies;
        $done    = 0;

        foreach (array_slice($due, 0, self::HOSTS_PER_RUN, true) as $host => $urls) {
            $verdict = self::probe($urls);
            if ($verdict === null) {
                // Could not measure at all (no outbound HTTP, host down,
                // Range unsupported). Say nothing rather than something
                // wrong; the next window asks again. What is kept is
                // that the host had its turn - without it the same
                // hosts are first in line in every run, and three of
                // them keep everybody behind them from ever being asked.
                $tried[$host] = time();
                continue;
            }
            if (isset($verdict['reason']) && $verdict['reason'] === 'blocked' && function_exists('myfeeds_log')) {
                // Once per host and month, here rather than at the door:
                // nothing is broken, and the door's own line is about
                // feed URLs.
                myfeeds_log(
                    'Image probe: ' . $host . ' was not asked how big its pictures are, because the address '
                    . 'leads inside this server or its private network. Its pictures are shown as before.',
                    'info'
                );
            }
            $recipes[$host] = $verdict;
            unset($tried[$host]);
            $done++;
        }

        unset($recipes[self::TRIED_KEY]);
        if ($tried !== array()) {
            $recipes[self::TRIED_KEY] = $tried;
        }
        $recipes[self::CHECKED_KEY] = time();
        $kept = self::save($recipes);

        // Come back while hosts are left that had no turn in this
        // window - also after a run that settled nobody, as long as
        // somebody replied: a 403 is a host saying no, and the hosts
        // behind it may say something else. A run in which nobody
        // replied at all says more about this server than about the
        // hosts; walking the whole queue would cost a timeout per
        // request, twice a day, so that stays at one run per window.
        //
        // And only when the note of this run was kept. It is what makes
        // the next run take other hosts; where the option cannot be
        // written, coming back would mean asking the same three hosts
        // every two minutes for good.
        $left = count($due) - self::HOSTS_PER_RUN;
        if ($kept && $left > 0 && ($done > 0 || self::$replies > $replies)) {
            self::schedule(120);
        }
        return $done;
    }

    /**
     * When each waiting host last had its turn without giving a size.
     *
     * Kept only for hosts that are still waiting: one that got its
     * verdict or left the catalogue is dropped, so the list cannot
     * outgrow the queue. It rides in the autoloaded option.
     *
     * @param array $recipes The stored map.
     * @param array $pending host => sample URLs, from pending_hosts().
     * @return array<string,int> host => unix time
     */
    private static function tried_at($recipes, $pending) {
        $stored = (isset($recipes[self::TRIED_KEY]) && is_array($recipes[self::TRIED_KEY]))
            ? $recipes[self::TRIED_KEY]
            : array();
        return array_map('intval', array_intersect_key($stored, $pending));
    }

    /**
     * The waiting hosts that are owed a question now, whoever has
     * waited longest first.
     *
     * A host asked inside the current window is left out: it had its
     * turn, and "ask again" means the next window, not the next run.
     * The order is what keeps the queue moving when runs are far
     * apart. On a site with little traffic half a day can pass between
     * two runs, every earlier turn is due again by then, and taken in
     * catalogue order the same three hosts would be asked for good.
     * A host nobody has asked yet goes before all of them.
     *
     * @param array $pending host => sample URLs, in catalogue order.
     * @param array $tried   host => unix time of the last turn.
     * @return array<string,array<int,string>> host => sample URLs
     */
    private static function due_hosts($pending, $tried) {
        $now   = time();
        $order = array();
        $place = 0;

        foreach ($pending as $host => $urls) {
            $at = isset($tried[$host]) ? (int) $tried[$host] : 0;
            if ($at > 0 && $at <= $now && ($now - $at) < self::SAMPLE_INTERVAL) {
                continue;
            }
            $order[] = array($at, $place++, $host);
        }

        // Place in the catalogue breaks a tie. Spelled out, because
        // sorting only keeps equal elements in order from PHP 8.0 on.
        usort($order, function ($a, $b) {
            return array($a[0], $a[1]) <=> array($b[0], $b[1]);
        });

        $due = array();
        foreach ($order as $entry) {
            $due[$entry[2]] = $pending[$entry[2]];
        }
        return $due;
    }

    /**
     * Hosts in the catalogue whose verdict is missing or expired.
     *
     * @return array<string,array<int,string>> host => sample URLs
     */
    public static function pending_hosts() {
        $recipes = self::recipes();
        $pending = array();

        foreach (self::catalogue_samples() as $host => $urls) {
            if (!isset($recipes[$host]) || !is_array($recipes[$host])) {
                $pending[$host] = $urls;
                continue;
            }
            $entry   = $recipes[$host];
            $grammar = isset($entry['grammar']) ? (string) $entry['grammar'] : '';
            $at      = isset($entry['at']) ? (int) $entry['at'] : 0;
            $ttl     = ($grammar === '' || $grammar === 'none') ? self::TTL_NONE : self::TTL_FOUND;
            if ((time() - $at) > $ttl) {
                $pending[$host] = $urls;
            }
        }
        return $pending;
    }

    /**
     * Sample image URLs out of the catalogue, grouped by host, skipping
     * hosts a seeded grammar already covers.
     *
     * One query per feed rather than one DISTINCT over the whole table:
     * feed_id is indexed and a feed's images sit on one host, so a
     * handful of rows per feed names every host the site can show. On
     * mylook that is nine short queries against 366k rows instead of a
     * full scan.
     *
     * @return array<string,array<int,string>>
     */
    public static function catalogue_samples() {
        global $wpdb;

        if (!class_exists('MyFeeds_DB_Manager') || !isset($wpdb)) {
            return array();
        }
        $table = MyFeeds_DB_Manager::table_name();

        // Capped: this runs inside an admin request twice a day, and
        // the loop below costs one short query per feed.
        $feed_ids = $wpdb->get_col("SELECT DISTINCT feed_id FROM {$table} LIMIT 50");
        if (!is_array($feed_ids) || $feed_ids === array()) {
            return array();
        }

        $by_host = array();
        foreach ($feed_ids as $feed_id) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT image_url FROM {$table}
                 WHERE feed_id = %d AND image_url <> '' AND status = 'active'
                 LIMIT %d",
                (int) $feed_id,
                self::SAMPLES_PER_HOST * 2
            ));
            foreach ((array) $rows as $url) {
                $url = (string) $url;
                if ($url === '' || !function_exists('myfeeds_image_url_host')) {
                    continue;
                }
                $host = myfeeds_image_url_host($url);
                if ($host === '') {
                    continue;
                }
                // Seeded hosts are sampled too. The seed is a guess -
                // an educated one, from the platform the URL names -
                // and a guess that is never checked is how `wid` sat in
                // here for Scene7 while media.jdsports.com ignored it.
                if (myfeeds_image_url_is_signed($url)) {
                    continue;
                }
                if (!isset($by_host[$host])) {
                    $by_host[$host] = array();
                }
                if (count($by_host[$host]) < self::SAMPLES_PER_HOST
                    && !in_array($url, $by_host[$host], true)
                ) {
                    $by_host[$host][] = $url;
                }
            }
        }
        return $by_host;
    }

    /**
     * Ask one host how it wants to be asked.
     *
     * @param array $urls Sample URLs from that host.
     * @return array|null Verdict to store, or null when nothing could be
     *                    measured and the question should stay open.
     */
    public static function probe($urls) {
        $baseline = 0;
        $sample   = '';

        // The biggest of the samples is the honest baseline: a feed
        // mixes a 40 KB packshot with a 16 MB master, and a grammar is
        // worth having because of the second one.
        $refused = 0;
        foreach ((array) $urls as $url) {
            $measured = self::measure($url);
            if ($measured['refused']) {
                $refused++;
            }
            $size = $measured['size'];
            if ($size === null) {
                continue;
            }
            if ($size > $baseline) {
                $baseline = $size;
                $sample   = (string) $url;
            }
        }

        if ($sample === '' || $baseline <= 0) {
            // Every sample was turned away at the door. That is not a
            // host being down: it is a decision, and it comes out the
            // same in twelve hours. Left open, the host would be looked
            // up twice a day for good, and since a run takes its hosts
            // from the front of the queue, three of these would keep
            // every host behind them from ever being measured.
            //
            // No grammar is stored, on purpose. Nothing was measured, so
            // the entry claims nothing: a seed still applies, and a URL
            // without one stays as the merchant wrote it - exactly what
            // an unmeasured host gets. pending_hosts() treats a missing
            // grammar like a "no" and waits TTL_NONE before asking again.
            if ($refused > 0 && $refused === count((array) $urls)) {
                return array(
                    'reason' => 'blocked',
                    'source' => 'probe',
                    'at'     => time(),
                );
            }
            return null;
        }

        $seed = myfeeds_image_seeded_recipe($sample, myfeeds_image_url_host($sample));

        if ($baseline < self::MIN_INTERESTING) {
            // Already small. Nothing to win, and no grammar could prove
            // itself against an image this size. A seed survives that
            // verdict: it is a platform fact, not a guess about this
            // one file, and applying it to a small image costs nothing.
            return array(
                'grammar' => $seed === null ? 'none' : $seed,
                'reason'  => 'small',
                'source'  => $seed === null ? 'probe' : 'seed',
                'at'      => time(),
                'before'  => $baseline,
            );
        }

        // The seed gets asked first, and gets an easier question: does
        // it hand back a smaller image at the width we would actually
        // use? Monotonicity is the test for DISCOVERING an unknown
        // query parameter - proof that it is read at all. A seed is a
        // known grammar, and some of them are not parametric: AWIN's is
        // a swap between two fixed buckets, and asking it for 400 and
        // 800 says nothing about whether it is honoured.
        if ($seed !== null) {
            $sized = self::remote_size(myfeeds_image_apply_recipe($sample, $seed, 400));
            if ($sized !== null && $sized >= 500 && $sized <= (int) round($baseline * self::MAX_SHARE)) {
                return array(
                    'grammar' => $seed,
                    'source'  => 'seed',
                    'at'      => time(),
                    'before'  => $baseline,
                    'after'   => $sized,
                );
            }
            // The seed did not deliver. Fall through and find out what
            // this host actually wants - that is the whole point.
        }

        foreach (array_keys(myfeeds_image_query_grammars()) as $grammar) {
            $small = self::remote_size(myfeeds_image_apply_recipe($sample, $grammar, 400));
            if ($small === null || $small < 500) {
                continue;
            }
            $large = self::remote_size(myfeeds_image_apply_recipe($sample, $grammar, 800));
            if ($large === null) {
                continue;
            }
            // Monotonic in the width we asked for, or the parameter is
            // being ignored and both numbers are the original again.
            if ($small >= $large) {
                continue;
            }
            if ($large > (int) round($baseline * self::MAX_SHARE)) {
                continue;
            }
            return array(
                'grammar' => $grammar,
                'source'  => $seed === null ? 'probe' : 'probe-overrode-seed',
                'at'      => time(),
                'before'  => $baseline,
                'after'   => $large,
            );
        }

        return array(
            'grammar' => 'none',
            'reason'  => $seed === null ? 'no-resizer' : 'seed-failed-and-no-other',
            'source'  => 'probe',
            'at'      => time(),
            'before'  => $baseline,
        );
    }

    /**
     * Full byte size of a remote resource, for the price of one byte.
     *
     * Range first: a transforming CDN reports the size of the image it
     * WOULD send in Content-Range, which is the number we care about.
     * HEAD second, for hosts that ignore Range. Neither: null, and the
     * caller treats the host as unmeasured rather than guessing.
     *
     * @param string $url URL to measure.
     * @return int|null Bytes, or null.
     */
    public static function remote_size($url) {
        $measured = self::measure($url);
        return $measured['size'];
    }

    /**
     * remote_size(), and whether the door turned the address away.
     *
     * Both requests go through the guarded door. A refusal ends the
     * measurement: the HEAD would be the same address, or the same
     * redirect, meeting the same check.
     *
     * @param string $url URL to measure.
     * @return array{size:int|null,refused:bool}
     */
    private static function measure($url) {
        $unmeasured = array('size' => null, 'refused' => false);

        if (!function_exists('myfeeds_remote_get') || !function_exists('myfeeds_remote_head')
            || !is_string($url) || $url === ''
        ) {
            return $unmeasured;
        }

        $args = array(
            'timeout'     => 10,
            'redirection' => 3,
            'sslverify'   => true,
            'headers'     => array('Range' => 'bytes=0-0', 'Accept' => 'image/*,*/*'),
            // A host is free to ignore Range and answer 200 with the
            // whole image, and WordPress holds a body in memory unless
            // it is told where to stop. cdn.shopify.com does exactly
            // that for an address it is asked for the first time
            // (measured 2026-10-11), which is every address this probe
            // makes up. Only headers are read here, so one byte is all
            // that is kept - not 0, which WordPress reads as no limit.
            // The transfer still runs to its end or to the timeout:
            // this stops the storing, not the receiving.
            'limit_response_size' => 1,
            // run() reports a refused host, once, in words about pictures.
            'myfeeds_log_refusal' => false,
        );
        $res = myfeeds_remote_get($url, $args);
        if (self::was_refused($res)) {
            return array('size' => null, 'refused' => true);
        }
        if (!is_wp_error($res)) {
            self::$replies++;
            $code = (int) wp_remote_retrieve_response_code($res);
            if ($code === 206) {
                $range = (string) wp_remote_retrieve_header($res, 'content-range');
                if (preg_match('#/(\d+)\s*$#', $range, $m)) {
                    return array('size' => (int) $m[1], 'refused' => false);
                }
            }
            if ($code === 200) {
                $len = (string) wp_remote_retrieve_header($res, 'content-length');
                if ($len !== '' && ctype_digit($len)) {
                    return array('size' => (int) $len, 'refused' => false);
                }
            }
            if ($code >= 400) {
                return $unmeasured;
            }
        }

        $head = myfeeds_remote_head($url, array('timeout' => 10, 'redirection' => 3, 'myfeeds_log_refusal' => false));
        if (self::was_refused($head)) {
            return array('size' => null, 'refused' => true);
        }
        if (is_wp_error($head)) {
            return $unmeasured;
        }
        self::$replies++;
        if ((int) wp_remote_retrieve_response_code($head) >= 400) {
            return $unmeasured;
        }
        $len = (string) wp_remote_retrieve_header($head, 'content-length');
        return ($len !== '' && ctype_digit($len))
            ? array('size' => (int) $len, 'refused' => false)
            : $unmeasured;
    }

    /**
     * Did the door refuse this because of where the address points?
     *
     * Only that. A name that does not resolve, or one redirect too many,
     * may be different tomorrow and stays an ordinary failure.
     *
     * @param mixed $response What the door returned.
     * @return bool
     */
    private static function was_refused($response) {
        return is_wp_error($response) && $response->get_error_code() === 'myfeeds_private_host';
    }

    /**
     * The stored map, always an array.
     *
     * @return array
     */
    public static function recipes() {
        $stored = get_option(MYFEEDS_IMAGE_RECIPES_OPTION, array());
        return is_array($stored) ? $stored : array();
    }

    /**
     * Write the map back, autoloaded — every product tile reads it.
     *
     * @param array $recipes Map to store.
     * @return bool Whether the write was taken.
     */
    private static function save($recipes) {
        return (bool) update_option(MYFEEDS_IMAGE_RECIPES_OPTION, $recipes, true);
    }

    /**
     * Drop every verdict and ask again. For support, and for the moment
     * a merchant switches CDN.
     */
    public static function forget() {
        delete_option(MYFEEDS_IMAGE_RECIPES_OPTION);
        self::schedule(5);
    }
}
