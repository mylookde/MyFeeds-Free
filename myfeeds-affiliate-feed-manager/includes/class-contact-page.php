<?php
/**
 * MyFeeds Custom Contact & FAQ Page
 * Replaces the default Freemius contact page with a branded dark-mode experience.
 * Matching design palette from class-pricing-page.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Contact_Page {

    public function init() {
        add_action('admin_menu', array($this, 'replace_contact_page'), 999);
        add_action('wp_ajax_myfeeds_send_contact', array($this, 'ajax_send_contact'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Register our contact page and redirect the legacy slug.
     */
    public function replace_contact_page() {
        add_submenu_page(
            'myfeeds-feeds',
            'Contact Us',
            'Contact Us',
            'manage_options',
            'myfeeds-custom-contact',
            array($this, 'render_contact_page')
        );

        // Redirect legacy contact URL to our page
        if (isset($_GET['page']) && $_GET['page'] === 'myfeeds-feeds-contact') {
            wp_safe_redirect(admin_url('admin.php?page=myfeeds-custom-contact'));
            exit;
        }
    }

    /**
     * Enqueue contact-page assets only on our custom contact screen.
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'myfeeds_page_myfeeds-custom-contact') {
            return;
        }
        wp_enqueue_style(
            'myfeeds-contact-page',
            MYFEEDS_PLUGIN_URL . 'assets/contact-page.css',
            array(),
            MYFEEDS_VERSION
        );
        wp_enqueue_script(
            'myfeeds-contact-page',
            MYFEEDS_PLUGIN_URL . 'assets/contact-page.js',
            array(),
            MYFEEDS_VERSION,
            true
        );
        wp_localize_script('myfeeds-contact-page', 'myfeedsContact', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('myfeeds_contact_nonce'),
        ));
    }

    /**
     * AJAX handler: Send contact message via wp_mail
     */
    public function ajax_send_contact() {
        check_ajax_referer('myfeeds_contact_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        $category = sanitize_text_field(wp_unslash($_POST['category'] ?? ''));
        $subject  = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $message  = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));

        if (empty($subject) || empty($message)) {
            wp_send_json_error(array('message' => 'Subject and message are required.'));
        }

        $current_user = wp_get_current_user();
        $user_email   = $current_user->user_email;

        // Build informative body
        $plan_label = 'Free';

        $body  = $message . "\n\n";
        $body .= "---\n";
        $body .= "Plan: " . $plan_label . "\n";
        $body .= "WordPress: " . get_bloginfo('version') . "\n";
        $body .= "PHP: " . phpversion() . "\n";
        $body .= "Plugin: " . (defined('MYFEEDS_VERSION') ? MYFEEDS_VERSION : 'unknown') . "\n";
        $body .= "Site: " . get_bloginfo('url') . "\n";
        $body .= "User: " . $user_email . "\n";

        $to      = 'support@myfeeds.site';
        $subject = '[MyFeeds ' . $category . '] ' . $subject;
        $headers = array(
            'Reply-To: ' . $user_email,
        );

        $sent = wp_mail($to, $subject, $body, $headers);

        if ($sent) {
            wp_send_json_success(array('message' => 'Message sent successfully!'));
        } else {
            wp_send_json_error(array('message' => 'Failed to send message. Please try again.'));
        }
    }

    /**
     * Render the full contact & FAQ page
     */
    public function render_contact_page() {
        // FAQ items grouped by category
        $faq_groups = array(
            'Getting Started' => array(
                array(
                    'q' => 'How do I get a product feed URL?',
                    'a' => 'Sign up with an affiliate network (e.g. AWIN, CJ, Tradedoubler), find their product feed section &mdash; usually called &ldquo;Create a feed&rdquo; or &ldquo;Product feeds&rdquo; &mdash; and copy the feed URL. Paste it into MyFeeds to start importing.',
                ),
                array(
                    'q' => 'How do I add my first feed?',
                    'a' => 'Go to MyFeeds in your WordPress admin, click &ldquo;Add Feed&rdquo;, paste your product feed URL, and run your first import.',
                ),
                array(
                    'q' => 'How long does an import take?',
                    'a' => 'Depends on feed size. A feed with 10,000 products typically takes 2&ndash;5 minutes.',
                ),
            ),
            'Using the Product Picker' => array(
                array(
                    'q' => 'How do I add products to a blog post?',
                    'a' => 'In the block editor, add a &ldquo;MyFeeds &ndash; Product Picker&rdquo; block, search for products, and select the ones you want to display.',
                ),
                array(
                    'q' => 'Can I use MyFeeds with the classic editor?',
                    'a' => 'No, MyFeeds requires the Gutenberg block editor (available in WordPress 5.8+).',
                ),
            ),
            'Import & Data' => array(
                array(
                    'q' => 'Why are some products missing after import?',
                    'a' => 'Only products with valid data (title, price, image, and affiliate link) are imported. Check your feed source for incomplete entries.',
                ),
                array(
                    'q' => 'Does MyFeeds slow down my site?',
                    'a' => 'No. Products are stored locally in your database. There are no external API calls on the frontend.',
                ),
                array(
                    'q' => 'Does MyFeeds work with any theme?',
                    'a' => 'Yes, it works with any WordPress theme that supports the block editor.',
                ),
            ),
            'Plans & Billing' => array(
                array(
                    'q' => 'Is there a free trial?',
                    'a' => 'Yes, all paid plans include a 7-day free trial with no commitment.',
                ),
                array(
                    'q' => 'Can I switch plans anytime?',
                    'a' => 'Yes, you can upgrade or downgrade at any time from the Manage Plan page.',
                ),
                array(
                    'q' => 'What happens when I downgrade?',
                    'a' => 'Your data stays intact. If you exceed the new plan&rsquo;s feed limit, you&rsquo;ll be asked to choose which feeds to keep active.',
                ),
            ),
        );

        ?>
        <div class="wrap myfeeds-contact-wrap">
            <div class="myfeeds-contact-container">

                <!-- Header -->
                <div class="myfeeds-contact-header">
                    <h1>Help & FAQ</h1>
                    <p>Find answers below or send us a message.</p>
                </div>

                <!-- FAQ Section -->
                <div class="myfeeds-faq-section">
                    <div class="myfeeds-faq-section-title">Frequently Asked Questions</div>

                    <?php foreach ($faq_groups as $group_label => $items): ?>
                        <div class="myfeeds-faq-group-label"><?php echo esc_html($group_label); ?></div>
                        <?php foreach ($items as $faq): ?>
                            <div class="myfeeds-faq-item">
                                <div class="myfeeds-faq-question">
                                    <span class="q-text"><?php echo esc_html($faq['q']); ?></span>
                                    <span class="q-chevron">&#9656;</span>
                                </div>
                                <div class="myfeeds-faq-answer">
                                    <p><?php echo wp_kses_post($faq['a']); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>

                <!-- Contact Form Section -->
                <div class="myfeeds-contact-form-section">
                    <div class="myfeeds-contact-form-title">Still need help?</div>
                    <p class="myfeeds-contact-form-subtitle">Send us a message and we'll get back to you as soon as possible.</p>

                    <div class="myfeeds-contact-form" id="myfeeds-contact-form">
                        <div class="field-group">
                            <label for="myfeeds-contact-category">Category</label>
                            <select id="myfeeds-contact-category">
                                <option value="Bug report">Bug report</option>
                                <option value="Feature request">Feature request</option>
                                <option value="Billing question">Billing question</option>
                            </select>
                        </div>
                        <div class="field-group">
                            <label for="myfeeds-contact-subject">Subject</label>
                            <input type="text" id="myfeeds-contact-subject" placeholder="Brief summary of your issue">
                        </div>
                        <div class="field-group">
                            <label for="myfeeds-contact-message">Message</label>
                            <textarea id="myfeeds-contact-message" rows="5" placeholder="Describe your issue or question in detail..."></textarea>
                        </div>
                        <button type="button" class="myfeeds-contact-submit" id="myfeeds-contact-submit">Send Message</button>
                        <div class="myfeeds-contact-msg" id="myfeeds-contact-msg"></div>
                    </div>

                    <div class="myfeeds-contact-success-box" id="myfeeds-contact-success" style="display:none;">
                        <p>Thank you! We'll get back to you within 24 hours.</p>
                    </div>
                </div>

                <!-- Footer -->
                <p class="myfeeds-contact-footer">We typically respond within 24 hours.</p>

            </div><!-- /.myfeeds-contact-container -->
        </div>
        <?php
    }
}
