<?php
/**
 * The one REST route of Amazon links: saving the Amazon site, the partner
 * tag, the switch for visitors abroad and the badge. It needs
 * manage_options and asks nothing of Amazon.
 *
 * What else a site saves on the same request hangs on two filters:
 *   myfeeds_amazon_save_connection      (null, $request)   true or WP_Error,
 *                                                          saved in place of
 *                                                          the tag alone
 *   myfeeds_amazon_connection_response  ($data, $request)  the answer
 *
 * Shared byte for byte by both plugins.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_REST {

    const NS = 'myfeeds/v1';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/amazon/connection', array(
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'save_connection'),
                'permission_callback' => array(__CLASS__, 'can_manage'),
            ),
        ));
    }

    public static function can_edit() {
        return current_user_can('edit_posts');
    }

    public static function can_manage() {
        return current_user_can('manage_options');
    }

    public static function save_connection(WP_REST_Request $request) {
        $saved = apply_filters('myfeeds_amazon_save_connection', null, $request);
        if ($saved === null) {
            // Whatever else the request carries is not read: what was
            // stored beside the tag stays exactly as it is.
            $saved = MyFeeds_Amazon_Link_Settings::save(array(
                'marketplace' => $request->get_param('marketplace'),
                'partner_tag' => $request->get_param('partner_tag'),
            ));
        }

        if (is_wp_error($saved)) {
            return $saved;
        }

        // Both describe the links the plugin builds itself, so they are
        // set wherever such links are.
        if ($request->has_param('keep_market')) {
            MyFeeds_Amazon_Link_Settings::save_keep_market((bool) $request->get_param('keep_market'));
        }

        if ($request->has_param('badge_id')) {
            MyFeeds_Amazon_Link_Settings::save_badge($request->get_param('badge_id'));
        }

        return rest_ensure_response(apply_filters('myfeeds_amazon_connection_response', self::link_settings(), $request));
    }

    /** The settings of the links, as the window shows them. */
    public static function link_settings() {
        $marketplace = MyFeeds_Amazon_Link_Settings::marketplace();

        return array(
            'marketplace'       => $marketplace,
            'marketplace_label' => MyFeeds_Amazon_Locales::label($marketplace),
            'partner_tag'       => MyFeeds_Amazon_Link_Settings::partner_tag($marketplace),
            'available'         => MyFeeds_Amazon_Link_Settings::available_marketplaces(),
            'keep_market'       => MyFeeds_Amazon_Link_Settings::keep_market(),
            'badge_id'          => MyFeeds_Amazon_Link_Settings::badge_id(),
        );
    }
}
