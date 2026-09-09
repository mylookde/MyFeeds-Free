<?php
/**
 * Text repair — one place that decides what "valid UTF-8" means.
 *
 * Why this file exists, measured on mylook.com.de 2026-09-10:
 * 192,972 of 194,507 italist products carried "??" in their name where
 * the feed had a perfectly valid em dash. Nothing was wrong with the
 * feed (193,757 lines held the real character, zero held "??") and
 * nothing was wrong with the download, the CSV reader or the database.
 *
 * The damage came from a character class holding the trademark,
 * registered and copyright marks, written without PCRE's `u` modifier.
 * Without `u` such a class is a set of BYTES, not characters — here
 * E2, 84, A2, C2, AE, A9 — so the first byte of every em dash, en dash,
 * curly quote and ellipsis was deleted, and the remaining two bytes
 * were no longer valid UTF-8.
 * A "force valid UTF-8" step then turned each orphan byte into a
 * question mark. "Café" became "Caf?", "Levi's" became "Levi??s".
 *
 * Two rules follow from that, and both live here so nobody has to
 * remember them twice:
 *
 * 1. Repair, never invent. Substituting "?" for a byte destroys the
 *    text and looks like data. Windows-1252 maps every single byte to
 *    a character, so a mis-encoded feed is recovered instead of
 *    punched full of holes.
 * 2. Repair BEFORE any `u`-modified regex touches the string. A `u`
 *    pattern applied to invalid UTF-8 makes preg_replace return null,
 *    which would blank the field entirely — a worse failure than the
 *    one we are fixing.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('myfeeds_is_valid_utf8')) {
    /**
     * Whether the string is already well-formed UTF-8.
     *
     * @param string $value
     * @return bool
     */
    function myfeeds_is_valid_utf8($value) {
        if (!is_string($value) || $value === '') {
            return true;
        }
        if (function_exists('mb_check_encoding')) {
            return (bool) mb_check_encoding($value, 'UTF-8');
        }
        // Same test without mbstring: a UTF-8 pattern only matches a
        // well-formed subject, and preg_match returns false on invalid
        // input rather than 0.
        return preg_match('//u', $value) === 1;
    }
}

if (!function_exists('myfeeds_repair_utf8')) {
    /**
     * Return the string as well-formed UTF-8, keeping as much of it as
     * possible and never substituting a placeholder character.
     *
     * Valid input is returned untouched — this must stay a no-op for
     * the overwhelming majority of feed values, including every
     * accented letter and every kind of dash.
     *
     * @param string $value
     * @return string
     */
    function myfeeds_repair_utf8($value) {
        if (!is_string($value) || $value === '') {
            return is_string($value) ? $value : '';
        }
        if (myfeeds_is_valid_utf8($value)) {
            return $value;
        }

        // Windows-1252 is what a mis-declared feed almost always is, and
        // every byte 0x00-0xFF has a character there, so nothing is lost.
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            if (is_string($converted) && $converted !== '' && myfeeds_is_valid_utf8($converted)) {
                return $converted;
            }
        }

        // Last resort: drop the bytes that cannot be read, rather than
        // turning them into question marks that look like content.
        $stripped = preg_replace('/[\x80-\xFF]/', '', $value);
        if (is_string($stripped) && myfeeds_is_valid_utf8($stripped)) {
            return $stripped;
        }

        return '';
    }
}

if (!function_exists('myfeeds_preg_replace_text')) {
    /**
     * preg_replace for human-readable text: the subject is repaired
     * first, and a failed match leaves the text alone instead of
     * blanking it.
     *
     * Every caller that strips symbols from a name, a price or a colour
     * should come through here. A `u` pattern plus a dirty subject is
     * the one combination that silently empties a field.
     *
     * @param string $pattern     Pattern INCLUDING the `u` modifier.
     * @param string $replacement
     * @param string $subject
     * @return string
     */
    function myfeeds_preg_replace_text($pattern, $replacement, $subject) {
        $subject = myfeeds_repair_utf8(is_string($subject) ? $subject : (string) $subject);
        $result  = preg_replace($pattern, $replacement, $subject);

        // null means the pattern gave up (bad subject, backtrack limit).
        // Keeping the readable original beats handing back nothing.
        return is_string($result) ? $result : $subject;
    }
}
