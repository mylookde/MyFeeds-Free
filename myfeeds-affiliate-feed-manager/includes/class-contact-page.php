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
    }

    /**
     * Hide Freemius contact menu, register our own, redirect old slug
     */
    public function replace_contact_page() {
        // 1. Hide the Freemius contact submenu via CSS
        add_action('admin_head', function() {
            echo '<style>
                span.fs-submenu-item.myfeeds.contact.fs_external_contact { display: none !important; }
            </style>';
        });

        // 2. Register our own contact page
        add_submenu_page(
            'myfeeds-feeds',
            'Contact Us',
            'Contact Us',
            'manage_options',
            'myfeeds-custom-contact',
            array($this, 'render_contact_page')
        );

        // 3. Redirect old Freemius contact URL to our page
        if (isset($_GET['page']) && $_GET['page'] === 'myfeeds-feeds-contact') {
            wp_safe_redirect(admin_url('admin.php?page=myfeeds-custom-contact'));
            exit;
        }
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
        if (class_exists('MyFeeds_Plan_Limits')) {
            if (MyFeeds_Plan_Limits::is_premium()) {
                $plan_label = 'Premium';
            } elseif (MyFeeds_Plan_Limits::is_pro()) {
                $plan_label = 'Pro';
            }
        }

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
        $nonce = wp_create_nonce('myfeeds_contact_nonce');
        $ajax_url = admin_url('admin-ajax.php');

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
                    'a' => 'Yes, all paid plans include a 3-day free trial with no commitment.',
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
            <style>
                /* Override WP Admin background for this page */
                .myfeeds-contact-wrap {
                    margin: -10px -20px -20px -20px !important;
                    padding: 0 !important;
                    max-width: none !important;
                }
                body.wp-admin #wpcontent,
                body.wp-admin #wpbody,
                body.wp-admin #wpbody-content {
                    background: #13111C !important;
                }
                .myfeeds-contact-container {
                    background: #13111C;
                    min-height: calc(100vh - 32px);
                    padding: 48px 32px 60px;
                }
                .myfeeds-contact-wrap > h1:first-of-type {
                    display: none;
                }
                #wpfooter {
                    display: none;
                }
                .myfeeds-contact-wrap,
                .myfeeds-contact-wrap * {
                    box-sizing: border-box;
                }

                /* ── Header ── */
                .myfeeds-contact-header {
                    text-align: center;
                    padding: 0 0 36px;
                }
                .myfeeds-contact-header h1 {
                    font-size: 28px;
                    font-weight: 700;
                    color: #f0eef6;
                    margin: 0 0 6px;
                }
                .myfeeds-contact-header p {
                    font-size: 15px;
                    color: #9e98b5;
                    margin: 0;
                }

                /* ── FAQ Section ── */
                .myfeeds-faq-section {
                    max-width: 860px;
                    margin: 0 auto 48px;
                }
                .myfeeds-faq-section-title {
                    font-size: 20px;
                    font-weight: 700;
                    color: #f0eef6;
                    text-align: center;
                    margin: 0 0 24px;
                }
                .myfeeds-faq-group-label {
                    font-size: 11px;
                    font-weight: 600;
                    color: #7a7394;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    margin: 20px 0 8px;
                }
                .myfeeds-faq-item {
                    background: #1e1b2e;
                    border: 1px solid #2e2a42;
                    border-radius: 10px;
                    margin-bottom: 8px;
                    overflow: hidden;
                    transition: border-color 0.2s ease;
                }
                .myfeeds-faq-item:hover {
                    border-color: #3d3757;
                }
                .myfeeds-faq-item.open {
                    border-color: #3d3757;
                }
                .myfeeds-faq-question {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 14px 18px;
                    cursor: pointer;
                    user-select: none;
                }
                .myfeeds-faq-question span.q-text {
                    font-size: 14px;
                    font-weight: 600;
                    color: #f0eef6;
                    flex: 1;
                }
                .myfeeds-faq-question span.q-chevron {
                    font-size: 14px;
                    color: #7a7394;
                    transition: transform 0.25s ease;
                    flex-shrink: 0;
                    margin-left: 12px;
                }
                .myfeeds-faq-item.open .q-chevron {
                    transform: rotate(90deg);
                }
                .myfeeds-faq-answer {
                    max-height: 0;
                    overflow: hidden;
                    transition: max-height 0.3s ease, padding 0.3s ease;
                    padding: 0 18px;
                }
                .myfeeds-faq-item.open .myfeeds-faq-answer {
                    max-height: 200px;
                    padding: 0 18px 14px;
                }
                .myfeeds-faq-answer p {
                    font-size: 13px;
                    font-weight: 400;
                    color: #c4bfda;
                    margin: 0;
                    padding-top: 8px;
                    line-height: 1.6;
                }

                /* ── Contact Form Section ── */
                .myfeeds-contact-form-section {
                    max-width: 560px;
                    margin: 0 auto 36px;
                    text-align: center;
                }
                .myfeeds-contact-form-title {
                    font-size: 20px;
                    font-weight: 700;
                    color: #f0eef6;
                    margin: 0 0 6px;
                }
                .myfeeds-contact-form-subtitle {
                    font-size: 14px;
                    color: #9e98b5;
                    margin: 0 0 28px;
                }
                .myfeeds-contact-form {
                    text-align: left;
                }
                .myfeeds-contact-form .field-group {
                    margin-bottom: 18px;
                }
                .myfeeds-contact-form label {
                    display: block;
                    font-size: 13px;
                    font-weight: 600;
                    color: #c4bfda;
                    margin-bottom: 6px;
                }
                .myfeeds-contact-form select,
                .myfeeds-contact-form input[type="text"],
                .myfeeds-contact-form textarea {
                    width: 100%;
                    background: #1e1b2e;
                    border: 1px solid #2e2a42;
                    border-radius: 8px;
                    color: #f0eef6;
                    padding: 10px 14px;
                    font-size: 14px;
                    font-family: inherit;
                    transition: border-color 0.2s ease;
                    outline: none;
                }
                .myfeeds-contact-form select:focus,
                .myfeeds-contact-form input[type="text"]:focus,
                .myfeeds-contact-form textarea:focus {
                    border-color: #667eea;
                }
                .myfeeds-contact-form select::placeholder,
                .myfeeds-contact-form input[type="text"]::placeholder,
                .myfeeds-contact-form textarea::placeholder {
                    color: #7a7394;
                }
                .myfeeds-contact-form textarea {
                    resize: vertical;
                    min-height: 120px;
                }
                .myfeeds-contact-form select {
                    appearance: none;
                    -webkit-appearance: none;
                    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%237a7394' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
                    background-repeat: no-repeat;
                    background-position: right 14px center;
                    padding-right: 36px;
                }
                .myfeeds-contact-submit {
                    display: block;
                    width: 100%;
                    padding: 13px 0;
                    border: none;
                    border-radius: 10px;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: #fff;
                    font-size: 14px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: box-shadow 0.2s ease, opacity 0.2s ease;
                    line-height: 1.4;
                }
                .myfeeds-contact-submit:hover {
                    box-shadow: 0 4px 20px rgba(102,126,234,0.4);
                }
                .myfeeds-contact-submit:disabled {
                    opacity: 0.6;
                    cursor: not-allowed;
                }
                .myfeeds-contact-msg {
                    margin-top: 14px;
                    font-size: 14px;
                    text-align: center;
                }
                .myfeeds-contact-msg.success {
                    color: #4ade80;
                }
                .myfeeds-contact-msg.error {
                    color: #f87171;
                }
                .myfeeds-contact-success-box {
                    text-align: center;
                    padding: 32px 0;
                }
                .myfeeds-contact-success-box p {
                    font-size: 16px;
                    font-weight: 600;
                    color: #4ade80;
                    margin: 0;
                }

                /* ── Footer ── */
                .myfeeds-contact-footer {
                    text-align: center;
                    font-size: 13px;
                    color: #7a7394;
                    margin: 0;
                }
            </style>

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

            <script>
            (function(){
                var wrap = document.querySelector('.myfeeds-contact-wrap');
                if (!wrap) return;

                // ── FAQ Accordion ──
                var faqItems = wrap.querySelectorAll('.myfeeds-faq-item');
                faqItems.forEach(function(item) {
                    var question = item.querySelector('.myfeeds-faq-question');
                    question.addEventListener('click', function() {
                        var wasOpen = item.classList.contains('open');
                        // Close all
                        faqItems.forEach(function(i) { i.classList.remove('open'); });
                        // Toggle clicked
                        if (!wasOpen) {
                            item.classList.add('open');
                        }
                    });
                });

                // ── Contact Form Submit ──
                var submitBtn = document.getElementById('myfeeds-contact-submit');
                var msgEl     = document.getElementById('myfeeds-contact-msg');
                var formEl    = document.getElementById('myfeeds-contact-form');
                var successEl = document.getElementById('myfeeds-contact-success');

                submitBtn.addEventListener('click', function() {
                    var category = document.getElementById('myfeeds-contact-category').value;
                    var subject  = document.getElementById('myfeeds-contact-subject').value.trim();
                    var message  = document.getElementById('myfeeds-contact-message').value.trim();

                    // Validate
                    if (!subject || !message) {
                        msgEl.textContent = 'Please fill in both subject and message.';
                        msgEl.className = 'myfeeds-contact-msg error';
                        return;
                    }

                    // Disable button
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Sending...';
                    msgEl.textContent = '';
                    msgEl.className = 'myfeeds-contact-msg';

                    var data = new FormData();
                    data.append('action', 'myfeeds_send_contact');
                    data.append('nonce', '<?php echo esc_js($nonce); ?>');
                    data.append('category', category);
                    data.append('subject', subject);
                    data.append('message', message);

                    fetch('<?php echo esc_js($ajax_url); ?>', {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: data
                    })
                    .then(function(res) { return res.json(); })
                    .then(function(res) {
                        if (res.success) {
                            formEl.style.display = 'none';
                            successEl.style.display = 'block';
                        } else {
                            msgEl.textContent = res.data && res.data.message ? res.data.message : 'Something went wrong.';
                            msgEl.className = 'myfeeds-contact-msg error';
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Send Message';
                        }
                    })
                    .catch(function() {
                        msgEl.textContent = 'Network error. Please try again.';
                        msgEl.className = 'myfeeds-contact-msg error';
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Send Message';
                    });
                });
            })();
            </script>
        </div>
        <?php
    }
}
