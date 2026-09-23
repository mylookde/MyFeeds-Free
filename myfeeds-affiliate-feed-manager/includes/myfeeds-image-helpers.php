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

if (!function_exists('myfeeds_upgrade_product_image_urls')) {
    /**
     * Walk a list of product rows and upgrade the `image_url` field on
     * each via myfeeds_upgrade_image_url(). Use this at the REST-response
     * layer for endpoints that hand product data to the block editor,
     * so the Gutenberg preview tiles match the frontend's sharp variant
     * even when the saved feed mapping still points the editor at a
     * smaller CDN size. Other image fields (additional_images,
     * large_image, …) are left untouched on purpose — the editor preview
     * only renders image_url, and the upgrade has to stay scoped to what
     * the consumer actually displays.
     *
     * Performance: pure PHP regex, ~0.5ms per row. A 30-item
     * picker/preview response costs under 20ms. Safe for any
     * editor-mount-time endpoint.
     *
     * @param array $items Product rows.
     * @return array Same shape, with upgraded image_url values.
     */
    function myfeeds_upgrade_product_image_urls($items) {
        if (!is_array($items)) {
            return $items;
        }
        foreach ($items as $key => $item) {
            if (is_array($item)
                && isset($item['image_url'])
                && is_string($item['image_url'])
                && $item['image_url'] !== ''
            ) {
                $items[$key]['image_url'] = myfeeds_upgrade_image_url($item['image_url']);
            }
        }
        return $items;
    }
}

/* ---------------------------------------------------------------------
 * Downscaling: the mirror image of the upgrader above.
 *
 * The upgrader exists because some merchants ship a 150px thumbnail and
 * we want a sharp card. The opposite is just as common and hurts far
 * more: a merchant ships the print master. Measured on mylook.com.de on
 * 2026-09-23, one image per product, as the browser would load it:
 *
 *   Jack & Jones   images.jackjones.com   avg 2.02 MB, PNGs up to 28.9 MB
 *   Selected       images.selected.com    350 KB
 *   Famous Footwear                        48 KB
 *   JD Sports      media.jdsports.com      15 KB
 *
 * Nothing about that is the plugin's fault and nothing about it is
 * visible until a feed like that arrives. But a review queue with 500
 * tiles then pulls a gigabyte, and a storefront without an image CDN in
 * front of it serves 2 MB per tile to every reader.
 *
 * Every serious image CDN can hand out a smaller copy; they just
 * disagree on how to ask. This block knows the grammars, applies one at
 * render time and never writes to the DB, exactly like the upgrader —
 * so the stored URL stays canonical and turning the cap off or changing
 * the width costs no re-import.
 *
 * Two ways a host gets a grammar:
 *
 *   1. Seeded below, keyed on a marker that identifies the PLATFORM and
 *      not the shop — `/dw/image/` is Salesforce Commerce Cloud whoever
 *      runs it, `res.cloudinary.com` is Cloudinary. That covers most
 *      feeds of most networks on the first render, with no network call.
 *   2. Learned by MyFeeds_Image_CDN_Probe, which asks an unknown host
 *      directly and remembers the answer. That is the part that makes
 *      this work for a feed nobody here has ever seen.
 *
 * A host we cannot shrink keeps its URL. Wrong is worse than big.
 * ------------------------------------------------------------------ */

if (!defined('MYFEEDS_IMAGE_RECIPES_OPTION')) {
    /** Learned host -> grammar map, written by the probe. */
    define('MYFEEDS_IMAGE_RECIPES_OPTION', 'myfeeds_image_cdn_recipes');
}

if (!function_exists('myfeeds_image_url_host')) {
    /**
     * Lowercase host of an image URL, or '' when there is none.
     *
     * @param string $url Source URL.
     * @return string
     */
    function myfeeds_image_url_host($url) {
        if (!is_string($url) || $url === '') {
            return '';
        }
        if (strncmp($url, '//', 2) === 0) {
            $url = 'https:' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }
}

if (!function_exists('myfeeds_image_query_grammars')) {
    /**
     * The width parameters image CDNs use, in the order the probe tries
     * them. Each is a query key that takes a pixel width.
     *
     *   width    Fastly Image Optimizer, Shopify, Storyblok
     *   w        imgix, Contentful, Sanity, Jetpack/Photon, Statamic
     *   sw       Salesforce Commerce Cloud (Demandware)
     *   wid      Adobe Scene7 / Dynamic Media
     *   imwidth  Akamai Image Manager
     *   maxwidth Kraken, a handful of in-house resizers
     *
     * `drop` names companion keys that would fight the new width — a
     * Demandware URL carrying sw=900&sh=1200 letterboxes if only sw
     * changes, so sh goes with it and the CDN keeps the aspect ratio.
     *
     * @return array<string,array>
     */
    function myfeeds_image_query_grammars() {
        return array(
            'width'    => array('key' => 'width',    'drop' => array('height')),
            'w'        => array('key' => 'w',        'drop' => array('h')),
            'sw'       => array('key' => 'sw',       'drop' => array('sh')),
            'wid'      => array('key' => 'wid',      'drop' => array('hei')),
            'imwidth'  => array('key' => 'imwidth',  'drop' => array('imheight')),
            'maxwidth' => array('key' => 'maxwidth', 'drop' => array('maxheight')),
        );
    }
}

if (!function_exists('myfeeds_image_seeded_recipe')) {
    /**
     * The grammar for a URL we recognise without asking anyone.
     *
     * Matched on platform markers, not on shop names: every Salesforce
     * Commerce Cloud install in the world serves images under
     * /dw/image/, so one entry covers Pieces, Bianco, Selected and the
     * next SFCC merchant that shows up in somebody's feed.
     *
     * @param string $url  Source URL.
     * @param string $host Lowercase host, already parsed.
     * @return string|null Grammar id, or null when nothing matches.
     */
    function myfeeds_image_seeded_recipe($url, $host) {
        // Salesforce Commerce Cloud / Demandware. The marker sits in the
        // path, so it holds for images.selected.com and www.bianco.com
        // alike.
        if (strpos($url, '/dw/image/') !== false) {
            return 'sw';
        }
        // Adobe Scene7 / Dynamic Media. Preset URLs look like
        // …/s/shop/SKU?$Main$ — the preset stays, wid narrows it.
        if (strpos($url, 'scene7.com') !== false || strpos($url, '/is/image/') !== false) {
            return 'wid';
        }
        if (strpos($host, 'cdn.shopify.com') !== false || strpos($host, '.myshopify.com') !== false) {
            return 'shopify';
        }
        if (strpos($host, 'res.cloudinary.com') !== false) {
            return 'cloudinary';
        }
        if (strpos($host, 'imgix.net') !== false
            || strpos($host, 'ctfassets.net') !== false
            || strpos($host, 'images.contentful.com') !== false
            || strpos($host, 'cdn.sanity.io') !== false
        ) {
            return 'w';
        }
        if (strpos($url, '/images/stencil/') !== false && strpos($host, 'bigcommerce.com') !== false) {
            return 'stencil';
        }
        // AWIN's productserve CDN has no resizer, but it does keep two
        // buckets of the same file. For a thumbnail the preview bucket
        // IS the small copy — the upgrader above walks the other way on
        // purpose, and both are right for what they are asked.
        if (strpos($host, 'productserve.com') !== false) {
            return 'productserve';
        }
        return null;
    }
}

if (!function_exists('myfeeds_image_learned_recipes')) {
    /**
     * Host -> grammar map the probe has written, host already lowercase.
     * A host that could not be shrunk is stored too, as 'none', so we
     * stop asking until the entry expires.
     *
     * @return array<string,array>
     */
    function myfeeds_image_learned_recipes() {
        if (!function_exists('get_option')) {
            return array();
        }
        $stored = get_option(MYFEEDS_IMAGE_RECIPES_OPTION, array());
        return is_array($stored) ? $stored : array();
    }
}

if (!function_exists('myfeeds_image_recipe_for_url')) {
    /**
     * The grammar to use for one URL: seeded first (it costs nothing and
     * cannot go stale), learned second.
     *
     * @param string $url Source URL.
     * @return string|null Grammar id, or null for "leave it alone".
     */
    function myfeeds_image_recipe_for_url($url) {
        $host = myfeeds_image_url_host($url);
        if ($host === '') {
            return null;
        }
        $seeded = myfeeds_image_seeded_recipe($url, $host);
        if ($seeded !== null) {
            return $seeded;
        }
        $learned = myfeeds_image_learned_recipes();
        if (isset($learned[$host]['grammar']) && is_string($learned[$host]['grammar'])) {
            $grammar = $learned[$host]['grammar'];
            return ($grammar === '' || $grammar === 'none') ? null : $grammar;
        }
        return null;
    }
}

if (!function_exists('myfeeds_image_url_is_signed')) {
    /**
     * True when the query string looks like it carries a signature.
     *
     * Adding a parameter to a signed URL invalidates the signature and
     * the CDN answers 403 — a broken image where a big one used to be.
     * Cheap to check, and the class of URL is rare enough in product
     * feeds that refusing to touch it costs nothing.
     *
     * @param string $url Source URL.
     * @return bool
     */
    function myfeeds_image_url_is_signed($url) {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return false;
        }
        $signed = array(
            'sig', 'signature', 'hmac', 'token', 'expires', 'expiry', 'policy',
            'x-amz-signature', 'x-amz-credential', 'key-pair-id', 'auth', 'md5',
        );
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq  = strpos($pair, '=');
            $key = strtolower($eq === false ? $pair : substr($pair, 0, $eq));
            if (in_array($key, $signed, true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('myfeeds_image_set_query_arg')) {
    /**
     * Set one query parameter, remove the ones named in $drop, keep
     * everything else exactly as it stood.
     *
     * Hand-rolled instead of add_query_arg() for two reasons: this file
     * has to run before WordPress is loaded in the test suite, and
     * add_query_arg() re-encodes the value-less parameters Scene7 relies
     * on (`?$Main$`) into something the CDN no longer recognises.
     *
     * Idempotent: calling it twice with the same key replaces, never
     * appends.
     *
     * @param string $url   Source URL.
     * @param string $key   Parameter to set.
     * @param int    $value Pixel width.
     * @param array  $drop  Parameter names to remove.
     * @return string
     */
    function myfeeds_image_set_query_arg($url, $key, $value, $drop = array()) {
        $fragment = '';
        $hash_at  = strpos($url, '#');
        if ($hash_at !== false) {
            $fragment = substr($url, $hash_at);
            $url      = substr($url, 0, $hash_at);
        }

        $query_at = strpos($url, '?');
        $base     = $query_at === false ? $url : substr($url, 0, $query_at);
        $query    = $query_at === false ? '' : substr($url, $query_at + 1);

        $keep = array();
        if ($query !== '') {
            foreach (explode('&', $query) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $eq   = strpos($pair, '=');
                $name = strtolower($eq === false ? $pair : substr($pair, 0, $eq));
                if ($name === strtolower($key) || in_array($name, array_map('strtolower', $drop), true)) {
                    continue;
                }
                $keep[] = $pair;
            }
        }
        $keep[] = $key . '=' . (int) $value;

        return $base . '?' . implode('&', $keep) . $fragment;
    }
}

if (!function_exists('myfeeds_image_apply_recipe')) {
    /**
     * Apply one grammar at one width. Unknown grammar: URL unchanged.
     *
     * @param string $url     Source URL.
     * @param string $grammar Grammar id.
     * @param int    $width   Target width in pixels.
     * @return string
     */
    function myfeeds_image_apply_recipe($url, $grammar, $width) {
        $width    = max(64, min(2400, (int) $width));
        $grammars = myfeeds_image_query_grammars();

        if (isset($grammars[$grammar])) {
            return myfeeds_image_set_query_arg(
                $url,
                $grammars[$grammar]['key'],
                $width,
                $grammars[$grammar]['drop']
            );
        }

        if ($grammar === 'shopify') {
            // Two resizers on one URL fight each other: a filename that
            // already says _1024x1024 caps the master before ?width= ever
            // sees it. Strip the filename size, then ask once.
            $bare = preg_replace(
                '/_(?:pico|icon|thumb|small|compact|medium|grande|large|master|\d{2,4}x\d{0,4}|x\d{2,4})(\.(?:jpe?g|png|webp|gif))/i',
                '$1',
                $url
            );
            if (is_string($bare) && $bare !== '') {
                $url = $bare;
            }
            return myfeeds_image_set_query_arg($url, 'width', $width, array('height'));
        }

        if ($grammar === 'cloudinary') {
            if (preg_match('#^(https?://res\.cloudinary\.com/[^/]+/image/upload/)(.+)$#i', $url, $m)) {
                $tail       = $m[2];
                $slash_pos  = strpos($tail, '/');
                $first      = $slash_pos === false ? $tail : substr($tail, 0, $slash_pos);
                $has_params = $first !== '' && (
                    strpos($first, ',') !== false
                    || preg_match('/^[a-z]_[a-z0-9_,.]+$/i', $first)
                );
                if ($has_params && preg_match('/(^|,)w_\d+(,|$)/', $first)) {
                    $resized = preg_replace('/(^|,)w_\d+(,|$)/', '${1}w_' . $width . '${2}', $first);
                    return $m[1] . $resized . substr($tail, strlen($first));
                }
                return $m[1] . 'w_' . $width . ',c_limit,q_auto,f_auto/' . $tail;
            }
            return $url;
        }

        if ($grammar === 'stencil') {
            $resized = preg_replace(
                '#(/images/stencil/)\d+x\d+(/)#',
                '${1}' . $width . 'x' . $width . '${2}',
                $url
            );
            return (is_string($resized) && $resized !== '') ? $resized : $url;
        }

        if ($grammar === 'productserve') {
            // AWIN has no resizer, only two buckets, and preview/ is a
            // fixed small size - roughly 200px. It is the right answer
            // for an admin tile and the wrong one for a product card,
            // where myfeeds_upgrade_image_url() has just walked the
            // other way on purpose. Above 500px the two would fight and
            // the cap would undo the upgrade, so it stands down.
            if ($width > 500) {
                return $url;
            }
            $resized = preg_replace('#(images\d*\.productserve\.com/)large/#', '${1}preview/', $url);
            return (is_string($resized) && $resized !== '') ? $resized : $url;
        }

        return $url;
    }
}

if (!function_exists('myfeeds_thumb_image_url')) {
    /**
     * A copy of the image no wider than $width, when the host can make
     * one. Otherwise the URL as it stands.
     *
     * Never widens: a feed that already ships a 200px thumbnail keeps
     * it, because asking a CDN for 400 from a 200px master buys blur,
     * not sharpness. Widening is myfeeds_upgrade_image_url()'s job and
     * it is deliberately not called from here — the two pull in opposite
     * directions and the caller says which one it wants.
     *
     * @param string $url   Source URL.
     * @param int    $width Target width in pixels.
     * @return string
     */
    function myfeeds_thumb_image_url($url, $width = 400) {
        if (!is_string($url) || $url === '') {
            return $url;
        }
        if (strncmp($url, 'http', 4) !== 0 && strncmp($url, '//', 2) !== 0) {
            return $url;
        }
        if (myfeeds_image_url_is_signed($url)) {
            return $url;
        }
        if (function_exists('apply_filters')) {
            /**
             * Last word on the width, so a site can turn the cap off
             * (return 0) or raise it for a retina-heavy theme.
             */
            $width = (int) apply_filters('myfeeds_image_thumb_width', $width, $url);
        }
        if ($width <= 0) {
            return $url;
        }
        $grammar = myfeeds_image_recipe_for_url($url);
        if ($grammar === null) {
            return $url;
        }
        return myfeeds_image_apply_recipe($url, $grammar, $width);
    }
}

if (!function_exists('myfeeds_thumb_product_image_urls')) {
    /**
     * Cap `image_url` on a list of product rows. Use it on any REST
     * payload that ends up in a dense admin grid — the review queue, the
     * picker search, the product browser. Those screens render a tile a
     * few hundred pixels wide and wp-admin has no image CDN in front of
     * it, so whatever the merchant stored is exactly what the browser
     * downloads.
     *
     * @param array $items Product rows.
     * @param int   $width Target width in pixels.
     * @return array Same shape, with capped image_url values.
     */
    function myfeeds_thumb_product_image_urls($items, $width = 400) {
        if (!is_array($items)) {
            return $items;
        }
        foreach ($items as $key => $item) {
            if (is_array($item)
                && isset($item['image_url'])
                && is_string($item['image_url'])
                && $item['image_url'] !== ''
            ) {
                $items[$key]['image_url'] = myfeeds_thumb_image_url($item['image_url'], $width);
            }
        }
        return $items;
    }
}

if (!function_exists('myfeeds_image_render_attrs')) {
    /**
     * Build the attribute set for a product <img> tag, with the URL
     * already sized. Caller wraps the returned `src` in esc_url()
     * at output time.
     *
     * Two opposite corrections, in this order:
     *
     *   1. Upgrade. A merchant thumbnail too small for a retina card
     *      gets swapped for the CDN's larger variant.
     *   2. Cap. What comes out is then held to `max_width` — the print
     *      master a merchant like Jack & Jones ships (2 MB, and 28 MB
     *      for their PNGs) becomes ~70 KB at 800 px.
     *
     * Running both is not contradictory, it is the same statement from
     * two sides: give me this image at the size the card actually shows.
     * Whichever correction the URL needs is the one that fires; a URL
     * already in range comes out untouched.
     *
     * Options:
     *   - 'lcp'       bool. Mark as LCP candidate (eager + fetchpriority high).
     *   - 'max_width' int.  Cap in CSS pixels. Default 800, which is 2x
     *                       for the ~400 px a product tile occupies on a
     *                       desktop grid. 0 turns the cap off.
     *
     * @param string $url  Source image URL.
     * @param array  $opts Optional flags.
     * @return array { src: string, attrs: string }
     */
    function myfeeds_image_render_attrs($url, $opts = array()) {
        $src    = myfeeds_upgrade_image_url($url);
        $is_lcp = !empty($opts['lcp']);
        $cap    = array_key_exists('max_width', $opts) ? (int) $opts['max_width'] : 800;

        if ($cap > 0 && function_exists('myfeeds_thumb_image_url')) {
            $src = myfeeds_thumb_image_url($src, $cap);
        }

        $attrs = $is_lcp
            ? array('loading="eager"', 'fetchpriority="high"', 'decoding="async"')
            : array('loading="lazy"', 'decoding="async"');

        return array(
            'src'   => $src,
            'attrs' => implode(' ', $attrs),
        );
    }
}
