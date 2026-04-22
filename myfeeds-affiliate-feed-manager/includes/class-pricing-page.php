<?php
/**
 * MyFeeds Custom Pricing Page
 * Replaces the default Freemius pricing page with a branded experience.
 * Uses Freemius JavaScript Checkout API for payment flow.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Pricing_Page {

    public function init() {
        add_action('admin_menu', array($this, 'replace_pricing_page'), 999);
    }

    /**
     * Hide Freemius pricing menu, register our own, redirect old slug
     */
    public function replace_pricing_page() {
        // 1. Hide the Freemius pricing submenu via CSS
        add_action('admin_head', function() {
            echo '<style>
                #toplevel_page_myfeeds-feeds ul.wp-submenu li a[href*="myfeeds-feeds-pricing"] { display: none !important; }
            </style>';
        });

        // 2. Register our own pricing page with a non-colliding slug
        add_submenu_page(
            'myfeeds-feeds',
            'Manage Plan',
            'Manage Plan',
            'manage_options',
            'myfeeds-custom-pricing',
            array($this, 'render_pricing_page')
        );

        // 3. Redirect old Freemius pricing URL to our page (but NOT during checkout flow)
        if (isset($_GET['page']) && $_GET['page'] === 'myfeeds-feeds-pricing' && !isset($_GET['checkout'])) {
            wp_redirect(admin_url('admin.php?page=myfeeds-custom-pricing'));
            exit;
        }
    }

    /**
     * Detect current plan
     */
    private function get_current_plan() {
        if (class_exists('MyFeeds_Plan_Limits')) {
            if (MyFeeds_Plan_Limits::is_premium()) {
                return 'premium';
            }
            if (MyFeeds_Plan_Limits::is_pro()) {
                return 'pro';
            }
        }
        return 'free';
    }

    /**
     * Get Freemius account page URL (for downgrade link)
     */
    private function get_account_url() {
        if (!function_exists('my_pp')) {
            return '#';
        }
        return admin_url('admin.php?page=myfeeds-feeds-account');
    }

    /**
     * Render the full pricing page
     */
    public function render_pricing_page() {
        $current_plan = $this->get_current_plan();
        $account_url  = $this->get_account_url();
        $freemius_pricing_url = admin_url('admin.php?page=myfeeds-feeds-pricing');

        // Plan features
        $free_features = array(
            '1 feed',
            '3 products in posts',
            'Grid layout',
            'Smart search',
            'Manual sync',
        );
        $pro_features = array(
            'Up to 5 feeds',
            'Unlimited products',
            'Carousel layout',
            'Daily auto-sync',
            'Quick sync',
            'Email support',
        );
        $premium_features = array(
            'Unlimited feeds',
            'Card design editor',
            'Google Fonts',
            'Custom font upload',
            'Drag & drop element order',
            'Priority support',
        );

        ?>
        <div class="wrap myfeeds-pricing-wrap">
            <style>
                /* Override WP Admin background for this page */
                .myfeeds-pricing-wrap {
                    margin: -10px -20px -20px -20px !important;
                    padding: 0 !important;
                    max-width: none !important;
                }
                body.wp-admin #wpcontent,
                body.wp-admin #wpbody,
                body.wp-admin #wpbody-content {
                    background: #13111C !important;
                }
                .myfeeds-pricing-container {
                    background: #13111C;
                    min-height: calc(100vh - 32px);
                    padding: 48px 32px 60px;
                }
                .myfeeds-pricing-wrap > h1:first-of-type {
                    display: none;
                }
                #wpfooter {
                    display: none;
                }

                .myfeeds-pricing-wrap,
                .myfeeds-pricing-wrap * {
                    box-sizing: border-box;
                }
                .myfeeds-pricing-wrap a {
                    text-decoration: none;
                }

                /* ── Header ── */
                .myfeeds-pricing-header {
                    text-align: center;
                    padding: 0 0 28px;
                }
                .myfeeds-pricing-header h1 {
                    font-size: 28px;
                    font-weight: 700;
                    color: #f0eef6;
                    margin: 0 0 6px;
                }
                .myfeeds-pricing-header p {
                    font-size: 15px;
                    color: #9e98b5;
                    margin: 0 0 24px;
                }

                /* ── Billing toggle ── */
                .myfeeds-billing-toggle {
                    display: inline-flex;
                    background: #1e1b2e;
                    border-radius: 20px;
                    padding: 3px;
                }
                .myfeeds-billing-toggle button {
                    padding: 6px 18px;
                    border: 1px solid transparent;
                    border-radius: 17px;
                    background: transparent;
                    font-size: 13px;
                    font-weight: 500;
                    color: #9e98b5;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    line-height: 1.4;
                }
                .myfeeds-billing-toggle button.active {
                    background: #2a2640;
                    border-color: #3d3757;
                    color: #f0eef6;
                    font-weight: 500;
                }
                .myfeeds-billing-toggle .myfeeds-save-label {
                    color: #a78bfa;
                    font-weight: 600;
                }

                /* ── Cards grid ── */
                .myfeeds-pricing-cards {
                    display: grid;
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                    gap: 16px;
                    align-items: start;
                    max-width: 860px;
                    margin: 0 auto;
                }

                /* ── Single card ── */
                .myfeeds-plan-card {
                    position: relative;
                    background: #1e1b2e;
                    border: 1px solid #2e2a42;
                    border-radius: 12px;
                    padding: 28px;
                    display: flex;
                    flex-direction: column;
                    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
                }
                .myfeeds-plan-card[data-plan="free"]:hover {
                    border-color: #3d3757;
                    transform: translateY(-2px);
                }
                .myfeeds-plan-card[data-plan="pro"] {
                    border: 2px solid #667eea;
                }
                .myfeeds-plan-card[data-plan="pro"]:hover {
                    transform: translateY(-4px);
                    box-shadow: 0 8px 30px rgba(102,126,234,0.2);
                }
                .myfeeds-plan-card[data-plan="premium"] {
                    background: linear-gradient(180deg, #211d33 0%, #1e1b2e 100%);
                    border: 1px solid #764ba2;
                }
                .myfeeds-plan-card[data-plan="premium"]:hover {
                    transform: translateY(-4px);
                    box-shadow: 0 8px 30px rgba(118,75,162,0.25);
                }

                /* ── Badge ── */
                .myfeeds-plan-badge {
                    position: absolute;
                    top: -11px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: #fff;
                    font-size: 11px;
                    font-weight: 600;
                    padding: 3px 14px;
                    border-radius: 10px;
                    white-space: nowrap;
                }
                .myfeeds-badge-premium {
                    background: #764ba2 !important;
                }

                /* ── Plan header ── */
                .myfeeds-plan-name {
                    font-size: 14px;
                    font-weight: 500;
                    margin: 0 0 4px;
                }
                .myfeeds-plan-tagline {
                    font-size: 12px;
                    color: #7a7394;
                    margin: 0 0 18px;
                }

                /* ── Price block ── */
                .myfeeds-plan-price-block {
                    margin-bottom: 20px;
                    min-height: 60px;
                }
                .myfeeds-plan-price {
                    font-size: 42px;
                    font-weight: 700;
                    color: #f0eef6;
                    line-height: 1;
                }
                .myfeeds-plan-price .period {
                    font-size: 13px;
                    font-weight: 400;
                    color: #7a7394;
                }
                .myfeeds-plan-billed {
                    font-size: 12px;
                    color: #7a7394;
                    margin-top: 4px;
                    display: none;
                }

                /* ── Features ── */
                .myfeeds-plan-features {
                    margin-bottom: 22px;
                    padding-bottom: 22px;
                    border-bottom: 1px solid #2e2a42;
                }
                .myfeeds-features-intro {
                    font-size: 12px;
                    font-weight: 600;
                    color: #7a7394;
                    text-transform: uppercase;
                    letter-spacing: 0.3px;
                    margin: 0 0 10px;
                }
                .myfeeds-feature-list {
                    list-style: none;
                    margin: 0;
                    padding: 0;
                }
                .myfeeds-feature-list li {
                    font-size: 13px;
                    color: #9e98b5;
                    padding: 4px 0;
                    display: flex;
                    align-items: flex-start;
                    gap: 8px;
                    line-height: 1.4;
                }
                .myfeeds-plan-card[data-plan="pro"] .myfeeds-feature-list li,
                .myfeeds-plan-card[data-plan="premium"] .myfeeds-feature-list li {
                    color: #c4bfda;
                }
                .myfeeds-feature-list li .check {
                    flex-shrink: 0;
                    font-weight: 700;
                    font-size: 14px;
                    line-height: 1.3;
                }

                /* ── CTA buttons ── */
                .myfeeds-plan-cta {
                    display: block;
                    width: 100%;
                    text-align: center;
                    padding: 12px 0;
                    border-radius: 10px;
                    font-size: 14px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    line-height: 1.4;
                    margin-top: auto;
                }
                .myfeeds-plan-cta.cta-current {
                    border: 1px solid #3d3757;
                    background: #2a2640;
                    color: #7a7394 !important;
                    cursor: default;
                    pointer-events: none;
                }
                .myfeeds-plan-cta.cta-downgrade {
                    border: 1px solid #2e2a42;
                    background: transparent;
                    color: #7a7394 !important;
                    text-decoration: none;
                    display: block;
                    text-align: center;
                }
                .myfeeds-plan-cta.cta-downgrade:hover {
                    border-color: #3d3757;
                    color: #9e98b5 !important;
                }
                .myfeeds-plan-cta.cta-pro {
                    border: none;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: #fff !important;
                    box-shadow: 0 2px 8px rgba(102,126,234,0.25);
                }
                .myfeeds-plan-cta.cta-pro:hover {
                    box-shadow: 0 4px 20px rgba(102,126,234,0.4);
                    color: #fff !important;
                }
                .myfeeds-plan-cta.cta-premium {
                    border: 1px solid #764ba2;
                    background: rgba(118,75,162,0.15);
                    color: #a78bfa !important;
                }
                .myfeeds-plan-cta.cta-premium:hover {
                    background: rgba(118,75,162,0.3);
                    color: #a78bfa !important;
                }

                /* ── Footer ── */
                .myfeeds-pricing-footer {
                    text-align: center;
                    font-size: 13px;
                    color: #7a7394;
                    margin: 28px 0 0;
                }

                /* ── Responsive ── */
                @media (max-width: 820px) {
                    .myfeeds-pricing-cards {
                        grid-template-columns: 1fr;
                        max-width: 380px;
                    }
                }
            </style>

            <div class="myfeeds-pricing-container">

            <!-- Header -->
            <div class="myfeeds-pricing-header">
                <h1>Choose your plan</h1>
                <p>Start free, upgrade when you need more power.</p>
                <div class="myfeeds-billing-toggle">
                    <button type="button" data-cycle="monthly">Monthly</button>
                    <button type="button" class="active" data-cycle="annual">Annual &mdash; <span class="myfeeds-save-label">Save 27%</span></button>
                </div>
            </div>

            <!-- Plan cards -->
            <div class="myfeeds-pricing-cards">

                <!-- FREE -->
                <div class="myfeeds-plan-card" data-plan="free">
                    <div class="myfeeds-plan-name" style="color:#9e98b5;">Free</div>
                    <div class="myfeeds-plan-tagline">Try it out, no card required</div>
                    <div class="myfeeds-plan-price-block">
                        <div class="myfeeds-plan-price">$0 <span class="period">/mo</span></div>
                    </div>
                    <div class="myfeeds-plan-features">
                        <div class="myfeeds-features-intro">Includes</div>
                        <ul class="myfeeds-feature-list">
                            <?php foreach ($free_features as $f): ?>
                                <li><span class="check" style="color:#7a7394;">&#10003;</span> <?php echo esc_html($f); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php if ($current_plan === 'free'): ?>
                        <div class="myfeeds-plan-cta cta-current">Current plan</div>
                    <?php else: ?>
                        <a href="<?php echo esc_url($account_url); ?>" class="myfeeds-plan-cta cta-downgrade">Downgrade</a>
                    <?php endif; ?>
                </div>

                <!-- PRO -->
                <div class="myfeeds-plan-card"
                     data-plan="pro"
                     data-monthly-price="9"
                     data-annual-price="79">
                    <div class="myfeeds-plan-badge">Most popular</div>
                    <div class="myfeeds-plan-name" style="color:#667eea;">Pro</div>
                    <div class="myfeeds-plan-tagline">For serious affiliate bloggers</div>
                    <div class="myfeeds-plan-price-block">
                        <div class="myfeeds-plan-price">$<span class="price-value">9</span> <span class="period">/mo</span></div>
                        <div class="myfeeds-plan-billed"></div>
                    </div>
                    <div class="myfeeds-plan-features">
                        <div class="myfeeds-features-intro">Everything in Free, plus</div>
                        <ul class="myfeeds-feature-list">
                            <?php foreach ($pro_features as $f): ?>
                                <li><span class="check" style="color:#667eea;">&#10003;</span> <?php echo esc_html($f); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php if ($current_plan === 'pro'): ?>
                        <div class="myfeeds-plan-cta cta-current">Current plan</div>
                    <?php elseif ($current_plan === 'premium'): ?>
                        <a href="<?php echo esc_url($account_url); ?>" class="myfeeds-plan-cta cta-downgrade">Downgrade</a>
                    <?php else: ?>
                        <button type="button" class="myfeeds-plan-cta cta-pro myfeeds-checkout-btn" data-plan-id="35610" data-billing-cycle="monthly">Upgrade to Pro</button>
                    <?php endif; ?>
                </div>

                <!-- PREMIUM -->
                <div class="myfeeds-plan-card"
                     data-plan="premium"
                     data-monthly-price="17"
                     data-annual-price="149">
                    <div class="myfeeds-plan-badge myfeeds-badge-premium">Best value</div>
                    <div class="myfeeds-plan-name" style="color:#a78bfa;">Premium</div>
                    <div class="myfeeds-plan-tagline">Full creative control</div>
                    <div class="myfeeds-plan-price-block">
                        <div class="myfeeds-plan-price">$<span class="price-value">17</span> <span class="period">/mo</span></div>
                        <div class="myfeeds-plan-billed"></div>
                    </div>
                    <div class="myfeeds-plan-features">
                        <div class="myfeeds-features-intro">Everything in Pro, plus</div>
                        <ul class="myfeeds-feature-list">
                            <?php foreach ($premium_features as $f): ?>
                                <li><span class="check" style="color:#a78bfa;">&#10003;</span> <?php echo esc_html($f); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php if ($current_plan === 'premium'): ?>
                        <div class="myfeeds-plan-cta cta-current">Current plan</div>
                    <?php else: ?>
                        <button type="button" class="myfeeds-plan-cta cta-premium myfeeds-checkout-btn" data-plan-id="45293" data-billing-cycle="monthly">Upgrade to Premium</button>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Footer -->
            <p class="myfeeds-pricing-footer">7-day free trial on all paid plans &middot; Cancel anytime</p>

            </div><!-- /.myfeeds-pricing-container -->

        </div>
        <?php
        // Enqueue Freemius Checkout JS properly
        wp_enqueue_script('freemius-checkout', 'https://checkout.freemius.com/checkout.min.js', array(), null, true);

        $inline_js = "(function(){
                var wrap = document.querySelector('.myfeeds-pricing-wrap');
                if (!wrap) return;

                // Billing toggle
                var toggleBtns = wrap.querySelectorAll('.myfeeds-billing-toggle button');
                var cards = wrap.querySelectorAll('.myfeeds-plan-card[data-monthly-price]');
                var cycle = 'monthly';

                function switchCycle(newCycle) {
                    cycle = newCycle;
                    toggleBtns.forEach(function(btn) {
                        btn.classList.toggle('active', btn.getAttribute('data-cycle') === cycle);
                    });
                    cards.forEach(function(card) {
                        var priceEl = card.querySelector('.price-value');
                        var periodEl = card.querySelector('.period');
                        var billedEl = card.querySelector('.myfeeds-plan-billed');
                        var btn = card.querySelector('.myfeeds-checkout-btn');

                        if (cycle === 'annual') {
                            var yearPrice = card.getAttribute('data-annual-price');
                            if (priceEl) priceEl.textContent = yearPrice;
                            if (periodEl) periodEl.textContent = '/year';
                            var monthlyEquiv = (parseFloat(yearPrice) / 12).toFixed(2);
                            if (billedEl) {
                                billedEl.textContent = '≈ $' + monthlyEquiv + '/mo';
                                billedEl.style.display = 'block';
                            }
                        } else {
                            var moPrice = card.getAttribute('data-monthly-price');
                            if (priceEl) priceEl.textContent = moPrice;
                            if (periodEl) periodEl.textContent = '/mo';
                            if (billedEl) billedEl.style.display = 'none';
                        }

                        if (btn) btn.setAttribute('data-billing-cycle', cycle);
                    });
                }

                toggleBtns.forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        switchCycle(this.getAttribute('data-cycle'));
                    });
                });

                // Freemius Checkout
                var checkoutBtns = wrap.querySelectorAll('.myfeeds-checkout-btn');
                checkoutBtns.forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        var planId = this.getAttribute('data-plan-id');
                        var billingCycle = this.getAttribute('data-billing-cycle');

                        if (typeof FS === 'undefined' || !FS.Checkout) {
                            alert('Checkout could not be loaded. Please reload the page and try again.');
                            return;
                        }

                        var handler = FS.Checkout.configure({
                            plugin_id: '21336',
                            public_key: 'pk_7423c8dfcb1a020ea6bb674a810fe'
                        });
                        handler.open({
                            plan_id: planId,
                            billing_cycle: billingCycle,
                            success: function(response) {
                                window.location.reload();
                            }
                        });
                    });
                });

                switchCycle('annual');
            })();";

        wp_add_inline_script('freemius-checkout', $inline_js);
    }
}
