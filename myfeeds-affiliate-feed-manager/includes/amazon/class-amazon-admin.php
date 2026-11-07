<?php
/**
 * The Amazon window on the Feeds screen.
 *
 * One door: the orange button next to the feeds opens a window that holds
 * what an Amazon link needs - the Amazon site, the partner tag, where
 * visitors abroad land, the badge - and one Save. There is deliberately no
 * separate submenu; a second place to edit the same settings is a second
 * place for them to drift.
 *
 * The button hangs on myfeeds_feeds_table_actions, the window on
 * myfeeds_feeds_page_footer. What else the window holds comes in through
 * its sections (myfeeds_amazon_modal_sections): title, top, help,
 * site_note, abroad_note, fields (rows under the link's), actions, bottom.
 *
 * Shared by both plugins, apart from the text domain.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Admin {

    const BRAND      = '#FC9000';
    const FEEDS_PAGE = 'myfeeds-feeds';
    const HANDLE     = 'myfeeds-amazon-admin';
    /**
     * What settings_url() ends on. No element carries it as an id: the
     * settings are a window, and amazon-admin.js opens it when the page
     * is reached with this anchor.
     */
    const ANCHOR     = 'myfeeds-amazon';

    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('myfeeds_feeds_table_actions', array(__CLASS__, 'render_add_button'));
        add_action('myfeeds_feeds_page_footer', array(__CLASS__, 'render_modal'));
    }

    /**
     * Where a caller should send someone to manage the connection.
     */
    public static function settings_url() {
        return admin_url('admin.php?page=' . self::FEEDS_PAGE . '#' . self::ANCHOR);
    }

    public static function enqueue($hook) {
        if (strpos((string) $hook, self::FEEDS_PAGE) === false) {
            return;
        }

        $rel = 'assets/amazon-admin.js';
        $ver = function_exists('myfeeds_asset_ver') ? myfeeds_asset_ver($rel) : (string) filemtime(MYFEEDS_PLUGIN_DIR . $rel);

        // The badge field picks from the media library.
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        wp_enqueue_script(
            self::HANDLE,
            MYFEEDS_PLUGIN_URL . $rel,
            array('wp-api-fetch'),
            $ver,
            true
        );

        wp_localize_script(self::HANDLE, 'myfeedsAmazonAdmin', array(
            'root'   => MyFeeds_Amazon_REST::NS,
            'nonce'  => wp_create_nonce('wp_rest'),
            'anchor' => self::ANCHOR,
            // Each Amazon site's own tag: switching the site in the window
            // shows that one, so a Save never puts one site's tag under
            // another site.
            'tags'   => (object) MyFeeds_Amazon_Link_Settings::partner_tags(),
            'i18n'   => array(
                'saving' => __('Saving…', 'myfeeds-affiliate-feed-manager'),
                'saved'  => __('Saved.', 'myfeeds-affiliate-feed-manager'),
                'failed' => __('Something went wrong.', 'myfeeds-affiliate-feed-manager'),
                'badge'  => __('Available at Amazon badge', 'myfeeds-affiliate-feed-manager'),
            ),
        ));

        wp_register_style(self::HANDLE, false, array(), $ver);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, self::css());
    }

    // =====================================================================
    // Surfaces
    // =====================================================================

    /**
     * Whether there is anything left to set up, for the button's label:
     * a partner tag is all a link needs.
     */
    private static function is_set_up() {
        $set_up = !empty(MyFeeds_Amazon_Link_Settings::available_marketplaces());
        return (bool) apply_filters('myfeeds_amazon_is_set_up', $set_up);
    }

    public static function render_add_button() {
        ?>
        <button type="button" id="myfeeds-add-amazon-btn" class="button" data-testid="add-amazon-btn"><?php
            echo esc_html(self::is_set_up() ? __('Amazon', 'myfeeds-affiliate-feed-manager') : __('+ Add Amazon', 'myfeeds-affiliate-feed-manager'));
        ?></button>
        <?php
    }

    /**
     * What a reader has to be told, said where the owner sets Amazon up:
     * the Associates Operating Agreement asks every Associate to say on
     * the site that it earns from qualifying purchases. The sentence is
     * Amazon's wording, not ours to translate.
     */
    public static function disclosure_html() {
        ob_start();
        ?>
        <p class="myfeeds-amz-disclosure">
            <strong><?php esc_html_e('Say it on your site.', 'myfeeds-affiliate-feed-manager'); ?></strong>
            <?php esc_html_e('Amazon asks every Associate to show this sentence where readers see it, for example in the footer or on your disclosure page:', 'myfeeds-affiliate-feed-manager'); ?>
            <q>As an Amazon Associate I earn from qualifying purchases.</q>
            <?php esc_html_e('On Amazon.de:', 'myfeeds-affiliate-feed-manager'); ?>
            <q lang="de">Als Amazon-Partner verdiene ich an qualifizierten Verkäufen.</q>
        </p>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * The window's parts, as a link needs them, and as whatever adds to
     * the window makes of them (myfeeds_amazon_modal_sections). Every
     * part is HTML, escaped by whoever wrote it.
     *
     * @return array<string,string>
     */
    private static function sections() {
        ob_start();
        ?>
        <p>
            <strong><?php esc_html_e('Add Amazon products by link or ASIN.', 'myfeeds-affiliate-feed-manager'); ?></strong>
            <?php esc_html_e('Save the partner tag of your Amazon Associates account. Then paste an Amazon link or an ASIN in the product picker or in a Look: the card shows the name you give it and links to Amazon with your tag.', 'myfeeds-affiliate-feed-manager'); ?>
        </p>
        <?php
        $help = (string) ob_get_clean() . self::disclosure_html();

        $defaults = array(
            'title'       => esc_html(self::is_set_up() ? __('Amazon', 'myfeeds-affiliate-feed-manager') : __('Add Amazon', 'myfeeds-affiliate-feed-manager')),
            'top'         => '',
            'help'        => $help,
            'site_note'   => esc_html__('Each Amazon site needs its own partner tag, so switch here and save a tag per site you want to use.', 'myfeeds-affiliate-feed-manager'),
            'abroad_note' => '',
            'fields'      => '',
            'actions'     => '<button type="button" class="button myfeeds-amz-primary" id="mf-amz-save">' . esc_html__('Save', 'myfeeds-affiliate-feed-manager') . '</button>',
            'bottom'      => '',
        );

        $sections = apply_filters('myfeeds_amazon_modal_sections', $defaults);
        if (!is_array($sections)) {
            return $defaults;
        }
        $out = array();
        foreach ($defaults as $key => $value) {
            $out[$key] = isset($sections[$key]) && is_string($sections[$key]) ? $sections[$key] : $value;
        }
        return $out;
    }

    public static function render_modal() {
        $marketplace = MyFeeds_Amazon_Link_Settings::marketplace();
        $partner_tag = MyFeeds_Amazon_Link_Settings::partner_tag($marketplace);
        $badge_id    = MyFeeds_Amazon_Link_Settings::badge_id();
        $s           = self::sections();
        // Every part below is HTML its author escaped (sections()).
        ?>
        <div id="myfeeds-amazon-modal" class="myfeeds-modal-overlay" style="display:none;" data-testid="amazon-modal">
            <div class="myfeeds-modal-content">
                <div class="myfeeds-modal-header">
                    <h3><?php echo $s['title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                    <button type="button" class="myfeeds-modal-close" id="myfeeds-amazon-modal-close">&times;</button>
                </div>
                <div class="myfeeds-modal-body">
                    <?php echo $s['top']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                    <div class="myfeeds-help-text myfeeds-amz-help">
                        <?php echo $s['help']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>

                    <table class="form-table myfeeds-amz-fields" role="presentation">
                        <tr>
                            <th scope="row"><label for="mf-amz-marketplace"><?php esc_html_e('Amazon site', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                            <td>
                                <select id="mf-amz-marketplace">
                                    <?php foreach (MyFeeds_Amazon_Locales::all() as $key => $entry) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($key, $marketplace); ?>>
                                            <?php echo esc_html($entry['label'] . ' — ' . $key); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php echo $s['site_note']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mf-amz-tag"><?php esc_html_e('Partner tag', 'myfeeds-affiliate-feed-manager'); ?></label></th>
                            <td>
                                <input type="text" id="mf-amz-tag" class="regular-text" value="<?php echo esc_attr($partner_tag); ?>" placeholder="yourstore-21">
                                <p class="description"><?php esc_html_e('Your Associates tracking id for the site selected above. Amazon cannot credit a sale without it.', 'myfeeds-affiliate-feed-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Visitors abroad', 'myfeeds-affiliate-feed-manager'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="mf-amz-keep-market" <?php checked(MyFeeds_Amazon_Link_Settings::keep_market()); ?>>
                                    <?php esc_html_e('Keep them on the Amazon site of my partner tag', 'myfeeds-affiliate-feed-manager'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Amazon sends visitors from other countries to a search on their own Amazon. Your tag earns there only if you turned those countries on in Associates Central under Account Settings, Global Earning Preferences (formerly OneLink). Leave this on unless you did.', 'myfeeds-affiliate-feed-manager'); ?>
                                    <?php echo $s['abroad_note']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Amazon badge', 'myfeeds-affiliate-feed-manager'); ?></th>
                            <td>
                                <input type="hidden" id="mf-amz-badge" value="<?php echo (int) $badge_id; ?>">
                                <span id="mf-amz-badge-preview" class="myfeeds-amz-badge-preview"><?php
                                    echo $badge_id ? wp_get_attachment_image($badge_id, 'medium', false, array('style' => 'width:120px;height:auto')) : '';
                                ?></span>
                                <button type="button" class="button" id="mf-amz-badge-pick"><?php esc_html_e('Choose image', 'myfeeds-affiliate-feed-manager'); ?></button>
                                <button type="button" class="button-link" id="mf-amz-badge-clear"<?php echo $badge_id ? '' : ' style="display:none"'; ?>><?php esc_html_e('Remove', 'myfeeds-affiliate-feed-manager'); ?></button>
                                <p class="description">
                                    <?php esc_html_e('Optional. Amazon cards without a product photo show the official "Available at Amazon" badge as their button. Download it unchanged from Associates Central (Amazon\'s trademark rules allow this one mark, not the Amazon logo) and upload it here. Without it, the button is plain text. The required trademark notice is added to the page automatically.', 'myfeeds-affiliate-feed-manager'); ?>
                                </p>
                            </td>
                        </tr>
                        <?php echo $s['fields']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </table>

                    <p class="myfeeds-amz-actions">
                        <?php echo $s['actions']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <span id="mf-amz-feedback" class="myfeeds-amz-feedback"></span>
                    </p>

                    <?php echo $s['bottom']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </div>
        </div>
        <?php
    }

    // =====================================================================
    // Styles
    // =====================================================================

    /**
     * Mirrors the Add Feed modal one for one; only the accent changes,
     * from the brand violet to the brand orange. IDs in the selectors,
     * because WordPress ships .wp-core-ui .button, which outranks a bare
     * class and would otherwise repaint the button grey.
     */
    private static function css() {
        $b = self::BRAND;

        return "
            /* The rule in assets/admin.css that paints Add Feed, with the
               purple swapped for orange. Not core's .button-primary: this
               plugin overrides that with its own gradient, 6px radius, no
               border, and a hover that lifts the button and widens its
               shadow while leaving the colour alone. */
            #myfeeds-add-amazon-btn,
            #mf-amz-save.myfeeds-amz-primary {
                background: linear-gradient(135deg, #EC8A12 0%, #CC6A00 100%) !important;
                text-align: center;
                color: #fff !important;
                border: none !important;
                border-radius: 6px !important;
                box-shadow: 0 2px 4px rgba(222, 122, 0, 0.2) !important;
                text-shadow: none !important;
                transition: transform 0.15s, box-shadow 0.15s !important;
            }

            #myfeeds-add-amazon-btn:hover,
            #mf-amz-save.myfeeds-amz-primary:hover {
                background: linear-gradient(135deg, #EC8A12 0%, #CC6A00 100%) !important;
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(222, 122, 0, 0.35) !important;
            }

            #myfeeds-add-amazon-btn:disabled,
            #mf-amz-save.myfeeds-amz-primary:disabled {
                opacity: 0.5;
                cursor: not-allowed;
                transform: none !important;
            }

            /* WordPress paints focused fields blue. Inside this modal the
               accent is orange, and a blue ring in an orange dialog reads
               as a stray element. */
            #myfeeds-amazon-modal input[type=text]:focus,
            #myfeeds-amazon-modal select:focus {
                border-color: {$b};
                box-shadow: 0 0 0 1px {$b};
                outline: 2px solid transparent;
            }

            /* Same width as the Add Feed dialog, stated here rather than
               borrowed from its class: assets/admin.css paints that class
               purple with !important, so wearing it to inherit a width
               dragged the whole violet theme along. */
            #myfeeds-amazon-modal .myfeeds-modal-content { max-width: 700px; }

            /* The full border, not just the left edge: the base
               .myfeeds-help-text rule in assets/admin.css ships a lilac
               1px border that would otherwise frame an orange box. */
            #myfeeds-amazon-modal .myfeeds-help-text {
                background: #fff7ec;
                border: 1px solid #F8D9A8;
                border-left: 4px solid {$b};
                border-radius: 4px;
                padding: 15px;
                margin: 0 0 20px;
            }
            #myfeeds-amazon-modal .myfeeds-help-text p { margin: 8px 0; }
            #myfeeds-amazon-modal .myfeeds-amz-disclosure q { font-style: italic; }
            /* The label column shrinks to its widest label instead of
               holding a fixed 150px, which left a stripe of empty space
               beside every short label. The right padding on the value
               column then matches that gap, so a field sits the same
               distance from the dialog edge as it does from its label. */
            #myfeeds-amazon-modal .form-table th {
                width: 1%;
                white-space: nowrap;
                padding: 12px 20px 12px 0;
                vertical-align: top;
            }
            #myfeeds-amazon-modal .form-table td { padding: 12px 20px 12px 0; }
            #myfeeds-amazon-modal .form-table td input[type=text],
            #myfeeds-amazon-modal .form-table td select { width: 100%; max-width: 100%; }

            .myfeeds-amz-actions {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
                margin: 20px 0 0;
            }
            .myfeeds-amz-feedback { font-size: 13px; }
            .myfeeds-amz-feedback.is-error { color: #b32d2e; }
            .myfeeds-amz-feedback.is-ok { color: #007017; }
        ";
    }
}
