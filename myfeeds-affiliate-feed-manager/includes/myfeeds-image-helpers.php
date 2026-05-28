<?php
/**
 * MyFeeds Pro — Image rendering helpers.
 *
 * Best-effort upgrade of merchant image URLs to a sharper variant
 * before they go into the <img src="…"> attribute, plus the matching
 * attribute set (loading, decoding, fetchpriority) that goes alongside.
 *
 * Pure render-time logic — never writes to the DB. That keeps the
 * stored URL canonical (so the upgrade can be turned off or changed
 * later without re-importing every feed) and means existing product
 * rows benefit immediately on the next page render, without waiting
 * for the next nightly sync to rewrite them.
 *
 * Scope of the upgrader: only known CDN URL patterns where we can
 * confidently swap a thumbnail size for a larger one. Anything we
 * don't recognise falls through unchanged — better to keep the merchant's
 * original URL than to mangle something unfamiliar.
 *
 * Patterns covered:
 *
 *   - AWIN's productserve image CDN (preview/ → large/)
 *   - Shopify CDN (cdn.shopify.com with named or numeric size suffix
 *     in the filename, or width=N query parameter)
 *   - Cloudinary (res.cloudinary.com — inject w_1024,q_auto,f_auto
 *     when no transformation is present)
 *   - BigCommerce stencil (/images/stencil/NNNxNNN/ → /images/stencil/1024x1024/)
 *   - WP-content uploads (-NNNxNNN before extension → strip suffix)
 *
 * Everything else passes through. The upgrader is idempotent.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('myfeeds_upgrade_image_url')) {
    /**
     * Return a higher-resolution variant of a known CDN image URL, or
     * the original URL when we don't recognise the pattern.
     *
     * @param string $url Source URL.
     * @return string Upgraded or original URL.
     */
    function myfeeds_upgrade_image_url($url) {
        if (!is_string($url) || $url === '') {
            return $url;
        }
        if (strncmp($url, 'http', 4) !== 0 && strncmp($url, '//', 2) !== 0) {
            return $url;
        }

        // AWIN productserve image CDN: /preview/<merch-id>/.../file.jpg
        // is the thumbnail bucket; /large/ holds the original-size mirror.
        if (strpos($url, 'images.productserve.com') !== false) {
            $upgraded = preg_replace(
                '#(images\.productserve\.com/)preview/#',
                '$1large/',
                $url
            );
            if ($upgraded && $upgraded !== $url) {
                return $upgraded;
            }
        }

        // Cloudinary: only inject a transformation when the URL has none
        // already. Detection: the path segment immediately after /upload/
        // either contains a comma (multi-parameter transformation) or
        // matches the `key_value` shape Cloudinary uses (w_400, c_fill,
        // f_auto, etc.).
        if (strpos($url, 'res.cloudinary.com') !== false) {
            if (preg_match('#^(https?://res\.cloudinary\.com/[^/]+/image/upload/)(.+)$#i', $url, $m)) {
                $tail = $m[2];
                $slash_pos = strpos($tail, '/');
                $first_segment = $slash_pos === false ? $tail : substr($tail, 0, $slash_pos);
                $has_transform = $first_segment !== '' && (
                    strpos($first_segment, ',') !== false
                    || preg_match('/^[a-z]_[a-z0-9_,.]+$/i', $first_segment)
                );
                if (!$has_transform) {
                    return $m[1] . 'w_1024,q_auto,f_auto/' . $tail;
                }
            }
        }

        // Shopify CDN: image filenames carry the size as `_grande`,
        // `_large`, `_NNNxNNN`, `_xNNN`, etc. Replace with `_1024x1024`
        // to request the larger variant Shopify generates on demand.
        if (strpos($url, 'cdn.shopify.com') !== false || strpos($url, '.myshopify.com') !== false) {
            $shopify_pattern = '/_(?:pico|icon|thumb|small|compact|medium|grande|large|master|\d{2,4}x\d{0,4}|x\d{2,4})(\.(?:jpe?g|png|webp|gif))(\?[^"\']*)?$/i';
            $upgraded = preg_replace($shopify_pattern, '_1024x1024$1$2', $url);
            if ($upgraded && $upgraded !== $url) {
                return $upgraded;
            }
            // Or the size is passed via querystring (?width=200).
            if (preg_match('/[?&]width=\d+/i', $url)) {
                return preg_replace('/([?&]width=)\d+/i', '${1}1024', $url);
            }
        }

        // BigCommerce stencil URLs encode the size as a path segment:
        // /images/stencil/200x200/products/123/456/file.jpg. Swap the
        // segment for 1024x1024 so the CDN regenerates a sharper copy.
        if (preg_match('#cdn\d*\.bigcommerce\.com/.+/images/stencil/#', $url)) {
            $upgraded = preg_replace(
                '#(/images/stencil/)\d+x\d+(/)#',
                '${1}1024x1024${2}',
                $url
            );
            if ($upgraded && $upgraded !== $url) {
                return $upgraded;
            }
        }

        // WP-content uploads: WordPress' built-in resize suffix is
        // `-NNNxNNN` directly before the extension. Strip it to get the
        // original-size mirror — Magento and Drupal use a similar
        // convention but their URLs are less predictable, so we limit
        // this to URLs that actually contain /wp-content/uploads/.
        if (strpos($url, '/wp-content/uploads/') !== false) {
            $upgraded = preg_replace(
                '#-\d{2,4}x\d{2,4}(\.(?:jpe?g|png|webp|gif))(\?[^"\']*)?$#i',
                '$1$2',
                $url
            );
            if ($upgraded && $upgraded !== $url) {
                return $upgraded;
            }
        }

        return $url;
    }
}

if (!function_exists('myfeeds_image_render_attrs')) {
    /**
     * Build the attribute set for a product <img> tag, with the URL
     * already upgraded. Caller wraps the returned `src` in esc_url()
     * at output time.
     *
     * Options:
     *   - 'lcp'    bool. Mark as LCP candidate (eager + fetchpriority high).
     *
     * @param string $url  Source image URL.
     * @param array  $opts Optional flags.
     * @return array { src: string, attrs: string }
     */
    function myfeeds_image_render_attrs($url, $opts = array()) {
        $upgraded = myfeeds_upgrade_image_url($url);
        $is_lcp = !empty($opts['lcp']);

        $attrs = $is_lcp
            ? array('loading="eager"', 'fetchpriority="high"', 'decoding="async"')
            : array('loading="lazy"', 'decoding="async"');

        return array(
            'src'   => $upgraded,
            'attrs' => implode(' ', $attrs),
        );
    }
}
