<?php
/**
 * Product Search XML into snapshot rows.
 *
 * The snapshot is JSON Lines, and the feed reader builds its header from
 * the keys of the first line - so every row carries every column, in the
 * same order, always. The column names are chosen to hit the Smart
 * Mapper's existing Rakuten schema (productname, linkurl, imageurl,
 * primarycategory, merchant_name, retail_price) without touching it.
 *
 * Prices: Rakuten sends `price` (retail) and `saleprice`. The schema reads
 * `price` before `saleprice`, so the retail figure would win and no card
 * would ever show a strike-through. The row therefore carries what the
 * customer pays as `price` and the higher retail figure, when there is
 * one, as `retail_price`.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Rakuten_Rows {

    /**
     * Every snapshot row has exactly these keys, in this order. The last
     * one, `carried`, is empty on a row this run fetched and a count on a
     * row carried over from the previous snapshot because this run did
     * not reach it (see MyFeeds_Source_Snapshot::carry_over()).
     */
    const COLUMNS = array(
        'id',
        'merchant_sku',
        'linkid',
        'mid',
        'merchant_name',
        'productname',
        'price',
        'retail_price',
        'currency',
        'primarycategory',
        'secondarycategory',
        'upccode',
        'description',
        'longdescription',
        'keywords',
        'linkurl',
        'imageurl',
        'createdon',
        'brand',
        'colour',
        'size',
        'carried',
    );

    const STRATEGY_SKU    = 'sku';
    const STRATEGY_LINKID = 'linkid';

    /**
     * A row without a SKU falls back to Rakuten's link id. The prefix
     * guarantees the fallback can never collide with a real SKU that
     * happens to be numeric.
     */
    const LINKID_PREFIX = 'l:';

    /**
     * Parse one Product Search response.
     *
     * @param string $xml      Raw response body.
     * @param string $strategy STRATEGY_SKU or STRATEGY_LINKID.
     * @return array{total_matches:int,total_pages:int,page_number:int,items:array<int,array<string,string>>,skipped:int}|WP_Error
     */
    public static function parse($xml, $strategy = self::STRATEGY_SKU) {
        $xml = (string) $xml;
        if (trim($xml) === '') {
            return new WP_Error('myfeeds_rakuten_bad_xml', __('Rakuten returned an empty response.', 'myfeeds-affiliate-feed-manager'));
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false || $doc->getName() !== 'result') {
            return new WP_Error(
                'myfeeds_rakuten_bad_xml',
                sprintf(
                    /* translators: %s: start of the response body */
                    __('Rakuten did not return a product list: %s', 'myfeeds-affiliate-feed-manager'),
                    self::snippet($xml)
                )
            );
        }

        $items   = array();
        $skipped = 0;

        foreach ($doc->item as $item) {
            $row = self::flatten($item, $strategy);
            if ($row['id'] === '') {
                $skipped++;
                continue;
            }
            $items[] = $row;
        }

        return array(
            'total_matches' => (int) $doc->TotalMatches,
            'total_pages'   => (int) $doc->TotalPages,
            'page_number'   => (int) $doc->PageNumber,
            'items'         => $items,
            'skipped'       => $skipped,
        );
    }

    /**
     * One <item> into one row with every column present.
     *
     * @param SimpleXMLElement $item
     * @param string           $strategy
     * @return array<string,string>
     */
    public static function flatten(SimpleXMLElement $item, $strategy = self::STRATEGY_SKU) {
        $sku    = self::text($item->sku);
        $linkid = self::text($item->linkid);

        $retail = self::money($item->price);
        $sale   = self::money($item->saleprice);

        // What the customer pays goes into `price`. A retail figure is
        // only worth keeping when it is actually higher.
        $paid  = ($sale !== null && $sale > 0) ? $sale : $retail;
        $strike = ($paid !== null && $retail !== null && $retail > $paid) ? $retail : null;

        $currency = self::attr($item->price, 'currency');
        if ($currency === '') {
            $currency = self::attr($item->saleprice, 'currency');
        }

        $row = array(
            'id'                => self::id_for($sku, $linkid, $strategy),
            'merchant_sku'      => $sku,
            'linkid'            => $linkid,
            'mid'               => self::text($item->mid),
            'merchant_name'     => self::text($item->merchantname),
            'productname'       => self::text($item->productname),
            'price'             => $paid === null ? '' : self::format_money($paid),
            'retail_price'      => $strike === null ? '' : self::format_money($strike),
            'currency'          => strtoupper($currency),
            'primarycategory'   => isset($item->category) ? self::text($item->category->primary) : '',
            'secondarycategory' => isset($item->category) ? self::text($item->category->secondary) : '',
            'upccode'           => self::text($item->upccode),
            'description'       => isset($item->description) ? self::text($item->description->short) : '',
            'longdescription'   => isset($item->description) ? self::text($item->description->long) : '',
            'keywords'          => self::text($item->keywords),
            'linkurl'           => self::text($item->linkurl),
            'imageurl'          => self::text($item->imageurl),
            'createdon'         => self::text($item->createdon),
            'carried'           => '',
        );

        $row = array_merge($row, self::derive_from_name($row['productname']));

        // Fixed order, whatever the code above did.
        $out = array();
        foreach (self::COLUMNS as $column) {
            $out[$column] = isset($row[$column]) ? $row[$column] : '';
        }

        return $out;
    }


    /**
     * Brand, colour and size out of the product name.
     *
     * Product Search returns fourteen fields and none of them is a brand,
     * a colour or a size - measured against the live API on 2026-09-09,
     * for two advertisers; `keywords` came back empty on every one of
     * 3,000 rows. Without this the shop has no brand filter on a Rakuten
     * feed at all, because the column behind it is empty.
     *
     * The names themselves are regular, in two shapes seen so far:
     *   Famous Footwear  "<brand> Women's <model> (<colour>) - Size 8.0 M"
     *   JD Sports        "<brand> Men's <model> in <colour> Size: 8.5"
     * Measured over 70,560 rows: brand 100 %, colour 99-100 %, size
     * 38 % (JD) and 100 % (Famous Footwear) - accessories carry no size.
     *
     * A value is only ever emitted when a pattern actually matched.
     * Empty is the honest answer and leaves the column mappable to
     * something else in the mapping editor.
     *
     * @param string $name
     * @return array{brand:string,colour:string,size:string}
     */
    public static function derive_from_name($name) {
        $name = trim((string) $name);
        $out  = array('brand' => '', 'colour' => '', 'size' => '');
        if ($name === '') {
            return $out;
        }

        // Colour: in brackets anywhere, else behind " in " up to an
        // optional size. The bracket form wins because a name can carry
        // the word "in" for other reasons.
        if (preg_match('/\(([^()]{2,60})\)/', $name, $m)) {
            $out['colour'] = trim($m[1]);
        } elseif (preg_match('/\s+in\s+([A-Z][^()]*?)(?:\s+Size:.*)?$/', $name, $m)) {
            $out['colour'] = trim($m[1]);
        }

        // One size grammar for the whole plugin: "Size: Large" and
        // "Size: 4XL Tall" are sizes as much as "- Size 11.0 W" is.
        if (class_exists('MyFeeds_Variants')) {
            $out['size'] = MyFeeds_Variants::size_from_name($name);
        } elseif (preg_match('/(?:Size:\s*|-\s*Size\s+)([0-9][0-9.\/]*\s*[A-Za-z0-9]{0,3})\s*$/', $name, $m)) {
            $out['size'] = trim($m[1]);
        }

        $out['brand'] = self::brand_from_name($name);

        return $out;
    }

    /**
     * The brand is what stands in front of who the product is for.
     *
     * Cutting at the gender or age marker is what gets the two-word
     * brands right - "Under Armour", "White Mountain", "Franco Sarto" -
     * where taking the first word alone would halve them. Without a
     * marker only the first word is safe, which is why "New Balance"
     * comes back as "New" on the 2 % of rows that have no marker: a
     * wrong guess at a longer name would be worse than a short one.
     *
     * @param string $name
     * @return string
     */
    public static function brand_from_name($name) {
        // The apostrophe comes as ASCII and as the typographic form, and
        // sometimes not at all ("Kids Shoes"). Written as an escape so no
        // quoting of this file can swallow it.
        $marker = '/\b(?:wom[ae]n|m[ae]n|kids?|boys?|girls?|juniors?|toddlers?|infants?|bab(?:y|ies)|youth|adults?|unisex)(?:\x27|\xe2\x80\x99)?s?\b/i';

        $brand = '';
        if (preg_match($marker, $name, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) {
            $brand = substr($name, 0, $m[0][1]);
        }

        if (trim($brand) === '') {
            $words = preg_split('/\s+/', $name);
            if (is_array($words) && count($words) >= 3) {
                $brand = $words[0];
            }
        }

        $brand = trim($brand, " \t\n\r\0\x0B\"'-,.");

        // "Nike Big Kids' ..." leaves "Nike Big" behind: the size band
        // belongs to the age group, not to the brand.
        $brand = preg_replace('/\s+(?:Big|Little|Grade|Grade School|Pre|Preschool|Pre-School|Baby)$/i', '', $brand);

        // A long prefix is a product name that happens to have no
        // marker, not a brand.
        $words = preg_split('/\s+/', trim((string) $brand));
        if (!is_array($words) || count($words) > 4) {
            return '';
        }

        return trim(implode(' ', $words));
    }

    /**
     * A row read back from the previous snapshot, brought up to the
     * current column set. Rows written before brand, colour and size
     * existed carry over without them, and a column that is empty for
     * half the file reads as "the advertiser does not have it".
     *
     * @param array $row
     * @return array
     */
    public static function backfill(array $row) {
        $needs = false;
        foreach (array('brand', 'colour', 'size') as $key) {
            if (!isset($row[$key])) {
                $needs = true;
            }
        }
        if ($needs) {
            $row = array_merge($row, self::derive_from_name(isset($row['productname']) ? $row['productname'] : ''));
        }

        $out = array();
        foreach (self::COLUMNS as $column) {
            $out[$column] = isset($row[$column]) ? $row[$column] : '';
        }

        return $out;
    }

    /**
     * The external id, chosen once per feed and never changed afterwards.
     *
     * @param string $sku
     * @param string $linkid
     * @param string $strategy
     * @return string Empty when neither identifier is usable.
     */
    public static function id_for($sku, $linkid, $strategy) {
        $sku    = trim((string) $sku);
        $linkid = trim((string) $linkid);

        if ($strategy === self::STRATEGY_LINKID) {
            if ($linkid !== '') {
                return $linkid;
            }
            return $sku;
        }

        if ($sku !== '') {
            return $sku;
        }

        return $linkid === '' ? '' : self::LINKID_PREFIX . $linkid;
    }

    /**
     * Measure a sample to decide the id strategy for a feed: merchants
     * that share one SKU across sizes and colours, or leave it empty,
     * lose rows on the unique key - Rakuten's own link id is the safer
     * key for them.
     *
     * @param array<int,array<string,string>> $rows Flattened rows.
     * @param float $duplicate_threshold Share of rows whose SKU repeats.
     * @param float $empty_threshold     Share of rows without a SKU.
     * @return array{strategy:string,rows:int,duplicate_share:float,empty_share:float}
     */
    public static function measure_strategy(array $rows, $duplicate_threshold = 0.05, $empty_threshold = 0.2) {
        $total = count($rows);
        if ($total === 0) {
            return array(
                'strategy'        => self::STRATEGY_SKU,
                'rows'            => 0,
                'duplicate_share' => 0.0,
                'empty_share'     => 0.0,
            );
        }

        $seen       = array();
        $duplicates = 0;
        $empty      = 0;

        foreach ($rows as $row) {
            $sku = isset($row['merchant_sku']) ? trim((string) $row['merchant_sku']) : '';
            if ($sku === '') {
                $empty++;
                continue;
            }
            if (isset($seen[$sku])) {
                $duplicates++;
                continue;
            }
            $seen[$sku] = true;
        }

        $duplicate_share = $duplicates / (float) $total;
        $empty_share     = $empty / (float) $total;

        $strategy = ($duplicate_share > $duplicate_threshold || $empty_share > $empty_threshold)
            ? self::STRATEGY_LINKID
            : self::STRATEGY_SKU;

        return array(
            'strategy'        => $strategy,
            'rows'            => $total,
            'duplicate_share' => $duplicate_share,
            'empty_share'     => $empty_share,
        );
    }

    /**
     * @param SimpleXMLElement|null $node
     * @return string Trimmed text with whitespace runs collapsed.
     */
    private static function text($node) {
        if ($node === null) {
            return '';
        }
        $value = trim((string) $node);
        if ($value === '') {
            return '';
        }
        return preg_replace('/\s+/u', ' ', $value);
    }

    /**
     * @param SimpleXMLElement|null $node
     * @param string                $name
     * @return string
     */
    private static function attr($node, $name) {
        if ($node === null || !isset($node[$name])) {
            return '';
        }
        return trim((string) $node[$name]);
    }

    /**
     * @param SimpleXMLElement|null $node
     * @return float|null
     */
    private static function money($node) {
        $value = self::text($node);
        if ($value === '') {
            return null;
        }
        $value = str_replace(array(',', ' '), '', $value);
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /**
     * @param float $value
     * @return string
     */
    private static function format_money($value) {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param string $body
     * @return string
     */
    private static function snippet($body) {
        $body = preg_replace('/\s+/', ' ', trim((string) $body));
        return function_exists('mb_substr') ? mb_substr($body, 0, 160) : substr($body, 0, 160);
    }
}
