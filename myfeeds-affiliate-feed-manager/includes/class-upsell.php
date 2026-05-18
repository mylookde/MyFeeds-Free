<?php
/**
 * Informational links to myfeeds.site.
 *
 * Adds an Upgrade link to the plugin's Plugins-screen row and a
 * "More features" entry to the MyFeeds submenu, both pointing to
 * separate paid plugins available at myfeeds.site. This plugin
 * itself is fully functional with no gated UI.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Upsell {

    const PRICING_URL = 'https://myfeeds.site/?utm_source=wp-plugin-free&utm_medium=admin-menu';

    public function init() {
        add_action('admin_menu', array($this, 'add_go_pro_submenu'), 100);
        add_action('admin_footer', array($this, 'open_go_pro_in_new_tab'));

        if (defined('MYFEEDS_PLUGIN_FILE')) {
            $basename = plugin_basename(MYFEEDS_PLUGIN_FILE);
            add_filter("plugin_action_links_{$basename}", array($this, 'add_upgrade_action_link'));
        }
    }

    /**
     * Append a small "Upgrade" link to the plugin's row on the Plugins
     * screen. Conventional pattern (Yoast, RankMath, WPForms all use it)
     * and the most-visited Pro-discovery surface for users who installed
     * MyFeeds and only see the plugin row, not the settings page.
     */
    public function add_upgrade_action_link($links) {
        $url = self::PRICING_URL . '&utm_term=plugins-list';
        $upgrade = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" style="color:#667eea;font-weight:600;">%s</a>',
            esc_url($url),
            esc_html__('Upgrade', 'myfeeds-affiliate-feed-manager')
        );
        array_unshift($links, $upgrade);
        return $links;
    }

    public function add_go_pro_submenu() {
        global $submenu;
        if (!isset($submenu['myfeeds-feeds'])) {
            return;
        }
        $submenu['myfeeds-feeds'][] = array(
            __('More features ↗', 'myfeeds-affiliate-feed-manager'),
            'manage_options',
            esc_url(self::PRICING_URL),
        );
    }

    /**
     * WP admin renders submenu anchors without a target attribute and there
     * is no filter to change that. Tag the external link via JS so it opens
     * in a new tab. Tiny inline script, runs on every admin page because
     * the sidebar menu is always present.
     */
    public function open_go_pro_in_new_tab() {
        $selector    = '#adminmenu a[href="' . self::PRICING_URL . '"]';
        $selector_js = wp_json_encode($selector);
        $script      = '(function(){var a=document.querySelector(' . $selector_js . ');if(a){a.target="_blank";a.rel="noopener noreferrer";}})();';
        wp_print_inline_script_tag($script);
    }

}
