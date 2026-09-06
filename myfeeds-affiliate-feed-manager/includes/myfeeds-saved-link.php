<?php
/**
 * Repairing a saved affiliate link that lost its backslashes.
 *
 * A picker block stores its products as JSON inside the block comment,
 * and JSON writes an ampersand as &. wp_insert_post() unslashes
 * everything it is handed, so any code that passes serialized blocks to
 * wp_update_post() without wp_slash() strips one layer of backslashes
 * from that JSON - and & becomes the literal text u0026.
 *
 * What is left is a link that still looks like a link and is not one:
 *
 *   https://www.awin1.com/pclick.php?p=42453300669u0026a=830239u0026m=1649
 *
 * One parameter, p, whose value is the whole rest of the query. The
 * affiliate id is gone, the merchant id is gone, and with the merchant
 * id goes the only thing that lets the dead-link check recognise a
 * product from a closed programme. That is how 34 products in eighteen
 * posts on mylook.com.de stayed "unknown" while their programme had
 * been closed for a week.
 *
 * The write path is fixed (wp_slash where it belongs) and the stored
 * content is repaired, but a saved block is not something we control -
 * it can come back from a revision, an export, a backup, another site.
 * So every place that reads a link out of one repairs it on the way in.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Undo the lost-backslash damage in a saved link, if there is any.
 *
 * Deliberately narrow: it only acts on a URL that has a query string,
 * carries the damage marker, and has no real ampersand anywhere - which
 * is what the damage looks like and what an intact link never looks
 * like. A URL that legitimately contains the letters u0026 in a value
 * keeps them as long as it also has an ampersand of its own.
 *
 * @param string $url
 * @return string
 */
function myfeeds_repair_saved_link($url) {
    $url = (string) $url;
    if ($url === '' || strpos($url, 'u0026') === false || strpos($url, '&') !== false) {
        return $url;
    }
    if (strpos($url, '?') === false) {
        return $url;
    }

    return str_replace('u0026', '&', $url);
}
