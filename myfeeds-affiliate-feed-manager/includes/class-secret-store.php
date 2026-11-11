<?php
/**
 * Secrets at rest.
 *
 * A credential that the network shows exactly once - an Amazon client
 * secret, a Rakuten client secret - cannot be read back from the network,
 * so losing it costs the user a regeneration. The value is sealed with
 * libsodium where the extension exists; without it the value is stored
 * as-is.
 *
 * The key is derived from the site salts plus a per-owner suffix. The
 * suffix keeps one module's secrets unreadable to another and, more
 * importantly, keeps the format byte-identical to what the Amazon module
 * wrote before this class existed: change 'myfeeds-amazon' and every
 * stored Amazon secret turns unreadable. A test pins that string.
 *
 * Rotating the salts makes every sealed value unreadable. That is the
 * intended trade-off: rotating salts is an explicit security action, and
 * re-entering a secret is cheap.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Secret_Store {

    /** Marks a value this class sealed; anything else is stored as-is. */
    const PREFIX = 'sodium:';

    /**
     * @param string $value  Plain secret. Empty stays empty.
     * @param string $suffix Owner suffix, e.g. 'myfeeds-amazon'.
     * @return string Sealed value, or the plain value when sodium is missing.
     */
    public static function encrypt($value, $suffix) {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        if (!self::available()) {
            return $value;
        }

        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($value, $nonce, self::key($suffix));

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the sealed bytes are stored as text in an option; nothing is hidden from the reader of this code.
        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * @param string $value  Stored value, sealed or plain.
     * @param string $suffix Owner suffix used when it was sealed.
     * @return string Plain secret; '' when sealed but unreadable.
     */
    public static function decrypt($value, $suffix) {
        $value = (string) $value;
        if (!self::is_sealed($value)) {
            return $value;
        }

        if (!self::available()) {
            return '';
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads back the text encrypt() stored above.
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, self::key($suffix));

        return $plain === false ? '' : $plain;
    }

    /**
     * @param string $value
     * @return bool
     */
    public static function is_sealed($value) {
        $value = (string) $value;
        return $value !== '' && strpos($value, self::PREFIX) === 0;
    }

    /**
     * A secret is stored but cannot be read back: the host dropped
     * libsodium, or the salts were rotated after it was saved. Both look
     * exactly like "wrong credentials" from the outside, so the screen
     * that shows the connection has to say which it is.
     *
     * @param string $value  Stored value.
     * @param string $suffix Owner suffix.
     * @return bool
     */
    public static function unreadable($value, $suffix) {
        return self::is_sealed($value) && self::decrypt($value, $suffix) === '';
    }

    /**
     * @return bool
     */
    public static function available() {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES');
    }

    /**
     * @param string $suffix
     * @return string Raw key of SODIUM_CRYPTO_SECRETBOX_KEYBYTES bytes.
     */
    private static function key($suffix) {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '')
            . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '')
            . (string) $suffix;

        return substr(hash('sha256', $material, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
