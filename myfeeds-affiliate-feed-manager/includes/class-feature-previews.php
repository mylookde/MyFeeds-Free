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
        $version = defined('MYFEEDS_VERSION') ? MYFEEDS_VERSION : null;
        wp_enqueue_style(
            'myfeeds-feature-previews',
            plugins_url('../assets/feature-previews.css', __FILE__),
            array(),
            $version
        );
        wp_enqueue_script(
            'myfeeds-feature-previews',
            plugins_url('../assets/feature-previews.js', __FILE__),
            array(),
            $version,
            true
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
            'title'      => __('Turn your blog into an affiliate shop.', 'myfeeds-affiliate-feed-manager'),
            'subtitle'   => __('A blog post earns once. A shop earns every day. Every product you already import becomes a browsable storefront on your own domain — visitors browse, filter, return; search engines index it.', 'myfeeds-affiliate-feed-manager'),
            'cta_label'  => __('Start 14-day free trial', 'myfeeds-affiliate-feed-manager'),
            'cta_url'    => self::CHECKOUT_BUSINESS_URL . '&utm_source=wp-plugin-free&utm_medium=feature-preview&utm_term=shop',
            'cta_note'   => __('No commitment, cancel any time', 'myfeeds-affiliate-feed-manager'),
            'screenshots' => array(
                array(
                    'image'       => $this->preview_image_url('shop-storefront.png'),
                    'placeholder' => __('Storefront grid', 'myfeeds-affiliate-feed-manager'),
                    'caption'     => __('A real product grid your visitors browse and filter — on your own domain, with your affiliate links.', 'myfeeds-affiliate-feed-manager'),
                ),
                array(
                    'image'       => $this->preview_image_url('shop-categories.png'),
                    'placeholder' => __('Category taxonomy', 'myfeeds-affiliate-feed-manager'),
                    'caption'     => __('Build and edit your category structure in one place — titles, slugs and meta tags ready for search engines.', 'myfeeds-affiliate-feed-manager'),
                ),
            ),
            'benefits' => array(
                __('A real storefront on your own domain — visitors browse, click out, you earn the commission', 'myfeeds-affiliate-feed-manager'),
                __('SEO-ready category pages — edit titles, slugs and meta tags in bulk from one screen', 'myfeeds-affiliate-feed-manager'),
                __('Smart sidebar filtering tailored to your product catalog', 'myfeeds-affiliate-feed-manager'),
                __('Schema, OpenGraph, sitemap and robots.txt handled automatically', 'myfeeds-affiliate-feed-manager'),
                __('Best-converting products surface first, automatically', 'myfeeds-affiliate-feed-manager'),
                __('Built on the same feeds you already import — zero new setup', 'myfeeds-affiliate-feed-manager'),
            ),
        ));
    }

    public function render_myfeeds_card_design() {
        $this->render_preview(array(
            'eyebrow'    => __('Pro feature', 'myfeeds-affiliate-feed-manager'),
            'tier'       => 'PRO',
            'title'      => __('Make product cards look like your brand.', 'myfeeds-affiliate-feed-manager'),
            'subtitle'   => __('Generic plugin cards kill conversions. Pick colours, fonts, spacing and shadows in a visual editor — see every change live on real product data. No CSS, no theme overrides, no broken mobile layouts.', 'myfeeds-affiliate-feed-manager'),
            'cta_label'  => __('Start 7-day free trial', 'myfeeds-affiliate-feed-manager'),
            'cta_url'    => self::CHECKOUT_PRO_URL . '&utm_source=wp-plugin-free&utm_medium=feature-preview&utm_term=card-design',
            'cta_note'   => __('No commitment, cancel any time', 'myfeeds-affiliate-feed-manager'),
            'screenshots' => array(
                array(
                    'image'       => $this->preview_image_url('card-design.png'),
                    'placeholder' => __('Card design editor with live preview', 'myfeeds-affiliate-feed-manager'),
                    'caption'     => __('Move a slider on the left, the card on the right updates instantly. No save-and-reload loop.', 'myfeeds-affiliate-feed-manager'),
                ),
            ),
            'benefits' => array(
                __('Live preview on real product data — see every change instantly', 'myfeeds-affiliate-feed-manager'),
                __('Colours for card, title, price, discount badge and button', 'myfeeds-affiliate-feed-manager'),
                __('Full Google Fonts catalog plus system fonts', 'myfeeds-affiliate-feed-manager'),
                __('Sliders for spacing, padding, corner radius and shadow', 'myfeeds-affiliate-feed-manager'),
                __('Mobile and desktop variants in the same editor', 'myfeeds-affiliate-feed-manager'),
                __('Save once, applies to every product card on your site', 'myfeeds-affiliate-feed-manager'),
            ),
        ));
    }

    public function render_myfeeds_analytics() {
        $this->render_preview(array(
            'eyebrow'    => __('Pro feature', 'myfeeds-affiliate-feed-manager'),
            'tier'       => 'PRO',
            'title'      => __('See which products actually pay you.', 'myfeeds-affiliate-feed-manager'),
            'subtitle'   => __('Stop guessing. Server-side click tracking plus automatic nightly conversion sync from your affiliate networks — know exactly which post earns, which product converts, which brand pays best, in one dashboard.', 'myfeeds-affiliate-feed-manager'),
            'cta_label'  => __('Start 7-day free trial', 'myfeeds-affiliate-feed-manager'),
            'cta_url'    => self::CHECKOUT_PRO_URL . '&utm_source=wp-plugin-free&utm_medium=feature-preview&utm_term=analytics',
            'cta_note'   => __('No commitment, cancel any time', 'myfeeds-affiliate-feed-manager'),
            'screenshots' => array(
                array(
                    'image'       => $this->preview_image_url('analytics-overview.png'),
                    'placeholder' => __('Analytics overview', 'myfeeds-affiliate-feed-manager'),
                    'caption'     => __('Total clicks, earnings and EPC at a glance — broken down by post, product, brand and network.', 'myfeeds-affiliate-feed-manager'),
                ),
                array(
                    'image'       => $this->preview_image_url('analytics-insights.png'),
                    'placeholder' => __('Insight cards', 'myfeeds-affiliate-feed-manager'),
                    'caption'     => __('Action cards surface what to do next: top earners to amplify, dead products to swap, posts with no clicks to revisit.', 'myfeeds-affiliate-feed-manager'),
                ),
            ),
            'benefits' => array(
                __('Earnings + EPC per post, product, brand and network', 'myfeeds-affiliate-feed-manager'),
                __('Automatic nightly conversion sync from your affiliate networks', 'myfeeds-affiliate-feed-manager'),
                __('Top performers and dead products surfaced as action items', 'myfeeds-affiliate-feed-manager'),
                __('Server-side tracking with self-click exclusion — clean numbers', 'myfeeds-affiliate-feed-manager'),
                __('Day-over-day deltas to spot what is rising and what is falling', 'myfeeds-affiliate-feed-manager'),
                __('Optional Google Analytics 4 event push for your own stack', 'myfeeds-affiliate-feed-manager'),
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
                                <button type="button"
                                        class="myfeeds-preview-zoom-trigger"
                                        data-myfeeds-zoom-src="<?php echo esc_url($shot['image']); ?>"
                                        data-myfeeds-zoom-caption="<?php echo esc_attr($shot['caption'] ?? ''); ?>"
                                        aria-label="<?php echo esc_attr__('Open larger view', 'myfeeds-affiliate-feed-manager'); ?>">
                                    <img src="<?php echo esc_url($shot['image']); ?>" alt="<?php echo esc_attr($shot['placeholder']); ?>">
                                </button>
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
                    <?php
                    printf(
                        /* translators: %s is an anchor tag wrapping the text "myfeeds.site" */
                        esc_html__('MyFeeds Pro and MyFeeds Business are separate paid plugins available at %s. Activating a license unlocks the feature on this site.', 'myfeeds-affiliate-feed-manager'),
                        '<a href="https://myfeeds.site/?utm_source=wp-plugin-free&utm_medium=feature-preview&utm_term=disclaimer" target="_blank" rel="noopener noreferrer">myfeeds.site</a>'
                    );
                    ?>
                </p>
            </div>
        </div>

        <div class="myfeeds-preview-lightbox" aria-hidden="true" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__('Screenshot zoom view', 'myfeeds-affiliate-feed-manager'); ?>">
            <button type="button" class="myfeeds-preview-lightbox-close" aria-label="<?php echo esc_attr__('Close', 'myfeeds-affiliate-feed-manager'); ?>">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="6" y1="18" x2="18" y2="6"/></svg>
            </button>
            <figure class="myfeeds-preview-lightbox-figure">
                <img class="myfeeds-preview-lightbox-image" src="" alt="">
                <figcaption class="myfeeds-preview-lightbox-caption"></figcaption>
            </figure>
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
