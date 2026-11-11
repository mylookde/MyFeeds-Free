<?php
/**
 * API source dialog on the feeds screen.
 *
 * One way in, like Amazon: "+ Add API source" opens a dialog that holds
 * the platform, the connection, its status, and the form that turns one
 * advertiser into a feed. No submenu - a second place to edit the same
 * credentials is a second place for them to drift.
 *
 * The button stands in the table header, or, on a screen that offers each
 * kind of source as a door of its own (MyFeeds_Source_Rules::doors()), in
 * the door for API sources - never in both, it has one id.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Source_Admin {

    const FEEDS_PAGE = 'myfeeds-feeds';

    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('myfeeds_source_doors', array(__CLASS__, 'render_door'));
        add_action('myfeeds_feeds_table_actions', array(__CLASS__, 'render_add_button'));
        add_action('myfeeds_feeds_page_footer', array(__CLASS__, 'render_modal'));
    }

    public static function settings_url() {
        return admin_url('admin.php?page=' . self::FEEDS_PAGE . '#myfeeds-source');
    }

    public static function enqueue($hook) {
        if (strpos((string) $hook, self::FEEDS_PAGE) === false) {
            return;
        }

        $js  = MYFEEDS_PLUGIN_DIR . 'assets/source-admin.js';
        $css = MYFEEDS_PLUGIN_DIR . 'assets/source-admin.css';

        wp_enqueue_script(
            'myfeeds-source-admin',
            MYFEEDS_PLUGIN_URL . 'assets/source-admin.js',
            array('wp-api-fetch'),
            file_exists($js) ? (string) filemtime($js) : MYFEEDS_VERSION,
            true
        );

        wp_enqueue_style(
            'myfeeds-source-admin',
            MYFEEDS_PLUGIN_URL . 'assets/source-admin.css',
            array(),
            file_exists($css) ? (string) filemtime($css) : MYFEEDS_VERSION
        );

        wp_localize_script('myfeeds-source-admin', 'myfeedsSourceAdmin', array(
            'root'      => MyFeeds_Source_REST::NS,
            'nonce'     => wp_create_nonce('wp_rest'),
            'connected' => MyFeeds_Rakuten_Credentials::is_configured(),
            'cap'       => MyFeeds_Rakuten_Client::RESULT_CAP,
            'i18n'      => array(
                'saving'         => __('Saving…', 'myfeeds-affiliate-feed-manager'),
                'testing'        => __('Asking Rakuten…', 'myfeeds-affiliate-feed-manager'),
                'saved'          => __('Saved.', 'myfeeds-affiliate-feed-manager'),
                'confirm'        => __('Disconnect Rakuten? Feeds already created keep their products until you delete them.', 'myfeeds-affiliate-feed-manager'),
                'failed'         => __('Something went wrong.', 'myfeeds-affiliate-feed-manager'),
                'loading'        => __('Loading advertisers…', 'myfeeds-affiliate-feed-manager'),
                'no_partners'    => __('No active advertiser partnerships found. Apply to advertisers in the Rakuten dashboard first.', 'myfeeds-affiliate-feed-manager'),
                'pick'           => __('Choose an advertiser to see how many products it lists.', 'myfeeds-affiliate-feed-manager'),
                'counting'       => __('Counting…', 'myfeeds-affiliate-feed-manager'),
                'count_none'     => __('No products match. Rakuten returned none for this advertiser and this selection.', 'myfeeds-affiliate-feed-manager'),
                /* translators: %s: number of products */
                'count_ok'       => __('%s products match. Everything fits in one pass.', 'myfeeds-affiliate-feed-manager'),
                /* translators: 1: number of products, 2: the most products Rakuten returns for one query */
                'count_over'     => __('%1$s products match. Rakuten answers at most %2$s per query, so MyFeeds fetches this catalogue in slices and shows how much it reached.', 'myfeeds-affiliate-feed-manager'),
                'creating'       => __('Starting the first fetch…', 'myfeeds-affiliate-feed-manager'),
                'created'        => __('Fetching. Rakuten hands out rows (one per size or colour); the feed and its product count appear in the table as soon as the first snapshot is complete.', 'myfeeds-affiliate-feed-manager'),
                'refresh'        => __('Refresh list', 'myfeeds-affiliate-feed-manager'),
                'filter_empty'   => __('No advertiser matches that filter.', 'myfeeds-affiliate-feed-manager'),
                'refreshing'     => __('Refresh started.', 'myfeeds-affiliate-feed-manager'),
                'edit_title'     => __('Edit Rakuten feed', 'myfeeds-affiliate-feed-manager'),
                'save_changes'   => __('Save and refresh', 'myfeeds-affiliate-feed-manager'),
                'create_feed'    => __('Create feed', 'myfeeds-affiliate-feed-manager'),
                'saved_feed'     => __('Saved. Fetching the catalogue again…', 'myfeeds-affiliate-feed-manager'),
                'progress_title' => __('API sources in progress', 'myfeeds-affiliate-feed-manager'),
                /* translators: 1: feed name, 2: rows fetched so far, 3: query number, 4: number of queries */
                'progress_fetching' => __('%1$s: fetching… %2$s rows so far (query %3$s of %4$s)', 'myfeeds-affiliate-feed-manager'),
                /* translators: 1: feed name, 2: seconds */
                'progress_waiting'  => __('%1$s: waiting %2$s s for Rakuten\'s rate limit', 'myfeeds-affiliate-feed-manager'),
                /* translators: 1: feed name, 2: error message */
                'progress_failed'   => __('%1$s: failed - %2$s', 'myfeeds-affiliate-feed-manager'),
                /* translators: 1: feed name, 2: number of products */
                'progress_done'     => __('%1$s: done, %2$s products. Importing…', 'myfeeds-affiliate-feed-manager'),
                'cats_loading'      => __('Reading the advertiser\'s categories…', 'myfeeds-affiliate-feed-manager'),
                'cats_from_feed'    => __('Row counts from your feed. Rakuten could not be sampled for further categories right now.', 'myfeeds-affiliate-feed-manager'),
                /* translators: %s: number of products in the sample */
                'cats_from_sample'  => __('Categories seen in a sample of %s products from Rakuten. The exact total appears below once you pick.', 'myfeeds-affiliate-feed-manager'),
                /* translators: %s: number of products in the sample */
                'cats_from_both'    => __('Row counts from your feed, plus categories seen in a sample of %s products that your feed does not hold yet. The exact total appears below once you pick.', 'myfeeds-affiliate-feed-manager'),
                /* translators: %s: number of rows */
                'cat_rows'          => __('%s rows', 'myfeeds-affiliate-feed-manager'),
                /* translators: 1: products of the sample in this category, 2: products in the sample */
                'cat_sampled'       => __('in %1$s of %2$s sampled', 'myfeeds-affiliate-feed-manager'),
                'cat_new'           => __(', not in your feed yet', 'myfeeds-affiliate-feed-manager'),
                'cats_none'         => __('Rakuten returned no categories for this advertiser.', 'myfeeds-affiliate-feed-manager'),
                'cats_pick'         => __('Choose an advertiser first.', 'myfeeds-affiliate-feed-manager'),
            ),
        ));
    }

    // =====================================================================
    // Surfaces
    // =====================================================================

    /**
     * The door for API sources, on a screen that offers each kind of
     * source as a door of its own (myfeeds_source_doors, told the kinds
     * it offers).
     *
     * @param mixed $doors MyFeeds_Source_Rules::doors().
     */
    public static function render_door($doors = array()) {
        if (!is_array($doors) || !in_array('api', $doors, true)) {
            return;
        }
        ?>
        <div class="myfeeds-source-door" data-testid="source-door-api">
            <h3><?php esc_html_e('Add an API source', 'myfeeds-affiliate-feed-manager'); ?></h3>
            <p><?php esc_html_e('For networks that hand out no feed link: connect your account and choose the advertisers whose products you want. Available today: Rakuten Advertising.', 'myfeeds-affiliate-feed-manager'); ?></p>
            <?php self::add_button(); ?>
        </div>
        <?php
    }

    /**
     * In the table header, while an API source may be connected or grow,
     * and not where the door above already carries the button.
     */
    public static function render_add_button() {
        if (!class_exists('MyFeeds_Source_Rules') || !MyFeeds_Source_Rules::can_connect('api', 'rakuten')
            || in_array('api', (array) MyFeeds_Source_Rules::doors(), true)) {
            return;
        }
        self::add_button();
    }

    private static function add_button() {
        ?>
        <button type="button" id="myfeeds-add-source-btn" class="button" data-testid="add-source-btn"><?php esc_html_e('+ Add API source', 'myfeeds-affiliate-feed-manager'); ?></button>
        <?php
    }

    /** True once the connection passed its test; the feed half of the dialog shows then. */
    public static function connection_ready() {
        $status = MyFeeds_Rakuten_Credentials::get_status();

        return MyFeeds_Rakuten_Credentials::is_configured()
            && $status['state'] === MyFeeds_Rakuten_Credentials::STATE_OK;
    }

    public static function render_modal() {
        $connection = MyFeeds_Rakuten_Credentials::get();
        $configured = MyFeeds_Rakuten_Credentials::is_configured();
        $ready      = self::connection_ready();
        ?>
        <div id="myfeeds-source-modal" class="myfeeds-modal-overlay" style="display:none;" data-testid="source-modal" data-ready="<?php echo $ready ? '1' : '0'; ?>">
            <div class="myfeeds-modal-content myfeeds-source-modal-content">
                <div class="myfeeds-modal-header">
                    <h3><?php echo esc_html($configured ? __('Rakuten Advertising', 'myfeeds-affiliate-feed-manager') : __('Connect an API source', 'myfeeds-affiliate-feed-manager')); ?></h3>
                    <button type="button" class="myfeeds-modal-close" id="myfeeds-source-modal-close">&times;</button>
                </div>
                <div class="myfeeds-modal-body">
                    <?php self::render_platform_field(); ?>

                    <?php self::render_status(); ?>

                    <div class="myfeeds-help-text myfeeds-src-help">
                        <p>
                            <strong><?php esc_html_e('Rakuten Advertising has no feed link.', 'myfeeds-affiliate-feed-manager'); ?></strong>
                            <?php esc_html_e('Its catalogue comes through the Product Search API instead. MyFeeds asks it page by page, writes the answer into a snapshot file, and from then on the result is an ordinary feed: same mapping, same blocks, same nightly refresh.', 'myfeeds-affiliate-feed-manager'); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Where to find your credentials:', 'myfeeds-affiliate-feed-manager'); ?></strong>
                            <?php
                            printf(
                                /* translators: %s: menu path inside the Rakuten developer portal */
                                esc_html__('Sign in to developers.rakutenadvertising.com with your publisher login, open %s, add an application, and copy its Client ID and Client Secret. The SID is your publisher account id from the Rakuten dashboard.', 'myfeeds-affiliate-feed-manager'),
                                '<code>' . esc_html__('Applications', 'myfeeds-affiliate-feed-manager') . '</code>'
                            );
                            ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Rakuten returns products of the advertisers you are partnered with. If one of them shows zero products, Rakuten returned none for it; its documentation does not say why. The advertiser or your Rakuten contact can tell you.', 'myfeeds-affiliate-feed-manager'); ?>
                        </p>
                        <p>
                            <a href="https://developers.rakutenadvertising.com/" target="_blank" rel="noopener">
                                <?php esc_html_e('Open the Rakuten developer portal', 'myfeeds-affiliate-feed-manager'); ?> &rarr;
                            </a>
                        </p>
                    </div>

                    <?php self::render_connection_fields($connection); ?>

                    <p class="myfeeds-src-actions">
                        <button type="button" class="button" id="mf-src-test"><?php esc_html_e('Save and test', 'myfeeds-affiliate-feed-manager'); ?></button>
                        <button type="button" class="button" id="mf-src-save"><?php esc_html_e('Save only', 'myfeeds-affiliate-feed-manager'); ?></button>
                        <?php if (MyFeeds_Rakuten_Credentials::is_stored()) : ?>
                            <button type="button" class="button" id="mf-src-disconnect"><?php esc_html_e('Disconnect', 'myfeeds-affiliate-feed-manager'); ?></button>
                        <?php endif; ?>
                        <span id="mf-src-feedback" class="myfeeds-src-feedback"></span>
                    </p>

                    <?php self::render_feed_form($ready); ?>
                </div>
            </div>
        </div>
        <div id="myfeeds-source-progress" class="myfeeds-source-progress" hidden data-testid="source-progress">
            <strong><?php esc_html_e('API sources in progress', 'myfeeds-affiliate-feed-manager'); ?></strong>
            <ul></ul>
        </div>
        <?php
    }

    // =====================================================================
    // Modal sections
    // =====================================================================

    public static function status_html() {
        ob_start();
        self::render_status();
        return ob_get_clean();
    }

    private static function render_status() {
        echo '<div id="mf-src-status">';
        self::render_status_inner();
        echo '</div>';
    }

    /**
     * Two environment problems look exactly like bad credentials from the
     * outside, so they are named before anything else.
     */
    private static function render_status_inner() {
        if (MyFeeds_Rakuten_Client::outbound_blocked()) {
            self::status_line(
                'error',
                __('This site blocks outgoing requests', 'myfeeds-affiliate-feed-manager'),
                sprintf(
                    /* translators: 1: PHP constant name, 2: host name */
                    __('%1$s is on in wp-config.php and Rakuten is not on the allow list. Add %2$s to WP_ACCESSIBLE_HOSTS, or ask your host to.', 'myfeeds-affiliate-feed-manager'),
                    'WP_HTTP_BLOCK_EXTERNAL',
                    MyFeeds_Rakuten_Client::required_host()
                )
            );
        }

        if (MyFeeds_Rakuten_Credentials::secret_unreadable()) {
            self::status_line(
                'error',
                __('The stored client secret can no longer be read', 'myfeeds-affiliate-feed-manager'),
                __('The site security keys were rotated, or the server lost the encryption library. Paste the secret again.', 'myfeeds-affiliate-feed-manager')
            );
        }

        if (!MyFeeds_Rakuten_Credentials::is_configured()) {
            return;
        }

        $status = MyFeeds_Rakuten_Credentials::get_status();

        $map = array(
            MyFeeds_Rakuten_Credentials::STATE_UNTESTED => array(
                'info',
                __('Credentials saved, not checked yet', 'myfeeds-affiliate-feed-manager'),
                __('Press Save and test to see what Rakuten says about this account.', 'myfeeds-affiliate-feed-manager'),
            ),
            MyFeeds_Rakuten_Credentials::STATE_OK => array(
                'ok',
                __('Connected', 'myfeeds-affiliate-feed-manager'),
                $status['message'] !== '' ? $status['message'] : __('Rakuten is answering.', 'myfeeds-affiliate-feed-manager'),
            ),
            MyFeeds_Rakuten_Credentials::STATE_INVALID => array(
                'error',
                __('Rakuten rejected the credentials', 'myfeeds-affiliate-feed-manager'),
                __('Check the client ID, the client secret and the SID. The SID is the publisher account id, not the application id.', 'myfeeds-affiliate-feed-manager'),
            ),
            MyFeeds_Rakuten_Credentials::STATE_NO_PARTNERS => array(
                'warn',
                __('Connected, but no active partnerships', 'myfeeds-affiliate-feed-manager'),
                __('The credentials work. Rakuten lists no advertiser that has approved you yet, so there is nothing to build a feed from.', 'myfeeds-affiliate-feed-manager'),
            ),
            MyFeeds_Rakuten_Credentials::STATE_THROTTLED => array(
                'warn',
                __('Rakuten is rate limiting the account', 'myfeeds-affiliate-feed-manager'),
                __('Every endpoint allows 100 calls a minute. This clears itself within a minute.', 'myfeeds-affiliate-feed-manager'),
            ),
            MyFeeds_Rakuten_Credentials::STATE_BLOCKED => array(
                'error',
                __('This site cannot reach Rakuten', 'myfeeds-affiliate-feed-manager'),
                $status['message'],
            ),
            MyFeeds_Rakuten_Credentials::STATE_ERROR => array(
                'error',
                __('Last call to Rakuten failed', 'myfeeds-affiliate-feed-manager'),
                $status['message'],
            ),
        );

        $state = isset($map[$status['state']]) ? $status['state'] : MyFeeds_Rakuten_Credentials::STATE_ERROR;
        list($kind, $title, $text) = $map[$state];

        self::status_line($kind, $title, $text);
    }

    private static function status_line($kind, $title, $text) {
        ?>
        <div class="myfeeds-src-status is-<?php echo esc_attr($kind); ?>">
            <strong><?php echo esc_html($title); ?></strong>
            <?php if ($text !== '') : ?><span><?php echo esc_html($text); ?></span><?php endif; ?>
        </div>
        <?php
    }

    /**
     * The dialog's first field: which network's API. One today, chosen;
     * everything below it is that platform's form.
     */
    private static function render_platform_field() {
        ?>
        <table class="form-table myfeeds-src-fields myfeeds-src-platform" role="presentation">
            <tr>
                <th scope="row"><label for="mf-src-platform"><?php esc_html_e('Affiliate platform', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                <td>
                    <select id="mf-src-platform" data-testid="source-platform">
                        <option value="rakuten" selected><?php esc_html_e('Rakuten Advertising', 'myfeeds-affiliate-feed-manager'); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    private static function render_connection_fields($connection) {
        $has_secret = $connection['client_secret'] !== '';
        ?>
        <table class="form-table myfeeds-src-fields" role="presentation">
            <tr>
                <th scope="row"><label for="mf-src-client-id"><?php esc_html_e('Client ID', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                <td><input type="text" id="mf-src-client-id" class="regular-text" value="<?php echo esc_attr($connection['client_id']); ?>" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><label for="mf-src-secret"><?php esc_html_e('Client secret', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                <td>
                    <span class="myfeeds-src-secret">
                        <input type="password" id="mf-src-secret" class="regular-text" value="" autocomplete="new-password"
                            placeholder="<?php echo $has_secret ? esc_attr__('Stored. Leave empty to keep it.', 'myfeeds-affiliate-feed-manager') : ''; ?>">
                        <button type="button" id="mf-src-reveal" class="myfeeds-src-eye"
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
                            <?php esc_html_e('Saved on this site, so you can read it back here if you ever need it again.', 'myfeeds-affiliate-feed-manager'); ?>
                        <?php else : ?>
                            <?php esc_html_e('Shown once in the developer portal. Paste it here and this site keeps it for you.', 'myfeeds-affiliate-feed-manager'); ?>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="mf-src-sid"><?php esc_html_e('SID', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                <td>
                    <input type="text" id="mf-src-sid" class="regular-text" value="<?php echo esc_attr($connection['sid']); ?>" autocomplete="off" placeholder="1234567">
                    <p class="description"><?php esc_html_e('Your publisher account id. It scopes the access token to this account.', 'myfeeds-affiliate-feed-manager'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * The half of the dialog that makes a feed. Rendered always, shown
     * only once the connection has passed its test - the JS reads
     * data-ready and flips the section.
     */
    private static function render_feed_form($ready) {
        ?>
        <div id="mf-src-feed" class="myfeeds-src-feed" <?php echo $ready ? '' : 'hidden'; ?>>
            <h4><?php esc_html_e('Add a feed from an advertiser', 'myfeeds-affiliate-feed-manager'); ?></h4>
            <p class="description">
                <?php esc_html_e('One feed is one advertiser. Pick it, decide whether you want everything it lists or only a part, and MyFeeds fetches the catalogue into a feed of its own.', 'myfeeds-affiliate-feed-manager'); ?>
            </p>

            <input type="hidden" id="mf-src-editing" value="">
            <p id="mf-src-editing-note" class="myfeeds-src-editing" hidden></p>

            <table class="form-table myfeeds-src-fields" role="presentation">
                <tr id="mf-src-advertiser-row">
                    <th scope="row"><label for="mf-src-filter"><?php esc_html_e('Advertiser', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                    <td>
                        <input type="search" id="mf-src-filter" class="regular-text" placeholder="<?php esc_attr_e('Filter by name…', 'myfeeds-affiliate-feed-manager'); ?>" autocomplete="off">
                        <select id="mf-src-advertiser" size="8" class="myfeeds-src-list" aria-label="<?php esc_attr_e('Advertisers', 'myfeeds-affiliate-feed-manager'); ?>"></select>
                        <p class="description">
                            <span id="mf-src-list-note"></span>
                            <button type="button" class="button-link" id="mf-src-reload"><?php esc_html_e('Refresh list', 'myfeeds-affiliate-feed-manager'); ?></button>
                        </p>
                    </td>
                </tr>
                <tr id="mf-src-scope-row">
                    <th scope="row"><?php esc_html_e('Scope', 'myfeeds-affiliate-feed-manager'); ?></th>
                    <td>
                        <fieldset>
                            <label><input type="radio" name="mf-src-mode" value="all" checked> <?php esc_html_e('Everything this advertiser lists', 'myfeeds-affiliate-feed-manager'); ?></label><br>
                            <label><input type="radio" name="mf-src-mode" value="keywords"> <?php esc_html_e('Only products matching keywords', 'myfeeds-affiliate-feed-manager'); ?></label><br>
                            <label><input type="radio" name="mf-src-mode" value="categories"> <?php esc_html_e('Only products in categories', 'myfeeds-affiliate-feed-manager'); ?></label>
                        </fieldset>
                        <div id="mf-src-cats" class="myfeeds-src-cats" hidden>
                            <input type="search" id="mf-src-cat-filter" class="regular-text" placeholder="<?php esc_attr_e('Filter categories…', 'myfeeds-affiliate-feed-manager'); ?>" autocomplete="off">
                            <div id="mf-src-cat-list" class="myfeeds-src-cat-list" role="group" aria-label="<?php esc_attr_e('Categories', 'myfeeds-affiliate-feed-manager'); ?>"></div>
                            <p class="description" id="mf-src-cat-note"></p>
                        </div>
                        <input type="text" id="mf-src-terms" class="regular-text" placeholder="<?php esc_attr_e('sneakers, running shoes', 'myfeeds-affiliate-feed-manager'); ?>" hidden>
                        <p class="description" id="mf-src-terms-help" hidden>
                            <?php esc_html_e('Comma-separated. Each term is fetched on its own; a product can match more than one, that is fine.', 'myfeeds-affiliate-feed-manager'); ?>
                        </p>
                    </td>
                </tr>
                <tr id="mf-src-discovered-row" hidden>
                    <th scope="row"><label for="mf-src-discovered"><?php esc_html_e('Pulled-in searches', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                    <td>
                        <input type="text" id="mf-src-discovered" class="regular-text" autocomplete="off">
                        <p class="description"><?php esc_html_e('Search terms the product picker pulled from Rakuten into this feed. They are fetched again every night. Comma-separated; remove one to stop refreshing it.', 'myfeeds-affiliate-feed-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mf-src-language"><?php esc_html_e('Language', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                    <td>
                        <select id="mf-src-language">
                            <option value=""><?php esc_html_e('Any', 'myfeeds-affiliate-feed-manager'); ?></option>
                            <?php foreach (MyFeeds_Rakuten_Source::LANGUAGES as $language) : ?>
                                <option value="<?php echo esc_attr($language); ?>"><?php echo esc_html($language); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Only matters for advertisers that list the same product in several languages.', 'myfeeds-affiliate-feed-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mf-src-name"><?php esc_html_e('Feed name', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                    <td>
                        <input type="text" id="mf-src-name" class="regular-text" autocomplete="off">
                        <p class="description"><?php esc_html_e('Shown in the feeds table.', 'myfeeds-affiliate-feed-manager'); ?></p>
                    </td>
                </tr>
            </table>

            <div id="mf-src-count" class="myfeeds-src-count"></div>

            <p class="myfeeds-src-actions">
                <button type="button" class="button" id="mf-src-create" disabled><?php esc_html_e('Create feed', 'myfeeds-affiliate-feed-manager'); ?></button>
                <span id="mf-src-feed-feedback" class="myfeeds-src-feedback"></span>
            </p>
        </div>
        <?php
    }
}
