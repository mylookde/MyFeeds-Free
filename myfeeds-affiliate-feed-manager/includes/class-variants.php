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

        // Delimiter is # - a size list containing "O/S" ends a
        // slash-delimited pattern in the middle of itself, which
        // preg_replace reports only as "Internal error" and a NULL.
        $letters = 'XXXS|XXS|XS|S|M|L|XL|XXL|XXXL|2XL|3XL|4XL|5XL|ONE\s?SIZE|O/S|OS';

        // A bare trailing number is NOT treated as a size. "Nike Air
        // Force 1" and "Jordan Air 4" end in one and are whole product
        // names; stripping it would look for siblings of "Nike Air
        // Force". A number counts as a size when something marks it as
        // one - a separator, a bracket, or a region prefix.
        $numbers = '(?:EU|UK|US|DE|FR|IT)\s?\\d{1,3}(?:[.,]\\d)?|\\d{2}\s?/\s?\\d{2}';

        // The size must stand on its own. Without this, the final "s"
        // of "Hard Cargo Pants" reads as size S and every product whose
        // name ends in s - Pants, Jeans, Shorts, Boots - loses a letter
        // and goes looking for siblings of a name that does not exist.
        $gap = '(?:[-–—/|·,]\\s*|\\s+|[\\(\\[]\\s*)';

        $pattern = '#' . $gap . '[\\(\\[]?\\s*(?:' . $letters . '|' . $numbers . ')\\s*[\\)\\]]?\\s*$'
            . '|(?:[-–—/|·,]\\s*|[\\(\\[]\\s*)\\d{1,3}(?:[.,]\\d)?\\s*[\\)\\]]?\\s*$#iu';

        $base = preg_replace($pattern, '', $name);
        $base = is_string($base) ? trim($base) : $name;

        // A strip that leaves almost nothing was not a size.
        if ($base === '' || mb_strlen($base) < 4) {
            return $name;
        }

        return $base;
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
