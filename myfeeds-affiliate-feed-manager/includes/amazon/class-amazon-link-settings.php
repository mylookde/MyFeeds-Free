<?php
/**
 * What a link to Amazon needs: the Amazon site, the site owner's partner
 * tag for it, whether visitors from other countries stay on that site, and
 * the official badge. A card or a Look point is built from these and the
 * ASIN alone; nothing here asks Amazon anything.
 *
 * Read from and written to myfeeds_amazon_connection (only its keys
 * marketplace and partner_tags), myfeeds_amazon_keep_market and
 * myfeeds_amazon_badge_id. Whatever else the connection option holds
 * travels through a save untouched - not read, re-written or defaulted -
 * so saving a tag can never change what was stored beside it.
 *
 * Shared by both plugins, apart from the text domain.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Link_Settings {

    const OPTION_CONNECTION = 'myfeeds_amazon_connection';

    /**
     * Keep visitors from other countries on the site whose tag the link
     * carries. Amazon otherwise sends them (by IP) to a search on their
     * local Amazon - with a tag that counts there only for owners who
     * turned those countries on (Associates Central: Account Settings,
     * Global Earning Preferences - Amazon's menu no longer says OneLink,
     * 22.10.2026). On by default; off for those owners. Measured
     * 2026-10-01: amazon.com/dp/<ASIN>?tag=... from a German IP lands on
     * amazon.de/s/?field-keywords=<title>; with creatorsDisableRedirect=true
     * it stays on amazon.com.
     */
    const OPTION_KEEP_MARKET = 'myfeeds_amazon_keep_market';

    // The official "Available at Amazon" badge, as an attachment the site
    // owner uploaded. Amazon's trademark rules let Associates use that one
    // mark, unaltered - the plugin does not ship it, the owner downloads
    // it from Associates Central.
    const OPTION_BADGE = 'myfeeds_amazon_badge_id';

    private static function stored() {
        $stored = get_option(self::OPTION_CONNECTION, array());
        return is_array($stored) ? $stored : array();
    }

    private static function tags() {
        $stored = self::stored();
        return isset($stored['partner_tags']) && is_array($stored['partner_tags']) ? $stored['partner_tags'] : array();
    }

    /** The Amazon site picked in the settings. */
    public static function marketplace() {
        $stored      = self::stored();
        $marketplace = isset($stored['marketplace']) ? (string) $stored['marketplace'] : '';
        return MyFeeds_Amazon_Locales::exists($marketplace) ? $marketplace : MyFeeds_Amazon_Locales::default_marketplace();
    }

    public static function partner_tag($marketplace = null) {
        $marketplace = $marketplace ?: self::marketplace();
        $tags        = self::tags();

        return isset($tags[$marketplace]) ? (string) $tags[$marketplace] : '';
    }

    /**
     * The Amazon sites a link can go to: a link without the owner's tag
     * earns nothing, so a site without one is not offered.
     */
    public static function available_marketplaces() {
        $available = array();

        foreach (self::tags() as $marketplace => $tag) {
            if ($tag !== '' && MyFeeds_Amazon_Locales::exists($marketplace)) {
                $available[$marketplace] = MyFeeds_Amazon_Locales::label($marketplace);
            }
        }

        return $available;
    }

    /**
     * The Amazon site a link goes to when nothing else says which: the
     * site picked in the settings if it has a partner tag, otherwise the
     * first site that has one. A link to a site without the owner's tag
     * earns nothing, so a tag decides, never the dropdown alone.
     */
    public static function link_marketplace() {
        $current = self::marketplace();
        if (self::partner_tag($current) !== '') {
            return $current;
        }
        $available = self::available_marketplaces();
        return !empty($available) ? (string) array_key_first($available) : $current;
    }

    public static function keep_market() {
        return get_option(self::OPTION_KEEP_MARKET, '1') === '1';
    }

    public static function save_keep_market($on) {
        update_option(self::OPTION_KEEP_MARKET, $on ? '1' : '0', false);
    }

    public static function badge_id() {
        $id = (int) get_option(self::OPTION_BADGE, 0);
        return ($id > 0 && function_exists('wp_attachment_is_image') && wp_attachment_is_image($id)) ? $id : 0;
    }

    public static function save_badge($id) {
        $id = (int) $id;
        if ($id > 0) {
            update_option(self::OPTION_BADGE, $id, false);
        } else {
            delete_option(self::OPTION_BADGE);
        }
    }

    /**
     * The Amazon site and its partner tag, written into the stored option
     * as it stands. An empty tag takes that site's tag away and nothing
     * else.
     *
     * @param array $input marketplace, partner_tag - both optional.
     * @return true|WP_Error
     */
    public static function save(array $input) {
        $stored = self::stored();

        $marketplace = isset($input['marketplace']) ? sanitize_text_field($input['marketplace']) : self::marketplace();
        if (!MyFeeds_Amazon_Locales::exists($marketplace)) {
            return new WP_Error('myfeeds_amazon_bad_marketplace', __('Unknown Amazon marketplace.', 'myfeeds-affiliate-feed-manager'));
        }

        $partner_tags = self::tags();
        if (isset($input['partner_tag'])) {
            $tag = sanitize_text_field($input['partner_tag']);
            if ($tag === '') {
                unset($partner_tags[$marketplace]);
            } else {
                $partner_tags[$marketplace] = $tag;
            }
        }

        $stored['marketplace']  = $marketplace;
        $stored['partner_tags'] = $partner_tags;

        update_option(self::OPTION_CONNECTION, $stored, false);

        return true;
    }
}
