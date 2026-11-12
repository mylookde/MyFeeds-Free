<?php
/**
 * MyFeeds Look — one of the author's own photos with shop points on it.
 *
 * Block myfeeds/look: an image from the media library, numbered points
 * placed anywhere on it, each pointing at an Amazon ASIN or a product from
 * the author's feeds. Server-rendered so the links, the click tracking and
 * the rule "a product that cannot be bought is left out" (rule 17) are the
 * same as on every other card.
 *
 * Built for Amazon products linked by ASIN, which carry no picture of
 * Amazon's: the author's own outfit photo shows them - and for fashion it
 * sells better than a cut-out.
 *
 * What it is careful about:
 *   - SEO: the photo is an ordinary <img> from the media library inside a
 *     <figure> with its alt text and caption, so image search and image
 *     sitemaps see it like any picture in a post. The same products follow
 *     as a plain numbered list of links. No new URLs (rule 20); links are
 *     nofollow sponsored and counted like every card's - through
 *     /myfeeds/go/, or directly for a link to Amazon (the click tracker
 *     decides, see point_item()).
 *   - Core Web Vitals: wp_get_attachment_image() gives width, height,
 *     srcset and WordPress's own lazy/eager decision, so no layout shift
 *     and no oversized file. The points open with <details> - no script.
 *     The few lines of CSS are printed once, inline, before the first
 *     block, so nothing renders unstyled first.
 *   - Page caches: the output is the same for every visitor.
 *
 * What a point can lead to beyond the site's own feeds comes in through
 * filters, so this file holds no source of its own:
 *   myfeeds_look_editor_amazon   (config)          what the editor says about Amazon
 *   myfeeds_look_amazon_items    (array, $points)  id => a point as the site
 *                                                  draws it (point_item()), or
 *                                                  false for an ASIN whose page
 *                                                  is gone
 *   myfeeds_look_not_found       (answer, $url)    a pasted shop address that no
 *                                                  feed sells
 *   myfeeds_hide_prices          (false)           the page shows no prices
 *
 * Shared by both plugins, apart from the text domain.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Look_Block {

    const BLOCK      = 'myfeeds/look';
    const MAX_POINTS = 30;

    private static $css_printed = false;
    private static $blocks = 0;

    public static function init() {
        add_action('init', array(__CLASS__, 'register'), 20);
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'enqueue_editor'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest'));
    }

    /**
     * Registered for everyone, so a post keeps rendering its looks also
     * where the inserter does not offer the block (the same courtesy rule
     * 12 gives archived products).
     * Offered in the inserter on every site - see enqueue_editor().
     */
    public static function register() {
        if (!function_exists('register_block_type')) {
            return;
        }
        // The editor canvas is an iframe (always, since WordPress 7). A
        // stylesheet the editor script adds to its own document never
        // reaches it; one registered as the block's editor_style does.
        $css = 'assets/look-block-editor.css';
        wp_register_style(
            'myfeeds-look-editor',
            MYFEEDS_PLUGIN_URL . $css,
            array(),
            function_exists('myfeeds_asset_ver') ? myfeeds_asset_ver($css) : (string) filemtime(MYFEEDS_PLUGIN_DIR . $css)
        );
        register_block_type(self::BLOCK, array(
            'api_version'     => 2,
            'editor_style'    => 'myfeeds-look-editor',
            'render_callback' => array(__CLASS__, 'render'),
            'attributes'      => array(
                'imageId'  => array('type' => 'number', 'default' => 0),
                'imageUrl' => array('type' => 'string', 'default' => ''),
                // 'media' = an attachment; 'url' = shown from where it lives.
                'imageSource'    => array('type' => 'string', 'default' => 'media'),
                'imageWidth'     => array('type' => 'number', 'default' => 0),
                'imageHeight'    => array('type' => 'number', 'default' => 0),
                'imageAlt'       => array('type' => 'string', 'default' => ''),
                'creditText'     => array('type' => 'string', 'default' => ''),
                'creditUrl'      => array('type' => 'string', 'default' => ''),
                'rightsConfirmed' => array('type' => 'string', 'default' => ''),
                // 0 = as wide as the content column.
                'width'          => array('type' => 'number', 'default' => 0),
                'imageAlign'     => array('type' => 'string', 'default' => 'center'),
                'points'   => array('type' => 'array', 'default' => array()),
                'showList' => array('type' => 'boolean', 'default' => true),
                // Prices and pictures on the page. On by default; an Amazon
                // point by link has neither (amazon_item()).
                'showPrices' => array('type' => 'boolean', 'default' => true),
                'showImages' => array('type' => 'boolean', 'default' => true),
            ),
        ));
    }

    /**
     * The block in the editor.
     *
     * What a point may lead to besides the site's own feeds is told to the
     * editor as it is, by whoever offers it (myfeeds_look_editor_amazon):
     *   allowed       Amazon is set up: a point takes an ASIN or Amazon link.
     *   links         Amazon products by link or ASIN.
     *   nameFromLink  false: the name is not taken from a pasted link.
     *   texts         what the editor says about an Amazon point, in place
     *                 of its own words (look-block.js).
     * Without it a point links to the site's own products.
     */
    public static function enqueue_editor() {
        $rel  = 'assets/look-block.js';
        $deps = array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-api-fetch', myfeeds_price_format_script());
        // A point searches in the picker's own window, which the picker's
        // bundle hands out (window.myfeedsProductSearch). Named only when
        // registered: WordPress drops a script whose dependency is
        // missing, and the Look would vanish from the editor with it.
        if (function_exists('wp_script_is') && wp_script_is('myfeeds-product-picker-editor', 'registered')) {
            $deps[] = 'myfeeds-product-picker-editor';
        }
        wp_enqueue_script(
            'myfeeds-look-block',
            MYFEEDS_PLUGIN_URL . $rel,
            $deps,
            function_exists('myfeeds_asset_ver') ? myfeeds_asset_ver($rel) : (string) filemtime(MYFEEDS_PLUGIN_DIR . $rel),
            true
        );

        $none   = array('allowed' => false, 'links' => false);
        $amazon = apply_filters('myfeeds_look_editor_amazon', $none);
        wp_localize_script('myfeeds-look-block', 'myfeedsLook', array(
            'amazon' => is_array($amazon) ? $amazon : $none,
        ));
    }

    // -----------------------------------------------------------------
    // Editor: a pasted product URL -> the feed product behind it
    // -----------------------------------------------------------------

    public static function register_rest() {
        register_rest_route('myfeeds/v1', '/look/resolve', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'rest_resolve'),
            'permission_callback' => function () { return current_user_can('edit_posts'); },
            'args'                => array('url' => array('type' => 'string', 'required' => true)),
        ));
        register_rest_route('myfeeds/v1', '/look/probe', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'rest_probe'),
            'permission_callback' => function () { return current_user_can('edit_posts'); },
            'args'                => array('url' => array('type' => 'string', 'required' => true)),
        ));
        register_rest_route('myfeeds/v1', '/look/import', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'rest_import'),
            'permission_callback' => function () { return current_user_can('upload_files'); },
        ));
    }

    /**
     * Which feed product sells the page this URL points at? Feeds keep the
     * shop's own product URL inside the tracked link (Rakuten's murl, AWIN's
     * ued - URL-encoded) and, per rule 14, in raw_data as merchant_url. The
     * path is what both share; host and query are left out because the
     * tracked link may encode them differently. About a second on mylook's
     * 366k rows for the first two looks, the raw_data scan only when both
     * miss - an editor action, never a page render.
     */
    public static function rest_resolve(WP_REST_Request $req) {
        $url     = (string) $req->get_param('url');
        $product = self::product_for_url($url);
        if (!$product) {
            // Not in the catalogue. Whoever brings products from elsewhere
            // may say more about the address (myfeeds_look_not_found).
            $answer = apply_filters('myfeeds_look_not_found', array('found' => false), $url);
            return rest_ensure_response(is_array($answer) ? array('found' => false) + $answer : array('found' => false));
        }
        return rest_ensure_response(array('found' => true, 'product' => self::editor_shape($product)));
    }

    public static function product_for_url($url) {
        $path = (string) wp_parse_url(trim($url), PHP_URL_PATH);
        $path = rtrim($path, '/');
        // Too short to name one product ("/", "/shop") - it would match
        // half the catalogue.
        if (strlen($path) < 12 || !class_exists('MyFeeds_DB_Manager')) {
            return null;
        }
        global $wpdb;
        $table = MyFeeds_DB_Manager::table_name();
        $tries = array(
            array('affiliate_link', rawurlencode($path)),
            array('affiliate_link', str_replace('%2F', '/', rawurlencode($path))),
            array('affiliate_link', $path),
            array('raw_data', $path),
        );
        foreach ($tries as $try) {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $try[0] is one of the two column names written above; the value goes through prepare().
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT external_id FROM {$table} WHERE status = 'active' AND {$try[0]} LIKE %s ORDER BY in_stock DESC LIMIT 1",
                '%' . $wpdb->esc_like($try[1]) . '%'
            ));
            if ($id) {
                return class_exists('MyFeeds_Product_Resolver') ? MyFeeds_Product_Resolver::resolve((string) $id) : null;
            }
        }
        return null;
    }

    // -----------------------------------------------------------------
    // A photo from a URL: probe, then show it from there or import it
    // -----------------------------------------------------------------

    const MAX_IMAGE_BYTES = 15728640; // 15 MB

    /**
     * What is behind a pasted URL: an image directly, or a page whose
     * preview image (og:image) is the photo - a Pinterest pin, a brand's
     * product page. Measures width and height so a photo shown from its
     * source cannot shift the page while it loads, and suggests credit,
     * source link and alt text. wp_safe_remote_get() refuses private
     * addresses, so the probe cannot be pointed at the site's own network.
     */
    public static function rest_probe(WP_REST_Request $req) {
        $url = esc_url_raw(trim((string) $req->get_param('url')));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return new WP_Error('myfeeds_look_bad_url', __('That is not a web address.', 'myfeeds-affiliate-feed-manager'), array('status' => 400));
        }

        $page_url  = '';
        $alt       = '';
        $res = wp_safe_remote_get($url, array('timeout' => 15, 'limit_response_size' => self::MAX_IMAGE_BYTES, 'redirection' => 4,
            'user-agent' => 'Mozilla/5.0 (compatible; MyFeeds image check; +' . home_url('/') . ')'));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) >= 400) {
            return new WP_Error('myfeeds_look_unreachable', __('This site does not let other servers read the page. Right-click the photo, copy the image address and paste that instead.', 'myfeeds-affiliate-feed-manager'), array('status' => 400));
        }
        $type = strtolower((string) wp_remote_retrieve_header($res, 'content-type'));
        $body = (string) wp_remote_retrieve_body($res);

        if (strpos($type, 'text/html') !== false) {
            $page_url = $url;
            $og = self::meta_content($body, array('og:image:secure_url', 'og:image', 'twitter:image'));
            if ($og === '') {
                return new WP_Error('myfeeds_look_no_image', __('This page names no preview image. Open the image itself and copy its address.', 'myfeeds-affiliate-feed-manager'), array('status' => 400));
            }
            $alt = self::meta_content($body, array('og:image:alt', 'og:title', 'twitter:title'));
            $url = esc_url_raw(html_entity_decode($og, ENT_QUOTES));
            $res = wp_safe_remote_get($url, array('timeout' => 15, 'limit_response_size' => self::MAX_IMAGE_BYTES, 'redirection' => 4));
            if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) >= 400) {
                return new WP_Error('myfeeds_look_unreachable', __('The page\'s image cannot be loaded.', 'myfeeds-affiliate-feed-manager'), array('status' => 400));
            }
            $body = (string) wp_remote_retrieve_body($res);
        }

        $size = $body !== '' ? @getimagesizefromstring($body) : false;
        if (!$size || empty($size[0]) || empty($size[1])) {
            return new WP_Error('myfeeds_look_not_image', __('This is not an image MyFeeds can read.', 'myfeeds-affiliate-feed-manager'), array('status' => 400));
        }

        $credit_from = $page_url !== '' ? $page_url : $url;
        return rest_ensure_response(array(
            'image_url'   => $url,
            'https'       => (bool) preg_match('#^https://#i', $url),
            'width'       => (int) $size[0],
            'height'      => (int) $size[1],
            'page_url'    => $page_url,
            'credit'      => self::label_from_url($credit_from),
            'alt'         => sanitize_text_field(html_entity_decode($alt, ENT_QUOTES)),
            'host'        => (string) wp_parse_url($url, PHP_URL_HOST),
        ));
    }

    /**
     * Copy the photo into the media library - only after the author
     * confirmed the rights to it, and with that confirmation kept on the
     * attachment (who, when, from where). Copying is a reproduction and
     * needs a licence; the plugin records who said they hold it.
     */
    public static function rest_import(WP_REST_Request $req) {
        if (!$req->get_param('confirm')) {
            return new WP_Error('myfeeds_look_rights', __('Confirm that you have the right to use this image first.', 'myfeeds-affiliate-feed-manager'), array('status' => 400));
        }
        $url = esc_url_raw((string) $req->get_param('image_url'));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return new WP_Error('myfeeds_look_bad_url', __('That is not a web address.', 'myfeeds-affiliate-feed-manager'), array('status' => 400));
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            return new WP_Error('myfeeds_look_download', $tmp->get_error_message(), array('status' => 400));
        }
        $mime = wp_get_image_mime($tmp);
        if (filesize($tmp) > self::MAX_IMAGE_BYTES || !$mime) {
            wp_delete_file($tmp);
            return new WP_Error('myfeeds_look_not_image', __('This is not an image MyFeeds can import (or it is larger than 15 MB).', 'myfeeds-affiliate-feed-manager'), array('status' => 400));
        }
        // WordPress 5.8.1 and later know every image type; before, the
        // ones a camera or a shop hands out.
        $ext = function_exists('wp_get_default_extension_for_mime_type') ? wp_get_default_extension_for_mime_type($mime) : '';
        if (!$ext) {
            $known = array('image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp');
            $ext = isset($known[$mime]) ? $known[$mime] : 'jpg';
        }
        $base = sanitize_file_name(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME)) ?: 'look';
        $file = array('name' => $base . '.' . $ext, 'tmp_name' => $tmp);

        $post_id = (int) $req->get_param('post_id');
        $id = media_handle_sideload($file, $post_id > 0 ? $post_id : 0);
        if (is_wp_error($id)) {
            wp_delete_file($tmp);
            return new WP_Error('myfeeds_look_import', $id->get_error_message(), array('status' => 400));
        }

        $alt = sanitize_text_field((string) $req->get_param('alt'));
        if ($alt !== '') {
            update_post_meta($id, '_wp_attachment_image_alt', $alt);
        }
        update_post_meta($id, '_myfeeds_image_source', array(
            'image_url' => $url,
            'source'    => esc_url_raw((string) $req->get_param('source_url')),
            'credit'    => sanitize_text_field((string) $req->get_param('credit')),
        ));
        update_post_meta($id, '_myfeeds_rights_confirmed', array(
            'user' => get_current_user_id(),
            'at'   => gmdate('c'),
            'from' => $url,
        ));

        $src = wp_get_attachment_image_src($id, 'large');
        return rest_ensure_response(array('id' => (int) $id, 'url' => $src ? $src[0] : wp_get_attachment_url($id)));
    }

    private static function meta_content($html, array $names) {
        foreach ($names as $name) {
            $q = preg_quote($name, '/');
            if (preg_match('/<meta[^>]+(?:property|name)=["\']' . $q . '["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)
                || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']' . $q . '["\']/i', $html, $m)) {
                return trim($m[1]);
            }
        }
        return '';
    }

    /**
     * "https://www.pinterest.com/pin/..." -> "Pinterest": the name between
     * "www." and the ending, as the author would say it. Image hosts that
     * belong to a platform are named after the platform.
     */
    public static function label_from_url($url) {
        $host  = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        $parts = array_values(array_filter(explode('.', preg_replace('/^www\./', '', $host))));
        if (count($parts) < 2) {
            return '';
        }
        $two = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
        $name = preg_match('/^(co|com|org|net|gov|ac|ne|or)\.[a-z]{2}$/', $two) && count($parts) >= 3
            ? $parts[count($parts) - 3]
            : $parts[count($parts) - 2];
        $platforms = array('pinimg' => 'Pinterest', 'cdninstagram' => 'Instagram', 'fbcdn' => 'Facebook',
            'media-amazon' => 'Amazon', 'ssl-images-amazon' => 'Amazon', 'googleusercontent' => 'Google',
            'twimg' => 'X', 'redd' => 'Reddit', 'unsplash' => 'Unsplash');
        if (isset($platforms[$name])) {
            return $platforms[$name];
        }
        return ucfirst($name);
    }

    /** What a row in the editor shows; the same keys the picker search returns. */
    private static function editor_shape(array $p) {
        $price = '';
        if ((float) ($p['price'] ?? 0) > 0) {
            $price = myfeeds_format_price((float) $p['price'], (string) ($p['currency'] ?? 'EUR'));
        }
        $image = (string) ($p['image_url'] ?? '');
        return array(
            'id'         => (string) ($p['id'] ?? ''),
            'title'      => (string) ($p['title'] ?? ''),
            'brand'      => (string) ($p['brand'] ?? ''),
            'merchant'   => (string) ($p['merchant'] ?? ''),
            'price_text' => $price,
            'image_url'  => function_exists('myfeeds_thumb_image_url') ? myfeeds_thumb_image_url($image, 120) : $image,
        );
    }

    // -----------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------

    public static function render($attrs) {
        $from_url = ($attrs['imageSource'] ?? 'media') === 'url';
        $caption  = '';

        if ($from_url) {
            // Shown from where it lives, not copied. The dimensions were
            // measured when it was inserted, so the page does not jump
            // while it loads; no referrer goes to the other server; and if
            // the source drops the image, the look hides its photo and
            // keeps the product list and the credit (is-broken).
            $src = esc_url_raw((string) ($attrs['imageUrl'] ?? ''));
            $w   = (int) ($attrs['imageWidth'] ?? 0);
            $h   = (int) ($attrs['imageHeight'] ?? 0);
            if ($src === '' || !preg_match('#^https://#i', $src)) {
                return '';
            }
            // An image CDN on the site may rewrite this src after us, and
            // the photo's own server may turn that CDN away: the photo
            // then goes back to its own address once before the look
            // gives up on it.
            $give_up  = 'var f=this.closest(\'.myfeeds-look\');if(f)f.classList.add(\'is-broken\');';
            $fallback = function_exists('myfeeds_image_origin_fallback_attrs')
                ? myfeeds_image_origin_fallback_attrs($src, $give_up)
                : ' onerror="' . $give_up . '"';
            $img = '<img class="myfeeds-look__img" src="' . esc_url($src) . '"'
                . ($w > 0 && $h > 0 ? ' width="' . $w . '" height="' . $h . '"' : '')
                . ' alt="' . esc_attr((string) ($attrs['imageAlt'] ?? '')) . '" loading="lazy" decoding="async" referrerpolicy="no-referrer"'
                . $fallback . '>';
        } else {
            $image_id = isset($attrs['imageId']) ? (int) $attrs['imageId'] : 0;
            if ($image_id <= 0 || !wp_attachment_is_image($image_id)) {
                return '';
            }
            $alt = trim((string) get_post_meta($image_id, '_wp_attachment_image_alt', true));
            $cap = (int) ($attrs['width'] ?? 0) > 0 ? min(1200, (int) $attrs['width']) : 1200;
            $img = wp_get_attachment_image($image_id, 'full', false, array(
                'class' => 'myfeeds-look__img',
                'alt'   => $alt,
                'sizes' => '(max-width: ' . $cap . 'px) 100vw, ' . $cap . 'px',
            ));
            if ($img === '') {
                return '';
            }
            $caption = function_exists('wp_get_attachment_caption') ? trim((string) wp_get_attachment_caption($image_id)) : '';
        }

        $items  = self::resolve_points(isset($attrs['points']) && is_array($attrs['points']) ? $attrs['points'] : array());
        $credit = self::credit_html((string) ($attrs['creditText'] ?? ''), (string) ($attrs['creditUrl'] ?? ''));
        $show   = self::show_options($attrs, $items);

        // One name per look: <details> sharing a name open one at a time
        // (the browser does it - no script). Older browsers simply let
        // several stay open.
        $group = 'myfeeds-look-' . (++self::$blocks);

        // Centred by default like mylook's pins; left or right floats it
        // beside the text. The width caps the whole look - photo, caption
        // and list stay one column.
        $align = (string) ($attrs['imageAlign'] ?? 'center');
        if (!in_array($align, array('left', 'right', 'center'), true)) {
            $align = 'center';
        }
        $width = (int) ($attrs['width'] ?? 0);
        $style = $width > 0 ? ' style="max-width:' . min(2000, $width) . 'px"' : '';

        $out  = self::css_once();
        $out .= '<figure class="myfeeds-look is-' . $align . '"' . $style . '>';
        $out .= '<div class="myfeeds-look__stage">' . $img;
        foreach ($items as $item) {
            $out .= self::render_point($item, $group, $show);
        }
        $out .= '</div>';
        if ($caption !== '' || $credit !== '') {
            $out .= '<figcaption class="myfeeds-look__caption">'
                . ($caption !== '' ? wp_kses_post($caption) : '')
                . ($caption !== '' && $credit !== '' ? ' ' : '')
                . $credit
                . '</figcaption>';
        }
        if (!empty($items) && (!isset($attrs['showList']) || $attrs['showList'])) {
            $out .= '<ol class="myfeeds-look__list' . ($show['pics'] ? ' has-pics' : '') . '">';
            foreach ($items as $item) {
                $out .= self::list_row_html($item, $show);
            }
            $out .= '</ol>';
        }
        $out .= '</figure>';

        return $out;
    }

    /**
     * "by Pinterest", the name linked to the source - an outgoing link,
     * so nofollow. Empty name, no credit.
     */
    private static function credit_html($label, $url) {
        $label = trim(sanitize_text_field($label));
        if ($label === '') {
            return '';
        }
        $url  = esc_url((string) $url, array('http', 'https'));
        $name = $url !== ''
            ? '<a href="' . $url . '" target="_blank" rel="nofollow noopener">' . esc_html($label) . '</a>'
            : esc_html($label);
        /* translators: %s: where the photo comes from, e.g. Pinterest */
        return '<span class="myfeeds-look__credit">' . sprintf(esc_html__('by %s', 'myfeeds-affiliate-feed-manager'), $name) . '</span>';
    }

    /**
     * What the author switched on in the sidebar: prices and pictures,
     * both on by default (an older Look without the attributes shows
     * them). 'pics' says whether the list under the photo needs its
     * picture column at all - only when a picture is on and some row has
     * one, so a Look of Amazon points by link keeps its narrow rows.
     *
     * @return array{prices:bool,images:bool,pics:bool}
     */
    public static function show_options(array $attrs, array $items) {
        $prices = !isset($attrs['showPrices']) || (bool) $attrs['showPrices'];
        $images = !isset($attrs['showImages']) || (bool) $attrs['showImages'];
        $pics   = false;
        if ($images) {
            foreach ($items as $item) {
                if (($item['image'] ?? '') !== '') {
                    $pics = true;
                    break;
                }
            }
        }
        return array('prices' => $prices, 'images' => $images, 'pics' => $pics);
    }

    private static function render_point(array $item, $group = '', array $show = array()) {
        $show  = $show + array('prices' => true, 'images' => true);
        $side  = $item['x'] > 60 ? ' is-left' : ($item['x'] < 40 ? ' is-right' : '');
        $side .= $item['y'] > 60 ? ' is-up' : '';
        $label = trim($item['brand'] . ' ' . $item['name']);

        return '<details class="myfeeds-look__pt' . $side . '"' . ($group !== '' ? ' name="' . esc_attr($group) . '"' : '') . ' style="left:' . $item['x'] . '%;top:' . $item['y'] . '%">'
            . '<summary class="myfeeds-look__dot" aria-label="' . esc_attr($item['n'] . '. ' . $label) . '"><span aria-hidden="true">' . (int) $item['n'] . '</span></summary>'
            . '<a class="myfeeds-look__card" href="' . esc_url($item['href']) . '" target="_blank" rel="' . esc_attr($item['rel']) . '"' . $item['attrs'] . '>'
            . ($show['images'] ? self::picture_html($item, 'card') : '')
            . self::label_html($item)
            . ($show['prices'] ? self::price_html($item) : '')
            . ($item['note'] !== '' ? '<span class="myfeeds-look__note">' . esc_html($item['note']) . '</span>' : '')
            . '<span class="myfeeds-look__go">' . esc_html($item['cta']) . '</span>'
            . '</a></details>';
    }

    /**
     * One row of the list under the photo. Every row has the same three
     * parts in the same places - number, what it is, where it goes - so
     * the CSS can lay them out on one grid whatever the text is. Brand
     * and price leave no empty element behind when there is none (an
     * Amazon point by link has no price).
     *
     * With pictures ($show['pics'], see show_options()) every row gets a
     * fourth part after the number, the picture's cell - empty where a
     * row has no picture, so the names still line up.
     */
    public static function list_row_html(array $item, array $show = array()) {
        $show = $show + array('prices' => true, 'images' => true, 'pics' => false);
        $pic  = '';
        if ($show['images'] && $show['pics']) {
            $pic = '<span class="myfeeds-look__pic" aria-hidden="true">' . self::picture_html($item, 'row') . '</span>';
        }
        return '<li><a class="myfeeds-look__row" href="' . esc_url($item['href']) . '" target="_blank" rel="' . esc_attr($item['rel']) . '"' . $item['attrs'] . '>'
            . '<span class="myfeeds-look__num" aria-hidden="true">' . (int) $item['n'] . '</span>'
            . $pic
            . '<span class="myfeeds-look__what">' . self::label_html($item) . ($show['prices'] ? self::price_html($item) : '') . '</span>'
            . '<span class="myfeeds-look__go">' . esc_html($item['cta']) . '</span>'
            . '</a></li>';
    }

    /**
     * The product's picture, in a row of the list (56 px) or at the top
     * of an open point's card. alt is empty: the name stands right next
     * to it, and a second reading of it would only repeat it.
     *
     * A feed picture is sized like every card's (myfeeds_image_render_attrs()):
     * a merchant's print master capped, a srcset where the host can
     * resize, and the way back to the feed's own address if a resized
     * copy or a site's image CDN fails. Lazy, with its size given, so
     * nothing below the photo competes with it or shifts the page; a card
     * picture inside a closed point is not loaded until the point opens.
     *
     * A picture marked 'image_as_is' is shown from the address it came
     * with - never resized, copied or proxied - where its source asks for
     * that.
     */
    private static function picture_html(array $item, $where) {
        $url = (string) ($item['image'] ?? '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        $row   = $where === 'row';
        $class = $row ? 'myfeeds-look__thumb' : 'myfeeds-look__shot';
        $dims  = $row ? ' width="56" height="56"' : ' width="220" height="140"';

        if (!empty($item['image_as_is'])) {
            return '<img class="' . $class . '" src="' . esc_url($url) . '" alt=""' . $dims . ' loading="lazy" decoding="async">';
        }

        $hide = $row ? 'this.style.visibility=\'hidden\';' : 'this.style.display=\'none\';';
        if (function_exists('myfeeds_image_render_attrs')) {
            $r = myfeeds_image_render_attrs($url, array('max_width' => 400, 'sizes' => $row ? '56px' : '220px'));
            $src    = $r['src'];
            $attrs  = $r['attrs'];
            $srcset = $r['srcset'] !== '' ? ' srcset="' . esc_attr($r['srcset']) . '" sizes="' . esc_attr($r['sizes']) . '"' : '';
        } else {
            $src    = $url;
            $attrs  = 'loading="lazy" decoding="async"';
            $srcset = '';
        }
        $fallback = function_exists('myfeeds_image_origin_fallback_attrs')
            ? myfeeds_image_origin_fallback_attrs($url, $hide)
            : ' onerror="' . $hide . '"';
        return '<img class="' . $class . '" src="' . esc_url($src) . '"' . $srcset . ' alt=""' . $dims . ' ' . $attrs . $fallback . '>';
    }

    /**
     * The price, and the one it replaces struck through after it. <s>,
     * not <del>: the old price was not edited out of this page, it is
     * simply no longer what the shop asks.
     *
     * A point whose source draws its own price ('price_html', from
     * myfeeds_look_amazon_items: a function, asked only where the Look
     * shows prices) shows that, with whatever its rules put beside it;
     * myfeeds_hide_prices is about the site's feeds.
     */
    private static function price_html(array $item) {
        if (isset($item['price_html']) && $item['price_html'] instanceof Closure) {
            $own = call_user_func($item['price_html']);
            return is_string($own) ? $own : '';
        }
        if (($item['price'] ?? '') === '') {
            return '';
        }
        // A price the site cannot vouch for any more (myfeeds_hide_prices).
        if (apply_filters('myfeeds_hide_prices', false)) {
            return '';
        }
        $was = (string) ($item['was'] ?? '');
        return '<span class="myfeeds-look__price"><span class="myfeeds-look__now">' . esc_html($item['price']) . '</span>'
            . ($was !== '' ? ' <s class="myfeeds-look__was">' . esc_html($was) . '</s>' : '')
            . '</span>';
    }

    private static function label_html(array $item) {
        return ($item['brand'] !== '' ? '<span class="myfeeds-look__brand">' . esc_html($item['brand']) . '</span>' : '')
            . '<span class="myfeeds-look__name">' . esc_html($item['name']) . '</span>';
    }

    /**
     * Points as the post can show them, numbered in order. A point whose
     * product cannot be bought - gone, sold out, behind a closed
     * programme, an Amazon ASIN without a name - is left out, and the
     * numbers close up so dot and list always agree.
     */
    public static function resolve_points(array $points) {
        $clean = array();
        foreach (array_slice($points, 0, self::MAX_POINTS) as $p) {
            if (!is_array($p) || empty($p['id'])) {
                continue;
            }
            $clean[] = array(
                'x'     => max(0, min(100, round((float) ($p['x'] ?? 50), 2))),
                'y'     => max(0, min(100, round((float) ($p['y'] ?? 50), 2))),
                'id'    => (string) $p['id'],
                'brand' => sanitize_text_field((string) ($p['brand'] ?? '')),
                'name'  => sanitize_text_field((string) ($p['name'] ?? '')),
                'note'  => sanitize_text_field((string) ($p['note'] ?? '')),
            );
        }

        // The Amazon points in one call, never one per point. Without
        // anyone to answer (myfeeds_look_amazon_items) each point is the
        // author's own words and the link built from its ASIN.
        $amazon = array();
        $asked  = array();
        foreach ($clean as $p) {
            if (self::is_amazon($p['id'])) {
                $asked[] = $p;
            }
        }
        if (!empty($asked)) {
            $amazon = apply_filters('myfeeds_look_amazon_items', array(), $asked);
            $amazon = is_array($amazon) ? $amazon : array();
        }

        $items = array();
        foreach ($clean as $p) {
            $item = self::is_amazon($p['id'])
                ? self::amazon_item($p, $amazon[$p['id']] ?? null)
                : self::feed_item($p);
            if ($item === null) {
                continue;
            }
            $item['n'] = count($items) + 1;
            $item['x'] = $p['x'];
            $item['y'] = $p['y'];
            $item['note'] = $p['note'];
            $items[] = $item;
        }
        return $items;
    }

    private static function is_amazon($id) {
        return class_exists('MyFeeds_Amazon_Compliance') && MyFeeds_Amazon_Compliance::is_amazon_id($id);
    }

    /**
     * The feed products one Look holds, for everything that reads posts
     * instead of rendering them: the purge and feed deletion
     * (placed_in_content_ids), Quick Sync and the orphan archive (the
     * importer's discovery), and the dead-products card, whose list is
     * what Clear all removes. One answer, so none of them can disagree
     * with another about what a point is.
     *
     * Why they need it at all: a point keeps far less than a picker's
     * copy - no link, no feed id. Once the catalogue row behind it is
     * deleted, nothing can say what it was, mark it in the editor, or
     * find the product the merchant re-listed in its place; the post
     * just drops it without a word. So the readers that delete rows have
     * to see these ids, and the readers that judge them have to ask.
     *
     * Amazon points are not feed products. They never have a row, so
     * every one would read as missing - Quick Sync would count it as
     * gone from the feed. Left out here, not at each reader. The id
     * decides, as in the renderer; 'kind' only backs it up.
     *
     * @param array $block One block as parse_blocks() returns it.
     * @return list<array{id:string,title:string,brand:string,image_url:string}>
     */
    public static function feed_points(array $block) {
        if (($block['blockName'] ?? '') !== self::BLOCK) {
            return array();
        }
        $points = $block['attrs']['points'] ?? array();
        if (!is_array($points)) {
            return array();
        }

        // Every post is read here, so a point a script or an old editor
        // saved in another shape must not raise a notice on the way.
        $text = function ($p, $key) {
            return isset($p[$key]) && is_scalar($p[$key]) ? (string) $p[$key] : '';
        };

        $out = array();
        foreach ($points as $p) {
            if (!is_array($p) || !isset($p['id']) || !is_scalar($p['id'])) {
                continue;
            }
            $id = trim((string) $p['id']);
            if ($id === '' || self::is_amazon($id) || $text($p, 'kind') === 'amazon') {
                continue;
            }
            $out[] = array(
                'id'        => $id,
                'title'     => trim(sanitize_text_field($text($p, 'name'))),
                'brand'     => trim(sanitize_text_field($text($p, 'brand'))),
                'image_url' => $text($p, 'img'),
            );
        }
        return $out;
    }

    /**
     * The Amazon products one Look holds, for the readers that judge what a
     * post holds by Amazon's answer instead of a catalogue row: the
     * dead-products card, Clear all and the editor's marks. Only ids that
     * name their market (amzn:<market>:<ASIN>) - that is what the page
     * renders and what Amazon can be asked about. The purge, the feed
     * deletion and Quick Sync never read these: there is no row to keep.
     *
     * The author's own words only, never Amazon's (the licence keeps
     * Amazon's data out of anything stored or remembered).
     *
     * @param array $block One block as parse_blocks() returns it.
     * @return list<array{id:string,title:string,brand:string}>
     */
    public static function amazon_points(array $block) {
        if (($block['blockName'] ?? '') !== self::BLOCK) {
            return array();
        }
        $points = $block['attrs']['points'] ?? array();
        if (!is_array($points)) {
            return array();
        }

        $out = array();
        foreach ($points as $p) {
            if (!is_array($p) || !isset($p['id']) || !is_scalar($p['id'])) {
                continue;
            }
            $id = trim((string) $p['id']);
            if ($id === '' || !self::is_amazon($id)) {
                continue;
            }
            $out[] = array(
                'id'    => $id,
                'title' => isset($p['name']) && is_scalar($p['name']) ? trim(sanitize_text_field((string) $p['name'])) : '',
                'brand' => isset($p['brand']) && is_scalar($p['brand']) ? trim(sanitize_text_field((string) $p['brand'])) : '',
            );
        }
        return $out;
    }

    /**
     * An Amazon point: the author's name and the plain tagged /dp/ link
     * built from its ASIN, no picture, no price. Where the site knows the
     * product (myfeeds_look_amazon_items) the point is what it answers,
     * built with point_item().
     *
     * @param array|false|null $known The site's point; false when its
     *                                page is gone.
     */
    private static function amazon_item(array $p, $known) {
        // The product's page is gone: the link would end on a 404. Left
        // out like a feed product that is gone (rule 17).
        if ($known === false) {
            return null;
        }
        $parsed = MyFeeds_Amazon_Compliance::parse_id($p['id']);
        if (!$parsed) {
            return null;
        }
        if (is_array($known) && isset($known['href'], $known['name']) && $known['href'] !== '' && $known['name'] !== '') {
            return $known + array('brand' => '', 'attrs' => '', 'rel' => 'nofollow sponsored noopener', 'price' => '', 'was' => '',
                'cta' => __('View on Amazon', 'myfeeds-affiliate-feed-manager'), 'image' => '', 'image_as_is' => false);
        }
        $link = MyFeeds_Amazon_Compliance::product_url($parsed['marketplace'], $parsed['asin']);
        if ($p['name'] === '' || $link === '') {
            return null;
        }
        // "Amazon", not the market's address (Marlon, 28.10.).
        return self::point_item($p['id'], $p['brand'], $p['name'], $link, 0, '', __('View on Amazon', 'myfeeds-affiliate-feed-manager'));
    }

    /**
     * A feed product as the picker would show it: re-listed under a new id
     * -> the new one; gone, sold out or behind a link that leads nowhere
     * -> left out; archived -> shown with its last values (rule 12).
     */
    private static function feed_item(array $p) {
        if (!class_exists('MyFeeds_Product_Resolver')) {
            return null;
        }
        $product = MyFeeds_Product_Resolver::resolve($p['id']);
        if (is_array($product) && ($product['status'] ?? '') === 'unavailable' && class_exists('MyFeeds_Variants')) {
            $product = MyFeeds_Variants::replacement_for($product);
        }
        if (!is_array($product) || ($product['status'] ?? 'active') === 'unavailable') {
            return null;
        }
        // Same reading as the picker: only an explicit 0 is sold out; a
        // source that does not say is not assumed to be.
        // A sold-out size whose sibling is buyable sends the reader to
        // the sibling, as the picker does. And, as there, only for a live
        // row: an archived one is frozen at its last values (rule 12), its
        // stock among them, and keeps rendering.
        $in_stock = $product['in_stock'] ?? null;
        if ($in_stock !== null && (int) $in_stock === 0 && ($product['status'] ?? 'active') === 'active') {
            $product = class_exists('MyFeeds_Variants') ? MyFeeds_Variants::buyable_sibling($product) : null;
            if (!is_array($product)) {
                return null;
            }
        }
        $link = function_exists('myfeeds_repair_saved_link')
            ? myfeeds_repair_saved_link((string) ($product['affiliate_link'] ?? ''))
            : (string) ($product['affiliate_link'] ?? '');
        if ($link === '' || $link === '#') {
            return null;
        }
        // Behind a closed programme, or a merchant the catalogue no longer
        // carries: the picker leaves its card out, by the same function.
        // A row can still say 'active' while its link ends on the
        // network's "merchant not available" page.
        if (class_exists('MyFeeds_Link_Health') && MyFeeds_Link_Health::leads_nowhere($link)) {
            return null;
        }

        $prices   = self::price_pair($product);
        $merchant = (string) ($product['merchant'] ?? '');

        $item = self::point_item(
            (string) ($product['id'] ?? $p['id']),
            (string) ($product['brand'] ?? $p['brand']),
            // The title the author kept or edited in the editor; the feed's
            // own only for points saved before there was a title field.
            $p['name'] !== '' ? $p['name'] : (string) ($product['title'] ?? ''),
            $link,
            (int) ($product['feed_id'] ?? 0),
            $prices['now'],
            $merchant !== ''
                /* translators: %s: shop name */
                ? sprintf(__('View at %s', 'myfeeds-affiliate-feed-manager'), $merchant)
                : __('View product', 'myfeeds-affiliate-feed-manager')
        );
        $item['was']   = $prices['was'];
        $item['image'] = (string) ($product['image_url'] ?? '');
        return $item;
    }

    /**
     * The price a reader pays and the one it replaces, read exactly as the
     * picker card reads them (old_price, or price above a lower
     * sale_price), so a Look and a picker beside it never disagree about
     * a discount. Empty strings where there is nothing to say.
     *
     * @return array{now:string,was:string}
     */
    public static function price_pair(array $product) {
        $price = (float) ($product['price'] ?? 0);
        $sale  = (float) ($product['sale_price'] ?? 0);
        $old   = (float) ($product['old_price'] ?? 0);
        if ($old === 0.0 && $sale > 0 && $sale < $price) {
            $old   = $price;
            $price = $sale;
        }
        if ($old > 0 && $sale > 0 && $sale < $old) {
            $price = $sale;
        }
        if ($price <= 0) {
            return array('now' => '', 'was' => '');
        }
        $currency = (string) ($product['currency'] ?? 'EUR');
        return array(
            'now' => myfeeds_format_price($price, $currency),
            'was' => $old > $price ? myfeeds_format_price($old, $currency) : '',
        );
    }

    /**
     * One point as the Look draws it: the link counted like every card's
     * (through /myfeeds/go/, or directly to Amazon - the click tracker
     * decides), its words, its price. A source that answers
     * myfeeds_look_amazon_items builds its points here and adds 'image'
     * (with 'image_as_is') and 'price_html' (a function that draws the
     * price, so nothing of it runs where the Look shows no prices).
     *
     * @param array $track More for the click tracker's product.
     * @return array
     */
    public static function point_item($id, $brand, $name, $link, $feed_id, $price, $cta, array $track = array()) {
        $product = array_merge(
            array('id' => $id, 'title' => $name, 'brand' => $brand, 'affiliate_link' => $link, 'feed_id' => $feed_id, 'click_block' => 'look-point'),
            $track
        );
        $href  = $link;
        $attrs = '';
        if (class_exists('MyFeeds_Click_Tracker')) {
            $wrapped = MyFeeds_Click_Tracker::wrap_card_url($product);
            if (is_string($wrapped) && $wrapped !== '') {
                $href = $wrapped;
            }
            $attrs = MyFeeds_Click_Tracker::card_data_attrs($product);
        }
        return array(
            'brand' => $brand,
            'name'  => $name,
            'href'  => $href,
            'attrs' => $attrs,
            'rel'   => class_exists('MyFeeds_Click_Tracker') ? MyFeeds_Click_Tracker::link_rel() : 'nofollow sponsored noopener',
            'price' => (string) $price,
            'was'   => '',
            'cta'   => (string) $cta,
            'image' => '',
            'image_as_is' => false,
        );
    }

    /**
     * Inline, once, ahead of the first look on the page: a stylesheet
     * enqueued from inside the content would arrive in the footer, after
     * the points had already been drawn as plain text under the photo.
     */
    private static function css_once() {
        if (self::$css_printed) {
            return '';
        }
        self::$css_printed = true;

        return '<style id="myfeeds-look-css">'
            . '.myfeeds-look{margin:1.5em 0}'
            . '.myfeeds-look__stage{position:relative;line-height:0}'
            . '.myfeeds-look__img{display:block;width:100%;height:auto}'
            . '.myfeeds-look__pt{position:absolute;z-index:1;line-height:1.35;transform:translate(-50%,-50%)}'
            . '.myfeeds-look__pt[open]{z-index:3}'
            . '.myfeeds-look__dot{list-style:none;display:block;width:30px;height:30px;border-radius:50%;cursor:pointer;background:#fff;color:#111;font:600 13px/30px system-ui,-apple-system,sans-serif;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.35);user-select:none}'
            . '.myfeeds-look__dot::-webkit-details-marker{display:none}'
            . '.myfeeds-look__dot::marker{content:""}'
            . '.myfeeds-look__dot:focus-visible{outline:2px solid #fff;outline-offset:2px}'
            . '.myfeeds-look__pt[open] .myfeeds-look__dot{background:#111;color:#fff}'
            . '.myfeeds-look__card{position:absolute;top:calc(100% + 8px);left:50%;transform:translateX(-50%);display:flex;flex-direction:column;gap:3px;width:220px;max-width:72vw;padding:12px 14px;border-radius:8px;background:#fff;color:#111;text-decoration:none;box-shadow:0 8px 28px rgba(0,0,0,.25);font-size:14px}'
            . '.myfeeds-look__pt.is-left .myfeeds-look__card{left:auto;right:-6px;transform:none}'
            . '.myfeeds-look__pt.is-right .myfeeds-look__card{left:-6px;transform:none}'
            . '.myfeeds-look__pt.is-up .myfeeds-look__card{top:auto;bottom:calc(100% + 8px)}'
            . '.myfeeds-look__brand{font-size:11px;letter-spacing:.08em;text-transform:uppercase;opacity:.65}'
            . '.myfeeds-look__name{font-weight:600;overflow-wrap:break-word;hyphens:auto}'
            . '.myfeeds-look__price{font-variant-numeric:tabular-nums}'
            . '.myfeeds-look__was{opacity:.6}'
            . '.myfeeds-look__note{font-size:13px;opacity:.8}'
            // Pictures: white behind them, as product photos expect, on a
            // light or a dark theme alike; contain, so nothing is cropped.
            . '.myfeeds-look__shot{display:block;width:100%;height:140px;margin:0 0 6px;object-fit:contain;border-radius:6px;background:#fff}'
            . '.myfeeds-look__stamp{display:block;font-size:11px;line-height:1.35;opacity:.72}'
            . '.myfeeds-look__go{font-size:13px;font-weight:600;text-decoration:underline;text-underline-offset:3px}'
            . '.myfeeds-look__caption{margin-top:8px;font-size:14px;opacity:.75}'
            . '.myfeeds-look__credit a{color:inherit;text-decoration:underline;text-underline-offset:2px}'
            . '.myfeeds-look.is-broken .myfeeds-look__stage{display:none}'
            . '.myfeeds-look.is-center{margin-left:auto;margin-right:auto}'
            . '.myfeeds-look.is-center .myfeeds-look__caption{text-align:center}'
            . '.myfeeds-look.is-left{float:left;margin:.3em 1.6em 1em 0}'
            . '.myfeeds-look.is-right{float:right;margin:.3em 0 1em 1.6em}'
            // A floated look is as wide as its content; the list below is
            // a size container and gives it no width, so a photo without
            // known dimensions (not loaded yet) would leave the float a
            // sliver. The floor is the editor's smallest photo width, so
            // it never overrides a width the author chose.
            . '.myfeeds-look.is-left,.myfeeds-look.is-right{min-width:min(200px,100%)}'
            . '@media (max-width:600px){.myfeeds-look.is-left,.myfeeds-look.is-right{float:none;margin:1.5em 0;max-width:none!important}}'
            // The list under the photo: one grid for every row, whatever
            // the text - number, what it is, where it goes. Colours come
            // from the theme (currentColor), so it reads on dark themes;
            // logical properties, so it mirrors itself in RTL. The list is
            // its own container: a look set to 300 px beside the text
            // stacks like a phone does, which a viewport query cannot see.
            . '.myfeeds-look__list{list-style:none;margin:14px 0 0;padding:0;container-type:inline-size}'
            // Without its photo the look has nothing else to take its
            // width from; a container would let a floated one collapse.
            . '.myfeeds-look.is-broken .myfeeds-look__list{container-type:normal}'
            . '.myfeeds-look__list>li{margin:0;padding:0}'
            . '.myfeeds-look__list>li+li{border-top:1px solid rgba(127,127,127,.25);border-top-color:color-mix(in srgb,currentColor 14%,transparent)}'
            . '.myfeeds-look__row{display:grid;grid-template-columns:24px minmax(0,1fr) auto;align-items:center;column-gap:14px;padding:10px 0;color:inherit;text-decoration:none;line-height:1.35}'
            . '.myfeeds-look__row:focus-visible{outline:2px solid currentColor;outline-offset:2px}'
            . '.myfeeds-look__num{display:block;box-sizing:border-box;width:24px;height:24px;border:1.5px solid currentColor;border-radius:50%;font:600 12px/21px system-ui,-apple-system,sans-serif;text-align:center;font-variant-numeric:tabular-nums}'
            . '.myfeeds-look__row .myfeeds-look__what{display:flex;flex-direction:column;gap:2px;min-width:0}'
            . '.myfeeds-look__row .myfeeds-look__brand{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.myfeeds-look__row .myfeeds-look__name{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden}'
            . '.myfeeds-look__row .myfeeds-look__price{font-size:14px}'
            . '.myfeeds-look__row .myfeeds-look__go{justify-self:end;max-width:16em;text-align:end}'
            . '.myfeeds-look__row:hover .myfeeds-look__go{text-decoration-thickness:2px}'
            // With pictures every row has a picture cell after the number,
            // empty where a row has none, so the names stay in one line.
            . '.myfeeds-look__list.has-pics .myfeeds-look__row{grid-template-columns:24px 56px minmax(0,1fr) auto}'
            . '.myfeeds-look__pic{display:block;width:56px;height:56px}'
            . '.myfeeds-look__thumb{display:block;width:56px;height:56px;object-fit:contain;border-radius:6px;background:#fff}'
            . '@container (max-width:479px){'
            . '.myfeeds-look__row{grid-template-columns:24px minmax(0,1fr);align-items:start;row-gap:6px}'
            . '.myfeeds-look__row .myfeeds-look__go{grid-column:2;justify-self:start;max-width:none;text-align:start}'
            . '.myfeeds-look__list.has-pics .myfeeds-look__row{grid-template-columns:24px 56px minmax(0,1fr)}'
            . '.myfeeds-look__list.has-pics .myfeeds-look__pic{grid-row:span 2}'
            . '.myfeeds-look__list.has-pics .myfeeds-look__row .myfeeds-look__go{grid-column:3}'
            . '}'
            // The same two rules by viewport, for a list that is no
            // container (a broken photo) and for browsers without
            // container queries (Safari < 16). A viewport under 480 px
            // always means a list under 480 px, so the two never disagree.
            . '@media (max-width:479px){'
            . '.myfeeds-look__row{grid-template-columns:24px minmax(0,1fr);align-items:start;row-gap:6px}'
            . '.myfeeds-look__row .myfeeds-look__go{grid-column:2;justify-self:start;max-width:none;text-align:start}'
            . '.myfeeds-look__list.has-pics .myfeeds-look__row{grid-template-columns:24px 56px minmax(0,1fr)}'
            . '.myfeeds-look__list.has-pics .myfeeds-look__pic{grid-row:span 2}'
            . '.myfeeds-look__list.has-pics .myfeeds-look__row .myfeeds-look__go{grid-column:3}'
            . '}'
            . '</style>'
            // A click beside an open point closes it, as does Escape; and
            // one open point per look where the browser does not know
            // <details name> yet. One listener for the page, no file.
            . '<script>(function(){'
            . 'function shut(keep){document.querySelectorAll(".myfeeds-look__pt[open]").forEach(function(d){if(d!==keep)d.open=false;});}'
            . 'document.addEventListener("click",function(e){var d=e.target.closest&&e.target.closest(".myfeeds-look__pt");shut(d);},true);'
            . 'document.addEventListener("keydown",function(e){if(e.key==="Escape")shut(null);});'
            . 'document.addEventListener("toggle",function(e){var d=e.target;if(d.classList&&d.classList.contains("myfeeds-look__pt")&&d.open)shut(d);},true);'
            . '})();</script>';
    }
}

MyFeeds_Look_Block::init();
