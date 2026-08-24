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
     * @return array{options:int,transients:int}
     */
    public static function sweep() {
        return array(
            'options'    => self::drop_dead_options(),
            'transients' => self::drop_expired_transients(),
        );
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
