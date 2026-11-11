<?php
/**
 * Short-lived store for Amazon product data.
 *
 * This is deliberately not a product table. The Associates licence allows
 * caching for at most 24 hours and images not at all beyond their link, so
 * rows here expire and are deleted rather than kept and refreshed like the
 * rows in wp_myfeeds_products. Only the ASIN — which may be stored
 * indefinitely — lives outside this table, inside the Gutenberg block.
 *
 * Amazon's own Best Programming Practices are stricter than its licence
 * for offers (1 hour) and we follow the stricter number.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Cache {

    // 1.1.0: stock is read from every availability type Amazon names
    // (shape_api_item()); rows written under 1.0.0 called a product with
    // "only 2 left" out of stock.
    const SCHEMA_VERSION = '1.1.0';
    const OPTION_SCHEMA  = 'myfeeds_amazon_schema_version';
    const PURGE_HOOK     = 'myfeeds_amazon_purge_expired';

    /**
     * What Amazon said about ASINs it did not return: "gone" (the ASIN is
     * invalid - its product page answers 404) or "no_data" (it exists, but
     * the API does not describe it). Measured on mylook.com.de on
     * 2026-10-28: both InvalidParameterValue ASINs led to a 404, every
     * ItemNotAccessible one to a live product page.
     *
     * Kept no longer than item data (ITEM_TTL), like everything Amazon
     * says about a product, and not autoloaded: only renders that miss
     * the cache, the editor and the dashboard read it.
     */
    const OPTION_ANSWERS = 'myfeeds_amazon_answers';
    const ANSWERS_MAX    = 2000;
    const ANSWER_GONE    = 'gone';
    const ANSWER_NO_DATA = 'no_data';

    /**
     * Offers move constantly and Amazon caps their cache life at one hour.
     */
    const OFFER_TTL = HOUR_IN_SECONDS;

    /**
     * Amazon's ceiling for everything that is not an offer. Nothing in this
     * plugin may raise it.
     */
    const ITEM_TTL = DAY_IN_SECONDS;

    public static function offers_are_stale($fetched_at) {
        return (time() - (int) $fetched_at) >= self::OFFER_TTL;
    }

    public static function item_is_stale($fetched_at) {
        return (time() - (int) $fetched_at) >= self::ITEM_TTL;
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'myfeeds_amazon_cache';
    }

    public static function init() {
        add_action(self::PURGE_HOOK, array(__CLASS__, 'purge_expired'));

        // Only where the table exists (maybe_create_table() writes the
        // schema option): a site that never used Amazon's API gets no
        // hourly job for a table it never had.
        if (get_option(self::OPTION_SCHEMA) !== false && !wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::PURGE_HOOK);
        }
    }

    public static function maybe_create_table() {
        $installed = get_option(self::OPTION_SCHEMA);
        if ($installed === self::SCHEMA_VERSION) {
            return;
        }

        self::create_table();

        // Rows read under 1.0.0 with a listing and "not in stock" are
        // mostly "only a few left", lead time or pre-order - buyable, but
        // dimmed on the page and, read now, out of stock in the dashboard.
        // Which type it was is not stored, so they go and are fetched
        // again. Rows with no listing at all were right both ways.
        if ($installed !== false && version_compare((string) $installed, '1.1.0', '<')) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix, no input
            $wpdb->query('DELETE FROM ' . self::table_name() . ' WHERE in_stock = 0 AND price > 0');
        }

        // Autoloaded on purpose: this check runs on every request, and a
        // non-autoloaded option would cost one extra query per page view
        // on the front end for a value that is eleven bytes long.
        update_option(self::OPTION_SCHEMA, self::SCHEMA_VERSION, true);
    }

    public static function create_table() {
        global $wpdb;

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            asin VARCHAR(10) NOT NULL,
            marketplace VARCHAR(32) NOT NULL,
            title VARCHAR(500),
            brand VARCHAR(200),
            image_url TEXT,
            detail_url TEXT,
            price DECIMAL(12,2),
            old_price DECIMAL(12,2),
            currency VARCHAR(10),
            in_stock TINYINT(1) DEFAULT 1,
            merchant VARCHAR(200),
            parent_asin VARCHAR(10),
            features LONGTEXT,
            item_fetched_at INT UNSIGNED NOT NULL DEFAULT 0,
            offers_fetched_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (asin, marketplace),
            KEY idx_item_fetched (item_fetched_at),
            KEY idx_offers_fetched (offers_fetched_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * The purge job runs whether Amazon's API runs or not, and a site
     * that never used the API never had the table created.
     */
    public static function table_ready() {
        global $wpdb;
        $table = self::table_name();

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    // =====================================================================
    // Read
    // =====================================================================

    /**
     * @param string[] $asins
     * @return array<string,array> keyed by ASIN
     */
    public static function get_many(array $asins, $marketplace) {
        global $wpdb;

        $asins = array_values(array_unique(array_map('strtoupper', $asins)));
        if (empty($asins)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($asins), '%s'));
        $params       = array_merge($asins, array($marketplace));

        $rows = $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; the IN list is one %s per ASIN, every value goes through prepare()
            "SELECT * FROM " . self::table_name() . " WHERE asin IN ({$placeholders}) AND marketplace = %s",
            $params
        ), ARRAY_A);

        $out = array();
        foreach ((array) $rows as $row) {
            // A row past Amazon's ceiling is treated as absent even
            // before the purge job removes it.
            if (self::item_is_stale($row['item_fetched_at'])) {
                continue;
            }
            $out[$row['asin']] = $row;
        }

        return $out;
    }

    /**
     * Which of these ASINs need a call to Amazon before they can be shown.
     * Offers age out after an hour, everything else after a day.
     */
    public static function stale_asins(array $asins, $marketplace, $need_offers = true) {
        $cached = self::get_many($asins, $marketplace);
        // An ASIN Amazon has just declined is not asked again until the
        // answer expires. Before, every render that missed the page cache
        // asked again for each one and waited a second for the same no.
        $answered = self::answers($asins, $marketplace);
        $stale  = array();

        foreach (array_map('strtoupper', $asins) as $asin) {
            if (isset($answered[$asin])) {
                continue;
            }
            if (!isset($cached[$asin])) {
                $stale[] = $asin;
                continue;
            }

            if ($need_offers && self::offers_are_stale($cached[$asin]['offers_fetched_at'])) {
                $stale[] = $asin;
            }
        }

        return array_values(array_unique($stale));
    }

    // =====================================================================
    // Write
    // =====================================================================

    public static function store(array $item, $marketplace) {
        global $wpdb;

        if (empty($item['asin'])) {
            return false;
        }

        $now = time();

        // Amazon describes it again: whatever it said before no longer holds.
        self::forget_answers(array($item['asin']), $marketplace);

        return false !== $wpdb->replace(
            self::table_name(),
            array(
                'asin'              => strtoupper($item['asin']),
                'marketplace'       => $marketplace,
                'title'             => $item['title'],
                'brand'             => $item['brand'],
                'image_url'         => $item['image_url'],
                'detail_url'        => $item['detail_url'],
                'price'             => $item['price'],
                'old_price'         => $item['old_price'],
                'currency'          => $item['currency'],
                'in_stock'          => (int) $item['in_stock'],
                'merchant'          => $item['merchant'],
                'parent_asin'       => $item['parent_asin'],
                'features'          => wp_json_encode($item['features']),
                'item_fetched_at'   => $now,
                'offers_fetched_at' => $now,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%d', '%s', '%s', '%s', '%d', '%d')
        );
    }

    /**
     * Deleting rather than keeping-and-ignoring is the point: Amazon's licence
     * limits how long the data may exist on our side, not just how long we
     * may show it.
     */
    public static function purge_expired() {
        global $wpdb;

        self::prune_answers();

        if (!self::table_ready()) {
            return 0;
        }

        $cutoff = time() - self::ITEM_TTL;

        return (int) $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix, the cutoff goes through prepare()
            'DELETE FROM ' . self::table_name() . ' WHERE item_fetched_at < %d',
            $cutoff
        ));
    }

    public static function purge_all() {
        global $wpdb;

        delete_option(self::OPTION_ANSWERS);

        if (!self::table_ready()) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix, no input
        return (int) $wpdb->query('DELETE FROM ' . self::table_name());
    }

    // =====================================================================
    // What Amazon said about ASINs it did not return
    // =====================================================================

    /**
     * @param array<string,string> $answers ASIN => ANSWER_GONE|ANSWER_NO_DATA
     * @return bool whether anything new was written
     */
    public static function remember_answers(array $answers, $marketplace) {
        $all = self::load_answers();
        $now = time();
        $new = false;

        foreach ($answers as $asin => $state) {
            if (!in_array($state, array(self::ANSWER_GONE, self::ANSWER_NO_DATA), true)) {
                continue;
            }
            $key = $marketplace . '|' . strtoupper((string) $asin);
            // An expired answer repeated is new again: kept as it was, it
            // would stay expired and the product would come back on the
            // page the day after Amazon first called it invalid.
            if (!isset($all[$key]) || $all[$key]['s'] !== $state
                || self::item_is_stale($all[$key]['t'])) {
                $new = true;
            }
            $all[$key] = array('s' => $state, 't' => $now);
        }

        if (!$new) {
            return false;
        }

        self::save_answers($all);
        return true;
    }

    /**
     * The answers still in force for these ASINs, without asking Amazon.
     *
     * @return array<string,string> ASIN => ANSWER_GONE|ANSWER_NO_DATA
     */
    public static function answers(array $asins, $marketplace) {
        $all = self::load_answers();
        if (empty($all)) {
            return array();
        }

        $out = array();
        foreach ($asins as $asin) {
            $asin = strtoupper((string) $asin);
            $key  = $marketplace . '|' . $asin;
            if (isset($all[$key]) && !self::item_is_stale($all[$key]['t'])) {
                $out[$asin] = $all[$key]['s'];
            }
        }

        return $out;
    }

    /**
     * Rows for products Amazon no longer describes. A row cached before
     * Amazon called the ASIN invalid would otherwise keep the card on the
     * page with its old price for the rest of its day - and it would not
     * be refreshed, because an answered ASIN is not asked again.
     */
    public static function forget_rows(array $asins, $marketplace) {
        global $wpdb;

        $asins = array_values(array_unique(array_map('strtoupper', $asins)));
        if (empty($asins)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($asins), '%s'));

        return (int) $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; the IN list is one %s per ASIN, every value goes through prepare()
            'DELETE FROM ' . self::table_name() . " WHERE marketplace = %s AND asin IN ({$placeholders})",
            array_merge(array($marketplace), $asins)
        ));
    }

    public static function forget_answers(array $asins, $marketplace) {
        $all = self::load_answers();
        if (empty($all)) {
            return;
        }

        $changed = false;
        foreach ($asins as $asin) {
            $key = $marketplace . '|' . strtoupper((string) $asin);
            if (isset($all[$key])) {
                unset($all[$key]);
                $changed = true;
            }
        }

        if ($changed) {
            self::save_answers($all);
        }
    }

    public static function prune_answers() {
        $all = self::load_answers();
        if (empty($all)) {
            return;
        }

        $kept = self::without_expired($all);
        if (count($kept) !== count($all)) {
            self::save_answers($kept);
        }
    }

    private static function load_answers() {
        $all = get_option(self::OPTION_ANSWERS, array());
        if (!is_array($all)) {
            return array();
        }

        $clean = array();
        foreach ($all as $key => $entry) {
            if (is_array($entry) && isset($entry['s'], $entry['t'])) {
                $clean[(string) $key] = array('s' => (string) $entry['s'], 't' => (int) $entry['t']);
            }
        }

        return $clean;
    }

    private static function without_expired(array $all) {
        return array_filter($all, static function ($entry) {
            return !self::item_is_stale($entry['t']);
        });
    }

    /**
     * Expired answers go, and past ANSWERS_MAX the oldest: a site cannot
     * place that many Amazon products, so only a runaway caller fills it.
     */
    private static function save_answers(array $all) {
        $all = self::without_expired($all);

        if (count($all) > self::ANSWERS_MAX) {
            uasort($all, static function ($a, $b) {
                return $b['t'] <=> $a['t'];
            });
            $all = array_slice($all, 0, self::ANSWERS_MAX, true);
        }

        if (empty($all)) {
            delete_option(self::OPTION_ANSWERS);
            return;
        }

        update_option(self::OPTION_ANSWERS, $all, false);
    }

    public static function count_rows() {
        global $wpdb;

        if (!self::table_ready()) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix, no input
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table_name());
    }

    // =====================================================================
    // Shaping
    // =====================================================================

    /**
     * Turn one Creators API item into the array shape the rest of MyFeeds
     * speaks. Response fields are lowerCamelCase since Creators API; the
     * PascalCase names from PA-API do not exist any more.
     */
    public static function shape_api_item(array $item) {
        $asin = isset($item['asin']) ? strtoupper($item['asin']) : '';
        if ($asin === '') {
            return null;
        }

        $info  = isset($item['itemInfo']) && is_array($item['itemInfo']) ? $item['itemInfo'] : array();
        $title = isset($info['title']['displayValue']) ? (string) $info['title']['displayValue'] : '';
        $brand = '';

        if (isset($info['byLineInfo']['brand']['displayValue'])) {
            $brand = (string) $info['byLineInfo']['brand']['displayValue'];
        } elseif (isset($info['byLineInfo']['manufacturer']['displayValue'])) {
            $brand = (string) $info['byLineInfo']['manufacturer']['displayValue'];
        }

        $image = '';
        foreach (array('large', 'medium', 'small') as $size) {
            if (!empty($item['images']['primary'][$size]['url'])) {
                $image = (string) $item['images']['primary'][$size]['url'];
                break;
            }
        }

        $features = array();
        if (!empty($info['features']['displayValues']) && is_array($info['features']['displayValues'])) {
            $features = array_slice(array_map('strval', $info['features']['displayValues']), 0, 5);
        }

        $listing = self::pick_listing($item);

        $price     = 0.0;
        $old_price = 0.0;
        $currency  = '';
        $merchant  = '';
        $in_stock  = 0;

        if ($listing) {
            if (isset($listing['price']['money']['amount'])) {
                $price = (float) $listing['price']['money']['amount'];
            }
            if (isset($listing['price']['money']['currency'])) {
                $currency = (string) $listing['price']['money']['currency'];
            }
            if (isset($listing['price']['savingBasis']['money']['amount'])) {
                $old_price = (float) $listing['price']['savingBasis']['money']['amount'];
            }
            if (isset($listing['merchantInfo']['name'])) {
                $merchant = (string) $listing['merchantInfo']['name'];
            }
            $in_stock = self::buyable_availability(
                isset($listing['availability']['type']) ? (string) $listing['availability']['type'] : ''
            ) ? 1 : 0;
        }

        return array(
            'asin'        => $asin,
            'title'       => $title,
            'brand'       => $brand,
            'image_url'   => $image,
            'detail_url'  => isset($item['detailPageURL']) ? (string) $item['detailPageURL'] : '',
            'price'       => $price,
            'old_price'   => $old_price > $price ? $old_price : 0.0,
            'currency'    => $currency,
            'in_stock'    => $in_stock,
            'merchant'    => $merchant,
            'parent_asin' => isset($item['parentASIN']) ? (string) $item['parentASIN'] : '',
            'features'    => $features,
        );
    }

    /**
     * Whether a listing with this availability type can be ordered.
     *
     * OffersV2 names eight types. Only OUT_OF_STOCK and UNAVAILABLE say a
     * shopper cannot buy it now; "only 3 left" (IN_STOCK_SCARCE), a lead
     * time, a pre-order and a later ship date are all orders Amazon takes.
     * A missing or UNKNOWN type claims nothing, so it is not out of stock.
     * Until 2026-10-28 only IN_STOCK counted: 48 of the 354 products in
     * mylook's cache had a price and a seller and were dimmed as sold out.
     *
     * No listing at all is decided by the caller: Amazon reports an item
     * without a featured offer as out of stock.
     */
    public static function buyable_availability($type) {
        return !in_array(strtoupper((string) $type), array('OUT_OF_STOCK', 'UNAVAILABLE'), true);
    }

    /**
     * The buy box listing is the one a shopper actually gets, so it wins
     * over whatever happens to come first in the array.
     */
    private static function pick_listing(array $item) {
        if (empty($item['offersV2']['listings']) || !is_array($item['offersV2']['listings'])) {
            return null;
        }

        $listings = $item['offersV2']['listings'];

        foreach ($listings as $listing) {
            if (!empty($listing['isBuyBoxWinner'])) {
                return $listing;
            }
        }

        return $listings[0];
    }

    /**
     * Cache row to the product array the card renderer and picker consume.
     */
    public static function row_to_product(array $row) {
        $features = json_decode((string) $row['features'], true);

        return array(
            'id'              => MyFeeds_Amazon_Compliance::make_id($row['marketplace'], $row['asin']),
            'asin'            => $row['asin'],
            'marketplace'     => $row['marketplace'],
            'title'           => (string) $row['title'],
            'price'           => (float) $row['price'],
            'old_price'       => (float) $row['old_price'],
            'currency'        => (string) $row['currency'],
            'image_url'       => (string) $row['image_url'],
            'affiliate_link'  => (string) $row['detail_url'],
            'brand'           => (string) $row['brand'],
            'category'        => '',
            'colour'          => '',
            'in_stock'        => (int) $row['in_stock'],
            'status'          => 'active',
            // The shop a reader buys from is Amazon, plainly - "Amazon",
            // not the market's address (Marlon, 28.10.). The listing's
            // seller ("Pure Costumes", "Woot") stood in the card's shop
            // line and read like another store.
            'merchant'        => 'Amazon',
            'feed_id'         => 0,
            'source'          => 'amazon',
            'features'        => is_array($features) ? $features : array(),
            'price_fetched_at' => (int) $row['offers_fetched_at'],
        );
    }

    /**
     * Same shape, straight from a live API item without a cache round trip.
     */
    public static function api_item_to_product(array $item, $marketplace) {
        $shaped = self::shape_api_item($item);
        if (!$shaped) {
            return null;
        }

        return self::row_to_product(array(
            'asin'              => $shaped['asin'],
            'marketplace'       => $marketplace,
            'title'             => $shaped['title'],
            'brand'             => $shaped['brand'],
            'image_url'         => $shaped['image_url'],
            'detail_url'        => $shaped['detail_url'],
            'price'             => $shaped['price'],
            'old_price'         => $shaped['old_price'],
            'currency'          => $shaped['currency'],
            'in_stock'          => $shaped['in_stock'],
            'merchant'          => $shaped['merchant'],
            'parent_asin'       => $shaped['parent_asin'],
            'features'          => wp_json_encode($shaped['features']),
            'offers_fetched_at' => time(),
        ));
    }
}
