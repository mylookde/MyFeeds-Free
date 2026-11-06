<?php
/**
 * The version stamp on a stylesheet or script URL.
 *
 * MYFEEDS_VERSION is the RELEASE number, and by design it does not move
 * between deploys - versions are bumped in batches, not per push. An
 * asset stamped with it is therefore frozen in the browser of anyone who
 * has opened the screen once: every improvement to that file is
 * invisible to them until the next release.
 *
 * This plugin has already paid for that once. The editor bundle was
 * stamped with a constant that did not exist, went out as ?ver=1.3.0 at
 * every release, and months of editor work never reached anybody who had
 * looked at the editor before. The fix is the same either way: stamp the
 * file with the file.
 *
 * @param string $relative Path under the plugin directory.
 * @return string
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('myfeeds_asset_ver')) {
    function myfeeds_asset_ver($relative) {
        $abs = MYFEEDS_PLUGIN_DIR . ltrim((string) $relative, '/');
        if (file_exists($abs)) {
            $stamp = filemtime($abs);
            if ($stamp) {
                return (string) $stamp;
            }
        }
        return defined('MYFEEDS_VERSION') ? MYFEEDS_VERSION : '1.0';
    }
}
