<?php
/**
 * Amazon by link or ASIN: the door, and what the editors are told.
 *
 * Every site can place Amazon products by link or ASIN with its own
 * partner tag and Amazon site - the text card in a post and the Amazon
 * point in a Look, linked straight to Amazon. That needs nothing from
 * Amazon, so links_active() has no condition.
 *
 * Links keep nothing of Amazon's, so there is nothing to clear when the
 * plugin goes: the partner tag and the badge are the owner's settings.
 *
 * Amazon's API is a second level this file holds no code for. Whether a
 * site has it is the answer of the filter myfeeds_amazon_api_active
 * (false unless something says otherwise); whoever brings the API hangs
 * its own parts on the hooks of the card, the settings window, the save
 * route and the two editors.
 *
 * Shared byte for byte by both plugins.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Modules {

    /** May this site place Amazon products by link or ASIN? */
    public static function links_active() {
        return true;
    }

    /**
     * May this site use Amazon's API? Stored credentials do not answer
     * this: a site that stopped having the API still holds the ones it
     * saved.
     */
    public static function api_active() {
        return (bool) apply_filters('myfeeds_amazon_api_active', false);
    }

    public static function init() {
        if (!self::links_active()) {
            return;
        }

        MyFeeds_Amazon_REST::init();
        MyFeeds_Amazon_Admin::init();
        MyFeeds_Amazon_Card::init();

        add_filter('myfeeds_look_editor_amazon', array(__CLASS__, 'look_config'));
    }

    /**
     * What the product picker's Amazon tab is told (myfeedsData.amazon):
     *   allowed      the tab is there.
     *   hasTag       a partner tag is saved: the tab adds products.
     *   marketplace  where a bare ASIN goes (one with a tag).
     *   marketplaces the Amazon sites with a tag.
     *   settingsUrl  the settings window.
     * Whoever brings Amazon's API adds its own answers here
     * (myfeeds_amazon_picker_config) and in the editor
     * (myfeeds.picker.amazonApi in picker-inspector.js).
     *
     * @return array
     */
    public static function picker_config() {
        if (!self::links_active()) {
            return array('allowed' => false);
        }

        $marketplace = MyFeeds_Amazon_Link_Settings::link_marketplace();
        $available   = MyFeeds_Amazon_Link_Settings::available_marketplaces();

        $config = array(
            'allowed'      => true,
            'hasTag'       => !empty($available),
            'marketplace'  => $marketplace,
            'marketplaces' => !empty($available) ? $available : array($marketplace => MyFeeds_Amazon_Locales::label($marketplace)),
            'settingsUrl'  => MyFeeds_Amazon_Admin::settings_url(),
        );

        $filtered = apply_filters('myfeeds_amazon_picker_config', $config);
        return is_array($filtered) ? $filtered : $config;
    }

    /**
     * What the Look's editor is told about Amazon (myfeeds_look_editor_amazon):
     *   allowed  a partner tag is saved: a point takes an ASIN or Amazon link.
     *   links    Amazon products by link or ASIN.
     *   api      false: a set point is named by its author. Whoever can
     *            look a point up says so on the same filter.
     *
     * @param array $amazon What earlier filters said.
     * @return array
     */
    public static function look_config($amazon) {
        if (!self::links_active()) {
            return $amazon;
        }
        return array(
            'allowed'      => !empty(MyFeeds_Amazon_Link_Settings::available_marketplaces()),
            'links'        => true,
            'api'          => false,
            'marketplace'  => MyFeeds_Amazon_Link_Settings::link_marketplace(),
            'marketplaces' => MyFeeds_Amazon_Link_Settings::available_marketplaces(),
            'settingsUrl'  => MyFeeds_Amazon_Admin::settings_url(),
        );
    }
}
