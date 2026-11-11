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

    public static function init() {
        self::filters();

        // Expiry runs whether the API runs or not. A site that stops using
        // it stops being able to refresh Amazon data, which is exactly when
        // leaving it in the table would breach Amazon's licence.
        MyFeeds_Amazon_Cache::init();

        // A published Look renders its Amazon points on every site; what
        // it may show of Amazon is decided inside.
        if (class_exists('MyFeeds_Amazon_Look')) {
            MyFeeds_Amazon_Look::init();
        }

        if (self::active()) {
            MyFeeds_Amazon_Cache::maybe_create_table();
        }

        // The three below decide inside what the API adds: the price
        // stamp on a card, the API's routes, its part of the window.
        MyFeeds_Amazon_Stamp::init();
        MyFeeds_Amazon_Api_REST::init();
        MyFeeds_Amazon_Api_Admin::init();
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
     * Called on plugin deactivation so a disabled plugin stops holding
     * Amazon data it can no longer refresh. The credentials, the partner
     * tags and the site's choice of source stay: they are the owner's
     * settings, and the other MyFeeds plugin reads the same ones.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook(MyFeeds_Amazon_Cache::PURGE_HOOK);
        wp_clear_scheduled_hook(self::PAGE_REFRESH_HOOK);
        MyFeeds_Amazon_Cache::purge_all();
    }
}
