<?php
/**
 * Informational links to myfeeds.site.
 *
 * Renders an upsell card in the settings-sidebar on the main MyFeeds
 * admin page, an Upgrade action link on the Plugins screen row, and a
 * "More features" entry in the MyFeeds submenu. All three point to
 * separate paid plugins available at myfeeds.site. This plugin itself
 * is fully functional with no gated UI.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Upsell {

    const PRICING_URL = 'https://myfeeds.site/?utm_source=wp-plugin-free&utm_medium=admin-menu';

    public function init() {
        add_action('admin_menu', array($this, 'add_go_pro_submenu'), 100);
        add_action('admin_footer', array($this, 'open_go_pro_in_new_tab'));
        add_action('myfeeds_settings_sidebar', array($this, 'render_sidebar_card'));

        if (defined('MYFEEDS_PLUGIN_FILE')) {
            $basename = plugin_basename(MYFEEDS_PLUGIN_FILE);
            add_filter("plugin_action_links_{$basename}", array($this, 'add_upgrade_action_link'));
        }
    }

    /**
     * Sidebar upsell card on the main MyFeeds settings page. Persistent
     * (not dismissible) because it's a layout element on a plugin-owned
     * page — wp.org guideline 11 only requires dismissibility for site-
     * wide notices and dashboard widgets, not for content rendered
     * inside the plugin's own admin screens.
     */
    public function render_sidebar_card() {
        $pricing_url = self::PRICING_URL . '&utm_term=settings-sidebar';
        $compare_url = 'https://myfeeds.site/#pricing?utm_source=wp-plugin-free&utm_medium=settings-sidebar&utm_term=compare-link';
        ?>
        <div class="myfeeds-upsell-card">
            <h3><?php esc_html_e('Get more from MyFeeds', 'myfeeds-affiliate-feed-manager'); ?></h3>
            <p class="myfeeds-upsell-sub">
                <?php esc_html_e('MyFeeds Pro and Business are separate paid plugins available at myfeeds.site.', 'myfeeds-affiliate-feed-manager'); ?>
            </p>
            <ul class="myfeeds-upsell-features">
                <li><?php esc_html_e('Multi-feed management', 'myfeeds-affiliate-feed-manager'); ?></li>
                <li><?php esc_html_e('Carousel block', 'myfeeds-affiliate-feed-manager'); ?></li>
                <li><?php esc_html_e('Card design editor with Google Fonts', 'myfeeds-affiliate-feed-manager'); ?></li>
                <li><?php esc_html_e('Click + conversion analytics', 'myfeeds-affiliate-feed-manager'); ?></li>
            </ul>
            <a href="<?php echo esc_url($pricing_url); ?>" target="_blank" rel="noopener noreferrer" class="myfeeds-upsell-cta">
                <?php esc_html_e('See Pro & Business →', 'myfeeds-affiliate-feed-manager'); ?>
            </a>
            <a href="<?php echo esc_url($compare_url); ?>" target="_blank" rel="noopener noreferrer" class="myfeeds-upsell-secondary">
                <?php esc_html_e('Compare plans', 'myfeeds-affiliate-feed-manager'); ?>
            </a>
        </div>
        <?php
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
