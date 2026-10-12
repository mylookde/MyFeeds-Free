<?php
/**
 * MyFeeds Feed Content Cache
 * 
 * Kapselt das Caching von Feed-Inhalten auf dem Dateisystem.
 * Jeder Feed wird pro Import-Run einmal heruntergeladen und für alle
 * folgenden Batches aus dem Cache gelesen.
 * 
 * DESIGN-ENTSCHEIDUNG:
 * Diese Funktionen sind bewusst als standalone-Hilfsfunktionen implementiert
 * (nicht als Klasse), damit sie später einfach durch eine DB-basierte
 * Lösung ersetzt werden können. Die Business-Logik ruft nur diese API auf
 * und kennt keine Dateipfade oder Speichermechanismen.
 * 
 * API:
 *   myfeeds_get_feed_content($feed_url, $feed_index, $run_id) → string|WP_Error
 *   myfeeds_cleanup_feed_cache($feed_index, $run_id)          → void
 *   myfeeds_cleanup_all_feed_caches($run_id)                  → void
 * 
 * @package MyFeeds
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if a URL points to a gzip-compressed file (.gz or .csv.gz).
 *
 * @param string $url Feed URL
 * @return bool
 */
function myfeeds_is_gzip_url($url) {
    $path = strtolower(wp_parse_url($url, PHP_URL_PATH) ?: '');
    return (bool) preg_match('/\.gz$/i', $path);
}

/**
 * Check if a URL points to a zip archive.
 *
 * @param string $url Feed URL
 * @return bool
 */
function myfeeds_is_zip_url($url) {
    $path = strtolower(wp_parse_url($url, PHP_URL_PATH) ?: '');
    return (bool) preg_match('/\.zip$/i', $path);
}

/**
 * Pick the entry inside a zip that is most likely to be the feed.
 *
 * Networks ship the catalogue next to readmes, checksums and the
 * __MACOSX sidecar a Mac adds when it compresses. Extension wins over
 * size, because a 2 KB readme.txt must never beat a 400 MB catalogue,
 * and among equals the largest wins.
 *
 * @param ZipArchive $zip Opened archive.
 * @return array{name:string,size:int}|null
 */
function myfeeds_pick_zip_entry($zip) {
    $feed_ext = array('csv', 'tsv', 'psv', 'ssv', 'txt', 'xml', 'json', 'jsonl', 'ndjson');
    $best = null;
    $best_rank = -1;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat) {
            continue;
        }
        $name = $stat['name'];

        if (substr($name, -1) === '/' || strpos($name, '__MACOSX/') === 0) {
            continue;
        }
        $base = basename($name);
        if ($base === '' || $base[0] === '.') {
            continue;
        }
        if ((int) $stat['size'] === 0) {
            continue;
        }

        $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
        $rank = in_array($ext, $feed_ext, true) ? 1 : 0;

        if ($rank > $best_rank || ($rank === $best_rank && $best !== null && (int) $stat['size'] > $best['size'])) {
            $best = array('name' => $name, 'size' => (int) $stat['size']);
            $best_rank = $rank;
        }
    }

    return $best;
}

/**
 * Extract the feed out of a zip into $dest_path.
 *
 * Streams entry -> destination in chunks; a catalogue zip can unpack to
 * several hundred megabytes and must never be held in memory.
 *
 * @param string $zip_path  Downloaded archive on disk.
 * @param string $dest_path Where the extracted feed should land.
 * @return true|WP_Error
 */
function myfeeds_extract_zip_feed($zip_path, $dest_path) {
    if (!class_exists('ZipArchive')) {
        return new WP_Error(
            'zip_unsupported',
            'This feed is a .zip archive, but PHP on this server has no zip support. '
            . 'Ask your host to enable the zip extension, or unpack the file and point MyFeeds at the extracted file.'
        );
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return new WP_Error('zip_error', 'The downloaded file looks like a .zip but could not be opened.');
    }

    $entry = myfeeds_pick_zip_entry($zip);
    if ($entry === null) {
        $zip->close();
        return new WP_Error('zip_empty', 'The .zip archive contains no readable feed file.');
    }

    $in = $zip->getStream($entry['name']);
    if (!$in) {
        $zip->close();
        return new WP_Error('zip_error', "Cannot read '{$entry['name']}' out of the .zip archive.");
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming required for large feed files
    $out = @fopen($dest_path, 'wb');
    if (!$out) {
        fclose($in);
        $zip->close();
        return new WP_Error('write_error', 'Cannot write the extracted feed to the cache folder.');
    }

    $written = 0;
    while (!feof($in)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $chunk = fread($in, 65536);
        if ($chunk === false || $chunk === '') {
            break;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($out, $chunk);
        $written += strlen($chunk);
    }
    fclose($in);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    fclose($out);
    $zip->close();

    if ($written === 0) {
        @wp_delete_file($dest_path);
        return new WP_Error('zip_empty', "'{$entry['name']}' in the .zip archive is empty.");
    }

    if (class_exists('MyFeeds_Logger')) {
        MyFeeds_Logger::info("FeedCache: Extracted '{$entry['name']}' ({$written} bytes) from zip");
    }

    return true;
}

/**
 * How long a feed download may take, in seconds.
 *
 * Two minutes. A feed that is streamed to disk moves several megabytes
 * a second, so this is rarely about size; it is about servers that
 * build the file on request before sending the first byte. Raising it
 * runs into PHP's own time limit on most shared hosts, which ends the
 * import without a word instead of with a message.
 *
 * @param string $feed_url
 * @return int
 */
function myfeeds_feed_download_timeout($feed_url = '') {
    $seconds = (int) apply_filters('myfeeds_feed_download_timeout', 120, $feed_url);
    return $seconds > 0 ? $seconds : 120;
}

/**
 * A status code whose cause is worth naming.
 *
 * 429 is not a broken link: the network counted the downloads. For
 * Tradedoubler that is a published rule and the usual way to meet it -
 * adding a feed, testing it and the nightly sync are three downloads
 * of the same file.
 *
 * @param int    $http_code
 * @param string $feed_url
 * @return WP_Error|null Null when the plain "HTTP n" says all there is.
 */
function myfeeds_feed_http_explained($http_code, $feed_url) {
    if ((int) $http_code !== 429) {
        return null;
    }

    $message = __('The feed\'s server refused the download because the feed was requested too often (HTTP 429). Nothing was changed; the next sync tries again.', 'myfeeds-affiliate-feed-manager');

    if (stripos((string) wp_parse_url((string) $feed_url, PHP_URL_HOST), 'tradedoubler.') !== false) {
        $message .= ' ' . __('Tradedoubler hands out the same version of a full feed three times in 24 hours and then waits until the feed has changed.', 'myfeeds-affiliate-feed-manager');
    }

    return new WP_Error('http_rate_limited', $message);
}

/**
 * A download that ran out of time, in words instead of cURL's.
 *
 * @param mixed $error   What the HTTP layer returned.
 * @param int   $seconds The limit the download ran under.
 * @return WP_Error|null Null when the error is not a timeout.
 */
function myfeeds_feed_timeout_explained($error, $seconds) {
    if (!is_wp_error($error)) {
        return null;
    }
    $said = (string) $error->get_error_message();
    if (stripos($said, 'timed out') === false && stripos($said, 'cURL error 28') === false) {
        return null;
    }

    return new WP_Error('download_timeout', sprintf(
        /* translators: %d: number of seconds */
        __('The download had not finished after %d seconds and was stopped; nothing was imported. A very large feed, or one the network builds on request, can take longer than that. Try again in a few minutes, or download the file yourself and upload it.', 'myfeeds-affiliate-feed-manager'),
        (int) $seconds
    ));
}

/**
 * Get feed content — from cache if available, otherwise download and cache.
 * 
 * The cache is keyed by feed_index + run_id, ensuring each import run
 * gets a fresh download while subsequent batches within the same run
 * read from the cached file.
 * 
 * @param string $feed_url    The URL to download the feed from
 * @param int    $feed_index  Index of the feed in the import queue
 * @param string $run_id      Unique identifier for the current import run
 * @return string|WP_Error    Feed content string, or WP_Error on failure
 */
function myfeeds_get_feed_content($feed_url, $feed_index, $run_id) {
    $cache_path = myfeeds_get_cache_path($feed_index, $run_id);
    
    // ── Cache Hit: Read from disk ──
    if ($cache_path !== null && file_exists($cache_path)) {
        $content = @file_get_contents($cache_path);
        if ($content !== false) {
            if (class_exists('MyFeeds_Logger')) {
                MyFeeds_Logger::info("FeedCache: HIT for feed_index={$feed_index} (" . strlen($content) . " bytes from cache)");
            }
            return $content;
        }
        // Cache file exists but unreadable — fall through to download
        if (class_exists('MyFeeds_Logger')) {
            MyFeeds_Logger::info("FeedCache: Cache file exists but unreadable, re-downloading");
        }
    }
    
    // ── Cache Miss: Download feed ──
    if (class_exists('MyFeeds_Logger')) {
        MyFeeds_Logger::info("FeedCache: MISS for feed_index={$feed_index}, downloading from {$feed_url}");
    }
    
    // An uploaded feed comes back from the disk: myfeeds_remote_get()
    // knows our own files and does not ask the web server for them.
    $response = myfeeds_remote_get($feed_url, array(
        'timeout' => 60,
        'headers' => array('Accept-Encoding' => 'gzip, deflate'),
    ));
    
    if (is_wp_error($response)) {
        $timed_out = myfeeds_feed_timeout_explained($response, 60);
        return $timed_out ? $timed_out : $response;
    }
    
    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        $explained = myfeeds_feed_http_explained($http_code, $feed_url);
        return $explained ? $explained : new WP_Error('http_error', "Feed returned HTTP {$http_code}");
    }
    
    $body = wp_remote_retrieve_body($response);
    
    // Handle gzip-compressed content (two detection methods):
    // a) URL ends on .gz or .csv.gz
    // b) Magic bytes \x1f\x8b at start of response
    $is_zip = myfeeds_is_zip_url($feed_url) || substr($body, 0, 4) === "PK\x03\x04";
    if ($is_zip) {
        $tmp_zip = wp_tempnam('myfeeds_zip_');
        if (!$tmp_zip || !@file_put_contents($tmp_zip, $body)) {
            return new WP_Error('write_error', 'Cannot stage the .zip archive for extraction.');
        }
        $tmp_out = $tmp_zip . '.out';
        $extracted = myfeeds_extract_zip_feed($tmp_zip, $tmp_out);
        wp_delete_file($tmp_zip);
        if (is_wp_error($extracted)) {
            @wp_delete_file($tmp_out);
            return $extracted;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
        $body = (string) @file_get_contents($tmp_out);
        wp_delete_file($tmp_out);
    }

    $is_gzip = myfeeds_is_gzip_url($feed_url) || substr($body, 0, 2) === "\x1f\x8b";
    if (!$is_zip && $is_gzip) {
        $decoded = function_exists('gzdecode') ? @gzdecode($body) : false;
        if ($decoded !== false) {
            $body = $decoded;
        } else {
            // Fallback: write to temp file and decompress via gzopen/gzread
            $tmp = wp_tempnam('myfeeds_gz_');
            if ($tmp && @file_put_contents($tmp, $body)) {
                $gz = @gzopen($tmp, 'rb');
                if ($gz) {
                    $decompressed = '';
                    while (!gzeof($gz)) {
                        $chunk = gzread($gz, 65536);
                        if ($chunk === false) break;
                        $decompressed .= $chunk;
                    }
                    gzclose($gz);
                    if (strlen($decompressed) > 0) {
                        $body = $decompressed;
                    }
                }
                wp_delete_file($tmp);
            }
        }
    }
    
    // ── Write to cache ──
    if ($cache_path !== null) {
        $cache_dir = dirname($cache_path);
        if (!is_dir($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }
        
        $written = @file_put_contents($cache_path, $body, LOCK_EX);
        if ($written !== false) {
            if (class_exists('MyFeeds_Logger')) {
                MyFeeds_Logger::info("FeedCache: Cached " . strlen($body) . " bytes to disk");
            }
        } else {
            // Cache write failed — continue without cache (fallback behavior)
            if (class_exists('MyFeeds_Logger')) {
                MyFeeds_Logger::info("FeedCache: WARNING — could not write cache file, continuing without cache");
            }
        }
    }
    
    return $body;
}

/**
 * Ensure feed is cached on disk via streaming download. Returns cache file PATH.
 * 
 * CRITICAL OOM FIX: Uses wp_remote_get with stream=true to download directly
 * to a temp file — the feed body NEVER enters PHP memory. This prevents
 * Fatal Errors on 40-350MB+ feeds.
 * 
 * Handles gzip-compressed feeds by decompressing in 64KB chunks via gzopen/gzread.
 * 
 * @param string $feed_url    The URL to download the feed from
 * @param int    $feed_index  Index of the feed in the import queue
 * @param string $run_id      Unique identifier for the current import run
 * @return string|WP_Error    Cache file path on disk, or WP_Error on failure
 */
function myfeeds_ensure_feed_cached($feed_url, $feed_index, $run_id) {
    $cache_path = myfeeds_get_cache_path($feed_index, $run_id);
    
    if ($cache_path === null) {
        return new WP_Error('no_cache_dir', 'No writable cache directory available');
    }
    
    // ── Cache Hit: File already on disk ──
    if (file_exists($cache_path) && filesize($cache_path) > 0) {
        if (class_exists('MyFeeds_Logger')) {
            MyFeeds_Logger::info("FeedCache: HIT for feed_index={$feed_index} (" . filesize($cache_path) . " bytes on disk)");
        }
        return $cache_path;
    }
    
    // ── Cache Miss: Stream download directly to disk (zero RAM) ──
    if (class_exists('MyFeeds_Logger')) {
        MyFeeds_Logger::info("FeedCache: MISS for feed_index={$feed_index}, streaming download from {$feed_url}");
    }
    
    $cache_dir = dirname($cache_path);
    if (!is_dir($cache_dir)) {
        wp_mkdir_p($cache_dir);
    }
    
    $temp_path = $cache_path . '.download';

    // Stream download directly to temp file — body never enters PHP memory.
    // An uploaded feed is copied from the disk by myfeeds_remote_get().
    $timeout = myfeeds_feed_download_timeout($feed_url);
    $response = myfeeds_remote_get($feed_url, array(
        'timeout' => $timeout,
        'stream'  => true,
        'filename' => $temp_path,
    ));
    
    if (is_wp_error($response)) {
        @wp_delete_file($temp_path);
        $timed_out = myfeeds_feed_timeout_explained($response, $timeout);
        return $timed_out ? $timed_out : $response;
    }
    
    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        @wp_delete_file($temp_path);
        $explained = myfeeds_feed_http_explained($http_code, $feed_url);
        return $explained ? $explained : new WP_Error('http_error', "Feed returned HTTP {$http_code}");
    }
    
    if (!file_exists($temp_path) || filesize($temp_path) === 0) {
        @wp_delete_file($temp_path);
        return new WP_Error('empty_download', 'Downloaded file is empty');
    }
    
    $download_size = filesize($temp_path);
    
    // ── Check for gzip-compressed content ──
    // Detection: a) URL ends on .gz / .csv.gz  b) Magic bytes \x1f\x8b
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming required for large feed files
    $fh = @fopen($temp_path, 'rb');
    if (!$fh) {
        @wp_delete_file($temp_path);
        return new WP_Error('file_read_error', 'Cannot read downloaded file');
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming required for large feed files
    $magic4 = fread($fh, 4);
    $magic  = substr($magic4, 0, 2);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming required for large feed files
    fclose($fh);
    
    $is_zip = myfeeds_is_zip_url($feed_url) || $magic4 === "PK\x03\x04";
    if ($is_zip) {
        $extracted = myfeeds_extract_zip_feed($temp_path, $cache_path);
        @wp_delete_file($temp_path);
        if (is_wp_error($extracted)) {
            return $extracted;
        }
        return $cache_path;
    }

    $is_gzip = myfeeds_is_gzip_url($feed_url) || $magic === "\x1f\x8b";
    if ($is_gzip) {
        // Decompress gzip to final cache path using streaming (64KB chunks)
        if (class_exists('MyFeeds_Logger')) {
            MyFeeds_Logger::info("FeedCache: Gzip detected ({$download_size} bytes), streaming decompression...");
        }
        
        $gz = @gzopen($temp_path, 'rb');
        if (!$gz) {
            @wp_delete_file($temp_path);
            return new WP_Error('gzip_error', 'Cannot open gzip file for decompression');
        }
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming required for large feed files
        $out = @fopen($cache_path, 'wb');
        if (!$out) {
            gzclose($gz);
            @wp_delete_file($temp_path);
            return new WP_Error('write_error', 'Cannot write decompressed cache file');
        }
        
        $bytes_written = 0;
        while (!gzeof($gz)) {
            $chunk = gzread($gz, 65536); // 64KB chunks
            if ($chunk === false) break;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming write for large feed cache files
            fwrite($out, $chunk);
            $bytes_written += strlen($chunk);
        }
        gzclose($gz);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming required for large feed files
        fclose($out);
        @wp_delete_file($temp_path);
        
        if (class_exists('MyFeeds_Logger')) {
            MyFeeds_Logger::info("FeedCache: Decompressed {$bytes_written} bytes to disk");
        }
    } else {
        // Not gzipped — move temp to cache via WP_Filesystem
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if (!$wp_filesystem->move($temp_path, $cache_path, true)) {
            @copy($temp_path, $cache_path);
            @wp_delete_file($temp_path);
        }
        
        if (class_exists('MyFeeds_Logger')) {
            MyFeeds_Logger::info("FeedCache: Cached {$download_size} bytes to disk (no gzip)");
        }
    }
    
    return $cache_path;
}

/**
 * Delete the cached feed file for a specific feed + run.
 * Called when a feed's processing is complete (is_complete=true).
 * 
 * @param int    $feed_index  Index of the completed feed
 * @param string $run_id      Import run identifier
 */
function myfeeds_cleanup_feed_cache($feed_index, $run_id) {
    $cache_path = myfeeds_get_cache_path($feed_index, $run_id);
    if ($cache_path !== null && file_exists($cache_path)) {
        wp_delete_file($cache_path);
        if (class_exists('MyFeeds_Logger')) {
            MyFeeds_Logger::info("FeedCache: Cleaned up cache for feed_index={$feed_index}");
        }
    }
}

/**
 * Delete ALL cached feed files for a given import run.
 * Called during complete_import() and cancel_import() for full cleanup.
 * 
 * @param string $run_id  Import run identifier
 */
function myfeeds_cleanup_all_feed_caches($run_id) {
    $cache_dir = myfeeds_get_cache_dir();
    if ($cache_dir === null || !is_dir($cache_dir)) {
        return;
    }
    
    $pattern = $cache_dir . '/myfeeds_feed_*_' . myfeeds_cache_run_token($run_id) . '.csv';
    $files = glob($pattern);
    
    if (!empty($files)) {
        foreach ($files as $file) {
            @wp_delete_file($file);
        }
        if (class_exists('MyFeeds_Logger')) {
            MyFeeds_Logger::info("FeedCache: Cleaned up " . count($files) . " cache files for run_id={$run_id}");
        }
    }
    
    // Also clean any orphaned cache files older than 24 hours
    myfeeds_cleanup_orphaned_caches();
}

/**
 * Every cache file shape the plugin has ever written into the cache dir.
 * The sweep looked for myfeeds_feed_*.csv only, so the myfeeds_content_*.txt
 * files an older version left behind were never anyone's job: 56 MB of them
 * sat on one of Marlon's sites from March until 2026-08-16.
 */
function myfeeds_cache_file_patterns() {
    // The third is a download that never finished: the half of a feed
    // a crashed import left next to the name its copy would have had.
    return array('myfeeds_feed_*.csv', 'myfeeds_content_*.txt', 'myfeeds_feed_*.csv.download');
}

/**
 * Cache files in $cache_dir last written before $threshold.
 *
 * @param string $cache_dir
 * @param int    $threshold Unix timestamp; older than this counts as stale
 * @return array file paths
 */
function myfeeds_stale_cache_files($cache_dir, $threshold) {
    $stale = array();

    foreach (myfeeds_cache_file_patterns() as $pattern) {
        $files = glob(rtrim($cache_dir, '/') . '/' . $pattern);
        if (empty($files)) {
            continue;
        }
        foreach ($files as $file) {
            if (filemtime($file) < $threshold) {
                $stale[] = $file;
            }
        }
    }

    return $stale;
}

/**
 * Remove orphaned cache files older than 24 hours.
 * Prevents disk space leaks from crashed imports that never completed.
 *
 * Runs when an import finishes and once a day with the housekeeping
 * (MyFeeds_Maintenance): the copy the mapping editor reads belongs to
 * no import, and on a site that imports nothing afterwards it used to
 * lie in the folder for good.
 *
 * @return int Files removed.
 */
function myfeeds_cleanup_orphaned_caches() {
    $cache_dir = myfeeds_get_cache_dir();
    if ($cache_dir === null || !is_dir($cache_dir)) {
        return 0;
    }

    $stale = myfeeds_stale_cache_files($cache_dir, time() - 86400);
    foreach ($stale as $file) {
        @wp_delete_file($file);
    }
    $cleaned = count($stale);

    if ($cleaned > 0 && class_exists('MyFeeds_Logger')) {
        MyFeeds_Logger::info("FeedCache: Cleaned {$cleaned} orphaned cache files (>24h old)");
    }

    return $cleaned;
}

// ─── Internal helpers (not part of public API) ───

/**
 * Get the full file path for a cached feed.
 * Returns null if no writable cache directory is available.
 * 
 * @param int    $feed_index
 * @param string $run_id
 * @return string|null
 */
function myfeeds_get_cache_path($feed_index, $run_id) {
    $cache_dir = myfeeds_get_cache_dir();
    if ($cache_dir === null) {
        return null;
    }
    
    return $cache_dir . '/myfeeds_feed_' . intval($feed_index) . '_' . myfeeds_cache_run_token($run_id) . '.csv';
}

/**
 * The part of a cache file's name that stands for its run.
 *
 * The copy of a feed lies under the uploads folder while an import
 * works through it, and a feed is what a network gave this publisher
 * and nobody else: tracking links with the publisher's id, on some
 * networks the commission per product. The run used to be named in the
 * clear - the md5 of the second an import started, and the plain word
 * "mapping" for the copy the mapping editor reads, which made that one
 * myfeeds_feed_0_mapping.csv on every site. The folder is closed
 * (myfeeds_close_cache_dir()), but a web server that does not read the
 * rule file hands out whatever is asked for by name. So the name is one
 * only this site can build.
 *
 * It stays the same name for the same run on the same site: the batches
 * of one import have to find the file the first batch downloaded.
 *
 * @param string $run_id
 * @return string 32 hex characters
 */
function myfeeds_cache_run_token($run_id) {
    $safe_run_id = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $run_id);
    return substr(hash_hmac('sha256', 'myfeeds_feed_cache|' . $safe_run_id, wp_salt('auth')), 0, 32);
}

/**
 * Close the cache folder to the web, with the rule file the folder for
 * uploaded feeds has.
 *
 * Nothing reads a cache copy over HTTP - the importer and the mapping
 * editor open it from the disk - so nobody has a reason to fetch one.
 * One writer for both folders: a second copy of the rules would be the
 * one that misses the next correction.
 *
 * @param string $dir Absolute path of the cache folder.
 * @return void
 */
function myfeeds_close_cache_dir($dir) {
    if (class_exists('MyFeeds_Feed_Upload')) {
        MyFeeds_Feed_Upload::harden($dir);
    }
}

/**
 * Determine the best writable directory for feed cache files.
 * Priority: WordPress uploads dir > system temp dir > null (no caching)
 * 
 * @return string|null Directory path, or null if no writable dir available
 */
function myfeeds_get_cache_dir() {
    static $cache_dir = false; // false = not yet determined
    
    if ($cache_dir !== false) {
        return $cache_dir;
    }
    
    // Option 1: Plugin-specific uploads subdirectory (most reliable on shared hosting)
    if (function_exists('wp_upload_dir') && function_exists('myfeeds_uploads_dir')) {
        $candidate = myfeeds_uploads_dir() . '/cache';
        if (is_dir($candidate) || wp_mkdir_p($candidate)) {
            // Only this one is under the web root; the temp folder below is not.
            myfeeds_close_cache_dir($candidate);
            $cache_dir = $candidate;
            return $cache_dir;
        }
    }
    
    // Option 2: System temp directory
    $tmp = sys_get_temp_dir();
    if ($tmp && wp_is_writable($tmp)) {
        $cache_dir = rtrim($tmp, '/');
        return $cache_dir;
    }
    
    // No writable directory — caching disabled
    $cache_dir = null;
    return null;
}
