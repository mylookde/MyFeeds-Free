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
    
    $response = wp_remote_get($feed_url, array(
        'timeout' => 60,
        'headers' => array('Accept-Encoding' => 'gzip, deflate'),
    ));
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        return new WP_Error('http_error', "Feed returned HTTP {$http_code}");
    }
    
    $body = wp_remote_retrieve_body($response);
    
    // Handle gzip-compressed content (two detection methods):
    // a) URL ends on .gz or .csv.gz
    // b) Magic bytes \x1f\x8b at start of response
    $is_gzip = myfeeds_is_gzip_url($feed_url) || substr($body, 0, 2) === "\x1f\x8b";
    if ($is_gzip) {
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
    
    // Stream download directly to temp file — body never enters PHP memory
    $response = wp_remote_get($feed_url, array(
        'timeout' => 120,
        'stream'  => true,
        'filename' => $temp_path,
    ));
    
    if (is_wp_error($response)) {
        @wp_delete_file($temp_path);
        return $response;
    }
    
    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        @wp_delete_file($temp_path);
        return new WP_Error('http_error', "Feed returned HTTP {$http_code}");
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
    $magic = fread($fh, 2);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming required for large feed files
    fclose($fh);
    
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
    
    $safe_run_id = preg_replace('/[^a-zA-Z0-9_.-]/', '', $run_id);
    $pattern = $cache_dir . '/myfeeds_feed_*_' . $safe_run_id . '.csv';
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
 * Remove orphaned cache files older than 24 hours.
 * Prevents disk space leaks from crashed imports that never completed.
 */
function myfeeds_cleanup_orphaned_caches() {
    $cache_dir = myfeeds_get_cache_dir();
    if ($cache_dir === null || !is_dir($cache_dir)) {
        return;
    }
    
    $files = glob($cache_dir . '/myfeeds_feed_*.csv');
    $threshold = time() - 86400; // 24 hours
    $cleaned = 0;
    
    foreach ($files as $file) {
        if (filemtime($file) < $threshold) {
            @wp_delete_file($file);
            $cleaned++;
        }
    }
    
    if ($cleaned > 0 && class_exists('MyFeeds_Logger')) {
        MyFeeds_Logger::info("FeedCache: Cleaned {$cleaned} orphaned cache files (>24h old)");
    }
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
    
    $safe_run_id = preg_replace('/[^a-zA-Z0-9_.-]/', '', $run_id);
    return $cache_dir . '/myfeeds_feed_' . intval($feed_index) . '_' . $safe_run_id . '.csv';
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
    
    // Option 1: WordPress uploads directory (most reliable on shared hosting)
    if (function_exists('wp_upload_dir')) {
        $upload_dir = wp_upload_dir();
        $candidate = $upload_dir['basedir'] . '/myfeeds-cache';
        if (is_dir($candidate) || wp_mkdir_p($candidate)) {
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
