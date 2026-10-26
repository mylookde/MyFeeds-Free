<?php
/**
 * Review ask.
 *
 * One polite, dismissible notice, shown only on MyFeeds admin screens and
 * only after the plugin has proven itself: at least 8 days installed (a
 * week of use, the length of the shortest paid trial, plus a day) and at
 * least one feed that synced successfully.
 *
 * Two steps in the same box. First one question, "how did you find
 * MyFeeds?": each answer is a plain link that opens a thank-you page on
 * myfeeds.site, which counts the answer. The plugin itself sends nothing.
 * Then the WordPress.org review request. The X and "No thanks" end it for
 * good, "Maybe later" snoozes it for 30 days.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Review_Ask {

    const OPTION = 'myfeeds_review_ask';
    const REVIEW_URL = 'https://wordpress.org/support/plugin/myfeeds-affiliate-feed-manager/reviews/#new-post';
    const THANKS_URL = 'https://myfeeds.site/thanks/';
    const MIN_DAYS_INSTALLED = 8;
    const SNOOZE_DAYS = 30;

    public function init() {
        add_action('admin_init', array($this, 'ensure_installed_since'));
        add_action('admin_notices', array($this, 'maybe_render'));
        add_action('wp_ajax_myfeeds_review_ask', array($this, 'handle_action'));
    }

    /**
     * The answers to "how did you find MyFeeds?", as the thank-you page
     * knows them. Keys go into the link, labels onto the buttons.
     */
    public static function found_answers() {
        return array(
            'pinterest'     => __('Pinterest', 'myfeeds-affiliate-feed-manager'),
            'google'        => __('Google', 'myfeeds-affiliate-feed-manager'),
            'wordpress-org' => __('WordPress.org', 'myfeeds-affiliate-feed-manager'),
            'blog-video'    => __('A blog or video', 'myfeeds-affiliate-feed-manager'),
            'other'         => __('Somewhere else', 'myfeeds-affiliate-feed-manager'),
        );
    }

    /**
     * The option doubles as install-date anchor and state store:
     * array('since' => ts, 'state' => ''|'snoozed'|'done', 'until' => ts,
     * 'found' => bool). Existing installs updating to this version start
     * their clock at update time, which is the honest reading of "has been
     * living with the plugin for a while".
     */
    public function ensure_installed_since() {
        $data = get_option(self::OPTION, null);
        if (is_array($data) && !empty($data['since'])) {
            return;
        }
        add_option(self::OPTION, array('since' => time(), 'state' => ''), '', false);
    }

    private function should_show() {
        if (!current_user_can('manage_options')) {
            return false;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string) $screen->id, 'myfeeds') === false) {
            return false;
        }

        $data = get_option(self::OPTION, array());
        if (!is_array($data) || empty($data['since'])) {
            return false;
        }
        $state = isset($data['state']) ? (string) $data['state'] : '';
        if ($state === 'done') {
            return false;
        }
        if ($state === 'snoozed' && (!isset($data['until']) || time() < (int) $data['until'])) {
            return false;
        }
        if ((time() - (int) $data['since']) < self::MIN_DAYS_INSTALLED * DAY_IN_SECONDS) {
            return false;
        }

        // Only ask people the plugin has actually worked for: at least
        // one feed with a completed sync and at least one product.
        $feeds = get_option('myfeeds_feeds', array());
        if (!is_array($feeds)) {
            return false;
        }
        foreach ($feeds as $feed) {
            if (!empty($feed['last_sync']) && (int) ($feed['product_count'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    public function maybe_render() {
        if (!$this->should_show()) {
            return;
        }
        $data  = get_option(self::OPTION, array());
        $found = is_array($data) && !empty($data['found']);
        $nonce = wp_create_nonce('myfeeds_review_ask');
        $star  = '<svg viewBox="0 0 24 24"><path d="M12 2l2.9 6.26 6.6.7-4.9 4.55 1.35 6.49L12 16.77 6.05 20l1.35-6.49L2.5 8.96l6.6-.7z"/></svg>';
        ?>
        <div class="notice myfeeds-review-ask" id="myfeeds-review-ask">
            <button type="button" class="myfeeds-ra-close" data-ra-action="done" aria-label="<?php esc_attr_e('Dismiss', 'myfeeds-affiliate-feed-manager'); ?>">&times;</button>
            <div class="myfeeds-ra-step" data-ra-step="found"<?php echo $found ? ' hidden' : ''; ?>>
                <p class="myfeeds-ra-title"><?php esc_html_e('Your product cards have been keeping themselves up to date for a week.', 'myfeeds-affiliate-feed-manager'); ?></p>
                <p class="myfeeds-ra-body"><?php esc_html_e('One quick question: how did you find MyFeeds?', 'myfeeds-affiliate-feed-manager'); ?></p>
                <p class="myfeeds-ra-chips">
                    <?php foreach (self::found_answers() as $key => $label) : ?>
                        <a class="myfeeds-ra-chip" data-ra-action="found" target="_blank" rel="noopener" href="<?php echo esc_url(add_query_arg(array('found' => $key, 'from' => 'free'), self::THANKS_URL)); ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </p>
            </div>
            <div class="myfeeds-ra-step" data-ra-step="review"<?php echo $found ? '' : ' hidden'; ?>>
                <?php if (!$found) : ?><p class="myfeeds-ra-thanks"><?php esc_html_e('Thank you!', 'myfeeds-affiliate-feed-manager'); ?></p><?php endif; ?>
                <div class="myfeeds-ra-stars" aria-hidden="true"><?php echo $star . $star . $star . $star . $star; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?></div>
                <p class="myfeeds-ra-body"><?php esc_html_e('If MyFeeds saves you time, a short review on WordPress.org helps other bloggers find it.', 'myfeeds-affiliate-feed-manager'); ?></p>
                <p class="myfeeds-ra-actions">
                    <a href="<?php echo esc_url(self::REVIEW_URL); ?>" target="_blank" rel="noopener noreferrer" class="myfeeds-ra-btn myfeeds-ra-primary" data-ra-action="done"><?php esc_html_e('Leave a review', 'myfeeds-affiliate-feed-manager'); ?></a>
                    <button type="button" class="myfeeds-ra-btn myfeeds-ra-ghost" data-ra-action="snooze"><?php esc_html_e('Maybe later', 'myfeeds-affiliate-feed-manager'); ?></button>
                    <button type="button" class="myfeeds-ra-link" data-ra-action="done"><?php esc_html_e('No thanks', 'myfeeds-affiliate-feed-manager'); ?></button>
                </p>
            </div>
        </div>
        <style>
        .myfeeds-review-ask{position:relative;border:1px solid #e3e0ee;border-left:4px solid #667eea;border-radius:8px;background:#fff;padding:16px 44px 16px 18px;margin:16px 20px 16px 2px;box-shadow:0 1px 2px rgba(30,25,60,.04)}
        .myfeeds-ra-step[hidden]{display:none}
        .myfeeds-ra-title{margin:0 0 4px;font-size:14px;font-weight:600;color:#1d1a2b}
        .myfeeds-ra-thanks{margin:0 0 6px;font-size:14px;font-weight:600;color:#2e7d32}
        .myfeeds-ra-body{margin:0 0 12px;font-size:13px;color:#5f5a70;max-width:560px}
        .myfeeds-ra-chips{display:flex;flex-wrap:wrap;gap:8px;margin:0}
        .myfeeds-ra-chip{display:inline-block;padding:6px 13px;border-radius:999px;border:1px solid #d9d5e6;background:#faf9ff;color:#3c3852;font-weight:600;font-size:12.5px;text-decoration:none}
        .myfeeds-ra-chip:hover,.myfeeds-ra-chip:focus{border-color:#667eea;color:#1d1a2b}
        .myfeeds-ra-stars{display:flex;gap:3px;margin-bottom:8px}
        .myfeeds-ra-stars svg{width:15px;height:15px;fill:#f5b301}
        .myfeeds-ra-actions{display:flex;align-items:center;gap:12px;margin:0;flex-wrap:wrap}
        .myfeeds-ra-btn{display:inline-block;padding:7px 16px;border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;line-height:1.4}
        .myfeeds-ra-primary{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff !important;border:none}
        .myfeeds-ra-primary:hover{opacity:.92;color:#fff}
        .myfeeds-ra-ghost{background:#fff;color:#3c3852;border:1px solid #d9d5e6}
        .myfeeds-ra-ghost:hover{border-color:#667eea;color:#1d1a2b}
        .myfeeds-ra-link{background:none;border:none;padding:0;font-size:12.5px;color:#8c879c;cursor:pointer;text-decoration:underline}
        .myfeeds-ra-link:hover{color:#5f5a70}
        .myfeeds-ra-close{position:absolute;top:8px;right:10px;background:none;border:none;font-size:18px;line-height:1;color:#a09aae;cursor:pointer;padding:4px}
        .myfeeds-ra-close:hover{color:#3c3852}
        </style>
        <script>
        (function(){
            var box = document.getElementById('myfeeds-review-ask');
            if (!box) return;
            function tell(what){
                var body = new URLSearchParams();
                body.set('action', 'myfeeds_review_ask');
                body.set('what', what);
                body.set('_wpnonce', <?php echo wp_json_encode($nonce); ?>);
                fetch(ajaxurl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString()});
            }
            box.addEventListener('click', function(e){
                var el = e.target.closest('[data-ra-action]');
                if (!el) return;
                var what = el.getAttribute('data-ra-action');
                tell(what);
                if (what === 'found') {
                    // The link opens the thank-you page in a new tab; here the
                    // box moves on to its second step.
                    box.querySelector('[data-ra-step="found"]').hidden = true;
                    box.querySelector('[data-ra-step="review"]').hidden = false;
                    return;
                }
                box.style.transition = 'opacity .25s';
                box.style.opacity = '0';
                setTimeout(function(){ box.remove(); }, 260);
            });
        })();
        </script>
        <?php
    }

    public function handle_action() {
        check_ajax_referer('myfeeds_review_ask');
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }
        $what = isset($_POST['what']) ? sanitize_key(wp_unslash($_POST['what'])) : '';
        $data = get_option(self::OPTION, array());
        if (!is_array($data)) {
            $data = array();
        }
        if ($what === 'found') {
            $data['found'] = true;
        } elseif ($what === 'snooze') {
            $data['state'] = 'snoozed';
            $data['until'] = time() + self::SNOOZE_DAYS * DAY_IN_SECONDS;
        } else {
            $data['state'] = 'done';
        }
        update_option(self::OPTION, $data, false);
        wp_send_json_success();
    }
}
