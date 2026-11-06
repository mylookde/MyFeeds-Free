<?php
/**
 * An Amazon product in a post, by link or ASIN: the card the author names.
 *
 * Everything on it is the author's own - brand, name, a line of their
 * own - plus a plain /dp/ link with the site's partner tag
 * (MyFeeds_Amazon_Compliance::product_url()). No picture and no price:
 * the licence allows neither without Amazon's own data, and copying
 * Amazon's pictures or prices is not allowed.
 *
 * Amazon stays out of the structured data on every block, and a page that
 * shows the official badge carries the trademark notice Amazon's
 * guidelines ask for with it. Anything else a page says about Amazon (a
 * price's disclaimer) is handed in through myfeeds_amazon_disclaimer_lines
 * and printed in the same note.
 *
 * Shared by both plugins, apart from the text domain.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Card {

    const HANDLE = 'myfeeds-amazon-card';

    private static $text_cards = false;
    private static $badge_marketplaces = array();
    private static $styles = array();

    public static function init() {
        add_filter('myfeeds_schema_product', array(__CLASS__, 'keep_out_of_schema'), 10, 2);
        add_action('wp_footer', array(__CLASS__, 'enqueue'), 5);
        add_action('wp_footer', array(__CLASS__, 'render_disclaimer'), 6);
    }

    /**
     * Amazon stays out of the structured data on every block. What a page
     * says about an Amazon product is the author's word or, with Amazon's
     * data, a price that ages in a cached page where no script reaches it.
     */
    public static function keep_out_of_schema($node, $product) {
        $id = isset($product['id']) ? (string) $product['id'] : '';

        return MyFeeds_Amazon_Compliance::is_amazon_id($id) ? null : $node;
    }

    /** Is there a link to send a reader to? Without a tag for that site there is none. */
    public static function can_link($id) {
        $parsed = MyFeeds_Amazon_Compliance::parse_id($id);
        return $parsed && MyFeeds_Amazon_Compliance::product_url($parsed['marketplace'], $parsed['asin']) !== '';
    }

    /**
     * Where the product photo would be, the card sets brand and name in
     * large type, so it keeps the height of its neighbours in the grid.
     *
     * The call to action is the official "Available at Amazon" badge when
     * the owner uploaded it, otherwise plain text. Never the Amazon logo
     * itself: the trademark rules reserve that, and the badge only counts
     * unaltered and at least 90 px wide.
     *
     * @param string $id     amzn:<code>:<ASIN>
     * @param array  $manual brand, name, note as the author typed them.
     * @param array  $card   click_block, pick (a recommendation card or
     *                       comparison table).
     * @return string '' without a name, or when the site has no partner
     *                tag for that Amazon site - an untagged link would
     *                earn nothing.
     */
    public static function render_text_card($id, array $manual, array $card = array()) {
        $parsed = MyFeeds_Amazon_Compliance::parse_id($id);
        $name   = trim((string) ($manual['name'] ?? ''));
        if (!$parsed || $name === '') {
            return '';
        }
        $url = MyFeeds_Amazon_Compliance::product_url($parsed['marketplace'], $parsed['asin']);
        if ($url === '') {
            return '';
        }

        $brand = trim((string) ($manual['brand'] ?? ''));
        $note  = trim((string) ($manual['note'] ?? ''));

        $product = array(
            'id'             => $id,
            'title'          => $name,
            'brand'          => $brand,
            'affiliate_link' => $url,
            'feed_id'        => 0,
            'click_block'    => isset($card['click_block']) ? $card['click_block'] : '',
        );
        $href  = $url;
        $attrs = '';
        if (class_exists('MyFeeds_Click_Tracker')) {
            $wrapped = MyFeeds_Click_Tracker::wrap_card_url($product);
            if (is_string($wrapped) && $wrapped !== '') {
                $href = $wrapped;
            }
            $attrs = MyFeeds_Click_Tracker::card_data_attrs($product);
        }
        $rel = class_exists('MyFeeds_Click_Tracker') ? MyFeeds_Click_Tracker::link_rel() : 'nofollow sponsored noopener';

        self::$text_cards = true;

        $badge_id = MyFeeds_Amazon_Link_Settings::badge_id();
        if ($badge_id > 0) {
            self::$badge_marketplaces[$parsed['marketplace']] = true;
            $alt = (string) get_post_meta($badge_id, '_wp_attachment_image_alt', true);
            $cta = wp_get_attachment_image($badge_id, 'medium', false, array(
                'class'    => 'myfeeds-amazon-badge',
                'alt'      => $alt !== '' ? $alt : __('Available at Amazon', 'myfeeds-affiliate-feed-manager'),
                'loading'  => 'lazy',
                'decoding' => 'async',
            ));
        } else {
            // "Amazon", not the market's address (Marlon, 28.10.).
            $cta = '<span class="myfeeds-amazon-cta__text">' . esc_html__('View on Amazon', 'myfeeds-affiliate-feed-manager') . '</span>';
        }

        $favourite = empty($card['pick']['favourite']) ? '' : ' is-favourite';
        $html  = '<a class="myfeeds-product-card myfeeds-amazon-text' . $favourite . '" href="' . esc_url($href) . '" target="_blank" rel="' . esc_attr($rel) . '"' . $attrs . '>';
        $html .= '<div class="myfeeds-product-image myfeeds-amazon-face"><div class="myfeeds-amazon-face__inner">';
        if ($brand !== '') {
            $html .= '<span class="myfeeds-amazon-face__brand">' . esc_html($brand) . '</span>';
        }
        $html .= '<span class="myfeeds-amazon-face__name">' . esc_html($name) . '</span>';
        $html .= '</div></div>';
        $html .= '<div class="myfeeds-product-details">';
        if (!empty($card['pick']) && class_exists('MyFeeds_Picker_Displays')) {
            $full_name = ($brand === '' || stripos($name, $brand) !== false) ? $name : $brand . ' ' . $name;
            $html .= '<div class="myfeeds-product-title">' . esc_html($full_name) . '</div>';
            $html .= MyFeeds_Picker_Displays::pick_html($card['pick'], $badge_id > 0 ? $cta : '');
        } else {
            if ($note !== '') {
                $html .= '<div class="myfeeds-amazon-note">' . esc_html($note) . '</div>';
            }
            $html .= '<div class="myfeeds-amazon-cta">' . $cta . '</div>';
        }
        $html .= '</div></a>';

        return $html;
    }

    /**
     * Styles of another part of a page about Amazon, printed with the
     * card's in the one stylesheet.
     */
    public static function add_styles($css) {
        self::$styles[md5((string) $css)] = (string) $css;
    }

    /**
     * One note per page, after the content: where the badge was shown, the
     * trademark notice Amazon's guidelines require with it, and whatever
     * else was handed in for this page.
     */
    public static function render_disclaimer() {
        $lines = apply_filters('myfeeds_amazon_disclaimer_lines', array());
        $lines = is_array($lines) ? array_values(array_filter(array_map('strval', $lines), 'strlen')) : array();
        if (!empty(self::$badge_marketplaces)) {
            $german = isset(self::$badge_marketplaces['www.amazon.de']) && count(self::$badge_marketplaces) === 1;
            $lines[] = $german
                ? 'Amazon und das Amazon-Logo sind Marken von Amazon.com, Inc. oder eines seiner verbundenen Unternehmen.'
                : 'Amazon and the Amazon logo are trademarks of Amazon.com, Inc. or its affiliates.';
        }
        if (empty($lines)) {
            return;
        }

        echo '<div class="myfeeds-amazon-disclaimer" role="note">';
        foreach ($lines as $line) {
            echo '<p>' . esc_html($line) . '</p>';
        }
        echo '</div>';
    }

    /** Inline: a few lines, only on a page with an Amazon card. */
    public static function enqueue() {
        if (!self::$text_cards && empty(self::$styles)) {
            return;
        }

        wp_register_style(self::HANDLE, false, array(), null);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, self::css() . implode('', self::$styles));
    }

    private static function css() {
        return '.myfeeds-amazon-disclaimer{font-size:11px;line-height:1.45;opacity:.7;margin:24px auto;max-width:960px;padding:0 16px}'
            . '.myfeeds-amazon-disclaimer p{margin:4px 0}'
            // The face stands where the product photo would: same box, so
            // the grid keeps one height. Colours follow the card design.
            // The name is sized against the card, not the screen: four
            // cards in a 645px text column are 149px wide on a desktop
            // screen, and 22px there broke "Hartschalenkoffer" mid-word.
            // cqi is 1% of the face's width (the vw line stays for
            // browsers without container units), and hyphens:auto lets a
            // long German word break where the page language allows.
            . '.myfeeds-product-card .myfeeds-amazon-face__inner{position:absolute;inset:0;display:flex;flex-direction:column;justify-content:center;gap:6px;padding:12%;text-align:center;container-type:inline-size}'
            . '.myfeeds-amazon-face__brand{font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.65}'
            . '.myfeeds-amazon-face__name{font-size:clamp(16px,1.6vw,22px);font-size:clamp(15px,13cqi,22px);line-height:1.2;font-weight:600;overflow-wrap:anywhere;-webkit-hyphens:auto;hyphens:auto}'
            . '.myfeeds-amazon-note{font-size:13px;line-height:1.4;opacity:.8;margin-bottom:8px}'
            . '.myfeeds-amazon-cta{margin-top:auto;padding-top:4px}'
            . '.myfeeds-amazon-cta__text{display:inline-block;font-size:13px;font-weight:600;text-decoration:underline;text-underline-offset:3px}'
            . '.myfeeds-amazon-cta .myfeeds-amazon-badge{display:block;width:120px;min-width:90px;height:auto;margin:6px 0}';
    }
}
