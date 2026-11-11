<?php
/**
 * An Amazon product's identity and its link.
 *
 * The Associates Program IP License (section (c) viii + ix in the German
 * text, same numbering in the other locales) lets a site keep an ASIN for
 * as long as it likes and nothing else of Amazon's for longer than a day;
 * images not at all. So a post holds an Amazon product as an id built from
 * the ASIN and its store (amzn:<code>:<ASIN>), never as a copy of what
 * Amazon says about it, and its card links to the plain product page with
 * the site owner's own partner tag.
 *
 * Shared byte for byte by both plugins.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Compliance {

    const ID_PREFIX = 'amzn';

    /**
     * The plain product link Amazon documents for Associates:
     * https://www.amazon.de/dp/<ASIN>/?tag=<partner tag>. Built from the
     * ASIN and the site's own tag, so it asks nothing of Amazon. Empty
     * when the site has no tag for that marketplace: an untagged link
     * would earn nothing.
     */
    public static function product_url($marketplace, $asin) {
        $asin = strtoupper((string) $asin);
        if (!self::is_valid_asin($asin) || !MyFeeds_Amazon_Locales::exists($marketplace)) {
            return '';
        }
        $tag = MyFeeds_Amazon_Link_Settings::partner_tag($marketplace);
        if ($tag === '') {
            return '';
        }
        // Only links built here get the switch; a link Amazon hands out
        // itself is passed on unaltered, as the licence requires.
        return 'https://' . $marketplace . '/dp/' . $asin . '/?tag=' . rawurlencode($tag)
            . (MyFeeds_Amazon_Link_Settings::keep_market() ? '&creatorsDisableRedirect=true' : '');
    }

    public static function make_id($marketplace, $asin) {
        return self::ID_PREFIX . ':' . MyFeeds_Amazon_Locales::code($marketplace) . ':' . strtoupper($asin);
    }

    public static function is_amazon_id($id) {
        return is_string($id) && strpos($id, self::ID_PREFIX . ':') === 0;
    }

    /**
     * @return array{marketplace:string,asin:string}|null
     */
    public static function parse_id($id) {
        if (!self::is_amazon_id($id)) {
            return null;
        }

        $parts = explode(':', $id);
        if (count($parts) !== 3) {
            return null;
        }

        $marketplace = MyFeeds_Amazon_Locales::marketplace_from_code($parts[1]);
        $asin        = strtoupper($parts[2]);

        if (!$marketplace || !self::is_valid_asin($asin)) {
            return null;
        }

        return array('marketplace' => $marketplace, 'asin' => $asin);
    }

    public static function is_valid_asin($asin) {
        return (bool) preg_match('/^[A-Z0-9]{10}$/', strtoupper((string) $asin));
    }
}
