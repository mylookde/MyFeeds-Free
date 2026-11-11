<?php
/**
 * Where this site's products come from.
 *
 * A site chooses its product source: a feed it imports from a link or a
 * file, or the advertisers of a network's API, which together are the
 * same source, or Amazon's API. Amazon by link or ASIN is no product
 * source; it is there whatever the site chose.
 *
 * A feed or a network's API is read from the feed list each time, so the
 * answer cannot drift from what the list holds: entries without a name or
 * a URL are no source, and neither are the sample products, which stay
 * only until a real source arrives. Amazon's API leaves no entry in the
 * list; that it was chosen is an option of its own (AMAZON_CHOICE), and
 * stored credentials are no choice.
 *
 * Every path that adds a source asks can_connect() first, and turns the
 * request down with refusal() otherwise. The way to another source is
 * "Change source" on the Feeds screen. What fetches or refreshes a source
 * asks runs(): only the site's own source does.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Source_Rules {

    /**
     * Amazon's API as the product source, in words: 'chosen' (it is),
     * 'set_aside' (it lost when the site came back from the other MyFeeds
     * plugin; the Feeds screen offers it again), 'not_chosen'. Missing
     * until this plugin first decided.
     */
    const AMAZON_CHOICE = 'myfeeds_amazon_api_source';

    /**
     * The site's product source: '' (none yet), 'feed', 'api:<network>'
     * ('api:rakuten') or 'amazon_api'. A feed this plugin imports decides
     * over entries a network's API wrote, and the list over Amazon's API.
     * Amazon's API is the source because it was chosen, set up or not: a
     * missing partner tag makes it a source that is not set up, not none.
     *
     * @return string
     */
    public static function current() {
        $list = get_option('myfeeds_feeds', array());
        if (!is_array($list)) {
            return '';
        }
        $api = '';
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = isset($entry['name']) ? trim((string) $entry['name']) : '';
            $url  = isset($entry['url']) ? trim((string) $entry['url']) : '';
            if ($name === '' || $url === '') {
                continue;
            }
            if (class_exists('MyFeeds_Demo_Content') && MyFeeds_Demo_Content::is_demo_feed($entry)) {
                continue;
            }
            if (empty($entry['api']) || !is_array($entry['api'])) {
                return 'feed';
            }
            if ($api === '') {
                $network = isset($entry['api']['network']) ? sanitize_key((string) $entry['api']['network']) : '';
                $api = 'api:' . ($network !== '' ? $network : 'other');
            }
        }
        if ($api === '' && get_option(self::AMAZON_CHOICE) === 'chosen') {
            return 'amazon_api';
        }
        return $api;
    }

    /**
     * The source in a word, for the Feeds screen.
     *
     * @param string|null $current A value of current(); the site's own when null.
     * @return string
     */
    public static function label($current = null) {
        $current = $current === null ? self::current() : (string) $current;
        if ($current === '') {
            return __('None', 'myfeeds-affiliate-feed-manager');
        }
        if ($current === 'feed') {
            return __('Feed', 'myfeeds-affiliate-feed-manager');
        }
        if ($current === 'api:rakuten') {
            return __('Rakuten', 'myfeeds-affiliate-feed-manager');
        }
        if ($current === 'amazon_api') {
            return __('Amazon\'s API', 'myfeeds-affiliate-feed-manager');
        }
        return __('API', 'myfeeds-affiliate-feed-manager');
    }

    /**
     * May a source of this kind be connected now, or grow? Always while
     * the site has none. Another advertiser of the network that already
     * is the source belongs to it. Anything else waits for "Change source".
     *
     * @param string $kind    'feed', 'api' or 'amazon_api'.
     * @param string $network For 'api': the network, 'rakuten'.
     * @return bool
     */
    public static function can_connect($kind, $network = '') {
        $current = self::current();
        if ($current === '') {
            return true;
        }
        return $kind === 'api' && $network !== '' && $current === 'api:' . sanitize_key((string) $network);
    }

    /**
     * Does a source of this kind run here: fetched, refreshed, searched
     * from the editor? Only the one the site has. Amazon's API also needs
     * to be set up: without the partner tag of its Amazon site it has
     * nothing to ask Amazon with.
     *
     * @param string $kind    'feed', 'api' or 'amazon_api'.
     * @param string $network For 'api': the network, 'rakuten'.
     * @return bool
     */
    public static function runs($kind, $network = '') {
        $current = self::current();
        if ($kind === 'api') {
            return $network !== '' && $current === 'api:' . sanitize_key((string) $network);
        }
        if ($kind === 'amazon_api') {
            return $current === 'amazon_api' && class_exists('MyFeeds_Amazon_Credentials') && MyFeeds_Amazon_Credentials::is_configured();
        }
        return $current !== '' && $current === (string) $kind;
    }

    /**
     * Is this kind the source the site chose, whether it runs or not? A
     * missing partner tag stops Amazon's API from running, not from being
     * the source: its window stays the place to set it up again, and its
     * pages still hold what it showed.
     *
     * @param string $kind 'feed', 'api:<network>' or 'amazon_api'.
     * @return bool
     */
    public static function chosen($kind) {
        return self::current() === (string) $kind;
    }

    /**
     * Drops this request's copy of what decides the source - the feed list,
     * Amazon's choice, and WordPress' copy of the options every request
     * loads - so the next answer reads them as stored. For a decision that
     * writes a source: another request may have connected one since this
     * one loaded the options.
     */
    public static function read_as_stored() {
        wp_cache_delete('myfeeds_feeds', 'options');
        wp_cache_delete(self::AMAZON_CHOICE, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /**
     * May the window of a source offer to remove its stored connection?
     * Not while the site's products come from it: the way off the source
     * is "Change source", which says first what leaves the posts. Stored
     * details the site does not use may go.
     *
     * @param string $kind 'api' or 'amazon_api'.
     * @return bool
     */
    public static function can_disconnect($kind) {
        $current = self::current();
        if ($kind === 'api') {
            return strpos($current, 'api:') !== 0;
        }
        return $current !== (string) $kind;
    }

    /**
     * Amazon's API becomes the site's product source. Written before the
     * page renders it, so it is read with the options every request loads.
     * The sample products go, as with every real source, and no fetch of a
     * network's API from before can finish now and add a second source.
     *
     * @param string $kind 'amazon_api'.
     */
    public static function choose($kind) {
        if ($kind !== 'amazon_api') {
            return;
        }
        update_option(self::AMAZON_CHOICE, 'chosen', true);
        if (class_exists('MyFeeds_Demo_Content')) {
            MyFeeds_Demo_Content::remove_if_active();
        }
        if (class_exists('MyFeeds_Source_Fetcher')) {
            MyFeeds_Source_Fetcher::stop_all();
        }
    }

    /**
     * Amazon's API stops being the site's product source, or was not
     * chosen: 'set_aside' when another source won as the site came back
     * from the other MyFeeds plugin, 'not_chosen' otherwise. Amazon's data
     * goes with it - this plugin keeps none it does not use, as Amazon's
     * terms ask. The credentials and the partner tags stay.
     *
     * @param string $kind 'amazon_api'.
     * @param string $why  'set_aside' or 'not_chosen'.
     */
    public static function release($kind, $why) {
        if ($kind !== 'amazon_api') {
            return;
        }
        update_option(self::AMAZON_CHOICE, $why === 'set_aside' ? 'set_aside' : 'not_chosen', true);
        if (class_exists('MyFeeds_Amazon_Cache')) {
            MyFeeds_Amazon_Cache::purge_all();
        }
    }

    /**
     * The kinds of source the Feeds screen offers as a way in: all of them
     * while the site has none, otherwise none.
     *
     * @return string[]
     */
    public static function doors() {
        return self::current() === '' ? array('feed', 'api', 'amazon_api') : array();
    }

    /** What a request to add a second source is told. */
    public static function refusal() {
        return __('This site already has a product source. Change the source first.', 'myfeeds-affiliate-feed-manager');
    }
}
