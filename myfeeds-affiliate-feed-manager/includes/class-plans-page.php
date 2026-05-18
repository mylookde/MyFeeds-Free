<?php
/**
 * "Plans & Pricing" admin page.
 *
 * Renders side-by-side cards for MyFeeds Pro and MyFeeds Business as
 * separate paid plugins. Buy buttons link directly to the Freemius
 * checkout for the corresponding plan, avoiding a marketing-site hop
 * for users who already know what they want. UTM params let us
 * attribute conversions to this surface.
 *
 * Lives behind its own submenu entry under MyFeeds so the surface is
 * discoverable from the main MyFeeds menu, not just from the upsell
 * card on the feeds screen.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Plans_Page {

    const PAGE_SLUG = 'myfeeds-plans';

    const CHECKOUT_PRO_URL = 'https://checkout.freemius.com/plugin/21336/plan/35610/?trial=paid';
    const CHECKOUT_BUSINESS_URL = 'https://checkout.freemius.com/plugin/21336/plan/48994/?trial=paid';
    const PRICING_PAGE_URL = 'https://myfeeds.site/#pricing';

    public function init() {
        add_action('admin_menu', array($this, 'register_menu'), 90);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    public function register_menu() {
        add_submenu_page(
            'myfeeds-feeds',
            __('Plans & Pricing', 'myfeeds-affiliate-feed-manager'),
            __('Plans & Pricing', 'myfeeds-affiliate-feed-manager'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render')
        );
    }

    public function enqueue_styles($hook) {
        if ($hook !== 'myfeeds_page_' . self::PAGE_SLUG) {
            return;
        }
        wp_enqueue_style(
            'myfeeds-plans-page',
            plugins_url('../assets/plans-page.css', __FILE__),
            array(),
            defined('MYFEEDS_VERSION') ? MYFEEDS_VERSION : null
        );
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'myfeeds-affiliate-feed-manager'));
        }

        $pro_url = self::CHECKOUT_PRO_URL . '&utm_source=wp-plugin-free&utm_medium=plans-page&utm_term=pro-cta';
        $business_url = self::CHECKOUT_BUSINESS_URL . '&utm_source=wp-plugin-free&utm_medium=plans-page&utm_term=business-cta';
        $compare_url = self::PRICING_PAGE_URL . '?utm_source=wp-plugin-free&utm_medium=plans-page&utm_term=compare';
        ?>
        <div class="wrap myfeeds-plans-wrap">
            <h1><?php esc_html_e('Plans & Pricing', 'myfeeds-affiliate-feed-manager'); ?></h1>

            <p class="myfeeds-plans-intro">
                <?php esc_html_e('You\'re currently on the Free plan. MyFeeds Pro and MyFeeds Business are separate paid plugins available at myfeeds.site, with additional features for higher-volume affiliate workflows.', 'myfeeds-affiliate-feed-manager'); ?>
            </p>

            <div class="myfeeds-plans-grid">

                <div class="myfeeds-plan-card">
                    <div class="myfeeds-plan-header">
                        <h2><?php esc_html_e('MyFeeds Pro', 'myfeeds-affiliate-feed-manager'); ?></h2>
                        <div class="myfeeds-plan-price">
                            <span class="myfeeds-plan-price-main">$29</span>
                            <span class="myfeeds-plan-price-unit"><?php esc_html_e('/ month', 'myfeeds-affiliate-feed-manager'); ?></span>
                        </div>
                        <p class="myfeeds-plan-price-alt"><?php esc_html_e('or $249 / year', 'myfeeds-affiliate-feed-manager'); ?></p>
                    </div>

                    <ul class="myfeeds-plan-features">
                        <li><?php esc_html_e('Manage multiple affiliate feeds', 'myfeeds-affiliate-feed-manager'); ?></li>
                        <li><?php esc_html_e('Carousel block for product showcases', 'myfeeds-affiliate-feed-manager'); ?></li>
                        <li><?php esc_html_e('Visual card design editor with Google Fonts', 'myfeeds-affiliate-feed-manager'); ?></li>
                        <li><?php esc_html_e('Click analytics dashboard', 'myfeeds-affiliate-feed-manager'); ?></li>
                        <li><?php esc_html_e('Conversion sync from major affiliate networks', 'myfeeds-affiliate-feed-manager'); ?></li>
                        <li><?php esc_html_e('Earnings + EPC overview', 'myfeeds-affiliate-feed-manager'); ?></li>
                    </ul>

                    <a href="<?php echo esc_url($pro_url); ?>" target="_blank" rel="noopener noreferrer" class="myfeeds-plan-cta">
                        <?php esc_html_e('Start 7-day free trial', 'myfeeds-affiliate-feed-manager'); ?>
                    </a>
                    <p class="myfeeds-plan-cta-note"><?php esc_html_e('No commitment, cancel any time', 'myfeeds-affiliate-feed-manager'); ?></p>
                </div>

                <div class="myfeeds-plan-card myfeeds-plan-card-featured">
                    <div class="myfeeds-plan-ribbon"><?php esc_html_e('For agencies & shops', 'myfeeds-affiliate-feed-manager'); ?></div>

                    <div class="myfeeds-plan-header">
                        <h2><?php esc_html_e('MyFeeds Business', 'myfeeds-affiliate-feed-manager'); ?></h2>
                        <div class="myfeeds-plan-price">
                            <span class="myfeeds-plan-price-main">$129</span>
                            <span class="myfeeds-plan-price-unit"><?php esc_html_e('/ month', 'myfeeds-affiliate-feed-manager'); ?></span>
                        </div>
                        <p class="myfeeds-plan-price-alt"><?php esc_html_e('or $1099 / year', 'myfeeds-affiliate-feed-manager'); ?></p>
                    </div>

                    <p class="myfeeds-plan-includes-note"><?php esc_html_e('Everything in Pro, plus:', 'myfeeds-affiliate-feed-manager'); ?></p>
                    <ul class="myfeeds-plan-features">
                        <li><?php esc_html_e('Full affiliate storefront system', 'myfeeds-affiliate-feed-manager'); ?></li>
                        <li><?php esc_html_e('Category taxonomy with smart matching', 'myfeeds-affiliate-feed-manager'); ?></li>
                        <li><?php esc_html_e('SEO engine: meta, schema, OG tags', 'myfeeds-affiliate-feed-manager'); ?></li>
                        <li><?php esc_html_e('Sitemap and robots.txt management', 'myfeeds-affiliate-feed-manager'); ?></li>
                        <li><?php esc_html_e('Advanced product filtering', 'myfeeds-affiliate-feed-manager'); ?></li>
                        <li><?php esc_html_e('Priority support', 'myfeeds-affiliate-feed-manager'); ?></li>
                    </ul>

                    <a href="<?php echo esc_url($business_url); ?>" target="_blank" rel="noopener noreferrer" class="myfeeds-plan-cta myfeeds-plan-cta-featured">
                        <?php esc_html_e('Start 14-day free trial', 'myfeeds-affiliate-feed-manager'); ?>
                    </a>
                    <p class="myfeeds-plan-cta-note"><?php esc_html_e('No commitment, cancel any time', 'myfeeds-affiliate-feed-manager'); ?></p>
                </div>

            </div>

            <p class="myfeeds-plans-footer">
                <a href="<?php echo esc_url($compare_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Compare plans in detail at myfeeds.site →', 'myfeeds-affiliate-feed-manager'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
