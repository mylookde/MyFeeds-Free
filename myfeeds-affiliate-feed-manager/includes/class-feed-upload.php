<?php
/**
 * Uploading a feed file instead of pointing at a URL.
 *
 * Four networks - Impact, FlexOffers, Pepperjam, Rakuten - hand publishers
 * a file and no link. Without this they had to find their own hosting for
 * it before MyFeeds could do anything at all.
 *
 * The uploaded file is given a real URL under the plugin's own uploads
 * folder, and the feed entry stores that URL like any other. Nine places
 * in this plugin fetch a feed; making an upload a second kind of source
 * would mean teaching all nine about local paths, and the one that got
 * missed would fail quietly. A URL costs nothing to support because
 * everything already speaks it.
 *
 * What it cannot do is stay current. The nightly sync refetches the same
 * unchanged file forever, so prices freeze at the moment of upload. That
 * is a property of the method, not a bug, and the UI has to say so -
 * see MyFeeds_Feed_Upload::describe_staleness().
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Feed_Upload {

    /** Folder under the plugin uploads dir that holds uploaded feeds. */
    const SUBDIR = 'uploaded';

    /** Hard ceiling, independent of php.ini, so the error is ours and readable. */
    const MAX_BYTES = 524288000; // 500 MB

    /**
     * Extension => mime, covering every shape the ten supported networks
     * hand out: delimited text in four flavours, XML, JSON, JSON-Lines,
     * and the two compressions that actually occur in this market.
     *
     * @return array<string,string>
     */
    public static function allowed_types() {
        return array(
            'csv'    => 'text/csv',
            'tsv'    => 'text/tab-separated-values',
            'psv'    => 'text/plain',
            'ssv'    => 'text/plain',
            'txt'    => 'text/plain',
            'tab'    => 'text/plain',
            'xml'    => 'application/xml',
            'json'   => 'application/json',
            'jsonl'  => 'application/json',
            'ndjson' => 'application/json',
            'gz'     => 'application/gzip',
            'zip'    => 'application/zip',
        );
    }

    /**
     * Human list for the upload hint, so the field and this class can
     * never drift apart.
     *
     * @return string
     */
    public static function allowed_extensions_label() {
        return '.' . implode(', .', array_keys(self::allowed_types()));
    }

    /**
     * Absolute path of the folder that holds uploaded feeds.
     *
     * @return string
     */
    public static function dir() {
        $dir = trailingslashit(myfeeds_uploads_dir()) . self::SUBDIR;

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        self::harden($dir);

        return $dir;
    }

    /**
     * Public URL of that folder.
     *
     * @return string
     */
    public static function url() {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['baseurl']) . 'myfeeds/' . self::SUBDIR;
    }

    /**
     * Stop the folder from ever executing anything and from listing itself.
     *
     * The files in here are feeds, not code, and the extension whitelist
     * already keeps .php out. This is the second lock: a misconfigured
     * host that runs .txt through PHP, or a future extension added to the
     * list without thinking, both stop here.
     *
     * @param string $dir Absolute path.
     * @return void
     */
    private static function harden($dir) {
        $index = trailingslashit($dir) . 'index.html';
        if (!file_exists($index)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents($index, '');
        }

        $htaccess = trailingslashit($dir) . '.htaccess';
        if (!file_exists($htaccess)) {
            $rules = "Options -Indexes\n"
                   . "<Files *>\n"
                   . "  SetHandler default-handler\n"
                   . "</Files>\n"
                   . "php_flag engine off\n"
                   . "AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .phps\n";
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents($htaccess, $rules);
        }
    }

    /**
     * Is this feed URL one of our own uploads?
     *
     * @param string $url Feed URL.
     * @return bool
     */
    public static function is_uploaded_url($url) {
        if (!is_string($url) || $url === '') {
            return false;
        }
        return strpos($url, '/myfeeds/' . self::SUBDIR . '/') !== false;
    }

    /**
     * Take a $_FILES entry, validate it hard, and land it in our folder.
     *
     * @param array  $file      One entry from $_FILES.
     * @param string $feed_name Used only to build a readable filename.
     * @return array{url:string,path:string,original:string}|WP_Error
     */
    public static function handle($file, $feed_name = '') {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', __('You are not allowed to upload feeds.', 'myfeeds-affiliate-feed-manager'));
        }

        if (empty($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return new WP_Error('no_file', __('No file was uploaded.', 'myfeeds-affiliate-feed-manager'));
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', self::describe_php_error((int) $file['error']));
        }

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('not_an_upload', __('That file did not arrive as an upload.', 'myfeeds-affiliate-feed-manager'));
        }

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0) {
            return new WP_Error('empty_file', __('The uploaded file is empty.', 'myfeeds-affiliate-feed-manager'));
        }
        if ($size > self::MAX_BYTES) {
            return new WP_Error('too_large', sprintf(
                /* translators: %s: maximum size, already formatted */
                __('That file is larger than the %s limit for uploaded feeds. Use a feed URL instead - there is no size limit on those.', 'myfeeds-affiliate-feed-manager'),
                size_format(self::MAX_BYTES)
            ));
        }

        $allowed = self::allowed_types();
        $original = isset($file['name']) ? (string) $file['name'] : 'feed';
        $ext = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

        if (!isset($allowed[$ext])) {
            return new WP_Error('bad_extension', sprintf(
                /* translators: 1: the rejected extension, 2: list of accepted ones */
                __('MyFeeds cannot read a "%1$s" file. Accepted: %2$s', 'myfeeds-affiliate-feed-manager'),
                $ext === '' ? __('(no extension)', 'myfeeds-affiliate-feed-manager') : $ext,
                self::allowed_extensions_label()
            ));
        }

        $dir = self::dir();
        if (!is_writable($dir)) {
            return new WP_Error('not_writable', __('The uploads folder is not writable, so the feed could not be stored.', 'myfeeds-affiliate-feed-manager'));
        }

        $filename = self::build_filename($feed_name, $ext);
        $target   = trailingslashit($dir) . $filename;

        if (!@move_uploaded_file($file['tmp_name'], $target)) {
            return new WP_Error('move_failed', __('The uploaded file could not be moved into place.', 'myfeeds-affiliate-feed-manager'));
        }
        @chmod($target, 0644);

        $readable = self::verify_readable($target);
        if (is_wp_error($readable)) {
            @wp_delete_file($target);
            return $readable;
        }

        return array(
            'url'      => trailingslashit(self::url()) . $filename,
            'path'     => $target,
            'original' => sanitize_file_name($original),
        );
    }

    /**
     * A name that is readable in the folder but not guessable from outside.
     *
     * The random half matters: without it, anyone who knows a site runs
     * MyFeeds could fetch /uploads/myfeeds/uploaded/feed.csv and read a
     * competitor's whole catalogue, including the merchant terms some
     * networks put in it.
     *
     * @param string $feed_name Feed name as typed by the operator.
     * @param string $ext       Validated extension.
     * @return string
     */
    private static function build_filename($feed_name, $ext) {
        $slug = sanitize_title($feed_name);
        if ($slug === '') {
            $slug = 'feed';
        }
        $slug = substr($slug, 0, 40);

        return $slug . '-' . wp_generate_password(20, false, false) . '.' . $ext;
    }

    /**
     * Prove the file is a feed before accepting it.
     *
     * An extension is a claim, not evidence. Running the real reader over
     * it here means the operator finds out now, in the dialog, instead of
     * after a failed import - and it reuses the same archive and web-page
     * detection the URL path got, so the message is identical either way.
     *
     * @param string $path Absolute path to the stored file.
     * @return true|WP_Error
     */
    private static function verify_readable($path) {
        if (!class_exists('MyFeeds_Feed_Reader')) {
            $reader_file = MYFEEDS_PLUGIN_DIR . 'includes/class-feed-reader.php';
            if (file_exists($reader_file)) {
                require_once $reader_file;
            }
        }
        if (!class_exists('MyFeeds_Feed_Reader')) {
            return true;
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        // Compressed uploads are unpacked by the cache layer at import
        // time, exactly like a compressed URL. Checking them here would
        // mean unpacking a 500 MB archive inside a form submission.
        if ($ext === 'gz' || $ext === 'zip') {
            return true;
        }

        $reader = new MyFeeds_Feed_Reader();
        if (!$reader->open($path)) {
            $reason = method_exists($reader, 'get_unsupported_reason') ? $reader->get_unsupported_reason() : '';
            return new WP_Error('unreadable', $reason !== ''
                ? $reason
                : __('MyFeeds could not read that file as a product feed.', 'myfeeds-affiliate-feed-manager'));
        }

        $first = $reader->read_next();
        $reader->close();

        if (empty($first) || !is_array($first)) {
            return new WP_Error('no_rows', __('That file opened but contains no product rows.', 'myfeeds-affiliate-feed-manager'));
        }

        return true;
    }

    /**
     * Turn PHP's upload error codes into something a site owner can act on.
     *
     * @param int $code One of the UPLOAD_ERR_* constants.
     * @return string
     */
    private static function describe_php_error($code) {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return sprintf(
                    /* translators: %s: server upload limit, already formatted */
                    __('The file is bigger than this server accepts for uploads (%s). Ask your host to raise upload_max_filesize and post_max_size, or use a feed URL instead.', 'myfeeds-affiliate-feed-manager'),
                    size_format(wp_max_upload_size())
                );
            case UPLOAD_ERR_PARTIAL:
                return __('The upload was cut off before it finished. Try again.', 'myfeeds-affiliate-feed-manager');
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
                return __('The server could not store the upload. This is a hosting setting, not a MyFeeds one.', 'myfeeds-affiliate-feed-manager');
            default:
                return __('The upload failed.', 'myfeeds-affiliate-feed-manager');
        }
    }

    /**
     * Delete the stored file behind an uploaded feed.
     *
     * Called when a feed is removed or its file replaced; without it every
     * re-upload leaves the old catalogue lying in the uploads folder.
     *
     * @param string $url Feed URL.
     * @return void
     */
    public static function delete_by_url($url) {
        if (!self::is_uploaded_url($url)) {
            return;
        }

        $name = basename((string) wp_parse_url($url, PHP_URL_PATH));
        if ($name === '' || strpos($name, '..') !== false) {
            return;
        }

        $path = trailingslashit(self::dir()) . $name;
        if (file_exists($path)) {
            @wp_delete_file($path);
        }
    }

    /**
     * The sentence the feeds table shows instead of a sync promise.
     *
     * @param array $feed Feed entry.
     * @return string
     */
    public static function describe_staleness($feed) {
        $when = isset($feed['uploaded_at']) ? $feed['uploaded_at'] : '';
        if ($when === '') {
            return __('Uploaded file. It does not refresh on its own - upload it again to update prices and stock.', 'myfeeds-affiliate-feed-manager');
        }

        return sprintf(
            /* translators: %s: human readable time difference, e.g. "2 days" */
            __('Uploaded %s ago. It does not refresh on its own - upload it again to update prices and stock.', 'myfeeds-affiliate-feed-manager'),
            human_time_diff(strtotime($when), current_time('timestamp'))
        );
    }
}
