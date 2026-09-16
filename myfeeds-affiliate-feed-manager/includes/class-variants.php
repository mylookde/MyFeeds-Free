<?php
/**
 * One garment, many rows.
 *
 * Feeds ship a row per size: "Hard Cargo Pants - S", "- M", "- L".
 * MyFeeds stores each as its own product, and a picker block points at
 * exactly one of them. When that one size sells out the card vanishes,
 * although the trousers are hanging in the shop in three other sizes -
 * and the card was never about a size. It shows the garment.
 *
 * Measured on mylook.com.de, 2026-09-05: 1,846 active rows were marked
 * out of stock while a sibling of the same product was in stock. Every
 * one of them was a product needlessly missing from a post.
 *
 * The feeds give us no parent id to group by, so the grouping is
 * derived, and deliberately in two steps that each have to agree:
 *
 *   1. The product name without its trailing size, matched as a prefix.
 *      product_name carries an index (idx_name_colour), so this is the
 *      cheap half and it narrows thousands of rows to a handful.
 *   2. An exact image match among those few. This is what keeps colours
 *      apart: "Hard Cargo Pants Black - L" shares the name prefix but
 *      not the photograph, and a card showing the sand-coloured pair
 *      must not link to the black one.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Variants {

    /**
     * The size grammar. One place, because five copies disagreed: the
     * search stripped " - S", the shop deleted every number, the detail
     * view stripped nothing, and each surface counted a different number
     * of products for the same garment.
     *
     * Three shapes, all measured on real feeds (mylook.com.de, 2026-09-15):
     *
     *   1. A "Size" marker: "… (Dark Brown Leather) - Size 11.0 W",
     *      "… - Size 7.0 2W", "… Size: Large", "… Size: 4XL Tall",
     *      "… Size: 11.0", "… Size 10". Behind the marker anything that
     *      reads as a size is one - a number with an optional width code,
     *      a letter size with an optional length word.
     *   2. A separator and a letter or region size: "- S", "-M", "(L)",
     *      "/ 38", "EU 42", "- One Size", "- X-Large", "S/M".
     *   3. A separator and a bare number: "- 52".
     *
     * A bare trailing number without a separator is NOT a size: "Nike
     * Air Force 1" is a whole product name.
     */
    const SIZE_LETTERS  = 'XXXS|XXS|XS|S|M|L|XL|XXL|XXXL|[2-6]XL|X-?LARGE|X-?SMALL|SMALL|MEDIUM|LARGE|ONE\s?SIZE|O/S|OS|(?:XS|S|M|L|XL)/(?:S|M|L|XL|XXL)';
    const SIZE_MODIFIER = '(?:\s+(?:TALL|SHORT|LONG|PETITE|REGULAR|PLUS|BIG))?';
    const SIZE_REGION   = '(?:EU|UK|US|DE|FR|IT)\s?\d{1,3}(?:[.,]\d)?|\d{2}\s?/\s?\d{2}';
    const SIZE_NUMBER   = '\d{1,3}(?:[.,]\d{1,2})?(?:\s*[-\x{2013}/]\s*\d{1,3}(?:[.,]\d{1,2})?)?(?:\s*\d?[A-Z]{1,2})?';

    /**
     * The pattern that finds a trailing size. The match is the whole
     * suffix including its separator, so base_name() can cut it and
     * size_from_name() can read it.
     *
     * Delimiter is # - a size list containing "O/S" ends a
     * slash-delimited pattern in the middle of itself, which
     * preg_replace reports only as "Internal error" and a NULL.
     *
     * @return string
     */
    public static function size_pattern() {
        // The size must stand on its own. Without this, the final "s"
        // of "Hard Cargo Pants" reads as size S and every product whose
        // name ends in s - Pants, Jeans, Shorts, Boots - loses a letter
        // and goes looking for siblings of a name that does not exist.
        $gap = '(?:[-\x{2013}\x{2014}/|\x{b7},]\s*|\s+|[\(\[]\s*)';
        $sep = '(?:[-\x{2013}\x{2014}/|\x{b7},]\s*|[\(\[]\s*)';

        $marker = '(?:[-\x{2013}\x{2014}/|\x{b7},]\s*)?\bSize:?\s*(?:(?:'
            . self::SIZE_LETTERS . ')' . self::SIZE_MODIFIER . '|' . self::SIZE_NUMBER . ')\s*[\)\]]?\s*$';

        $letters = $gap . '[\(\[]?\s*(?:' . self::SIZE_LETTERS . ')' . self::SIZE_MODIFIER . '\s*[\)\]]?\s*$';
        $region  = $gap . '[\(\[]?\s*(?:' . self::SIZE_REGION . ')\s*[\)\]]?\s*$';
        $number  = $sep . '\d{1,3}(?:[.,]\d)?\s*[\)\]]?\s*$';

        return '#(?:' . $marker . '|' . $letters . '|' . $region . '|' . $number . ')#iu';
    }

    /**
     * A product name with its trailing size removed.
     *
     * Returns the name unchanged when it ends in no size we recognise -
     * a product with no size suffix has no siblings to find, and
     * guessing would group unrelated products.
     *
     * @param string $name
     * @return string
     */
    public static function base_name($name) {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        $base = preg_replace(self::size_pattern(), '', $name, 1);
        $base = is_string($base) ? trim($base) : $name;

        // A strip that leaves almost nothing was not a size.
        if ($base === '' || mb_strlen($base) < 4) {
            return $name;
        }

        return $base;
    }

    /**
     * The size a name ends in, as a label: "11.0 W", "Large", "4XL Tall",
     * "S", "42". '' when the name carries none.
     *
     * @param string $name
     * @return string
     */
    public static function size_from_name($name) {
        $name = trim((string) $name);
        if ($name === '' || self::base_name($name) === $name) {
            return '';
        }
        if (preg_match(self::size_pattern(), $name, $m) !== 1) {
            return '';
        }
        $size = (string) $m[0];
        $size = preg_replace('#^[\s\-\x{2013}\x{2014}/|\x{b7},]+#u', '', $size);
        $size = preg_replace('#^Size:?\s*#iu', '', $size);
        $size = trim((string) $size, " \t()[]");
        return preg_replace('#\s+#u', ' ', $size);
    }

    /**
     * The one key that says "these rows are one product".
     *
     * Feed + image + name without size + colour. The image is what every
     * feed measured so far shares across the sizes of one colour, the
     * name keeps two products with one stock photo apart (gift cards),
     * the colour protects against a feed with one photo per colourway,
     * and the feed keeps one garment at two merchants as two products -
     * two links, two commissions.
     *
     * Without an image the brand stands in for it.
     *
     * @return string 32 hex characters.
     */
    public static function variant_key($feed_id, $name, $image_url, $brand = '', $colour = '') {
        $base   = mb_strtolower(self::base_name($name));
        $image  = mb_strtolower(trim((string) $image_url));
        $colour = mb_strtolower(trim((string) $colour));
        if ($image !== '') {
            return md5((int) $feed_id . '|' . $image . '|' . $base . '|' . $colour);
        }
        return md5((int) $feed_id . '|b:' . mb_strtolower(trim((string) $brand)) . '|' . $base . '|' . $colour);
    }

    /**
     * Does this product name look like one size of several?
     *
     * @param string $name
     * @return bool
     */
    public static function has_size_suffix($name) {
        return self::base_name($name) !== trim((string) $name);
    }

    /**
     * Another size of the same product that can actually be bought.
     *
     * @param array $product A product row as row_to_product() returns it.
     * @return array|null The sibling, or null when there is none.
     */
    public static function buyable_sibling($product) {
        if (!is_array($product) || !class_exists('MyFeeds_DB_Manager')) {
            return null;
        }

        $name  = (string) ($product['title'] ?? '');
        $image = (string) ($product['image_url'] ?? '');
        $feed  = (int) ($product['feed_id'] ?? 0);

        // Without a photograph there is nothing to confirm a match with,
        // and confirming is the whole point of the second step.
        if ($image === '' || !self::has_size_suffix($name)) {
            return null;
        }

        $base = self::base_name($name);
        if ($base === '') {
            return null;
        }

        global $wpdb;
        $table = MyFeeds_DB_Manager::table_name();
        $like  = $wpdb->esc_like($base) . '%';

        // The image match belongs in the query, not after it. Reading a
        // fixed number of prefix matches and sifting them here would
        // mean an unordered cap deciding what we get to see - and on a
        // base name with more rows than the cap, the one size that is
        // in stock could sit outside it and read as "nothing available".
        // MySQL uses idx_name_colour for the prefix either way.
        $where = 'product_name LIKE %s AND status = %s AND in_stock = 1'
            . ' AND image_url = %s AND external_id <> %s';
        $args  = array($like, 'active', $image, (string) ($product['id'] ?? ''));

        if ($feed > 0) {
            $where .= ' AND feed_id = %d';
            $args[] = $feed;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} LIMIT 1",
            $args
        ), ARRAY_A);

        return $row ? MyFeeds_DB_Manager::product_from_row($row) : null;
    }

    /**
     * Which of these external ids have a buyable sibling.
     *
     * The dashboard classifies hundreds of ids at once; asking per id
     * would be hundreds of queries. Two queries here: the rows, then
     * their candidates in one prefix search per distinct base name.
     *
     * @param array $external_ids
     * @return array<string,bool> Keyed by external id, true when buyable elsewhere.
     */
    public static function buyable_elsewhere($external_ids) {
        $out = array();
        $external_ids = array_values(array_unique(array_filter(array_map('strval', (array) $external_ids))));
        if (empty($external_ids) || !class_exists('MyFeeds_DB_Manager')) {
            return $out;
        }

        global $wpdb;
        $table = MyFeeds_DB_Manager::table_name();
        $ph = implode(',', array_fill(0, count($external_ids), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT external_id, product_name, image_url, feed_id
             FROM {$table} WHERE external_id IN ({$ph})",
            $external_ids
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            $product = array(
                'id'        => $row['external_id'],
                'title'     => $row['product_name'],
                'image_url' => $row['image_url'],
                'feed_id'   => $row['feed_id'],
            );
            if (self::buyable_sibling($product) !== null) {
                $out[(string) $row['external_id']] = true;
            }
        }

        return $out;
    }
}
