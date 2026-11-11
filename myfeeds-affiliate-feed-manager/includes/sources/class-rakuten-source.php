<?php
/**
 * What a Rakuten feed asks the API for.
 *
 * A feed is one advertiser (mid) plus a scope: everything the advertiser
 * lists, or only what matches a set of keywords or categories. This
 * class turns that into Product Search queries; the fetcher runs them.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Rakuten_Source {

    const MODE_ALL        = 'all';
    const MODE_KEYWORDS   = 'keywords';
    const MODE_CATEGORIES = 'categories';

    /** Search terms per scope; each term is at least one API query. */
    const MAX_TERMS = 20;

    /** Languages the Product Search guide lists for its language filter. */
    const LANGUAGES = array('en_US', 'fr_FR', 'de_DE', 'pt_BR');

    /**
     * Characters the Product Search guide says a search term may not
     * contain. Replaced by a space rather than rejected: a user who pastes
     * "T-Shirts & Tops" wants the products, not a validation error.
     */
    const FORBIDDEN = '&=?{}\\()[]-;~|$!><*%';

    /**
     * Bring a scope from the form or the stored feed into one shape.
     *
     * @param mixed $input {mode, terms (array or comma list), language}
     * @return array{mode:string,terms:array<int,string>,language:string}
     */
    public static function normalize_scope($input) {
        $input = is_array($input) ? $input : array();

        $mode = isset($input['mode']) ? (string) $input['mode'] : self::MODE_ALL;
        if (!in_array($mode, array(self::MODE_ALL, self::MODE_KEYWORDS, self::MODE_CATEGORIES), true)) {
            $mode = self::MODE_ALL;
        }

        $terms = array();
        if ($mode !== self::MODE_ALL && isset($input['terms'])) {
            $raw = is_array($input['terms']) ? $input['terms'] : explode(',', (string) $input['terms']);
            foreach ($raw as $term) {
                $term = self::clean_term($term);
                if ($term === '' || in_array($term, $terms, true)) {
                    continue;
                }
                $terms[] = $term;
                if (count($terms) >= self::MAX_TERMS) {
                    break;
                }
            }
        }

        // A scope that names no term is "everything".
        if ($mode !== self::MODE_ALL && empty($terms)) {
            $mode = self::MODE_ALL;
        }

        $language = isset($input['language']) ? (string) $input['language'] : '';
        if (!in_array($language, self::LANGUAGES, true)) {
            $language = '';
        }

        return array(
            'mode'     => $mode,
            'terms'    => $terms,
            'language' => $language,
        );
    }

    /**
     * @param string $term
     * @return string
     */
    public static function clean_term($term) {
        $term = (string) $term;
        $term = str_replace(str_split(self::FORBIDDEN), ' ', $term);
        $term = preg_replace('/\s+/u', ' ', $term);
        $term = trim($term);

        return function_exists('mb_substr') ? mb_substr($term, 0, 80) : substr($term, 0, 80);
    }

    /**
     * The queries a scope stands for, before any cap handling: one for
     * "everything", one per term otherwise. Each is a parameter set for
     * MyFeeds_Rakuten_Client::search_products().
     *
     * @param int   $mid
     * @param array $scope Normalised scope.
     * @return array<int,array<string,string>>
     */
    public static function scope_queries($mid, array $scope, array $extra_terms = array()) {
        $base = array('mid' => (string) (int) $mid);
        if ($scope['language'] !== '') {
            $base['language'] = $scope['language'];
        }

        $queries = array();
        $keys    = array();

        if ($scope['mode'] === self::MODE_ALL || empty($scope['terms'])) {
            $queries[] = $base;
            $keys[self::query_key($base)] = 1;
        } else {
            $param = $scope['mode'] === self::MODE_CATEGORIES ? 'cat' : 'keyword';
            foreach ($scope['terms'] as $term) {
                $query = $base;
                $query[$param] = $term;
                $queries[] = $query;
                $keys[self::query_key($query)] = 1;
            }
        }

        // Terms discovered from picker searches ride along as keyword
        // queries, so what a search once pulled in stays current.
        foreach ($extra_terms as $term) {
            $term = self::clean_term($term);
            if ($term === '') {
                continue;
            }
            $query = $base;
            $query['keyword'] = $term;
            $key = self::query_key($query);
            if (isset($keys[$key])) {
                continue;
            }
            $keys[$key] = 1;
            $queries[] = $query;
        }

        return $queries;
    }

    /**
     * The single query a discovered term stands for.
     *
     * @param int    $mid
     * @param array  $scope Normalised scope (for the language).
     * @param string $term
     * @return array<string,string>
     */
    public static function term_query($mid, array $scope, $term) {
        $query = array('mid' => (string) (int) $mid);
        if ($scope['language'] !== '') {
            $query['language'] = $scope['language'];
        }
        $query['keyword'] = self::clean_term($term);

        return $query;
    }

    // =====================================================================
    // Getting past the result cap
    // =====================================================================

    /**
     * Product Search never returns more than 5,000 results for one query,
     * whatever the page number. A query whose TotalMatches is above that
     * is re-asked in more views: sorted by price in both directions, then
     * by name in both directions, then once per primary category seen so
     * far, each of those in both price directions again. Every view is
     * capped too, so this is coverage, not completeness - the fetcher
     * measures how much it reached and the table shows it.
     *
     * Duplicate rows across views are harmless: the importer upserts on
     * (external_id, feed_id), so the same product twice is one product.
     *
     * @param array $query      The query that hit the cap (no sort yet).
     * @param array $categories Primary categories seen in its rows.
     * @return array<int,array<string,string>> Further queries to run.
     */
    public static function expansions(array $query, array $categories) {
        if (isset($query['sort'])) {
            // A sorted view is already an expansion; it does not expand
            // again or the query plan would grow without end.
            return array();
        }

        $out = array();
        foreach (array('retailprice', 'productname') as $sort) {
            foreach (array('asc', 'dsc') as $direction) {
                $view = $query;
                $view['sort']     = $sort;
                $view['sorttype'] = $direction;
                $out[] = $view;
            }
        }

        if (isset($query['cat'])) {
            // Already a category slice: the sorted views are all that is
            // left to try.
            return $out;
        }

        foreach ($categories as $category) {
            $category = self::clean_term($category);
            if ($category === '') {
                continue;
            }
            foreach (array('asc', 'dsc') as $direction) {
                $view = $query;
                $view['cat']      = $category;
                $view['sort']     = 'retailprice';
                $view['sorttype'] = $direction;
                $out[] = $view;
            }
        }

        return $out;
    }

    /**
     * One string per distinct query, so a query plan never runs the same
     * view twice.
     *
     * @param array $query
     * @return string
     */
    public static function query_key(array $query) {
        ksort($query);
        return md5(wp_json_encode($query));
    }

    /**
     * How many pages a query is worth asking for.
     *
     * @param int $total_pages What the envelope said.
     * @param int $page_size   Rows per page.
     * @return int
     */
    public static function pages_to_fetch($total_pages, $page_size) {
        $page_size = max(1, (int) $page_size);
        $cap_pages = (int) ceil(MyFeeds_Rakuten_Client::RESULT_CAP / $page_size);

        return max(0, min((int) $total_pages, $cap_pages));
    }

    /**
     * @param int $seen  Distinct ids fetched.
     * @param int $total TotalMatches of the base query (or the sum over terms).
     * @return float 0..1
     */
    public static function coverage($seen, $total) {
        $seen  = max(0, (int) $seen);
        $total = max(0, (int) $total);

        if ($total === 0) {
            return $seen > 0 ? 1.0 : 0.0;
        }

        return min(1.0, $seen / (float) $total);
    }

    // =====================================================================
    // Categories an advertiser uses
    // =====================================================================

    /**
     * Category names in a set of rows: the primary category, and every
     * step of the secondary path ("Dresses~~Dress" is two). Rakuten's
     * `cat` filter matches either, so each is a usable scope term.
     *
     * @param array<int,array<string,string>> $rows
     * @return array<string,int> name => rows that carry it
     */
    public static function categories_in_rows(array $rows) {
        $out = array();
        foreach ($rows as $row) {
            $names = array();
            if (!empty($row['primarycategory'])) {
                $names[] = (string) $row['primarycategory'];
            }
            if (!empty($row['secondarycategory'])) {
                foreach (explode('~~', (string) $row['secondarycategory']) as $part) {
                    $names[] = $part;
                }
            }
            $seen = array();
            foreach ($names as $name) {
                $name = self::clean_term($name);
                if ($name === '' || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $out[$name] = isset($out[$name]) ? $out[$name] + 1 : 1;
            }
        }
        arsort($out);

        return $out;
    }

    /**
     * Categories for an advertiser: what the feed holds, and what Rakuten
     * shows in a sample - always both, merged.
     *
     * The feed alone is circular: a feed scoped to "Shoes" holds shoes,
     * so its category list is shoes, and the one category a person opens
     * this dialog to ADD is the one it cannot show. Seen on 2026-09-08:
     * JD Sports listed "Clothing (47)" from a keyword pull, while a sample
     * of the whole advertiser had clothing in 192 of 500 products. The
     * sample alone is thin in the other direction - five pages of one
     * advertiser are mostly whatever sorts first.
     *
     * @param int $mid
     * @return array{source:string,categories:array<int,array{name:string,rows:int,sampled:int}>,sampled_rows:int}|WP_Error
     */
    public static function categories_for($mid) {
        $mid = (int) $mid;

        $feed_counts = self::feed_category_counts($mid);
        $sample      = self::sample_category_counts($mid);

        if (is_wp_error($sample)) {
            if (empty($feed_counts)) {
                return $sample;
            }
            // The feed is still an answer when Rakuten is not reachable;
            // the note says the sample is missing.
            return self::merge_categories($feed_counts, array(), 0);
        }

        return self::merge_categories($feed_counts, $sample['counts'], $sample['rows']);
    }

    /**
     * One list from both sources. Every name from either side is in it:
     * `rows` is how many rows of the feed carry it (0 = not in the feed
     * yet), `sampled` how many of the sampled products do. Feed rows sort
     * first, then the sample, so what a feed already holds stays on top
     * and what it could add follows.
     *
     * @param array<string,int> $feed_counts   name => rows in the snapshot
     * @param array<string,int> $sample_counts name => products in the sample
     * @param int               $sampled_rows  Size of the sample.
     * @return array{source:string,categories:array<int,array{name:string,rows:int,sampled:int}>,sampled_rows:int}
     */
    public static function merge_categories(array $feed_counts, array $sample_counts, $sampled_rows) {
        $list = array();
        foreach ($feed_counts as $name => $rows) {
            $list[$name] = array('name' => (string) $name, 'rows' => (int) $rows, 'sampled' => 0);
        }
        foreach ($sample_counts as $name => $n) {
            if (!isset($list[$name])) {
                $list[$name] = array('name' => (string) $name, 'rows' => 0, 'sampled' => 0);
            }
            $list[$name]['sampled'] = (int) $n;
        }

        $list = array_values($list);
        usort($list, function ($a, $b) {
            if ($a['rows'] !== $b['rows']) {
                return $b['rows'] - $a['rows'];
            }
            if ($a['sampled'] !== $b['sampled']) {
                return $b['sampled'] - $a['sampled'];
            }
            return strcmp($a['name'], $b['name']);
        });

        if (!empty($feed_counts)) {
            $source = empty($sample_counts) ? 'feed' : 'both';
        } else {
            $source = 'sample';
        }

        return array('source' => $source, 'categories' => $list, 'sampled_rows' => (int) $sampled_rows);
    }

    /**
     * Row counts per category from the feed that exists for this
     * advertiser, if one does. The snapshot, not the products table - the
     * table holds only the mapped primary category ("Apparel &
     * Accessories"), the file also has the secondary path
     * ("Shoes~~Boots") a scope is chosen by.
     *
     * @param int $mid
     * @return array<string,int> name => rows; empty without a feed
     */
    private static function feed_category_counts($mid) {
        if (!class_exists('MyFeeds_Source_Feeds')) {
            return array();
        }
        foreach (MyFeeds_Source_Feeds::feeds() as $feed) {
            if ((int) $feed['api']['mid'] !== $mid || empty($feed['stable_id'])) {
                continue;
            }
            $path = MyFeeds_Source_Feeds::snapshot_path($feed);
            if ($path !== '' && is_readable($path)) {
                $counts = MyFeeds_Source_Snapshot::categories($path);
                if (!empty($counts)) {
                    return $counts;
                }
            }
            $out = array();
            foreach (self::categories_from_table((int) $feed['stable_id']) as $entry) {
                $out[$entry['name']] = $entry['count'];
            }
            if (!empty($out)) {
                return $out;
            }
        }

        return array();
    }

    /**
     * Five pages from Rakuten - the base query and its four sorted views,
     * 100 rows each - counted per category.
     *
     * @param int $mid
     * @return array{counts:array<string,int>,rows:int}|WP_Error
     */
    private static function sample_category_counts($mid) {
        $rows    = array();
        $queries = array(array('mid' => (string) $mid));
        foreach (self::expansions(array('mid' => (string) $mid), array()) as $view) {
            $queries[] = $view;
        }

        foreach ($queries as $query) {
            $query['max'] = MyFeeds_Rakuten_Client::PAGE_SIZE;
            $page = MyFeeds_Rakuten_Client::search_products($query);
            if (is_wp_error($page)) {
                if (!empty($rows)) {
                    break;
                }
                return $page;
            }
            foreach ($page['items'] as $row) {
                $rows[] = $row;
            }
        }

        return array('counts' => self::categories_in_rows($rows), 'rows' => count($rows));
    }

    /**
     * @param int $stable_id
     * @return array<int,array{name:string,count:int}>
     */
    private static function categories_from_table($stable_id) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !class_exists('MyFeeds_DB_Manager') || !method_exists('MyFeeds_DB_Manager', 'table_name')) {
            return array();
        }
        $table = MyFeeds_DB_Manager::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $table is the plugin's own products table; the list is asked when the dialog opens and kept ten minutes (MyFeeds_Source_REST::categories()).
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT category, COUNT(*) AS c FROM {$table}
             WHERE feed_id = %d AND status = 'active' AND category <> ''
             GROUP BY category ORDER BY c DESC LIMIT 300",
            (int) $stable_id
        ), ARRAY_A);
        // phpcs:enable

        $out = array();
        foreach ((array) $rows as $row) {
            $name = self::clean_term($row['category']);
            if ($name === '') {
                continue;
            }
            $out[] = array('name' => $name, 'count' => (int) $row['c']);
        }

        return $out;
    }

    /**
     * A one-line description of a scope for the feeds table.
     *
     * @param array $scope Normalised scope.
     * @return string
     */
    public static function describe_scope(array $scope) {
        if ($scope['mode'] === self::MODE_ALL || empty($scope['terms'])) {
            return __('All products', 'myfeeds-affiliate-feed-manager');
        }

        $label = $scope['mode'] === self::MODE_CATEGORIES
            ? __('Categories', 'myfeeds-affiliate-feed-manager')
            : __('Keywords', 'myfeeds-affiliate-feed-manager');

        return $label . ': ' . implode(', ', $scope['terms']);
    }
}
