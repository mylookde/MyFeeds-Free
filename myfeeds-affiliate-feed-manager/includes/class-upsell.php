<?php
/**
 * Free-to-Pro upsell surfaces.
 *
 * Renders the small, dismissible info banner that sits at the top of the
 * MyFeeds admin pages and adds a "Go Pro" entry to the MyFeeds submenu.
 * Both are informational links to myfeeds.site — the plugin itself
 * contains no gated or locked UI (wp.org Guideline 5).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Upsell {

    const DISMISS_META_KEY = 'myfeeds_upsell_dismissed_v1';
    const PRICING_URL      = 'https://myfeeds.site/?utm_source=wp-plugin-free&utm_medium=admin-menu';

    public function init() {
        add_action('admin_menu', array($this, 'add_go_pro_submenu'), 100);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_dismiss_handler'));
        add_action('admin_notices', array($this, 'render_dismissible_banner'));
        add_action('wp_ajax_myfeeds_dismiss_upsell', array($this, 'ajax_dismiss'));
        add_action('admin_footer', array($this, 'open_go_pro_in_new_tab'));
    }

    public function add_go_pro_submenu() {
        global $submenu;
        if (!isset($submenu['myfeeds-feeds'])) {
            return;
        }
        $submenu['myfeeds-feeds'][] = array(
            __('Go Pro ↗', 'myfeeds-affiliate-feed-manager'),
            'manage_options',
            esc_url(self::PRICING_URL),
        );
    }

    /**
     * WP admin renders submenu anchors without a target attribute and there
     * is no filter to change that. Tag the Go Pro link via JS so it opens
     * in a new tab. Tiny inline script, runs on every admin page because
     * the sidebar menu is always present.
     */
    public function open_go_pro_in_new_tab() {
        $selector    = '#adminmenu a[href="' . self::PRICING_URL . '"]';
        $selector_js = wp_json_encode($selector);
        $script      = '(function(){var a=document.querySelector(' . $selector_js . ');if(a){a.target="_blank";a.rel="noopener noreferrer";}})();';
        wp_print_inline_script_tag($script);
    }

    public function enqueue_dismiss_handler($hook) {
        if (!$this->is_myfeeds_screen()) {
            return;
        }
        $user_id = get_current_user_id();
        if (!$user_id || get_user_meta($user_id, self::DISMISS_META_KEY, true)) {
            return;
        }
        // The MyFeeds admin bundle is registered by class-feed-manager.php on
        // every myfeeds-* screen; we piggyback on it for our tiny handler.
        $data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('myfeeds_dismiss_upsell'),
        );
        wp_add_inline_script(
            'myfeeds-admin',
            'window.myfeedsUpsellDismiss = ' . wp_json_encode($data) . ';',
            'before'
        );
        wp_add_inline_script('myfeeds-admin', $this->dismiss_script(), 'after');
    }

    public function render_dismissible_banner() {
        if (!$this->is_myfeeds_screen()) {
            return;
        }
        $user_id = get_current_user_id();
        if (!$user_id || get_user_meta($user_id, self::DISMISS_META_KEY, true)) {
            return;
        }
        ?>
        <div class="notice notice-info is-dismissible myfeeds-upsell-banner">
            <p>
                <strong><?php esc_html_e('Need more than one feed?', 'myfeeds-affiliate-feed-manager'); ?></strong>
                <?php esc_html_e('Pro adds multi-feed management and a carousel block. Premium adds an analytics dashboard and the visual card design editor.', 'myfeeds-affiliate-feed-manager'); ?>
                <a href="<?php echo esc_url(self::PRICING_URL); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e('See plans →', 'myfeeds-affiliate-feed-manager'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public function ajax_dismiss() {
        check_ajax_referer('myfeeds_dismiss_upsell');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }
        update_user_meta(get_current_user_id(), self::DISMISS_META_KEY, 1);
        wp_send_json_success();
    }

    /**
     * The banner is only shown on the main MyFeeds feeds page. Other
     * MyFeeds-* admin screens (dark-themed contact page, mapping editor,
     * settings) carry their own visual language, where a default WP
     * admin notice would clash with the surrounding design.
     */
    private function is_myfeeds_screen() {
        // Reading $_GET['page'] for admin-screen detection is read-only and
        // does not require a nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === 'myfeeds-feeds';
    }

    private function dismiss_script() {
        return <<<'JS'
(function () {
    var cfg = window.myfeedsUpsellDismiss;
    if (!cfg) { return; }
    var banner = document.querySelector('.myfeeds-upsell-banner');
    if (!banner) { return; }
    banner.addEventListener('click', function (event) {
        if (!event.target.classList.contains('notice-dismiss')) { return; }
        var body = new FormData();
        body.append('action', 'myfeeds_dismiss_upsell');
        body.append('_wpnonce', cfg.nonce);
        fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body });
    });
})();
JS;
    }
}
