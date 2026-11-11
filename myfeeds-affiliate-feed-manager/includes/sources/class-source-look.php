<?php
/**
 * The Look and Rakuten: a pasted shop address no feed sells yet.
 *
 * Rakuten hands out a catalogue in slices, never whole, so a product the
 * author's Look points at may simply not be in the feed's snapshot yet.
 * When the shop behind a pasted address is one of the Rakuten feeds, the
 * Look's lookup says so (myfeeds_look_not_found), and the editor can ask
 * Rakuten for it through /look/fetch: the same discovery the picker runs -
 * rows appended to the feed's snapshot, imported, the term remembered for
 * the nightly refresh.
 *
 * Kept apart from class-look-block.php: the Look runs on every site, the
 * question only where Rakuten runs.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Source_Look {

    public static function init() {
        add_filter('myfeeds_look_not_found', array(__CLASS__, 'not_found'), 10, 2);
        add_action('rest_api_init', array(__CLASS__, 'register_rest'));
    }

    public static function register_rest() {
        register_rest_route('myfeeds/v1', '/look/fetch', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'rest_fetch'),
            'permission_callback' => function () { return current_user_can('edit_posts'); },
            'args'                => array('url' => array('type' => 'string', 'required' => true)),
        ));
    }

    /**
     * Not in the catalogue. If the shop is one of the Rakuten feeds, the
     * snapshot may simply not hold it yet - say so, so the editor can
     * offer to fetch it.
     */
    public static function not_found($answer, $url) {
        $answer = is_array($answer) ? $answer : array('found' => false);
        $answer['rakuten'] = self::rakuten_target((string) $url);
        return $answer;
    }

    /**
     * Ask Rakuten for a product a pasted shop URL points at: the shop gives
     * the feed, the URL's readable slug the search term. The editor polls
     * /sources/runs/<key> and then resolves the URL again.
     */
    public static function rest_fetch(WP_REST_Request $req) {
        $target = self::rakuten_target((string) $req->get_param('url'));
        if (!$target || !class_exists('MyFeeds_Source_Fetcher')) {
            return new WP_Error('myfeeds_look_no_source', __('None of your Rakuten feeds is this shop.', 'myfeeds-affiliate-feed-manager'), array('status' => 400));
        }
        if ($target['term'] === '') {
            return new WP_Error('myfeeds_look_no_term', __('This address does not name the product, so there is nothing to search Rakuten for. Paste the product page address instead.', 'myfeeds-affiliate-feed-manager'), array('status' => 400));
        }
        $result = MyFeeds_Source_Fetcher::discover($target['sid'], $target['term']);
        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => 400));
        }
        return rest_ensure_response(array(
            'key'    => MyFeeds_Source_Fetcher::discovery_key($target['sid']),
            'term'   => $target['term'],
            'feed'   => $target['name'],
            'queued' => !empty($result['queued']),
        ));
    }

    /**
     * The Rakuten feed selling on this URL's shop, and the term its slug
     * spells. null when no Rakuten feed is that shop.
     *
     * @return array{sid:int,name:string,term:string}|null
     */
    public static function rakuten_target($url) {
        // Asked of the rules the fetcher runs by. A site where Rakuten
        // does not run may still hold its connection and a feed; the Look
        // must not start a fetch there, and the route the editor would
        // poll is not registered.
        if (!class_exists('MyFeeds_Source_Rules') || !MyFeeds_Source_Rules::runs('api', 'rakuten')) {
            return null;
        }
        if (!class_exists('MyFeeds_Source_Feeds') || !class_exists('MyFeeds_Rakuten_Credentials')
            || !MyFeeds_Rakuten_Credentials::is_configured()) {
            return null;
        }
        $host = self::bare_host((string) wp_parse_url(trim($url), PHP_URL_HOST));
        if ($host === '') {
            return null;
        }
        foreach (self::rakuten_hosts() as $sid => $entry) {
            if ($entry['host'] === $host) {
                return array('sid' => (int) $sid, 'name' => $entry['name'], 'term' => self::term_from_url($url));
            }
        }
        return null;
    }

    private static function bare_host($host) {
        return preg_replace('/^(www|m|shop)\./', '', strtolower(trim($host)));
    }

    /**
     * Which shop each Rakuten feed sells for, read off one of its own
     * tracked links (murl= carries the shop's URL). Kept for a day; a new
     * feed shows up on the first lookup after it imported.
     */
    private static function rakuten_hosts() {
        $cached = get_transient('myfeeds_look_rakuten_hosts');
        if (is_array($cached)) {
            return $cached;
        }
        global $wpdb;
        $table = class_exists('MyFeeds_DB_Manager') ? MyFeeds_DB_Manager::table_name() : '';
        $map = array();
        foreach (MyFeeds_Source_Feeds::feeds() as $feed) {
            if (!MyFeeds_Source_Feeds::is_api_feed($feed) || $table === '') {
                continue;
            }
            $sid  = (int) $feed['stable_id'];
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is the plugin's own products table (MyFeeds_DB_Manager::table_name()); one link per feed, the answer kept a day.
            $link = (string) $wpdb->get_var($wpdb->prepare("SELECT affiliate_link FROM {$table} WHERE feed_id = %d AND affiliate_link LIKE %s LIMIT 1", $sid, '%murl=%'));
            parse_str((string) wp_parse_url($link, PHP_URL_QUERY), $q);
            $host = self::bare_host((string) wp_parse_url(isset($q['murl']) ? (string) $q['murl'] : '', PHP_URL_HOST));
            if ($host !== '') {
                $map[$sid] = array('host' => $host, 'name' => (string) $feed['name']);
            }
        }
        set_transient('myfeeds_look_rakuten_hosts', $map, DAY_IN_SECONDS);
        return $map;
    }

    /**
     * "/pdp/nike-push-up-grip-3.0/prod2836874/N1000749/010" -> "nike push up
     * grip 3.0". The longest path segment made of words; item numbers,
     * colour codes and file endings are dropped.
     */
    public static function term_from_url($url) {
        $path = (string) wp_parse_url(trim($url), PHP_URL_PATH);
        $best = '';
        $best_words = 0;
        foreach (explode('/', $path) as $seg) {
            $seg   = preg_replace('/\.(html?|php|aspx?)$/i', '', rawurldecode($seg));
            $words = preg_split('/[-_+\s]+/', $seg, -1, PREG_SPLIT_NO_EMPTY);
            $words = array_values(array_filter($words, function ($w) {
                return preg_match('/[a-z]/i', $w) && !preg_match('/^(prod|sku|p)?\d{4,}[a-z]?\d*$/i', $w);
            }));
            if (count($words) > $best_words && count($words) >= 2) {
                $best = implode(' ', $words);
                $best_words = count($words);
            }
        }
        $best = strtolower(trim($best));
        return class_exists('MyFeeds_Rakuten_Source') ? MyFeeds_Rakuten_Source::clean_term($best) : $best;
    }
}
