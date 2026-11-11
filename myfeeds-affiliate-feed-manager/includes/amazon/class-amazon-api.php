<?php
/**
 * Amazon's API (the Creators API) on top of Amazon links.
 *
 * Amazon by link or ASIN is MyFeeds_Amazon_Modules and the classes it
 * starts. Everything that talks to Amazon - the credentials, the catalogue
 * search, Amazon's picture and price with their stamp, the short-lived
 * cache - hangs on the hooks those classes offer, and this class is where
 * it is hung on. Whether Amazon's API runs on this site is asked here and
 * nowhere else in these files: MyFeeds_Source_Rules answers
 * (runs('amazon_api')).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Api {

    /** The daily event that clears the cached pages showing Amazon's data. */
    const PAGE_REFRESH_HOOK = 'myfeeds_amazon_page_refresh';

    /** The editor's part of the API: the Amazon tab with Amazon's data, and the Look's Amazon. */
    const EDITOR_HANDLE = 'myfeeds-amazon-api-picker';

    public static function init() {
        self::filters();

        // The two jobs are planned on init, not here on plugins_loaded:
        // planning one runs every cron_schedules filter of the site, and a
        // filter that translates its labels must not run before init
        // (WordPress says so in the debug log).
        if (did_action('init')) {
            self::schedule_jobs();
        } else {
            add_action('init', array(__CLASS__, 'schedule_jobs'));
        }

        // A published Look renders its Amazon points on every site; what
        // it may show of Amazon is decided inside.
        if (class_exists('MyFeeds_Amazon_Look')) {
            MyFeeds_Amazon_Look::init();
        }

        if (self::active()) {
            MyFeeds_Amazon_Cache::maybe_create_table();
        }

        add_action(self::PAGE_REFRESH_HOOK, array(__CLASS__, 'refresh_pages'));

        // The three below decide inside what the API adds: the price
        // stamp on a card, the API's routes, its part of the window.
        MyFeeds_Amazon_Stamp::init();
        MyFeeds_Amazon_Api_REST::init();
        MyFeeds_Amazon_Api_Admin::init();
    }

    /**
     * The hourly expiry of Amazon's cache and the daily clearing of cached
     * pages.
     *
     * Expiry runs whether the API runs or not. A site that stops using it
     * stops being able to refresh Amazon data, which is exactly when
     * leaving it in the table would breach Amazon's licence.
     *
     * A cached page keeps Amazon's titles and picture links for as long as
     * the cache keeps the page; a site whose only source is Amazon's API
     * imports nothing that would clear it.
     */
    public static function schedule_jobs() {
        MyFeeds_Amazon_Cache::init();

        if (self::pages_carry_its_data()) {
            if (!wp_next_scheduled(self::PAGE_REFRESH_HOOK)) {
                wp_schedule_event(self::next_four_am(), 'daily', self::PAGE_REFRESH_HOOK);
            }
        } elseif (wp_next_scheduled(self::PAGE_REFRESH_HOOK)) {
            wp_clear_scheduled_hook(self::PAGE_REFRESH_HOOK);
        }
    }

    /**
     * The answers the shared Amazon classes ask for. Filters only, so a
     * test can put them in place without the actions and the cron.
     */
    public static function filters() {
        add_filter('myfeeds_amazon_picker_config', array(__CLASS__, 'picker_config'));
        add_filter('myfeeds_amazon_save_connection', array('MyFeeds_Amazon_Api_REST', 'save_credentials'), 10, 2);
        add_filter('myfeeds_amazon_connection_response', array('MyFeeds_Amazon_Api_REST', 'connection_response'), 10, 2);
        add_filter('myfeeds_amazon_modal_sections', array('MyFeeds_Amazon_Api_Admin', 'sections'));
        add_filter('myfeeds_amazon_is_set_up', array('MyFeeds_Amazon_Api_Admin', 'is_set_up'));
        add_filter('myfeeds_amazon_disclaimer_lines', array('MyFeeds_Amazon_Stamp', 'disclaimer_lines'));
        add_filter('myfeeds_pick_texts_notes', array(__CLASS__, 'pick_notes'), 10, 2);
    }

    /**
     * Does Amazon's API run on this site? MyFeeds_Source_Rules answers.
     * Stored credentials do not: a site that stopped using the API still
     * holds the ones it saved, and uses them again only once the API runs
     * here again.
     */
    public static function active() {
        return class_exists('MyFeeds_Source_Rules') && MyFeeds_Source_Rules::runs('amazon_api');
    }

    /**
     * Is the API's part of the Amazon window there, with the routes that
     * set it up? Where the API runs; where a door may make it the site's
     * source now; and where it is the source but not set up (a partner tag
     * missing), so it can be set up again where it was set up.
     */
    public static function settings_open() {
        if (self::active()) {
            return true;
        }
        return class_exists('MyFeeds_Source_Rules')
            && (MyFeeds_Source_Rules::can_connect('amazon_api') || MyFeeds_Source_Rules::chosen('amazon_api'));
    }

    /**
     * Why Amazon's price is missing from a recommendation card or a
     * comparison table, said in the editor under its texts: next to other
     * shops Amazon asks for its lowest new price, which the API does not
     * give out (MyFeeds_Picker_Displays::hides_amazon_price()).
     *
     * @param string[] $notes What earlier filters said.
     * @param array    $items The block's items as the page renders them.
     * @return string[]
     */
    public static function pick_notes($notes, $items) {
        $notes = is_array($notes) ? $notes : array();
        if (is_array($items) && class_exists('MyFeeds_Picker_Displays') && MyFeeds_Picker_Displays::hides_amazon_price($items)) {
            $notes[] = __('Amazon\'s price is left out in this block. Next to other shops Amazon asks for its lowest new price, and its API does not give that out. Readers see the price on Amazon.', 'myfeeds-affiliate-feed-manager');
        }
        return $notes;
    }

    /**
     * What the API adds to the picker's Amazon tab (myfeedsData.amazon),
     * only where it runs. Where it does not, the tab is told what a link
     * needs and nothing more, whatever is stored: credentials saved
     * earlier stay stored, and a tab that took them for a connection would
     * search over routes that are not there.
     *   connected   the API is set up.
     *   apiState    what Amazon last said.
     */
    public static function picker_config($config) {
        if (!is_array($config) || empty($config['allowed']) || !self::active()) {
            return $config;
        }
        $marketplace = isset($config['marketplace']) ? (string) $config['marketplace'] : MyFeeds_Amazon_Link_Settings::link_marketplace();

        return array_merge($config, array(
            'connected'     => MyFeeds_Amazon_Credentials::is_configured(),
            'apiState'      => MyFeeds_Amazon_Credentials::get_status()['state'],
            'searchIndices' => MyFeeds_Amazon_Api_Locales::search_indices($marketplace),
            'maxPage'       => MyFeeds_Amazon_Client::MAX_ITEM_PAGE,
            'perPage'       => MyFeeds_Amazon_Client::MAX_ITEM_COUNT,
        ));
    }

    /**
     * The editor's scripts and styles of Amazon's API (amazon-api-picker.js
     * and .css): registered, and handed to both blocks' editors only where
     * the API runs. Elsewhere the Amazon tab is the shared one, by link or
     * ASIN, and the editor asks none of the API's routes.
     *
     * @return array{scripts:string[],styles:string[]}
     */
    public static function editor_handles() {
        $out = array('scripts' => array(), 'styles' => array());
        if (!self::active()) {
            return $out;
        }

        $js  = 'assets/amazon-api-picker.js';
        $css = 'assets/amazon-api-picker.css';
        if (file_exists(MYFEEDS_PLUGIN_DIR . $js)) {
            wp_register_script(
                self::EDITOR_HANDLE,
                MYFEEDS_PLUGIN_URL . $js,
                array('myfeeds-picker-inspector', 'wp-hooks', 'wp-api-fetch', 'wp-element', 'wp-components'),
                myfeeds_asset_ver($js),
                true
            );
            $out['scripts'][] = self::EDITOR_HANDLE;
        }
        if (file_exists(MYFEEDS_PLUGIN_DIR . $css)) {
            wp_register_style(self::EDITOR_HANDLE, MYFEEDS_PLUGIN_URL . $css, array('myfeeds-amazon-picker'), myfeeds_asset_ver($css));
            $out['styles'][] = self::EDITOR_HANDLE;
        }

        return $out;
    }

    /**
     * Do the site's pages show Amazon's data as their products? Where
     * Amazon's API is the source the site chose, set up or not - a page
     * cached while it ran still holds Amazon's titles - and no feed of the
     * site's own refreshes the cached pages after its import.
     */
    private static function pages_carry_its_data() {
        if (!class_exists('MyFeeds_Source_Rules') || !MyFeeds_Source_Rules::chosen('amazon_api')) {
            return false;
        }
        $feeds = get_option('myfeeds_feeds', array());
        $feeds = is_array($feeds) ? $feeds : array();
        if (class_exists('MyFeeds_Demo_Content')) {
            $feeds = MyFeeds_Demo_Content::without_samples($feeds);
        }
        return empty($feeds);
    }

    /**
     * Once a day, at 04:00 the site's time: the cached pages that show
     * products are cleared, so none of them shows Amazon's title or picture
     * longer than Amazon's licence allows (a day).
     */
    public static function refresh_pages() {
        if (!self::pages_carry_its_data() || !class_exists('MyFeeds_Page_Cache_Purge')) {
            return;
        }
        MyFeeds_Page_Cache_Purge::purge_product_posts();
    }

    /** The next 04:00 in the site's time zone. */
    private static function next_four_am() {
        $at = new DateTimeImmutable('today 04:00', wp_timezone());
        if ($at->getTimestamp() <= time()) {
            $at = $at->modify('+1 day');
        }
        return $at->getTimestamp();
    }

    /**
     * Called on plugin deactivation so a disabled plugin stops holding
     * Amazon data it can no longer refresh. The credentials, the partner
     * tags and the site's choice of source stay: they are the owner's
     * settings, and the other MyFeeds plugin reads the same ones.
     *
     * The cached pages that may show Amazon's data are cleared first - the
     * API is the site's source and its credentials are stored: once the
     * plugin is off, neither the daily clearing nor an import clears them,
     * and a page cache may keep a page for a week.
     */
    public static function deactivate() {
        if (class_exists('MyFeeds_Source_Rules') && MyFeeds_Source_Rules::chosen('amazon_api')
            && MyFeeds_Amazon_Credentials::is_stored() && class_exists('MyFeeds_Page_Cache_Purge')) {
            MyFeeds_Page_Cache_Purge::purge_product_posts();
        }
        wp_clear_scheduled_hook(MyFeeds_Amazon_Cache::PURGE_HOOK);
        wp_clear_scheduled_hook(self::PAGE_REFRESH_HOOK);
        MyFeeds_Amazon_Cache::purge_all();
    }
}
