<?php
/**
 * The snapshot file behind an API-source feed.
 *
 * An API source is an upload that refreshes itself: the fetcher writes
 * what the API returned into a JSON Lines file in the upload folder, the
 * feed entry points at that file's URL, and from there on every part of
 * the plugin reads it the way it reads an uploaded feed.
 *
 * Two rules keep the weekly full import honest, because that import
 * marks every product missing from the file as unavailable:
 *
 *   1. A run writes to `<file>.part` and only an atomic rename makes it
 *      the snapshot. An aborted run leaves the previous snapshot in place.
 *   2. A run that cannot have reached everything (an advertiser above
 *      the API's result cap) carries rows it did not see over from the
 *      previous snapshot, twice, before letting them go.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Source_Snapshot {

    const EXTENSION = 'jsonl';
    const PART      = '.part';

    /** Runs a row survives without being seen before it is dropped. */
    const CARRY_RUNS = 2;

    /** @var string|null Tests point the folder somewhere writable. */
    public static $dir_override = null;

    /** @var string|null Tests give the folder a URL without WordPress. */
    public static $url_override = null;

    /**
     * Folder that holds snapshots: the same folder uploads live in, so
     * the fetch layer's own-file short cut applies unchanged.
     *
     * @return string
     */
    public static function dir() {
        if (self::$dir_override !== null) {
            return self::$dir_override;
        }
        return MyFeeds_Feed_Upload::dir();
    }

    /**
     * @param string $filename
     * @return string Public URL, the form the feed entry stores.
     */
    public static function url_for($filename) {
        $base = self::$url_override !== null ? self::$url_override : MyFeeds_Feed_Upload::url();
        return trailingslashit($base) . $filename;
    }

    /**
     * @param string $filename
     * @return string Absolute path.
     */
    public static function path_for($filename) {
        return trailingslashit(self::dir()) . $filename;
    }

    /**
     * A name that is readable in the folder but not guessable from
     * outside - the random half is what keeps a stranger from reading a
     * competitor's catalogue.
     *
     * @param string $network
     * @param string $label
     * @return string
     */
    public static function new_filename($network, $label) {
        $slug = strtolower((string) $label);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string) $slug, '-');
        if ($slug === '') {
            $slug = 'feed';
        }
        $slug = substr($slug, 0, 40);

        $network = preg_replace('/[^a-z0-9]+/', '', strtolower((string) $network));

        return $network . '-' . $slug . '-' . wp_generate_password(20, false, false) . '.' . self::EXTENSION;
    }

    /**
     * @param string $path Snapshot path.
     * @return string Path of the file a run writes to.
     */
    public static function part_path($path) {
        return $path . self::PART;
    }

    /**
     * @param string $path Snapshot path.
     * @return string Path of the file that lists the ids a run has seen.
     */
    public static function ids_path($path) {
        return $path . '.ids';
    }

    /**
     * Remember the ids one page produced. The set can reach a few hundred
     * thousand entries for a large advertiser; a line per id in a file
     * next to the part costs nothing per page, an option rewritten on
     * every page would.
     *
     * @param string   $path Snapshot path.
     * @param string[] $ids
     * @return void
     */
    public static function append_ids($path, array $ids) {
        if (empty($ids)) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $fh = @fopen(self::ids_path($path), 'ab');
        if (!$fh) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($fh, implode("\n", array_map('strval', $ids)) . "\n");
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($fh);
    }

    /**
     * @param string $path Snapshot path.
     * @return array<string,int> id => 1 for every id the run has seen.
     */
    public static function read_ids($path) {
        $set = array();
        $ids = self::ids_path($path);
        if (!is_readable($ids)) {
            return $set;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $fh = @fopen($ids, 'rb');
        if (!$fh) {
            return $set;
        }
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line !== '') {
                $set[$line] = 1;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($fh);

        return $set;
    }

    /**
     * Open the part file for appending. A fresh run truncates; a resumed
     * step appends.
     *
     * @param string $path     Snapshot path.
     * @param bool   $truncate
     * @return resource|WP_Error
     */
    public static function open_part($path, $truncate = false) {
        $dir = dirname($path);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('myfeeds_source_write', __('The uploads folder could not be created.', 'myfeeds-affiliate-feed-manager'));
        }

        if ($truncate) {
            @wp_delete_file(self::ids_path($path));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming append to a snapshot
        $fh = @fopen(self::part_path($path), $truncate ? 'wb' : 'ab');
        if (!$fh) {
            return new WP_Error('myfeeds_source_write', __('The snapshot file could not be written. Check that the uploads folder is writable.', 'myfeeds-affiliate-feed-manager'));
        }

        return $fh;
    }

    /**
     * Append rows as JSON Lines. Every row carries every column in the
     * fixed order - the reader builds its header from the first line.
     *
     * @param resource $fh
     * @param array<int,array<string,string>> $rows
     * @return int Bytes written.
     */
    public static function append_rows($fh, array $rows) {
        $written = 0;
        foreach ($rows as $row) {
            $line = self::encode_row($row);
            if ($line === '') {
                continue;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            $n = fwrite($fh, $line . "\n");
            if ($n !== false) {
                $written += $n;
            }
        }
        fflush($fh);

        return $written;
    }

    /**
     * @param array<string,string> $row
     * @return string One line, no newline; '' when the row cannot be encoded.
     */
    public static function encode_row(array $row) {
        $ordered = array();
        foreach (MyFeeds_Rakuten_Rows::COLUMNS as $column) {
            $ordered[$column] = isset($row[$column]) ? (string) $row[$column] : '';
        }

        $line = wp_json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($line) || $line === '') {
            // A row with broken UTF-8 must not poison the file. Scrub and
            // try once more; give up on the row, never on the file.
            foreach ($ordered as $key => $value) {
                $ordered[$key] = self::scrub($value);
            }
            $line = wp_json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return is_string($line) ? str_replace(array("\r", "\n"), ' ', $line) : '';
    }

    /**
     * Rows of the previous snapshot this run did not see, appended to the
     * part with their carry count raised. Rows carried too often are let
     * go, which is when the weekly import marks them unavailable.
     *
     * @param string   $previous Path of the snapshot being replaced.
     * @param resource $fh       Open part handle.
     * @param array    $seen     id => true for every id this run fetched.
     * @return array{carried:int,dropped:int}
     */
    public static function carry_over($previous, $fh, array $seen) {
        $carried = 0;
        $dropped = 0;

        if (!is_readable($previous)) {
            return array('carried' => 0, 'dropped' => 0);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $in = @fopen($previous, 'rb');
        if (!$in) {
            return array('carried' => 0, 'dropped' => 0);
        }

        $batch = array();
        while (($line = fgets($in)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row) || !isset($row['id']) || (string) $row['id'] === '') {
                continue;
            }
            if (isset($seen[(string) $row['id']])) {
                continue;
            }

            $count = isset($row['carried']) && $row['carried'] !== '' ? (int) $row['carried'] : 0;
            $count++;
            if ($count > self::CARRY_RUNS) {
                $dropped++;
                continue;
            }

            $row['carried'] = (string) $count;
            $batch[] = MyFeeds_Rakuten_Rows::backfill($row);
            $carried++;

            if (count($batch) >= 500) {
                self::append_rows($fh, $batch);
                $batch = array();
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($in);

        if (!empty($batch)) {
            self::append_rows($fh, $batch);
        }

        return array('carried' => $carried, 'dropped' => $dropped);
    }

    /**
     * Make the part the snapshot. rename() within one folder is atomic
     * on every filesystem WordPress runs on, so a reader sees either the
     * old file or the new one, never a half.
     *
     * @param string $path Snapshot path.
     * @return true|WP_Error
     */
    public static function commit($path) {
        $part = self::part_path($path);

        if (!file_exists($part) || filesize($part) === 0) {
            @wp_delete_file($part);
            @wp_delete_file(self::ids_path($path));
            return new WP_Error('myfeeds_source_empty', __('The fetch produced no rows, so the previous snapshot was kept.', 'myfeeds-affiliate-feed-manager'));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- an atomic swap in one folder; WP_Filesystem::move() copies and deletes where the method is not direct.
        if (!@rename($part, $path)) {
            @wp_delete_file($part);
            @wp_delete_file(self::ids_path($path));
            return new WP_Error('myfeeds_source_write', __('The snapshot could not be moved into place.', 'myfeeds-affiliate-feed-manager'));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- the renamed part keeps fopen's mode; the importer reads the snapshot like an upload.
        @chmod($path, 0644);
        @wp_delete_file(self::ids_path($path));

        return true;
    }

    /**
     * Append a discovery's part to the live snapshot and remove the part.
     * Streamed, because either file may be tens of megabytes.
     *
     * @param string $work     Work path of the discovery (its part is $work.part).
     * @param string $snapshot Snapshot path.
     * @return true|WP_Error
     */
    public static function append_part_to($work, $snapshot) {
        $part = self::part_path($work);
        if (!file_exists($part) || filesize($part) === 0) {
            self::discard($work);
            return new WP_Error('myfeeds_source_empty', __('The search produced no rows.', 'myfeeds-affiliate-feed-manager'));
        }

        // The snapshot must end on a newline before rows are appended. An
        // append-only handle cannot be read from, so the last byte is
        // looked at before the handle is opened.
        $needs_newline = false;
        $size = file_exists($snapshot) ? (int) filesize($snapshot) : 0;
        if ($size > 0) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
            $tail = @file_get_contents($snapshot, false, null, $size - 1, 1);
            $needs_newline = $tail !== "\n";
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $in = @fopen($part, 'rb');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = @fopen($snapshot, 'ab');
        if (!$in || !$out) {
            if ($in) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($in);
            }
            if ($out) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($out);
            }
            return new WP_Error('myfeeds_source_write', __('The snapshot could not be extended.', 'myfeeds-affiliate-feed-manager'));
        }

        if ($needs_newline) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            fwrite($out, "\n");
        }

        while (!feof($in)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streamed in chunks, the part can be tens of megabytes.
            $chunk = fread($in, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            fwrite($out, $chunk);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($in);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($out);

        self::discard($work);

        return true;
    }

    /**
     * Throw the part away; the previous snapshot stays.
     *
     * @param string $path Snapshot path.
     * @return void
     */
    public static function discard($path) {
        foreach (array(self::part_path($path), self::ids_path($path)) as $file) {
            if (file_exists($file)) {
                @wp_delete_file($file);
            }
        }
    }

    /**
     * @param string $path Snapshot path.
     * @return void
     */
    public static function delete($path) {
        self::discard($path);
        if (file_exists($path)) {
            @wp_delete_file($path);
        }
    }

    /**
     * Category names across a snapshot, with how many rows carry each -
     * primary category and every step of the secondary path. Streamed,
     * a snapshot can be fifty megabytes.
     *
     * @param string $path
     * @return array<string,int> name => rows
     */
    public static function categories($path) {
        $counts = array();
        if (!is_readable($path)) {
            return $counts;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $in = @fopen($path, 'rb');
        if (!$in) {
            return $counts;
        }

        $batch = array();
        while (($line = fgets($in)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $batch[] = array(
                'primarycategory'   => isset($row['primarycategory']) ? $row['primarycategory'] : '',
                'secondarycategory' => isset($row['secondarycategory']) ? $row['secondarycategory'] : '',
            );
            if (count($batch) >= 2000) {
                foreach (MyFeeds_Rakuten_Source::categories_in_rows($batch) as $name => $n) {
                    $counts[$name] = isset($counts[$name]) ? $counts[$name] + $n : $n;
                }
                $batch = array();
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($in);

        if (!empty($batch)) {
            foreach (MyFeeds_Rakuten_Source::categories_in_rows($batch) as $name => $n) {
                $counts[$name] = isset($counts[$name]) ? $counts[$name] + $n : $n;
            }
        }
        arsort($counts);

        return $counts;
    }

    /**
     * @param string $path
     * @return int Rows in a snapshot (non-empty lines).
     */
    public static function count_rows($path) {
        if (!is_readable($path)) {
            return 0;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $in = @fopen($path, 'rb');
        if (!$in) {
            return 0;
        }
        $count = 0;
        while (($line = fgets($in)) !== false) {
            if (trim($line) !== '') {
                $count++;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($in);

        return $count;
    }

    /**
     * @param string $value
     * @return string Valid UTF-8, invalid sequences dropped.
     */
    private static function scrub($value) {
        $value = (string) $value;
        if (function_exists('mb_convert_encoding')) {
            $clean = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            if (is_string($clean)) {
                return $clean;
            }
        }
        return (string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x{80}-\x{10FFFF}]/u', '', $value);
    }
}
