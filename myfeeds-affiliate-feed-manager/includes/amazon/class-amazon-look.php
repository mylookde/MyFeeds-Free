<?php
/**
 * The Look and Amazon's API: what the editor is told, and the Amazon
 * points of a Look on the page with Amazon's data - its title, its own
 * link, its picture, and its price with the stamp Amazon's licence asks
 * for.
 *
 * The Look itself (class-look-block.php) asks through filters; this class
 * answers them. Where Amazon's API does not run, the Look draws an Amazon
 * point from its author's words and the link built from its ASIN.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Look {

    public static function init() {
        // After the shared links answer, which it builds on.
        add_filter('myfeeds_look_editor_amazon', array(__CLASS__, 'editor_config'), 20);
        add_filter('myfeeds_look_amazon_items', array(__CLASS__, 'items'), 10, 2);
    }

    /**
     * What a point may lead to, told to the editor as it is. The links
     * part is MyFeeds_Amazon_Modules::look_config() (allowed, links,
     * marketplace, marketplaces, settingsUrl); where Amazon's API runs this
     * adds the API's, and elsewhere nothing:
     *   api           Amazon's API runs on this site.
     *   apiUsable     ... and Amazon answers: the row looks a set point up
     *                 (myfeeds.look.amazonDetails) and the search window
     *                 offers Amazon.
     *   nameFromLink  false while Amazon names a set point itself.
     *   texts         the shared editor's words about an Amazon point, for
     *                 the state the site is in (look-block.js).
     *
     * Without the API the name is simply the author's to give, and
     * credentials saved earlier stay stored without making the lookup
     * route appear.
     */
    public static function editor_config($amazon) {
        if (!class_exists('MyFeeds_Amazon_Modules') || !MyFeeds_Amazon_Modules::links_active()) {
            return $amazon;
        }
        // Where the API does not run, the links' answer is the whole answer.
        if (!MyFeeds_Amazon_Api::active()) {
            return $amazon;
        }
        $state  = MyFeeds_Amazon_Credentials::get_status()['state'];
        $usable = MyFeeds_Amazon_Credentials::is_configured()
            && !in_array($state, array(MyFeeds_Amazon_Credentials::STATE_NOT_ELIGIBLE, MyFeeds_Amazon_Credentials::STATE_INVALID), true);

        $texts = array(
            'prices'          => $usable ? '' : __('Amazon prices appear once Amazon unlocks its API for you.', 'myfeeds-affiliate-feed-manager'),
            'pictures'        => $usable ? '' : __('Amazon pictures appear once Amazon unlocks its API for you.', 'myfeeds-affiliate-feed-manager'),
            'namePlaceholder' => __('Product name (needed without Amazon\'s API)', 'myfeeds-affiliate-feed-manager'),
            'nameNeeded'      => __('Amazon has not unlocked its API for you yet, so it cannot say what this product is called. Give it a name; the point stays hidden on the page until it has one.', 'myfeeds-affiliate-feed-manager'),
        );

        return array_merge(MyFeeds_Amazon_Modules::look_config($amazon), array(
            'api'          => true,
            'apiUsable'    => $usable,
            'nameFromLink' => !$usable,
            'texts'        => $texts,
        ));
    }

    /**
     * The Amazon points of one Look with Amazon's data, for the shared
     * Look (myfeeds_look_amazon_items): Amazon's title - the author's own
     * wins when they typed one - and Amazon's own link, unaltered (Amazon's
     * licence forbids changing it), Amazon's picture as Amazon serves it,
     * and its price with the time it was fetched. The price carries the
     * stamp, and the page's script re-reads price and time on every view:
     * the two rules of Amazon's licence, as on the Amazon card. No stamp, no price.
     * An ASIN Amazon called invalid is false (rule 17); one Amazon gave no
     * data for is left to the shared Look, which draws it from its
     * author's words.
     *
     * @param array $items  What earlier filters made.
     * @param array $points The Look's Amazon points: id, brand, name.
     * @return array<string,array|false>
     */
    public static function items($items, $points) {
        $items = is_array($items) ? $items : array();
        if (!is_array($points) || empty($points) || !class_exists('MyFeeds_Look_Block')) {
            return $items;
        }
        $ids = array();
        foreach ($points as $p) {
            if (is_array($p) && isset($p['id'])) {
                $ids[] = (string) $p['id'];
            }
        }
        $products = self::products(array(), $ids);

        foreach ($points as $p) {
            if (!is_array($p) || !isset($p['id']) || isset($items[(string) $p['id']])) {
                continue;
            }
            $item = self::item($p, $products[(string) $p['id']] ?? null);
            if ($item !== null) {
                $items[(string) $p['id']] = $item;
            }
        }
        return $items;
    }

    /**
     * One Amazon point with Amazon's data, as the Look draws it: false
     * when Amazon called the ASIN invalid, null where Amazon gave nothing
     * to draw it with (the shared Look takes the author's words then).
     *
     * @param array            $p       The point: id, brand, name.
     * @param array|false|null $product Amazon's data for it.
     * @return array|false|null
     */
    public static function item(array $p, $product) {
        if ($product === false) {
            return false;
        }
        if (!is_array($product) || empty($product['affiliate_link']) || !class_exists('MyFeeds_Look_Block')) {
            return null;
        }
        $id    = (string) $p['id'];
        $brand = (string) ($p['brand'] ?? '');
        $own   = (string) ($p['name'] ?? '');
        $name  = $own !== '' ? $own : (string) ($product['title'] ?? '');
        if ($name === '') {
            return null;
        }
        // Shown, never kept: the click carries the author's words only.
        $item = MyFeeds_Look_Block::point_item($id, (string) ($product['brand'] ?? ''), $name, (string) $product['affiliate_link'], 0, '',
            __('View on Amazon', 'myfeeds-affiliate-feed-manager'), array('source' => 'amazon', 'amz_manual' => array('brand' => $brand, 'name' => $own)));
        $item['image']       = (string) ($product['image_url'] ?? '');
        $item['image_as_is'] = true;
        // Asked only where the Look shows prices: a stamp brings the page
        // the price script and the disclaimer, which a Look without prices
        // has no use for.
        $item['price_html']  = function () use ($id, $product) {
            return self::price_html($id, $product);
        };
        return $item;
    }

    /**
     * Amazon's price as the Look shows it, or '' for none: the price, the
     * struck one - always there, hidden when empty, so a refresh that
     * brings one has a place to put it - and the stamp after both.
     */
    private static function price_html($id, array $product) {
        $price = (float) ($product['price'] ?? 0);
        if ($price <= 0 || !class_exists('MyFeeds_Amazon_Stamp')) {
            return '';
        }
        $stamp = MyFeeds_Amazon_Stamp::stamp_html($id, (int) ($product['price_fetched_at'] ?? 0), 'span', 'myfeeds-look__stamp');
        if ($stamp === '') {
            return '';
        }
        $old      = (float) ($product['old_price'] ?? 0);
        $currency = (string) ($product['currency'] ?? '') ?: 'EUR';
        $was      = $old > $price ? myfeeds_format_price($old, $currency) : '';
        return '<span class="myfeeds-look__price"><span class="myfeeds-look__now">' . esc_html(myfeeds_format_price($price, $currency)) . '</span>'
            . ' <s class="myfeeds-look__was"' . ($was === '' ? ' hidden' : '') . '>' . esc_html($was) . '</s>'
            . '</span>'
            . $stamp;
    }

    /**
     * Amazon's data for the ASINs of one Look, in one call, never one per
     * point, and never as a live lookup a page render waits for when
     * Amazon has just said no ('missing'). Where Amazon's API does not run
     * this comes back empty without asking anyone (MyFeeds_Amazon_Products
     * decides). An ASIN Amazon called invalid - its page answers 404 - is
     * false, so the Look leaves the point out (rule 17).
     *
     * @param array    $products What earlier filters found.
     * @param string[] $ids
     * @return array<string,array|false>
     */
    public static function products($products, $ids) {
        $products = is_array($products) ? $products : array();
        if (!class_exists('MyFeeds_Amazon_Products') || !is_array($ids) || empty($ids)) {
            return $products;
        }
        $products += MyFeeds_Amazon_Products::get_by_ids($ids, 'missing');
        foreach ($ids as $id) {
            $id = (string) $id;
            if ((!isset($products[$id]) || !is_array($products[$id]) || empty($products[$id]['affiliate_link']))
                && MyFeeds_Amazon_Products::is_gone($id)) {
                $products[$id] = false;
            }
        }
        return $products;
    }
}
