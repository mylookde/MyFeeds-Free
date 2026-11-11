<?php
/**
 * Bootstrap for API sources.
 *
 * Built like MyFeeds_Amazon_Modules: one wiring order, and nothing wired
 * while the door is shut. The door is MyFeeds_Source_Rules: it is open
 * while Rakuten runs on this site, or while a Rakuten source may be
 * connected now.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Source_Modules {

    public static function is_active() {
        if (!class_exists('MyFeeds_Source_Rules')) {
            return false;
        }
        return MyFeeds_Source_Rules::runs('api', 'rakuten') || MyFeeds_Source_Rules::can_connect('api', 'rakuten');
    }

    public static function init() {
        // Display and clean-up only, whatever the door says: an API feed
        // the site still holds reads as paused in the table instead of as
        // an uploaded file, and deleting it takes its snapshot file along.
        if (class_exists('MyFeeds_Source_Feeds')) {
            MyFeeds_Source_Feeds::init();
        }
        // The Look asks whether a shop it finds in no feed is a Rakuten
        // feed's; rakuten_target() asks the rules for itself.
        if (class_exists('MyFeeds_Source_Look')) {
            MyFeeds_Source_Look::init();
        }

        if (!self::is_active()) {
            return;
        }

        if (class_exists('MyFeeds_Source_REST')) {
            MyFeeds_Source_REST::init();
        }

        if (class_exists('MyFeeds_Source_Admin')) {
            MyFeeds_Source_Admin::init();
        }

        if (class_exists('MyFeeds_Source_Fetcher')) {
            MyFeeds_Source_Fetcher::init();
        }
    }

    /**
     * What the product picker is told (myfeedsData.sources): a search
     * that finds little in a Rakuten feed's snapshot may pull the same
     * words from Rakuten into that feed. Only where Rakuten runs.
     *
     * @return array{allowed:bool,connected?:bool,settingsUrl?:string}
     */
    public static function picker_config() {
        if (!class_exists('MyFeeds_Source_Rules') || !MyFeeds_Source_Rules::runs('api', 'rakuten')) {
            return array('allowed' => false);
        }
        return array(
            'allowed'     => true,
            'connected'   => class_exists('MyFeeds_Rakuten_Credentials') && MyFeeds_Rakuten_Credentials::is_configured(),
            'settingsUrl' => class_exists('MyFeeds_Source_Admin') ? MyFeeds_Source_Admin::settings_url() : '',
        );
    }

    /**
     * The picker's Rakuten line in the block editor (assets/source-picker.js
     * and .css), registered where Rakuten runs and nowhere else. Each
     * handle only once its file is there: a dependency that is not
     * registered keeps WordPress from loading the script or stylesheet
     * that names it.
     *
     * @return array{scripts:string[],styles:string[]} Handles for the bundle's
     *         dependencies and for the editor style's.
     */
    public static function editor_handles() {
        $out = array('scripts' => array(), 'styles' => array());
        if (!class_exists('MyFeeds_Source_Rules') || !MyFeeds_Source_Rules::runs('api', 'rakuten')) {
            return $out;
        }

        $js  = 'assets/source-picker.js';
        $css = 'assets/source-picker.css';
        if (file_exists(MYFEEDS_PLUGIN_DIR . $js)) {
            wp_register_script(
                'myfeeds-source-picker',
                MYFEEDS_PLUGIN_URL . $js,
                array('myfeeds-picker-inspector', 'wp-hooks', 'wp-api-fetch', 'wp-element'),
                myfeeds_asset_ver($js),
                true
            );
            $out['scripts'][] = 'myfeeds-source-picker';
        }
        if (file_exists(MYFEEDS_PLUGIN_DIR . $css)) {
            wp_register_style('myfeeds-source-picker', MYFEEDS_PLUGIN_URL . $css, array(), myfeeds_asset_ver($css));
            $out['styles'][] = 'myfeeds-source-picker';
        }

        return $out;
    }

    /**
     * Called on plugin deactivation: a disabled plugin must not keep a
     * refresh cron or a fetch chain alive.
     */
    public static function deactivate() {
        if (class_exists('MyFeeds_Source_Fetcher')) {
            MyFeeds_Source_Fetcher::deactivate();
        }
    }
}
