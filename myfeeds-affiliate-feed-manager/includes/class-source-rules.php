<?php
/**
 * Where this site's products come from.
 *
 * A site chooses its product source: a feed it imports from a link or a
 * file, or the advertisers of a network's API, which together are the
 * same source. Amazon by link or ASIN is no product source; it is there
 * whatever the site chose.
 *
 * The answer is read from the feed list each time, so it cannot drift
 * from what the list holds: entries without a name or a URL are no
 * source, and neither are the sample products, which stay only until a
 * real source arrives.
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
     * The site's product source: '' (none yet), 'feed', or 'api:<network>'
     * ('api:rakuten'). A feed this plugin imports decides over entries a
     * network's API wrote.
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
     * from the editor? Only the one the site has.
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
        return $current !== '' && $current === (string) $kind;
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
