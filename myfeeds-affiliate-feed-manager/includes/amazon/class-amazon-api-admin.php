<?php
/**
 * Amazon's API in the Amazon window on the Feeds screen.
 *
 * The window itself is MyFeeds_Amazon_Admin, the links' own: the Amazon
 * site, the partner tag, where visitors abroad land, the badge, Save.
 * Where Amazon's API runs the window gets the credentials, the test, the
 * connection state and the request budget on top - through the window's
 * sections (myfeeds_amazon_modal_sections) - and where it does not, none
 * of those, not even a credential stored earlier.
 *
 * Most of the API's copy is explanation rather than form. Amazon gates
 * the Creators API behind a sales threshold that has nothing to do with
 * the plugin, and an account that is not there yet gets a flat 403.
 * Saying so up front prevents the support ticket that a bare error would
 * cause.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Api_Admin {

    const HANDLE = 'myfeeds-amazon-api-admin';

    /** The window's two filters are hung on by MyFeeds_Amazon_Api::filters(). */
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
    }

    /**
     * Where the API runs there is something left to set up until it is
     * connected; elsewhere a partner tag is all there is.
     */
    public static function is_set_up($set_up) {
        return MyFeeds_Amazon_Api::active() ? MyFeeds_Amazon_Credentials::is_configured() : $set_up;
    }

    public static function enqueue($hook) {
        if (strpos((string) $hook, MyFeeds_Amazon_Admin::FEEDS_PAGE) === false || !MyFeeds_Amazon_Api::active()) {
            return;
        }

        $rel = 'assets/amazon-api-admin.js';
        $ver = function_exists('myfeeds_asset_ver') ? myfeeds_asset_ver($rel) : (string) filemtime(MYFEEDS_PLUGIN_DIR . $rel);

        wp_enqueue_script(
            self::HANDLE,
            MYFEEDS_PLUGIN_URL . $rel,
            array(MyFeeds_Amazon_Admin::HANDLE),
            $ver,
            true
        );

        // The version Amazon hands out follows the store's region, so the
        // script prefills it when the Amazon site changes.
        $versions = array();
        foreach (array_keys(MyFeeds_Amazon_Locales::all()) as $marketplace) {
            $versions[$marketplace] = MyFeeds_Amazon_Api_Locales::expected_version($marketplace);
        }

        wp_localize_script(self::HANDLE, 'myfeedsAmazonApiAdmin', array(
            'root'     => MyFeeds_Amazon_REST::NS,
            'versions' => $versions,
            'i18n'     => array(
                'testing' => __('Asking Amazon…', 'myfeeds-affiliate-feed-manager'),
                'confirm' => __('Disconnect Amazon and delete all cached Amazon data?', 'myfeeds-affiliate-feed-manager'),
            ),
        ));

        wp_add_inline_style(MyFeeds_Amazon_Admin::HANDLE, self::css());
    }

    /**
     * The window's sections. Where Amazon's API does not run: what a link
     * needs, as the links' window has it. Where it runs: the API's part on
     * top.
     */
    public static function sections($sections) {
        $sections = is_array($sections) ? $sections : array();

        if (!MyFeeds_Amazon_Api::active()) {
            return $sections;
        }

        $connection  = MyFeeds_Amazon_Credentials::get();
        $marketplace = $connection['marketplace'];
        $configured  = MyFeeds_Amazon_Credentials::is_configured();

        $sections['title'] = esc_html($configured ? __('Amazon', 'myfeeds-affiliate-feed-manager') : __('Connect Amazon', 'myfeeds-affiliate-feed-manager'));
        $sections['top']   = self::status_html($configured);

        ob_start();
        ?>
        <p>
            <strong><?php esc_html_e('Amazon unlocks its API only for accounts that already sell.', 'myfeeds-affiliate-feed-manager'); ?></strong>
            <?php esc_html_e('You need an Amazon Associates account with 10 qualifying sales in the last 30 days. Until then, keep using normal Amazon links — this starts working on its own once you get there.', 'myfeeds-affiliate-feed-manager'); ?>
        </p>
        <p>
            <strong><?php esc_html_e('Where to find your credentials:', 'myfeeds-affiliate-feed-manager'); ?></strong>
            <?php
            printf(
                /* translators: %s: menu path inside Associates Central */
                esc_html__('Sign in to Amazon Associates, open %s, choose Create Application, then Add New Credential. Copy the Credential ID, Secret and Version — or download the CSV, because Amazon shows the secret only once.', 'myfeeds-affiliate-feed-manager'),
                '<code>' . esc_html__('Tools → Creators API', 'myfeeds-affiliate-feed-manager') . '</code>'
            );
            ?>
        </p>
        <p class="description">
            <?php esc_html_e('Only the main account owner can create credentials. Access switches off again after 30 days without a qualifying sale, and comes back about two days after the next one ships.', 'myfeeds-affiliate-feed-manager'); ?>
        </p>
        <p>
            <a href="<?php echo esc_url(MyFeeds_Amazon_Api_Locales::creators_api_url($marketplace)); ?>" target="_blank" rel="noopener">
                <?php esc_html_e('Open Creators API in Associates Central', 'myfeeds-affiliate-feed-manager'); ?> &rarr;
            </a>
        </p>
        <?php
        $sections['help'] = ob_get_clean() . MyFeeds_Amazon_Admin::disclosure_html();

        ob_start();
        esc_html_e('Your credentials work on every Amazon site. Each site needs its own partner tag, so switch here and save a tag per site you want to use.', 'myfeeds-affiliate-feed-manager');
        echo '<br>';
        printf(
            /* translators: 1: region code, 2: login host name */
            esc_html__('This site sits in Amazon region %1$s, so sign-in runs through %2$s. Amazon has to have approved Creators API access in a region before you can sell into it.', 'myfeeds-affiliate-feed-manager'),
            '<strong>' . esc_html(MyFeeds_Amazon_Api_Locales::region($marketplace)) . '</strong>',
            '<code>' . esc_html((string) wp_parse_url(MyFeeds_Amazon_Api_Locales::token_endpoint($marketplace), PHP_URL_HOST)) . '</code>'
        );
        $sections['site_note'] = ob_get_clean();

        $sections['abroad_note'] = esc_html__('It applies to the links MyFeeds builds from an ASIN; links from Amazon\'s API are passed on unchanged.', 'myfeeds-affiliate-feed-manager');
        $sections['fields']      = self::credential_rows($connection);

        ob_start();
        ?>
        <button type="button" class="button" id="mf-amz-test"><?php esc_html_e('Save and test', 'myfeeds-affiliate-feed-manager'); ?></button>
        <button type="button" class="button" id="mf-amz-save"><?php esc_html_e('Save only', 'myfeeds-affiliate-feed-manager'); ?></button>
        <?php // Never while the site's products come from it: that way off is "Change source". ?>
        <?php if (MyFeeds_Amazon_Credentials::is_stored() && MyFeeds_Source_Rules::can_disconnect('amazon_api')) : ?>
            <button type="button" class="button" id="mf-amz-disconnect"><?php esc_html_e('Disconnect', 'myfeeds-affiliate-feed-manager'); ?></button>
        <?php endif; ?>
        <?php
        $sections['actions'] = ob_get_clean();

        ob_start();
        self::render_limits($marketplace);
        $sections['bottom'] = ob_get_clean();

        return $sections;
    }

    // =====================================================================
    // Sections
    // =====================================================================

    /**
     * Two environment problems look exactly like bad credentials from the
     * outside, so they are named before anything else.
     */
    public static function status_html($configured) {
        ob_start();
        echo '<div id="mf-amz-status">';
        self::render_status_inner($configured);
        echo '</div>';
        return ob_get_clean();
    }

    private static function render_status_inner($configured) {
        if (MyFeeds_Amazon_Client::outbound_blocked()) {
            self::status_line(
                'error',
                __('This site blocks outgoing requests', 'myfeeds-affiliate-feed-manager'),
                sprintf(
                    /* translators: 1: PHP constant name, 2: list of host names */
                    __('%1$s is on in wp-config.php and Amazon is not on the allow list. Add %2$s to WP_ACCESSIBLE_HOSTS, or ask your host to.', 'myfeeds-affiliate-feed-manager'),
                    'WP_HTTP_BLOCK_EXTERNAL',
                    MyFeeds_Amazon_Client::required_hosts_text()
                )
            );
        }

        if (MyFeeds_Amazon_Credentials::secret_unreadable()) {
            self::status_line(
                'error',
                __('The stored secret can no longer be read', 'myfeeds-affiliate-feed-manager'),
                __('The site security keys were rotated, or the server lost the encryption library. Paste the secret again, or generate a new credential.', 'myfeeds-affiliate-feed-manager')
            );
        }

        if (!$configured) {
            return;
        }

        $status = MyFeeds_Amazon_Credentials::get_status();

        $map = array(
            MyFeeds_Amazon_Credentials::STATE_UNTESTED => array(
                'info',
                __('Credentials saved, not checked yet', 'myfeeds-affiliate-feed-manager'),
                __('Press Save and test to see what Amazon says about this account.', 'myfeeds-affiliate-feed-manager'),
            ),
            MyFeeds_Amazon_Credentials::STATE_OK => array(
                'ok',
                __('Connected', 'myfeeds-affiliate-feed-manager'),
                __('Amazon is answering. You can search its catalogue in the product picker.', 'myfeeds-affiliate-feed-manager'),
            ),
            MyFeeds_Amazon_Credentials::STATE_NOT_ELIGIBLE => array(
                'warn',
                __('Amazon has not unlocked the API for your account yet', 'myfeeds-affiliate-feed-manager'),
                __('Your credentials are fine. Amazon wants 10 qualifying sales in the last 30 days before it answers.', 'myfeeds-affiliate-feed-manager'),
            ),
            MyFeeds_Amazon_Credentials::STATE_INVALID => array(
                'error',
                __('Amazon rejected the credentials', 'myfeeds-affiliate-feed-manager'),
                __('Check the credential id, the secret, and whether the partner tag belongs to the selected Amazon site.', 'myfeeds-affiliate-feed-manager'),
            ),
            MyFeeds_Amazon_Credentials::STATE_THROTTLED => array(
                'warn',
                __('Amazon is rate limiting the account', 'myfeeds-affiliate-feed-manager'),
                __('New accounts get one request per second. This clears itself as your Amazon sales grow.', 'myfeeds-affiliate-feed-manager'),
            ),
            MyFeeds_Amazon_Credentials::STATE_ERROR => array(
                'error',
                __('Last call to Amazon failed', 'myfeeds-affiliate-feed-manager'),
                $status['message'],
            ),
        );

        $state = isset($map[$status['state']]) ? $status['state'] : MyFeeds_Amazon_Credentials::STATE_ERROR;
        list($kind, $title, $text) = $map[$state];

        self::status_line($kind, $title, $text);
    }

    private static function status_line($kind, $title, $text) {
        ?>
        <div class="myfeeds-amz-status is-<?php echo esc_attr($kind); ?>">
            <strong><?php echo esc_html($title); ?></strong>
            <?php if ($text !== '') : ?><span><?php echo esc_html($text); ?></span><?php endif; ?>
        </div>
        <?php
    }

    /** The rows of Amazon's API, under the rows a link needs. */
    private static function credential_rows(array $connection) {
        ob_start();
        ?>
            <tr>
                <th scope="row"><label for="mf-amz-id"><?php esc_html_e('Credential ID', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                <td><input type="text" id="mf-amz-id" class="regular-text" value="<?php echo esc_attr($connection['credential_id']); ?>" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><label for="mf-amz-secret"><?php esc_html_e('Secret', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                <td>
                    <?php $has_secret = $connection['credential_secret'] !== ''; ?>
                    <span class="myfeeds-amz-secret">
                        <input type="password" id="mf-amz-secret" class="regular-text" value="" autocomplete="new-password"
                            placeholder="<?php echo $has_secret ? esc_attr__('Stored. Leave empty to keep it.', 'myfeeds-affiliate-feed-manager') : ''; ?>">
                        <?php // Always shown: while typing it checks what you entered, once saved it reads the stored one back. ?>
                            <button type="button" id="mf-amz-reveal" class="myfeeds-amz-eye"
                                data-has-secret="<?php echo $has_secret ? '1' : '0'; ?>"
                                aria-pressed="false"
                                aria-label="<?php esc_attr_e('Show the secret', 'myfeeds-affiliate-feed-manager'); ?>"
                                title="<?php esc_attr_e('Show the secret', 'myfeeds-affiliate-feed-manager'); ?>">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path class="eye-open" d="M1.5 12S5 5.5 12 5.5 22.5 12 22.5 12 19 18.5 12 18.5 1.5 12 1.5 12Z"/>
                                    <circle class="eye-open" cx="12" cy="12" r="3.2"/>
                                    <line class="eye-slash" x1="4" y1="20" x2="20" y2="4"/>
                                </svg>
                            </button>
                    </span>
                    <p class="description">
                        <?php if ($has_secret) : ?>
                            <?php esc_html_e('Saved on this site, so you can read it back here if you ever need it again. Amazon shows it only once.', 'myfeeds-affiliate-feed-manager'); ?>
                        <?php else : ?>
                            <?php esc_html_e('Amazon shows the secret only once. Paste it here and this site keeps it for you.', 'myfeeds-affiliate-feed-manager'); ?>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="mf-amz-version"><?php esc_html_e('Version', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                <td>
                    <input type="text" id="mf-amz-version" class="small-text" value="<?php echo esc_attr($connection['credential_version']); ?>">
                    <p class="description"><?php esc_html_e('Amazon assigns this when you create the credential. It selects the login endpoint and is usually filled in for you.', 'myfeeds-affiliate-feed-manager'); ?></p>
                </td>
            </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * The request counter and why Amazon products behave differently from
     * a feed. Always visible: the counter is the one number a user wants
     * to glance at, and hiding it behind a toggle made it disappear.
     */
    private static function render_limits($marketplace) {
        $used  = MyFeeds_Amazon_Client::calls_used_today();
        $total = MyFeeds_Amazon_Client::daily_budget();
        $pct   = $total > 0 ? min(100, round($used / $total * 100)) : 0;
        ?>
        <div class="myfeeds-amz-limits">
            <div class="myfeeds-amz-budget">
                <span class="myfeeds-amz-budget__label"><?php esc_html_e('Requests today', 'myfeeds-affiliate-feed-manager'); ?></span>
                <span class="myfeeds-amz-budget__value" id="mf-amz-budget">
                    <?php echo esc_html(number_format_i18n($used)); ?><span class="myfeeds-amz-budget__total"> / <?php echo esc_html(number_format_i18n($total)); ?></span>
                </span>
                <span class="myfeeds-amz-budget__bar"><span style="width: <?php echo esc_attr($pct); ?>%"></span></span>
                <span class="myfeeds-amz-budget__note">
                    <?php
                    if (MyFeeds_Amazon_Client::budget_is_learned()) {
                        esc_html_e('The figure on the right is this site\'s own limit, adjusted from what Amazon has actually allowed so far. Amazon does not publish your account\'s allowance anywhere.', 'myfeeds-affiliate-feed-manager');
                    } else {
                        esc_html_e('The figure on the right is this site\'s own starting limit, just under the allowance Amazon documents for a new account. Amazon does not publish your real one, so this adjusts itself: up after a busy day without throttling, down if Amazon starts refusing calls.', 'myfeeds-affiliate-feed-manager');
                    }
                    ?>
                    <?php
                    printf(
                        /* translators: %s: number of products currently cached */
                        esc_html__('Cached products right now: %s.', 'myfeeds-affiliate-feed-manager'),
                        '<strong id="mf-amz-cached">' . esc_html(number_format_i18n(MyFeeds_Amazon_Cache::count_rows())) . '</strong>'
                    );
                    ?>
                </span>
            </div>

            <div class="myfeeds-amz-rules">
                <span class="myfeeds-amz-rules__title"><?php esc_html_e('What Amazon allows', 'myfeeds-affiliate-feed-manager'); ?></span>
                <ul>
                    <li><strong><?php esc_html_e('Prices', 'myfeeds-affiliate-feed-manager'); ?></strong> <?php esc_html_e('refresh at least every hour, and every card shows when its price was read.', 'myfeeds-affiliate-feed-manager'); ?></li>
                    <li><strong><?php esc_html_e('Titles and images', 'myfeeds-affiliate-feed-manager'); ?></strong> <?php esc_html_e('are kept for at most 24 hours, then fetched again.', 'myfeeds-affiliate-feed-manager'); ?></li>
                    <li><strong><?php esc_html_e('Saved permanently', 'myfeeds-affiliate-feed-manager'); ?></strong> <?php esc_html_e('is only the ASIN, which is all Amazon allows.', 'myfeeds-affiliate-feed-manager'); ?></li>
                    <li><strong><?php esc_html_e('A search', 'myfeeds-affiliate-feed-manager'); ?></strong> <?php esc_html_e('returns at most 100 products. That ceiling is Amazon\'s, not ours.', 'myfeeds-affiliate-feed-manager'); ?></li>
                </ul>
                <p class="description">
                    <a href="<?php echo esc_url(MyFeeds_Amazon_Locales::license_url($marketplace)); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e('Read the Associates licence', 'myfeeds-affiliate-feed-manager'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }

    // =====================================================================
    // Styles
    // =====================================================================

    /**
     * What the API adds to the window's styles (MyFeeds_Amazon_Admin::css()):
     * the test button, the two secondary buttons, the secret's eye, the
     * state line and the request counter.
     */
    private static function css() {
        $b = MyFeeds_Amazon_Admin::BRAND;

        return "
            #mf-amz-test {
                background: linear-gradient(135deg, #EC8A12 0%, #CC6A00 100%) !important;
                text-align: center;
                color: #fff !important;
                border: none !important;
                border-radius: 6px !important;
                box-shadow: 0 2px 4px rgba(222, 122, 0, 0.2) !important;
                text-shadow: none !important;
                transition: transform 0.15s, box-shadow 0.15s !important;
            }
            #mf-amz-test:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(222, 122, 0, 0.35) !important;
            }
            #mf-amz-test:disabled {
                opacity: 0.5;
                cursor: not-allowed;
                transform: none !important;
            }

            /* Mirrors the secondary buttons at assets/admin.css:313.
               Disconnect shares the look on Marlon's call: it sits behind a
               confirm dialog, so the row reads as one set rather than
               having a stray red control in it. */
            #mf-amz-save,
            #mf-amz-disconnect {
                background: #fff !important;
                color: #E58200 !important;
                border: 1px solid #E58200 !important;
                border-radius: 6px !important;
                text-shadow: none !important;
                text-align: center;
                transition: background 0.15s !important;
            }

            #mf-amz-save:hover,
            #mf-amz-disconnect:hover {
                background: #fff7ec !important;
            }

            /* Reveal toggle: orange when the secret is on screen, so the
               state is obvious at a glance rather than only in the icon. */
            .myfeeds-amz-secret {
                display: flex;
                align-items: stretch;
                gap: 6px;
                width: 100%;
            }
            .myfeeds-amz-secret input { flex: 1 1 auto; min-width: 0; }
            .myfeeds-amz-eye {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex: 0 0 auto;
                width: 34px;
                padding: 0;
                border: 1px solid #dcdcde;
                border-radius: 6px;
                background: #fff;
                color: #646970;
                cursor: pointer;
                transition: color .15s, border-color .15s, background .15s;
            }
            .myfeeds-amz-eye:hover { color: #C46A00; border-color: #EC8A12; }
            .myfeeds-amz-eye:focus {
                outline: 1px solid transparent;
                border-color: #EC8A12;
                box-shadow: 0 0 0 1.5px rgba(236,138,18,.5);
            }
            .myfeeds-amz-eye.is-on {
                color: #fff;
                background: linear-gradient(135deg, #EC8A12 0%, #CC6A00 100%);
                border-color: #CC6A00;
            }
            .myfeeds-amz-eye .eye-slash { opacity: 0; }
            .myfeeds-amz-eye.is-on .eye-slash { opacity: 1; }
            .myfeeds-amz-eye:disabled { opacity: .5; cursor: default; }

            #myfeeds-amazon-modal input[type=password]:focus {
                border-color: {$b};
                box-shadow: 0 0 0 1px {$b};
                outline: 2px solid transparent;
            }
            #myfeeds-amazon-modal .form-table td input[type=password] { width: 100%; max-width: 100%; }
            #myfeeds-amazon-modal .form-table td input.small-text { width: 6em; }

            .myfeeds-amz-status {
                border-left: 4px solid #dcdcde;
                background: #f6f7f7;
                padding: 10px 14px;
                margin-bottom: 16px;
                font-size: 13px;
            }
            .myfeeds-amz-status strong { display: block; }
            .myfeeds-amz-status span { color: #50575e; }
            .myfeeds-amz-status.is-info { border-left-color: #72aee6; background: #f2f7fc; }
            .myfeeds-amz-status.is-ok { border-left-color: #00a32a; background: #f0f7f1; }
            .myfeeds-amz-status.is-warn { border-left-color: {$b}; background: #fff7ec; }
            .myfeeds-amz-status.is-error { border-left-color: #d63638; background: #fcf0f1; }

            .myfeeds-amz-limits {
                display: flex;
                gap: 20px;
                flex-wrap: wrap;
                border-top: 1px solid #f0f0f1;
                margin-top: 20px;
                padding-top: 16px;
                font-size: 13px;
            }
            .myfeeds-amz-budget { flex: 1 1 200px; min-width: 0; }
            .myfeeds-amz-budget__label {
                display: block;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: .04em;
                font-size: 11px;
                color: #646970;
            }
            .myfeeds-amz-budget__value {
                display: block;
                font-size: 26px;
                font-weight: 600;
                line-height: 1.25;
                color: #1d2327;
            }
            .myfeeds-amz-budget__total { font-size: 15px; font-weight: 400; color: #646970; }
            .myfeeds-amz-budget__bar {
                display: block;
                height: 5px;
                border-radius: 3px;
                background: #f0f0f1;
                overflow: hidden;
                margin: 8px 0;
            }
            .myfeeds-amz-budget__bar > span {
                display: block;
                height: 100%;
                background: linear-gradient(90deg, #E8820A, #C96500);
            }
            .myfeeds-amz-budget__note { display: block; color: #646970; font-size: 12px; }
            .myfeeds-amz-rules { flex: 1 1 300px; min-width: 0; }
            .myfeeds-amz-rules__title {
                display: block;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: .04em;
                font-size: 11px;
                color: #646970;
                margin-bottom: 4px;
            }
            .myfeeds-amz-rules ul { margin: 0; padding: 0; list-style: none; }
            .myfeeds-amz-rules li { padding: 5px 0; border-bottom: 1px solid #f0f0f1; }
            .myfeeds-amz-rules li:last-child { border-bottom: 0; }
        ";
    }
}
