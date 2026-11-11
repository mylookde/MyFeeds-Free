<?php
/**
 * Amazon's price on a page, with Amazon's data (the Creators API).
 *
 * Two things must happen wherever Amazon's price is shown and neither is
 * optional: the price carries a date/time stamp with the prescribed
 * disclaimer, and the displayed price is never older than an hour. The
 * second one is why the price is refreshed in the browser instead of being
 * trusted from the server-rendered HTML - see the note on enqueue().
 *
 * The card by link or ASIN is MyFeeds_Amazon_Card; this part comes with
 * Amazon's data. It hangs on the card's insertion point
 * (myfeeds_product_card_details_extra) and on the page's Amazon note
 * (myfeeds_amazon_disclaimer_lines); a Look's Amazon price asks
 * stamp_html() (MyFeeds_Amazon_Look::items()).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Stamp {

    const HANDLE = 'myfeeds-amazon-live';

    private static $seen_ids = array();
    private static $seen_marketplaces = array();

    /**
     * The page's note hangs on in MyFeeds_Amazon_Api::filters().
     */
    public static function init() {
        add_filter('myfeeds_product_card_details_extra', array(__CLASS__, 'render_stamp'), 10, 2);
        // Before the card's styles go out (wp_footer, 5): the stamp's
        // join them.
        add_action('wp_footer', array(__CLASS__, 'enqueue'), 4);
    }

    public static function render_stamp($html, $product) {
        if (empty($product['id']) || !MyFeeds_Amazon_Compliance::is_amazon_id($product['id'])) {
            return $html;
        }

        $parsed = MyFeeds_Amazon_Compliance::parse_id($product['id']);
        if (!$parsed) {
            return $html;
        }

        // No price, so no stamp beside it. What stays is the anchor
        // amazon-live.js finds the card by: the card still has to say when
        // Amazon has run out of the product.
        if (!empty($product['pick']['hide_price'])) {
            self::$seen_ids[$product['id']] = true;
            self::$seen_marketplaces[$parsed['marketplace']] = true;
            return $html . '<div class="myfeeds-amazon-stamp" hidden data-myfeeds-amazon-id="' . esc_attr($product['id']) . '"></div>';
        }

        $fetched_at = isset($product['price_fetched_at']) ? (int) $product['price_fetched_at'] : 0;

        return $html . self::stamp_html($product['id'], $fetched_at);
    }

    /**
     * The stamp beside an Amazon price, wherever one is shown - the card,
     * and a Look's point and list row. Showing it is what brings the
     * page its disclaimer and the script that keeps price and stamp
     * current (enqueue(), disclaimer_lines()), so there is no Amazon
     * price on a page without both.
     *
     * Only the timestamp sits next to the price. Amazon's licence accepts the
     * disclaimer being provided nearby by "a hyperlink, pop-up box or
     * similar method" rather than repeated next to every price, and a
     * card is inside an anchor so it cannot hold a link of its own.
     *
     * @param string $id         amzn:<code>:<ASIN>
     * @param int    $fetched_at When Amazon gave the price.
     * @param string $tag        'div', or 'span' inside inline markup.
     * @param string $class      An extra class for the caller's layout.
     * @return string '' for an id that is not Amazon's.
     */
    public static function stamp_html($id, $fetched_at, $tag = 'div', $class = '') {
        $parsed = MyFeeds_Amazon_Compliance::parse_id($id);
        if (!$parsed) {
            return '';
        }
        $tag = $tag === 'span' ? 'span' : 'div';

        self::$seen_ids[$id] = true;
        self::$seen_marketplaces[$parsed['marketplace']] = true;

        $stamp = self::format_stamp($parsed['marketplace'], (int) $fetched_at);

        return '<' . $tag . ' class="myfeeds-amazon-stamp' . ($class !== '' ? ' ' . esc_attr($class) : '') . '" data-myfeeds-amazon-id="' . esc_attr($id) . '" data-myfeeds-amazon-at="' . (int) $fetched_at . '">'
            . '<span class="myfeeds-amazon-stamp__time">' . esc_html($stamp) . '</span>'
            . '</' . $tag . '>';
    }

    /**
     * The disclaimer wording is prescribed by Amazon's licence, not ours to
     * rephrase. German and English are quoted from the respective operating
     * agreements; other locales fall back to the English text, which the
     * agreements accept for an English-language application.
     */
    public static function disclaimer($marketplace) {
        $site = MyFeeds_Amazon_Locales::site_name($marketplace);

        if ($marketplace === 'www.amazon.de') {
            return sprintf(
                'Produktpreise und Verfügbarkeit sind zum angegebenen Datum/zur angegebenen Uhrzeit zutreffend und können sich jederzeit ändern. Preis- und Verfügbarkeitsinformationen auf %s zum Zeitpunkt des Kaufs gelten für den Kauf dieses Produkts.',
                $site
            );
        }

        return sprintf(
            'Product prices and availability are accurate as of the date/time indicated and are subject to change. Any price and availability information displayed on %s at the time of purchase will apply to the purchase of this product.',
            $site
        );
    }

    /**
     * Amazon's licence allows dropping the date part when the price was fetched
     * on the same day it is shown, so a same-day card stays compact.
     */
    public static function format_stamp($marketplace, $fetched_at) {
        $fetched_at = (int) $fetched_at;
        if ($fetched_at <= 0) {
            return '';
        }

        $tz        = wp_timezone();
        $fetched   = (new DateTimeImmutable('@' . $fetched_at))->setTimezone($tz);
        $now       = (new DateTimeImmutable('now'))->setTimezone($tz);
        $same_day  = $fetched->format('Y-m-d') === $now->format('Y-m-d');
        $time_only = get_option('time_format', 'H:i');
        $with_date = get_option('date_format', 'Y-m-d') . ' ' . $time_only;

        $stamp = $fetched->format($same_day ? $time_only : $with_date);

        return sprintf(
            /* translators: 1: Amazon site name, 2: date and time the price was retrieved, 3: timezone abbreviation */
            __('%1$s price: as of %2$s %3$s', 'myfeeds-affiliate-feed-manager'),
            MyFeeds_Amazon_Locales::site_name($marketplace),
            $stamp,
            $fetched->format('T')
        );
    }

    /**
     * The disclaimer Amazon's licence prescribes, once per page for each Amazon
     * site whose prices appeared on it, in the card's note.
     */
    public static function disclaimer_lines($lines) {
        $lines = is_array($lines) ? $lines : array();
        foreach (array_keys(self::$seen_marketplaces) as $marketplace) {
            $lines[] = self::disclaimer($marketplace);
        }
        return $lines;
    }

    /**
     * A page cache serves stored HTML without running PHP. Without this,
     * a card cached on Monday would still show Monday's price on Thursday
     * while its stamp claimed to be current — exactly what the refresh
     * rule of Amazon's licence forbids. The script re-reads price, stock and stamp
     * from the REST route on every real page view, and takes a price off
     * the page once nothing can vouch for it inside Amazon's limit.
     */
    public static function enqueue() {
        if (empty(self::$seen_ids)) {
            return;
        }

        $src = MYFEEDS_PLUGIN_URL . 'assets/amazon-live.js';
        $ver = file_exists(MYFEEDS_PLUGIN_DIR . 'assets/amazon-live.js')
            ? (string) filemtime(MYFEEDS_PLUGIN_DIR . 'assets/amazon-live.js')
            : '1';

        wp_enqueue_script(self::HANDLE, $src, array(), $ver, true);

        wp_localize_script(self::HANDLE, 'myfeedsAmazonLive', array(
            'endpoint' => rest_url(MyFeeds_Amazon_REST::NS . '/amazon/live'),
            'ids'      => array_keys(self::$seen_ids),
            'maxAge'   => MyFeeds_Amazon_Cache::ITEM_TTL,
        ));

        MyFeeds_Amazon_Card::add_styles(self::css());
    }

    private static function css() {
        return '.myfeeds-amazon-stamp{font-size:11px;line-height:1.35;opacity:.72;margin-top:6px}'
            // The stamp belongs to the price. The details are a flex column
            // sorted by the card design, and without an order of its own
            // the stamp slipped in between the brand and the title.
            . '.myfeeds-product-details .myfeeds-amazon-stamp{order:var(--myfeeds-order-price,2)}'
            . '.myfeeds-amazon-stamp.is-updating{opacity:.4}'
            . '.myfeeds-product-card.myfeeds-out-of-stock .myfeeds-product-image{opacity:.55}';
    }
}
