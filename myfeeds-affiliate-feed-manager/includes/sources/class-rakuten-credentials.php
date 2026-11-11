<?php
/**
 * Storage for the Rakuten Advertising API connection.
 *
 * Three values, all from the developer portal (developers.rakutenadvertising.com,
 * "Applications"): the client id and client secret of an application, and
 * the publisher account id that the token is scoped to ("SID"). One
 * connection serves every Rakuten feed on the site.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Rakuten_Credentials {

    const OPTION_CONNECTION = 'myfeeds_rakuten_connection';
    const OPTION_STATUS     = 'myfeeds_rakuten_status';

    /**
     * Key suffix the secret is sealed with. Keep it distinct from the
     * Amazon suffix so one module can never open the other's secret.
     */
    const SECRET_SUFFIX = 'myfeeds-rakuten';

    const STATE_DISCONNECTED = 'disconnected';
    const STATE_UNTESTED     = 'untested';
    const STATE_OK           = 'ok';
    const STATE_INVALID      = 'invalid';
    const STATE_NO_PARTNERS  = 'no_partners';
    const STATE_THROTTLED    = 'throttled';
    const STATE_BLOCKED      = 'blocked';
    const STATE_ERROR        = 'error';

    private static function defaults() {
        return array(
            'client_id'     => '',
            'client_secret' => '',
            'sid'           => '',
        );
    }

    /**
     * @return array{client_id:string,client_secret:string,sid:string}
     */
    public static function get() {
        $stored = get_option(self::OPTION_CONNECTION, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        $connection = array_merge(self::defaults(), $stored);
        $connection['client_id']     = (string) $connection['client_id'];
        $connection['sid']           = (string) $connection['sid'];
        $connection['client_secret'] = MyFeeds_Secret_Store::decrypt($connection['client_secret'], self::SECRET_SUFFIX);

        return $connection;
    }

    /**
     * Partial update: a caller that omits the secret keeps the stored one,
     * so the settings form can render a masked field without ever echoing
     * the real value back to the browser.
     *
     * @param array $input client_id, client_secret, sid (any subset).
     * @return true|WP_Error
     */
    public static function save(array $input) {
        $current = self::get();

        // The cached token is keyed by client id and SID, so it has to be
        // dropped while the old values are still readable.
        if (class_exists('MyFeeds_Rakuten_Client')) {
            MyFeeds_Rakuten_Client::forget_token();
        }

        $client_id = isset($input['client_id'])
            ? trim(sanitize_text_field((string) $input['client_id']))
            : $current['client_id'];

        $secret = $current['client_secret'];
        if (isset($input['client_secret']) && trim((string) $input['client_secret']) !== '') {
            $secret = trim((string) $input['client_secret']);
        }

        $sid = isset($input['sid'])
            ? trim(sanitize_text_field((string) $input['sid']))
            : $current['sid'];

        if ($client_id === '' || $sid === '') {
            return new WP_Error(
                'myfeeds_rakuten_missing',
                __('Client ID and SID are required.', 'myfeeds-affiliate-feed-manager')
            );
        }

        update_option(self::OPTION_CONNECTION, array(
            'client_id'     => $client_id,
            'client_secret' => MyFeeds_Secret_Store::encrypt($secret, self::SECRET_SUFFIX),
            'sid'           => $sid,
        ), false);

        self::set_status(self::STATE_UNTESTED);

        return true;
    }

    public static function disconnect() {
        delete_option(self::OPTION_CONNECTION);
        delete_option(self::OPTION_STATUS);
        if (class_exists('MyFeeds_Rakuten_Client')) {
            MyFeeds_Rakuten_Client::forget_token();
        }
    }

    public static function is_configured() {
        $connection = self::get();

        return $connection['client_id'] !== ''
            && $connection['client_secret'] !== ''
            && $connection['sid'] !== '';
    }

    /**
     * Is anything of the connection stored? Also a client id alone, or a
     * secret the site can no longer read: is_configured() is false for
     * those, and Disconnect still has something to remove.
     */
    public static function is_stored() {
        $stored = get_option(self::OPTION_CONNECTION, array());
        foreach (is_array($stored) ? $stored : array() as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a secret is stored but cannot be read back (libsodium
     * gone, or the site salts rotated). From the outside that looks like
     * wrong credentials; the connection screen says which it is.
     */
    public static function secret_unreadable() {
        $stored = get_option(self::OPTION_CONNECTION, array());
        if (!is_array($stored) || empty($stored['client_secret'])) {
            return false;
        }

        return MyFeeds_Secret_Store::unreadable($stored['client_secret'], self::SECRET_SUFFIX);
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
            'state'      => (string) $state,
            'message'    => (string) $message,
            'checked_at' => time(),
        ), false);
    }

    /**
     * Translate an API failure into the connection state so the screen
     * can explain the cause instead of echoing a raw error.
     */
    public static function record_error(WP_Error $error) {
        $map = array(
            'myfeeds_rakuten_unauthorized' => self::STATE_INVALID,
            'myfeeds_rakuten_no_partners'  => self::STATE_NO_PARTNERS,
            'myfeeds_rakuten_throttled'    => self::STATE_THROTTLED,
            'myfeeds_rakuten_blocked'      => self::STATE_BLOCKED,
        );

        $code  = (string) $error->get_error_code();
        $state = isset($map[$code]) ? $map[$code] : self::STATE_ERROR;

        self::set_status($state, $error->get_error_message());
    }
}
