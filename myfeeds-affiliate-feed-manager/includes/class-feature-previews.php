<?php
/**
 * Marketing-only preview pages for Pro/Business features.
 *
 * Adds three submenu entries (Shop, Card Design, Analytics) with a
 * brand-coloured PRO/BUSINESS badge. Each one opens a static info
 * page describing the feature with screenshots, benefit bullets, and
 * a direct trial CTA to the Freemius checkout.
 *
 * IMPORTANT — wp.org guideline 5 compliance:
 * The pages here MUST stay pure marketing. No real editor UI, no
 * disabled inputs, no fake-data dashboards behind an overlay. The
 * actual Pro/Business feature code lives only in the MyFeeds Pro
 * plugin repository, never here. What we render is the same kind of
 * surface Yoast renders for its Premium Workouts page: a page that
 * describes a feature and links to checkout, nothing more.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Feature_Previews {

    const CHECKOUT_PRO_URL = 'https://checkout.freemius.com/plugin/21336/plan/35610/?trial=paid';
    const CHECKOUT_BUSINESS_URL = 'https://checkout.freemius.com/plugin/21336/plan/48994/?trial=paid';

    public function init() {
        add_action('admin_menu', array($this, 'register_submenus'), 95);
        add_action('admin_head', array($this, 'print_submenu_badge_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    public function register_submenus() {
        $previews = $this->preview_configs();
        foreach ($previews as $config) {
            add_submenu_page(
                'myfeeds-feeds',
                esc_html($config['page_title']),
                $this->menu_label_with_badge($config['menu_title'], $config['tier']),
                'manage_options',
                $config['slug'],
                array($this, 'render_' . str_replace('-', '_', $config['slug']))
            );
        }
    }

    public function enqueue_styles($hook) {
        $slugs = array_column($this->preview_configs(), 'slug');
        $allowed = array_map(function ($s) { return 'myfeeds_page_' . $s; }, $slugs);
        if (!in_array($hook, $allowed, true)) {
            return;
        }
        wp_enqueue_style(
            'myfeeds-feature-previews',
            plugins_url('../assets/feature-previews.css', __FILE__),
            array(),
            defined('MYFEEDS_VERSION') ? MYFEEDS_VERSION : null
        );
    }

    /**
     * Glow-tinted PRO / BUSINESS badge in the submenu list. wp-admin
     * sidebar is dark, so a brand-gradient pill with a soft inner glow
     * reads as "premium tier" without resorting to the gold/yellow
     * crown-icon convention.
     */
    public function print_submenu_badge_styles() {
        ?>
        <style>
            .myfeeds-tier-badge {
                display: inline-block;
                margin-left: 6px;
                padding: 1px 7px;
                border-radius: 10px;
                font-size: 9px;
                font-weight: 700;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                color: #fff;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                box-shadow: 0 0 8px rgba(118, 75, 162, 0.55), 0 1px 2px rgba(0, 0, 0, 0.2);
                vertical-align: middle;
                line-height: 1.4;
            }
            #adminmenu .myfeeds-tier-badge {
                /* WP collapses small text in the submenu by default — re-assert. */
                font-size: 9px;
            }
        </style>
        <?php
    }

    /**
     * Return the URL of a preview screenshot if the PNG exists in
     * assets/feature-previews/, or an empty string. An empty image
     * URL makes render_preview() fall back to the dashed placeholder
     * box, so the page degrades gracefully while screenshots are
     * still being prepared.
     */
    private function preview_image_url($filename) {
        if (!defined('MYFEEDS_PLUGIN_FILE')) {
            return '';
        }
        $relative = 'assets/feature-previews/' . $filename;
        $absolute = plugin_dir_path(MYFEEDS_PLUGIN_FILE) . $relative;
        if (!file_exists($absolute)) {
            return '';
        }
        return plugins_url($relative, MYFEEDS_PLUGIN_FILE);
    }

    private function menu_label_with_badge($label, $tier) {
        // Submenu labels accept inline HTML. Tier comes from a closed
        // set ("PRO" or "BUSINESS"), never user input, but esc_html the
        // label anyway for defense in depth.
        return esc_html($label) . ' <span class="myfeeds-tier-badge">' . esc_html($tier) . '</span>';
    }

    // =========================================================================
    // PAGE RENDERERS
    // =========================================================================

    public function render_myfeeds_shop() {
        $this->render_preview(array(
            'eyebrow'    => __('Business feature', 'myfeeds-affiliate-feed-manager'),
            'tier'       => 'BUSINESS',
            'title'      => __('Affiliate Storefront System', 'myfeeds-affiliate-feed-manager'),
            'subtitle'   => __('Turn your blog into a full affiliate shop. Storefront, category taxonomy, advanced filters, SEO engine — all driven by the same product feeds you already import.', 'myfeeds-affiliate-feed-manager'),
            'cta_label'  => __('Start 14-day free trial', 'myfeeds-affiliate-feed-manager'),
            'cta_url'    => self::CHECKOUT_BUSINESS_URL . '&utm_source=wp-plugin-free&utm_medium=feature-preview&utm_term=shop',
            'cta_note'   => __('No commitment, cancel any time', 'myfeeds-affiliate-feed-manager'),
            'screenshots' => array(
                array(
                    'image'       => $this->preview_image_url('shop-storefront.png'),
                    'placeholder' => __('Storefront grid', 'myfeeds-affiliate-feed-manager'),
                    'caption'     => __('A full product grid on your own domain — no WooCommerce required.', 'myfeeds-affiliate-feed-manager'),
                ),
                array(
                    'image'       => $this->preview_image_url('shop-categories.png'),
                    'placeholder' => __('Category taxonomy', 'myfeeds-affiliate-feed-manager'),
                    'caption'     => __('Nested categories with smart product matching from your feeds.', 'myfeeds-affiliate-feed-manager'),
                ),
            ),
            'benefits' => array(
                __('Affiliate shop on your own domain, indexed by Google', 'myfeeds-affiliate-feed-manager'),
                __('Nested category URLs (/shop/men/jackets/) with redirects', 'myfeeds-affiliate-feed-manager'),
                __('Sidebar filters: color, brand, size, attribute, merchant', 'myfeeds-affiliate-feed-manager'),
                __('Built-in SEO engine: meta, schema, OG tags, sitemap', 'myfeeds-affiliate-feed-manager'),
                __('Click-driven product sorting (your best-converters first)', 'myfeeds-affiliate-feed-manager'),
            ),
        ));
    }

    public function render_myfeeds_card_design() {
        $this->render_preview(array(
            'eyebrow'    => __('Pro feature', 'myfeeds-affiliate-feed-manager'),
            'tier'       => 'PRO',
            'title'      => __('Visual Card Design Editor', 'myfeeds-affiliate-feed-manager'),
            'subtitle'   => __('Match the product cards to your brand without writing CSS. Pick colours, spacing, shadows and corner radii from a visual editor, and preview the result on real product data live.', 'myfeeds-affiliate-feed-manager'),
            'cta_label'  => __('Start 7-day free trial', 'myfeeds-affiliate-feed-manager'),
            'cta_url'    => self::CHECKOUT_PRO_URL . '&utm_source=wp-plugin-free&utm_medium=feature-preview&utm_term=card-design',
            'cta_note'   => __('No commitment, cancel any time', 'myfeeds-affiliate-feed-manager'),
            'screenshots' => array(
                array(
                    'image'       => $this->preview_image_url('card-design.png'),
                    'placeholder' => __('Card design editor with live preview', 'myfeeds-affiliate-feed-manager'),
                    'caption'     => __('Visual sliders on the left, live product-card preview on the right — change a value, see it instantly.', 'myfeeds-affiliate-feed-manager'),
                ),
            ),
            'benefits' => array(
                __('Live preview on real product data — no save-and-reload loop', 'myfeeds-affiliate-feed-manager'),
                __('Custom colours for brand, title, price, discount, button', 'myfeeds-affiliate-feed-manager'),
                __('Full Google Fonts catalog, plus system fonts', 'myfeeds-affiliate-feed-manager'),
                __('Spacing, padding, corner-radius, image-aspect controls', 'myfeeds-affiliate-feed-manager'),
                __('Mobile + desktop variants in the same editor', 'myfeeds-affiliate-feed-manager'),
            ),
        ));
    }

    public function render_myfeeds_analytics() {
        $this->render_preview(array(
            'eyebrow'    => __('Pro feature', 'myfeeds-affiliate-feed-manager'),
            'tier'       => 'PRO',
            'title'      => __('Click + Conversion Analytics', 'myfeeds-affiliate-feed-manager'),
            'subtitle'   => __('Stop guessing which products earn. See clicks, conversions and earnings per post, per product, per brand, per network — synced nightly from AWIN, CJ, Tradedoubler, Rakuten, Impact and more.', 'myfeeds-affiliate-feed-manager'),
            'cta_label'  => __('Start 7-day free trial', 'myfeeds-affiliate-feed-manager'),
            'cta_url'    => self::CHECKOUT_PRO_URL . '&utm_source=wp-plugin-free&utm_medium=feature-preview&utm_term=analytics',
            'cta_note'   => __('No commitment, cancel any time', 'myfeeds-affiliate-feed-manager'),
            'screenshots' => array(
                array(
                    'image'       => $this->preview_image_url('analytics-overview.png'),
                    'placeholder' => __('Analytics overview', 'myfeeds-affiliate-feed-manager'),
                    'caption'     => __('Total clicks, earnings, EPC and daily-click chart at a glance.', 'myfeeds-affiliate-feed-manager'),
                ),
                array(
                    'image'       => $this->preview_image_url('analytics-insights.png'),
                    'placeholder' => __('Insight cards', 'myfeeds-affiliate-feed-manager'),
                    'caption'     => __('Top earning posts, top-performing brands, dead products, posts with no clicks — every signal in one place.', 'myfeeds-affiliate-feed-manager'),
                ),
            ),
            'benefits' => array(
                __('Server-side click tracking with self-click exclusion', 'myfeeds-affiliate-feed-manager'),
                __('Nightly conversion sync from 8 affiliate networks', 'myfeeds-affiliate-feed-manager'),
                __('Earnings + EPC per post, product, brand, network', 'myfeeds-affiliate-feed-manager'),
                __('Top performers, dead products, inactive posts surfaced', 'myfeeds-affiliate-feed-manager'),
                __('Optional Google Analytics 4 event push', 'myfeeds-affiliate-feed-manager'),
            ),
        ));
    }

    // =========================================================================
    // SHARED PAGE TEMPLATE
    // =========================================================================

    private function render_preview(array $config) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'myfeeds-affiliate-feed-manager'));
        }
        ?>
        <div class="wrap myfeeds-preview-wrap">

            <div class="myfeeds-preview-hero">
                <div class="myfeeds-preview-hero-text">
                    <div class="myfeeds-preview-eyebrow">
                        <span class="myfeeds-tier-badge"><?php echo esc_html($config['tier']); ?></span>
                        <span class="myfeeds-preview-eyebrow-text"><?php echo esc_html($config['eyebrow']); ?></span>
                    </div>
                    <h1><?php echo esc_html($config['title']); ?></h1>
                    <p class="myfeeds-preview-subtitle"><?php echo esc_html($config['subtitle']); ?></p>

                    <ul class="myfeeds-preview-benefits">
                        <?php foreach ($config['benefits'] as $benefit) : ?>
                            <li><?php echo esc_html($benefit); ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="myfeeds-preview-cta-row">
                        <a href="<?php echo esc_url($config['cta_url']); ?>" target="_blank" rel="noopener noreferrer" class="myfeeds-preview-cta">
                            <?php echo esc_html($config['cta_label']); ?>
                        </a>
                        <span class="myfeeds-preview-cta-note"><?php echo esc_html($config['cta_note']); ?></span>
                    </div>
                </div>
            </div>

            <?php if (!empty($config['screenshots'])) : ?>
                <div class="myfeeds-preview-screenshots">
                    <?php foreach ($config['screenshots'] as $shot) : ?>
                        <figure class="myfeeds-preview-screenshot">
                            <?php if (!empty($shot['image'])) : ?>
                                <img src="<?php echo esc_url($shot['image']); ?>" alt="<?php echo esc_attr($shot['placeholder']); ?>">
                            <?php else : ?>
                                <div class="myfeeds-preview-screenshot-placeholder">
                                    <span><?php echo esc_html($shot['placeholder']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($shot['caption'])) : ?>
                                <figcaption><?php echo esc_html($shot['caption']); ?></figcaption>
                            <?php endif; ?>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="myfeeds-preview-footer">
                <p class="myfeeds-preview-disclaimer">
                    <?php esc_html_e('MyFeeds Pro and MyFeeds Business are separate paid plugins available at myfeeds.site. Activating a license unlocks the feature on this site.', 'myfeeds-affiliate-feed-manager'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // CONFIG
    // =========================================================================

    private function preview_configs() {
        return array(
            array(
                'slug'        => 'myfeeds-shop',
                'menu_title'  => __('Shop', 'myfeeds-affiliate-feed-manager'),
                'page_title'  => __('MyFeeds — Shop', 'myfeeds-affiliate-feed-manager'),
                'tier'        => 'BUSINESS',
            ),
            array(
                'slug'        => 'myfeeds-card-design',
                'menu_title'  => __('Card Design', 'myfeeds-affiliate-feed-manager'),
                'page_title'  => __('MyFeeds — Card Design', 'myfeeds-affiliate-feed-manager'),
                'tier'        => 'PRO',
            ),
            array(
                'slug'        => 'myfeeds-analytics',
                'menu_title'  => __('Analytics', 'myfeeds-affiliate-feed-manager'),
                'page_title'  => __('MyFeeds — Analytics', 'myfeeds-affiliate-feed-manager'),
                'tier'        => 'PRO',
            ),
        );
    }
}
