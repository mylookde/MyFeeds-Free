<?php
/**
 * Storage for the Creators API connection.
 *
 * One credential pair works across every marketplace; only the partner tag
 * is per marketplace, so tags are kept as a map rather than forcing a
 * second connection per country. The tag, the Amazon site, the switch for
 * visitors abroad and the badge are what a link needs, and they are
 * MyFeeds_Amazon_Link_Settings'; this class passes them on and keeps what
 * only the API needs.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Credentials {

    const OPTION_CONNECTION  = MyFeeds_Amazon_Link_Settings::OPTION_CONNECTION;
    const OPTION_STATUS      = 'myfeeds_amazon_status';
    const OPTION_BADGE       = MyFeeds_Amazon_Link_Settings::OPTION_BADGE;
    const OPTION_KEEP_MARKET = MyFeeds_Amazon_Link_Settings::OPTION_KEEP_MARKET;

    const STATE_DISCONNECTED = 'disconnected';
    const STATE_UNTESTED     = 'untested';
    const STATE_OK           = 'ok';
    const STATE_NOT_ELIGIBLE = 'not_eligible';
    const STATE_INVALID      = 'invalid';
    const STATE_THROTTLED    = 'throttled';
    const STATE_ERROR        = 'error';

    private static function defaults() {
        return array(
            'credential_id'      => '',
            'credential_secret'  => '',
            'credential_version' => '',
            'marketplace'        => MyFeeds_Amazon_Locales::default_marketplace(),
            'partner_tags'       => array(),
        );
    }

    public static function get() {
        $stored = get_option(self::OPTION_CONNECTION, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        $connection = array_merge(self::defaults(), $stored);
        $connection['credential_secret'] = self::decrypt($connection['credential_secret']);

        if (!MyFeeds_Amazon_Locales::exists($connection['marketplace'])) {
            $connection['marketplace'] = MyFeeds_Amazon_Locales::default_marketplace();
        }

        if ($connection['credential_version'] === '') {
            $connection['credential_version'] = MyFeeds_Amazon_Api_Locales::expected_version($connection['marketplace']);
        }

        if (!is_array($connection['partner_tags'])) {
            $connection['partner_tags'] = array();
        }

        return $connection;
    }

    /**
     * Partial update: a caller that omits the secret keeps the stored one,
     * so the settings form can render a masked field without ever echoing
     * the real value back to the browser.
     */
    public static function save(array $input) {
        $current = self::get();

        // The cached token is keyed by credential id, so it has to be
        // dropped while the old id is still readable.
        MyFeeds_Amazon_Client::forget_token();

        $marketplace = isset($input['marketplace']) ? sanitize_text_field($input['marketplace']) : $current['marketplace'];
        if (!MyFeeds_Amazon_Locales::exists($marketplace)) {
            return new WP_Error('myfeeds_amazon_bad_marketplace', __('Unknown Amazon marketplace.', 'myfeeds-affiliate-feed-manager'));
        }

        $credential_id = isset($input['credential_id'])
            ? sanitize_text_field($input['credential_id'])
            : $current['credential_id'];

        $secret = $current['credential_secret'];
        if (isset($input['credential_secret']) && $input['credential_secret'] !== '') {
            $secret = trim((string) $input['credential_secret']);
        }

        $version = isset($input['credential_version']) && $input['credential_version'] !== ''
            ? sanitize_text_field($input['credential_version'])
            : MyFeeds_Amazon_Api_Locales::expected_version($marketplace);

        $partner_tags = $current['partner_tags'];
        if (isset($input['partner_tag'])) {
            $tag = sanitize_text_field($input['partner_tag']);
            if ($tag === '') {
                unset($partner_tags[$marketplace]);
            } else {
                $partner_tags[$marketplace] = $tag;
            }
        }

        $connection = array(
            'credential_id'      => $credential_id,
            'credential_secret'  => self::encrypt($secret),
            'credential_version' => $version,
            'marketplace'        => $marketplace,
            'partner_tags'       => $partner_tags,
        );

        update_option(self::OPTION_CONNECTION, $connection, false);

        return true;
    }

    /**
     * Forgets what only Amazon's API needs: the credential, its secret and
     * version, what Amazon last said about them, the cached token. The
     * Amazon site, the partner tags, the badge and where visitors abroad
     * land stay - every Amazon link on the site is built from them.
     */
    public static function forget_api() {
        // The cached token is keyed by credential id, so it has to be
        // dropped while the id is still readable.
        MyFeeds_Amazon_Client::forget_token();

        // Read as stored, not as this request first saw it: a tag saved
        // from another tab meanwhile stays.
        wp_cache_delete(self::OPTION_CONNECTION, 'options');
        wp_cache_delete('alloptions', 'options');
        $stored = get_option(self::OPTION_CONNECTION, null);
        if (is_array($stored)) {
            $kept = array_diff_key($stored, array_flip(array('credential_id', 'credential_secret', 'credential_version')));
            if ($kept !== $stored) {
                update_option(self::OPTION_CONNECTION, $kept, false);
            }
        }
        delete_option(self::OPTION_STATUS);
    }

    /**
     * Is any part of what only the API needs stored: a credential id, or a
     * secret, readable or not? What Disconnect offers to forget. A whole
     * that works is is_configured(); a part of it, or a secret the site
     * can no longer open, is still something the owner may want gone.
     */
    public static function is_stored() {
        $stored = get_option(self::OPTION_CONNECTION, array());
        if (!is_array($stored)) {
            return false;
        }
        foreach (array('credential_id', 'credential_secret') as $key) {
            if (isset($stored[$key]) && (string) $stored[$key] !== '') {
                return true;
            }
        }
        return false;
    }

    public static function is_configured($marketplace = null) {
        $connection  = self::get();
        $marketplace = $marketplace ?: $connection['marketplace'];

        return $connection['credential_id'] !== ''
            && $connection['credential_secret'] !== ''
            && self::partner_tag($marketplace) !== '';
    }

    public static function partner_tag($marketplace = null) {
        return MyFeeds_Amazon_Link_Settings::partner_tag($marketplace);
    }

    public static function marketplace() {
        return MyFeeds_Amazon_Link_Settings::marketplace();
    }

    public static function available_marketplaces() {
        return MyFeeds_Amazon_Link_Settings::available_marketplaces();
    }

    public static function badge_id() {
        return MyFeeds_Amazon_Link_Settings::badge_id();
    }

    /**
     * Should a page render ask Amazon right now? Not while Amazon has just
     * said no: below the ten sales every call fails, and each failure costs
     * a page render a token round trip for nothing. The editor still asks
     * (its lookups set the state back to ok the day Amazon unlocks), and a
     * page render tries again after six hours on its own.
     */
    public static function api_refused_recently() {
        $status = self::get_status();
        if (!in_array($status['state'], array(self::STATE_NOT_ELIGIBLE, self::STATE_INVALID), true)) {
            return false;
        }
        return (time() - (int) $status['checked_at']) < 6 * HOUR_IN_SECONDS;
    }

    public static function get_status() {
        $status = get_option(self::OPTION_STATUS, array());
        if (!is_array($status)) {
            $status = array();
        }

        return array_merge(array(
            'state'      => self::is_configured() ? self::STATE_UNTESTED : self::STATE_DISCONNECTED,
            'message'    => '',
            'checked_at' => 0,
        ), $status);
    }

    public static function set_status($state, $message = '') {
        update_option(self::OPTION_STATUS, array(
            'state'      => $state,
            'message'    => (string) $message,
            'checked_at' => time(),
        ), false);
    }

    /**
     * Translate an API failure into the connection state so the admin
     * screen can explain the cause instead of echoing a raw error.
     */
    public static function record_error(WP_Error $error) {
        $code = $error->get_error_code();

        $map = array(
            'myfeeds_amazon_not_eligible' => self::STATE_NOT_ELIGIBLE,
            'myfeeds_amazon_unauthorized' => self::STATE_INVALID,
            'myfeeds_amazon_invalid_tag'  => self::STATE_INVALID,
            'myfeeds_amazon_throttled'    => self::STATE_THROTTLED,
        );

        $state = isset($map[$code]) ? $map[$code] : self::STATE_ERROR;
        self::set_status($state, $error->get_error_message());
    }

    // =====================================================================
    // Secret at rest
    // =====================================================================

    /**
     * Key suffix the Amazon secret was sealed with since the module first
     * shipped. Changing it makes every stored Amazon secret unreadable,
     * which is why it is a named constant and a test pins the value.
     */
    const SECRET_SUFFIX = 'myfeeds-amazon';

    /**
     * The secret is shown by Amazon exactly once and cannot be read back,
     * so losing it costs the user a credential regeneration. Sealed by
     * MyFeeds_Secret_Store; on hosts without libsodium the value is stored
     * as-is, which is what every other credential in this plugin already
     * does.
     */
    private static function encrypt($value) {
        return MyFeeds_Secret_Store::encrypt($value, self::SECRET_SUFFIX);
    }

    /**
     * Returns true when a secret is stored but cannot be read back. That
     * happens if the host drops libsodium, or if the site salts were
     * rotated after the secret was saved. Both look identical to "wrong
     * credentials" from the outside, so the settings screen says which.
     */
    public static function secret_unreadable() {
        $stored = get_option(self::OPTION_CONNECTION, array());
        if (!is_array($stored) || empty($stored['credential_secret'])) {
            return false;
        }

        return MyFeeds_Secret_Store::unreadable($stored['credential_secret'], self::SECRET_SUFFIX);
    }

    private static function decrypt($value) {
        return MyFeeds_Secret_Store::decrypt($value, self::SECRET_SUFFIX);
    }
}
