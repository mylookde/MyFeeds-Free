<?php
/**
 * Housekeeping, so a plugin that has been installed for a year does not
 * leave a year of debris behind.
 *
 * Two things accumulate on a WordPress site and neither announces itself.
 * Options marked autoload are read on *every* request, front end included,
 * so a 12 KB leftover from a version nobody runs any more is 12 KB read a
 * few hundred thousand times. And transients outlive their usefulness in
 * the options table until something deletes them.
 *
 * The list below is deliberately short and explicit. "Anything the current
 * code does not mention" is the tempting rule and the wrong one: it would
 * delete stored credentials the moment the other edition of this plugin is
 * the one running, and it would delete one-shot migration flags, which
 * makes finished migrations run a second time.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Maintenance {

    const HOOK = 'myfeeds_daily_maintenance';

    /**
     * Options written by versions that no longer exist. Payload only -
     * never a migration marker, never anything holding credentials.
     *
     * @return array<int,string>
     */
    public static function dead_options() {
        return array(
            'myfeeds_debug_log',      // superseded by error_log; grew unbounded, autoloaded
            'myfeeds_debug_logs',     // same, plural spelling from an even earlier version
            'myfeeds_feeds_archive',  // backup of deleted feeds nothing ever read back
            'myfeeds_test_version',   // left over from a version check that was removed
        );
    }

    public static function init() {
        add_action(self::HOOK, array(__CLASS__, 'sweep'));

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * @return array{options:int,transients:int,actions:int}
     */
    public static function sweep() {
        return array(
            'options'    => self::drop_dead_options(),
            'transients' => self::drop_expired_transients(),
            'actions'    => self::drop_old_actions(),
            'orphans'    => self::drop_orphaned_products(),
        );
    }

    /**
     * Products whose feed no longer exists.
     *
     * Deleting a feed already clears its products, so on a healthy site
     * this finds nothing. It is here for the case where something else
     * removed a feed and did not: a restored backup, an edit made
     * straight in the database, a delete path nobody has written yet.
     *
     * Such a row is worse than useless. It keeps status='active', so it
     * passes every query in the plugin, and none of them checks the feed
     * list. It still has its image and its price, and its link points
     * into a programme the site no longer has.
     *
     * Filtering the feed list at read time instead is the wrong fix:
     * cleanup_orphaned_products() deliberately keeps products a published
     * post is showing, as STATUS_ARCHIVED, so live cards do not go blank.
     * A read-time filter on feed_id would drop exactly those.
     *
     * Safe to run unattended: it deletes nothing when the feed list is
     * empty, and stands down entirely if a configured feed has no
     * stable_id.
     *
     * @return int
     */
    private static function drop_orphaned_products() {
        if (!class_exists('MyFeeds_DB_Manager')
            || !method_exists('MyFeeds_DB_Manager', 'cleanup_orphaned_products')) {
            return 0;
        }
        if (method_exists('MyFeeds_DB_Manager', 'is_db_mode') && !MyFeeds_DB_Manager::is_db_mode()) {
            return 0;
        }

        return (int) MyFeeds_DB_Manager::cleanup_orphaned_products();
    }

    /**
     * How long a finished background job stays readable.
     *
     * A week: long enough that someone investigating "why did my prices
     * not update on Tuesday" can still see Tuesday, short enough that the
     * tables do not grow without end on a site that syncs nightly.
     *
     * @return int seconds
     */
    public static function retention_seconds() {
        /**
         * Filter how long finished MyFeeds background jobs are kept.
         *
         * @param int $seconds Default one week.
         */
        return (int) apply_filters('myfeeds_action_retention', 7 * DAY_IN_SECONDS);
    }

    /**
     * Delete our own finished Action Scheduler rows once they are old.
     *
     * Deliberately scoped to hooks that start with myfeeds_, and
     * deliberately NOT done by filtering action_scheduler_retention_period.
     * Action Scheduler is a shared library - WooCommerce ships it too - and
     * that filter is global. Shortening it would quietly delete another
     * plugin's job history on the same site, which is not ours to do.
     *
     * @return int rows removed
     */
    private static function drop_old_actions() {
        global $wpdb;

        $actions = $wpdb->prefix . 'actionscheduler_actions';
        $logs    = $wpdb->prefix . 'actionscheduler_logs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ($wpdb->get_var("SHOW TABLES LIKE '{$actions}'") !== $actions) {
            return 0;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - self::retention_seconds());

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT action_id FROM {$actions}
             WHERE hook LIKE %s
               AND status IN ('complete', 'failed', 'canceled')
               AND scheduled_date_gmt < %s
             LIMIT 1000",
            $wpdb->esc_like('myfeeds_') . '%',
            $cutoff
        ));

        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Logs first: a log row pointing at an action that no longer
        // exists is exactly the kind of debris this is meant to remove.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$logs} WHERE action_id IN ({$placeholders})",
            ...$ids
        ));

        $removed = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$actions} WHERE action_id IN ({$placeholders})",
            ...$ids
        ));

        if ($removed > 0 && function_exists('myfeeds_log')) {
            $days = round(self::retention_seconds() / DAY_IN_SECONDS);
            myfeeds_log("Maintenance: removed {$removed} finished background job(s) older than {$days} day(s)", 'info');
        }

        return $removed;
    }

    /**
     * @return int
     */
    private static function drop_dead_options() {
        $removed = 0;

        foreach (self::dead_options() as $name) {
            if (get_option($name, null) !== null && delete_option($name)) {
                $removed++;
            }
        }

        if ($removed > 0 && function_exists('myfeeds_log')) {
            myfeeds_log("Maintenance: removed {$removed} option(s) left by older versions", 'info');
        }

        return $removed;
    }

    /**
     * Delete our own transients whose expiry has passed.
     *
     * WordPress only clears an expired transient when something asks for
     * it. A facet cache keyed by filter combination is asked for once and
     * then never again, so without this it sits in the options table for
     * good.
     *
     * @return int
     */
    private static function drop_expired_transients() {
        global $wpdb;

        $now = time();

        $timeouts = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s AND option_value < %d",
            $wpdb->esc_like('_transient_timeout_myfeeds_') . '%',
            $now
        ));

        $removed = 0;
        foreach ($timeouts as $timeout_name) {
            $key = substr($timeout_name, strlen('_transient_timeout_'));
            delete_transient($key);
            $removed++;
        }

        if ($removed > 0 && function_exists('myfeeds_log')) {
            myfeeds_log("Maintenance: removed {$removed} expired transient(s)", 'info');
        }

        return $removed;
    }
}
