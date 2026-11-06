<?php
/**
 * On a MyFeeds screen, only MyFeeds speaks.
 *
 * Other plugins, themes and mu-plugins hang their notices on every admin
 * screen. On MyFeeds' own screens that pushed the screen itself down: a
 * server warning, a theme's list of incomplete looks and an SMTP reminder
 * stood above the plans and above a feature page (Marlon, 2026-11-06). So
 * on those screens every notice callback that is not MyFeeds' own comes
 * off the four notice hooks, just before WordPress prints them. Every
 * other admin screen is left exactly as it was, and so are the notices
 * themselves: they show on the next screen that is not ours.
 *
 * "Own" is decided by where a callback's code lives, not by its name: in
 * this plugin's folder, outside vendor/. A name prefix would keep a
 * foreign myfeeds_something() and lose a closure of ours, and a bundled
 * library (vendor/) speaks for every plugin that uses it, not for this
 * one. The filter `myfeeds_own_notice_callback` lets code that knows
 * better claim a callback; nothing else can.
 *
 * A screen is a MyFeeds screen when MyFeeds draws it: the callback behind
 * the page is own by the same rule. That also covers pages registered
 * without a parent, which a menu-slug test would miss.
 *
 * Shared byte for byte between the free and the paid plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Notice_Scope {

    const HOOKS = array('admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices');

    public static function init() {
        // The last thing before admin-header.php prints the notices, so a
        // notice added late in the request is still seen.
        add_action('in_admin_header', array(__CLASS__, 'apply'), PHP_INT_MAX);
    }

    public static function apply() {
        if (!self::is_own_screen()) {
            return;
        }
        foreach (self::HOOKS as $hook) {
            foreach (self::callbacks($hook) as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    if (isset($callback['function']) && !self::is_own($callback['function'])) {
                        remove_action($hook, $callback['function'], $priority);
                    }
                }
            }
        }
    }

    /**
     * Whether this request draws a MyFeeds admin screen. WordPress names
     * the page's action in $page_hook; MyFeeds draws the screen when a
     * callback on it is MyFeeds' own.
     */
    public static function is_own_screen() {
        global $page_hook;
        if (!is_string($page_hook) || $page_hook === '') {
            return false;
        }
        foreach (self::callbacks($page_hook) as $callbacks) {
            foreach ($callbacks as $callback) {
                if (isset($callback['function']) && self::is_own($callback['function'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param mixed $callback anything add_action() accepts
     */
    public static function is_own($callback) {
        $file = self::defined_in($callback);
        $own = $file !== '' && self::belongs_to_plugin($file, dirname(__DIR__));
        return (bool) apply_filters('myfeeds_own_notice_callback', $own, $callback);
    }

    /**
     * The file a callback's code is in, or '' when PHP cannot say: a
     * function built into PHP, or a class that is not loaded.
     *
     * @param mixed $callback
     */
    public static function defined_in($callback) {
        try {
            if ($callback instanceof Closure) {
                $ref = new ReflectionFunction($callback);
            } elseif (is_array($callback) && isset($callback[0], $callback[1]) && is_string($callback[1])) {
                $ref = new ReflectionMethod($callback[0], $callback[1]);
            } elseif (is_string($callback) && strpos($callback, '::') !== false) {
                $parts = explode('::', $callback, 2);
                $ref = new ReflectionMethod($parts[0], $parts[1]);
            } elseif (is_string($callback)) {
                $ref = new ReflectionFunction($callback);
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $ref = new ReflectionMethod($callback, '__invoke');
            } else {
                return '';
            }
        } catch (ReflectionException $e) {
            return '';
        }
        $file = $ref->getFileName();
        return is_string($file) ? $file : '';
    }

    /**
     * Whether a file lies in the plugin folder $root, outside its vendor/.
     * Compared as paths with a trailing slash, so a sibling folder whose
     * name starts the same (myfeeds-pro-old) is not mistaken for it.
     */
    public static function belongs_to_plugin($file, $root) {
        $file = str_replace('\\', '/', (string) $file);
        $root = rtrim(str_replace('\\', '/', (string) $root), '/') . '/';
        if ($root === '/' || strpos($file, $root) !== 0) {
            return false;
        }
        return strpos($file, $root . 'vendor/') !== 0;
    }

    /** @return array<int, array<string, array{function: mixed}>> priority => callbacks */
    private static function callbacks($hook) {
        global $wp_filter;
        if (!isset($wp_filter[$hook]) || !is_object($wp_filter[$hook]) || !isset($wp_filter[$hook]->callbacks)
            || !is_array($wp_filter[$hook]->callbacks)) {
            return array();
        }
        return $wp_filter[$hook]->callbacks;
    }
}

MyFeeds_Notice_Scope::init();
