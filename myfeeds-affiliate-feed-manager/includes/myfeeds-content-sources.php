<?php
/**
 * Where MyFeeds products can sit on a site.
 *
 * The nightly Quick Sync refreshes only the products it finds, so this
 * list decides whether a card shows today's price or last Sunday's. It
 * used to be "posts and pages", fixed in the SQL. A block in a recipe or
 * review post type, in a synced pattern, in a block widget or in a block
 * theme's template showed a price the nightly sync never touched, and to
 * the person who placed it that looked like a sync that does not work.
 *
 * One list for every reader of "which products does the site's content
 * hold", so the sync and the rules that keep a placed product's row from
 * being deleted never disagree about what is on the site.
 *
 * Shared with Free byte for byte.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Post types whose published content can hold a MyFeeds block: every
 * public post type except attachments, plus synced patterns (wp_block)
 * and the templates and template parts a block theme keeps in the
 * database. A theme or plugin narrows or widens the list with the filter
 * myfeeds_discovery_post_types.
 *
 * A synced pattern is read where it is stored, not by following the
 * core/block references that point to it. That finds it wherever it is
 * used - a post, a widget, a template - and costs no query per reference.
 * A pattern nothing uses is refreshed too, which costs nothing anyone
 * would notice.
 *
 * Post types that are no longer registered drop out: their posts stay
 * in the database, but nothing on the site shows them.
 *
 * @return array<int,string>
 */
function myfeeds_discovery_post_types() {
    $types = array('post', 'page');
    if (function_exists('get_post_types')) {
        $types = array_merge($types, array_values((array) get_post_types(array('public' => true), 'names')));
    }
    $types = array_merge($types, array('wp_block', 'wp_template', 'wp_template_part'));
    $types = myfeeds_clean_post_types(array_diff($types, array('attachment')));

    if (!function_exists('apply_filters')) {
        return $types;
    }

    try {
        $filtered = apply_filters('myfeeds_discovery_post_types', $types);
    } catch (\Throwable $e) {
        return $types;
    }

    // Not a list is a slip in someone's callback, not a request to read
    // nothing. An empty list is a request, and is honoured.
    return is_array($filtered) ? myfeeds_clean_post_types($filtered) : $types;
}

/**
 * Pure: post type names as WordPress registers them - lowercase letters,
 * digits, dashes and underscores, at most 20 characters - each once.
 * Anything else is dropped, which is also what makes the names safe to
 * quote straight into SQL.
 *
 * @param mixed $types
 * @return array<int,string>
 */
function myfeeds_clean_post_types($types) {
    $out = array();
    foreach ((array) $types as $type) {
        if (!is_string($type)) {
            continue;
        }
        $type = trim($type);
        if ($type === '' || strlen($type) > 20 || !preg_match('/^[a-z0-9_\-]+$/', $type)) {
            continue;
        }
        $out[$type] = true;
    }
    return array_keys($out);
}

/**
 * The discovery post types as the inside of an SQL IN (...): "'post',
 * 'page', ...". Empty when the list is empty - the caller then has no
 * posts to read, and must not send "IN ()".
 *
 * @return string
 */
function myfeeds_discovery_post_types_sql() {
    $types = myfeeds_discovery_post_types();
    return empty($types) ? '' : "'" . implode("', '", $types) . "'";
}

/**
 * The content of every block widget that sits in a sidebar. A widget
 * under "Inactive widgets" is shown nowhere and does not count. Block
 * widgets live in one option, so this is one read, never a query per
 * widget.
 *
 * Keyed "block-<n>" like WordPress names the widget, so a log line can
 * say which one held what.
 *
 * @return array<string,string>
 */
function myfeeds_block_widget_contents() {
    if (!function_exists('get_option')) {
        return array();
    }

    $widgets = get_option('widget_block', array());
    $sidebars = get_option('sidebars_widgets', array());
    if (!is_array($widgets) || empty($widgets) || !is_array($sidebars)) {
        return array();
    }

    $shown = array();
    foreach ($sidebars as $sidebar => $ids) {
        // 'array_version' is a number, not a sidebar.
        if ($sidebar === 'wp_inactive_widgets' || !is_array($ids)) {
            continue;
        }
        foreach ($ids as $id) {
            if (is_string($id) && preg_match('/^block-(\d+)$/', $id, $m)) {
                $shown[(int) $m[1]] = true;
            }
        }
    }

    $out = array();
    foreach ($widgets as $number => $widget) {
        // '_multiwidget' sits in the same option.
        if (!is_int($number) && !ctype_digit((string) $number)) {
            continue;
        }
        if (!isset($shown[(int) $number]) || !is_array($widget)) {
            continue;
        }
        $content = $widget['content'] ?? '';
        if (is_string($content) && $content !== '') {
            $out['block-' . (int) $number] = $content;
        }
    }
    return $out;
}
