<?php
/**
 * MyFeeds Dead-Product Detector
 *
 * Scans published posts that contain a product picker or a Look and
 * reports which posts reference products that are no longer active in
 * the user's feed. Surface is purely informational: a count + the list
 * of affected post titles.
 *
 * Results are cached in a transient so the scan only runs when needed
 * and is invalidated after every feed sync (see batch-importer hooks).
 *
 * @since 1.0.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Dead_Products {

    const BLOCK_MARKER = 'wp:myfeeds/product-picker';
    // Every block of this plugin that holds products: the picker and the
    // Look (rule 13 - a Look's points are placed products too).
    const CONTENT_MARKER = 'wp:myfeeds/';
    const CACHE_KEY    = 'myfeeds_dead_products_report_v1';
    const CACHE_TTL    = HOUR_IN_SECONDS;
    // Hard cap on the post sweep — protects against pathological sites
    // with thousands of posts holding products.
    const POST_SCAN_LIMIT = 500;

    /**
     * Cached report, or a freshly computed one if the cache is empty.
     * Always returns the same shape so callers don't have to defend.
     */
    public static function get_report() {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['posts'], $cached['dead_count'])) {
            return $cached;
        }
        return self::recompute();
    }

    /**
     * Force a recompute. Caches the fresh result for CACHE_TTL.
     */
    public static function recompute() {
        $report = self::build_report();
        set_transient(self::CACHE_KEY, $report, self::CACHE_TTL);
        return $report;
    }

    /**
     * Drop the cache so the next render rescans. Wired into the
     * batch importer's completion event so a sync that re-activates
     * or removes products immediately reflects in the UI.
     */
    public static function bust_cache() {
        delete_transient(self::CACHE_KEY);
    }

    private static function empty_report() {
        return array(
            'dead_count'   => 0,
            'total_count'  => 0,
            'posts'        => array(),
            'truncated'    => false,
            'computed_at'  => time(),
        );
    }

    private static function build_report() {
        if (!function_exists('parse_blocks')) {
            return self::empty_report();
        }

        global $wpdb;
        $needle = '%' . $wpdb->esc_like(self::CONTENT_MARKER) . '%';
        $limit  = (int) self::POST_SCAN_LIMIT;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- limit is an int constant
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_content
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_content LIKE %s
             ORDER BY post_modified DESC
             LIMIT {$limit}",
            $needle
        ), ARRAY_A);

        if (empty($rows)) {
            return self::empty_report();
        }

        $by_post = array();
        $all_ids = array();
        foreach ($rows as $r) {
            $ids = self::extract_product_ids_from_content($r['post_content']);
            if (empty($ids)) {
                continue;
            }
            $by_post[(int) $r['ID']] = array(
                'title'       => (string) $r['post_title'],
                'ids'         => $ids,
                'total_count' => count($ids),
            );
            foreach ($ids as $id) {
                $all_ids[$id] = true;
            }
        }
        if (empty($all_ids)) {
            return self::empty_report();
        }

        $classified = self::classify_product_ids(array_keys($all_ids));

        $total_dead = 0;
        $posts_with_dead = array();
        foreach ($by_post as $post_id => $info) {
            $dead_here = 0;
            foreach ($info['ids'] as $pid) {
                if (($classified[$pid] ?? 'unknown') === 'dead') {
                    $dead_here++;
                }
            }
            if ($dead_here > 0) {
                $posts_with_dead[] = array(
                    'post_id'     => (int) $post_id,
                    'title'       => $info['title'] !== '' ? $info['title'] : __('(untitled post)', 'myfeeds-affiliate-feed-manager'),
                    'dead_count'  => $dead_here,
                    'total_count' => $info['total_count'],
                );
                $total_dead += $dead_here;
            }
        }

        // Sort most-affected first, then alphabetically by title.
        usort($posts_with_dead, function ($a, $b) {
            if ($b['dead_count'] !== $a['dead_count']) {
                return $b['dead_count'] - $a['dead_count'];
            }
            return strcasecmp($a['title'], $b['title']);
        });

        return array(
            'dead_count'   => $total_dead,
            'total_count'  => count($all_ids),
            'posts'        => $posts_with_dead,
            'truncated'    => count($rows) >= $limit,
            'computed_at'  => time(),
        );
    }

    /**
     * Walks the block tree and returns the unique product ids the
     * product picker references via selectedProducts and a Look through
     * its points (MyFeeds_Look_Block::feed_points(), the one answer every
     * reader of a Look asks; Amazon points are not feed products).
     */
    private static function extract_product_ids_from_content($content) {
        $blocks = parse_blocks((string) $content);
        $out = array();
        self::collect_ids_from_blocks($blocks, $out);
        return array_keys($out);
    }

    private static function collect_ids_from_blocks($blocks, &$out) {
        if (!is_array($blocks)) {
            return;
        }
        foreach ($blocks as $b) {
            if (!is_array($b)) {
                continue;
            }
            $name = isset($b['blockName']) ? (string) $b['blockName'] : '';
            if ($name === 'myfeeds/product-picker') {
                $attrs = isset($b['attrs']) && is_array($b['attrs']) ? $b['attrs'] : array();
                $selected = isset($attrs['selectedProducts']) && is_array($attrs['selectedProducts'])
                    ? $attrs['selectedProducts']
                    : array();
                foreach ($selected as $p) {
                    if (!is_array($p) || empty($p['id'])) {
                        continue;
                    }
                    $pid = (string) $p['id'];
                    if (!isset($out[$pid])) {
                        $out[$pid] = true;
                    }
                }
            }
            if ($name === 'myfeeds/look' && class_exists('MyFeeds_Look_Block')) {
                foreach (MyFeeds_Look_Block::feed_points($b) as $point) {
                    if (!isset($out[$point['id']])) {
                        $out[$point['id']] = true;
                    }
                }
            }
            if (!empty($b['innerBlocks']) && is_array($b['innerBlocks'])) {
                self::collect_ids_from_blocks($b['innerBlocks'], $out);
            }
        }
    }

    /**
     * Resolve each id to one of:
     *   active   — at least one row in wp_myfeeds_products that is
     *              'active' or 'archived'
     *   dead     — rows exist but none of them is (they are unavailable:
     *              the merchant dropped the product, or its feed was
     *              deleted)
     *   unknown  — no row at all (e.g. never imported, or wrong id format)
     *
     * An archived row is the product of a feed set aside: a post shows it
     * as it was (rule 12), so it is in no post's way. Counted as dead, the
     * card would list every post that holds one after the feeds were set
     * aside.
     *
     * Unknown is treated as not-dead by the caller. Flagging "no data"
     * as "no longer in feeds" is a false positive: a product that never
     * imported, or an id in an older format, has no row and is not dead.
     */
    private static function classify_product_ids($ids) {
        $out = array();
        if (empty($ids) || !class_exists('MyFeeds_DB_Manager') || !MyFeeds_DB_Manager::table_exists()) {
            foreach ($ids as $id) {
                $out[(string) $id] = 'unknown';
            }
            return $out;
        }
        global $wpdb;
        $products_table = MyFeeds_DB_Manager::table_name();
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));

        // Primary lookup: external_id (= the value the picker block
        // saves). MAX(CASE WHEN ...) handles the multi-row case
        // (re-imports leave older rows behind).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders + table name are safe
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT external_id AS match_key,
                    MAX(CASE WHEN status IN ('active','archived') THEN 1 ELSE 0 END) AS any_alive
             FROM {$products_table}
             WHERE external_id IN ({$placeholders})
             GROUP BY external_id",
            $ids
        ), ARRAY_A);
        foreach ((array) $rows as $r) {
            $key = (string) $r['match_key'];
            $out[$key] = ((int) $r['any_alive'] === 1) ? 'active' : 'dead';
        }

        // Fallback: numeric PK lookup. Older block payloads stored
        // the DB primary key instead of external_id. Recover alive
        // matches; never overwrite an existing 'active'.
        $unresolved = array();
        foreach ($ids as $id) {
            if (!isset($out[(string) $id]) || $out[(string) $id] === 'dead') {
                if (ctype_digit((string) $id)) {
                    $unresolved[] = (int) $id;
                }
            }
        }
        if (!empty($unresolved)) {
            $unresolved = array_values(array_unique($unresolved));
            $pk_placeholders = implode(',', array_fill(0, count($unresolved), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders + table name are safe
            $pk_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id AS match_key,
                        MAX(CASE WHEN status IN ('active','archived') THEN 1 ELSE 0 END) AS any_alive
                 FROM {$products_table}
                 WHERE id IN ({$pk_placeholders})
                 GROUP BY id",
                $unresolved
            ), ARRAY_A);
            foreach ((array) $pk_rows as $r) {
                $key   = (string) (int) $r['match_key'];
                $alive = (int) $r['any_alive'] === 1;
                if ($alive || !isset($out[$key])) {
                    $out[$key] = $alive ? 'active' : 'dead';
                }
            }
        }

        foreach ($ids as $id) {
            if (!isset($out[(string) $id])) {
                $out[(string) $id] = 'unknown';
            }
        }
        return $out;
    }
}

// Feed-sync completion is the only event that can change which
// products count as "active", so the report is invalidated there.
// The hook fires for every import mode (full / active-only /
// priority pass), which is exactly the coverage we want.
add_action('myfeeds_feed_update_complete', array('MyFeeds_Dead_Products', 'bust_cache'));
// Post edits/saves change which product ids are referenced. Bust on
// post save too, scoped to published posts that contain a picker or a
// Look, so the next admin visit reflects the current content.
add_action('save_post', function ($post_id, $post) {
    if (!($post instanceof WP_Post)) {
        return;
    }
    if ($post->post_status !== 'publish') {
        return;
    }
    if (strpos((string) $post->post_content, MyFeeds_Dead_Products::CONTENT_MARKER) === false) {
        return;
    }
    MyFeeds_Dead_Products::bust_cache();
}, 10, 2);
