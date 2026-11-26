<?php
/**
 * robots.txt: keep crawlers off the plugin's REST addresses.
 *
 * The page script of a card from Amazon's API carries the address it asks
 * for live prices (/wp-json/myfeeds/v1/amazon/live). Google read it out of
 * the page and fetched it bare, without the product ids it needs: on
 * mylook.com.de Search Console listed it as "blocked due to another 4xx
 * issue" (26.11.2026). A REST answer is data for a script, never a page,
 * so robots.txt says so.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Robots {

    public static function init() {
        add_filter('robots_txt', array(__CLASS__, 'filter_robots_txt'), PHP_INT_MAX, 2);
    }

    /**
     * Only for a public site: with "Discourage search engines" WordPress
     * writes a global Disallow itself and $public is false.
     */
    public static function filter_robots_txt($output, $public) {
        if (!$public) {
            return $output;
        }
        $prefix = function_exists('rest_get_url_prefix') ? trim((string) rest_get_url_prefix(), '/') : 'wp-json';
        return self::add_disallows(
            $output,
            array('Disallow: /' . ($prefix !== '' ? $prefix : 'wp-json') . '/myfeeds/'),
            '# MyFeeds - REST answers are data for scripts, not pages'
        );
    }

    /**
     * Add Disallow lines to the last "User-agent: *" group, or open one if
     * there is none. A Disallow only counts inside a group, and one group
     * per plugin is what Yandex flags; SEO plugins replace core's group and
     * put theirs at the end. Lines the file already has are not added twice.
     *
     * @param string   $output    Current robots.txt text.
     * @param string[] $disallows Complete "Disallow: ..." lines.
     * @param string   $comment   Comment line written above the block.
     * @return string
     */
    public static function add_disallows($output, array $disallows, $comment = '') {
        $output = is_string($output) ? $output : '';
        $missing = array();
        foreach ($disallows as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/(^|\n)[ \t]*' . preg_quote($line, '/') . '[ \t]*(\r?\n|$)/', $output)) {
                $missing[] = $line;
            }
        }
        if (empty($missing)) {
            return $output;
        }
        $block = array();
        if ($comment !== '') {
            $block[] = $comment;
        }
        foreach ($missing as $line) {
            $block[] = $line;
        }

        $lines = preg_split('/\r?\n/', $output);
        $group_start = -1;
        foreach ($lines as $i => $line) {
            if (preg_match('/^[ \t]*user-agent[ \t]*:[ \t]*\*[ \t]*$/i', $line)) {
                $group_start = $i;
            }
        }
        if ($group_start === -1) {
            $own = array_merge(array('', 'User-agent: *'), $block, array(''));
            return rtrim($output, "\r\n") . "\n" . implode("\n", $own);
        }

        // Past further User-agent lines of the same group, then its rules;
        // stop at the first line that cannot belong to the group.
        $insert_at = $group_start + 1;
        $count     = count($lines);
        $seen_rule = false;
        while ($insert_at < $count) {
            $line = $lines[$insert_at];
            if (trim($line) === '') {
                break;
            }
            if (preg_match('/^[ \t]*user-agent[ \t]*:/i', $line)) {
                if ($seen_rule) {
                    break;
                }
                $insert_at++;
                continue;
            }
            if (preg_match('/^[ \t]*(sitemap|host)[ \t]*:/i', $line)) {
                break;
            }
            $seen_rule = true;
            $insert_at++;
        }
        array_splice($lines, $insert_at, 0, $block);
        return implode("\n", $lines);
    }
}
