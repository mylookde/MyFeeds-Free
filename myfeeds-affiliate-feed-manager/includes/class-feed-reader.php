<?php
/**
 * MyFeeds Universal Feed Reader
 *
 * Abstracts CSV/TSV/SSV/PSV/XML/JSON/JSON-Lines feeds into a single
 * streaming interface. Memory-safe: uses fgetcsv() for delimited files
 * and XMLReader for XML (never loads entire file).
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Feed_Reader {

    /** @var string Detected format: csv|tsv|ssv|psv|xml|json|json_lines */
    private $format = '';

    /** @var string Path to the feed file on disk */
    private $file_path = '';

    // -- Delimited (CSV/TSV/SSV/PSV) state --
    /** @var resource|null File handle for delimited files */
    private $fh = null;

    /** @var string Delimiter character */
    private $delimiter = ',';

    /** @var array Header row (field names) */
    private $header = array();

    /** @var int Total data rows (excluding header) */
    private $total_items = -1;

    /** @var bool A Rakuten catalogue file: columns by position, no header row. */
    private $positional = false;

    /** @var string Advertiser name from a Rakuten catalogue's HDR record. */
    private $positional_merchant = '';

    // -- XML state --
    /** @var XMLReader|null */
    private $xml_reader = null;

    /** @var string Tag name of each product element (without namespace prefix) */
    private $xml_item_tag = '';

    /** @var int Items read so far (for skip_to / count tracking) */
    private $xml_items_read = 0;

    /** @var array|null Headers collected from the first XML item */
    private $xml_first_headers = null;

    /** @var array|null Pre-extracted XML items as strings (fallback for truncated XML) */
    private $xml_string_items = null;

    /** @var int Current index in xml_string_items */
    private $xml_string_index = 0;

    /** @var bool The XMLReader stands on a node nothing has looked at yet. */
    private $xml_on_unread_node = false;

    /** @var string '' or 'tradedoubler': a feed whose rows need their own reading. */
    private $xml_dialect = '';


    /** @var string Why the file could not be read, empty when it could. */
    private $unsupported_reason = '';

    // -- JSON state --
    // Reuses $fh. The file is walked, never loaded: one product is in
    // memory at a time, whatever the size of the feed.

    /** Keys a JSON feed puts its list of products under, best first. */
    const JSON_LIST_KEYS = array('products', 'items', 'data', 'results', 'offers', 'entries');

    /** Bytes read from the file per step. */
    const JSON_CHUNK = 262144;

    /** A file that is one object and no list is decoded whole, up to this size. */
    const JSON_SINGLE_OBJECT_MAX = 8388608;

    /** @var string Read-ahead of the JSON file */
    private $json_buf = '';

    /** @var int Position in $json_buf */
    private $json_pos = 0;

    /** @var int File offset of $json_buf[0] */
    private $json_base = 0;

    /** @var int File offset of the '[' that opens the list of products */
    private $json_list_at = 0;

    /** @var array|null The one row of a file that is a single object */
    private $json_single = null;

    // -- JSON-Lines state --
    // Reuses $fh from delimited section.

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Open a feed file and auto-detect (or use hinted) format.
     *
     * @param string $file_path Absolute path to the cached feed file.
     * @param string $format_hint Optional: csv, tsv, ssv, psv, xml, json, json_lines.
     * @return bool True on success.
     */
    public function open($file_path, $format_hint = '') {
        $this->file_path = $file_path;

        if (!file_exists($file_path)) {
            myfeeds_log("Feed Reader: File not found: {$file_path}", 'error');
            return false;
        }

        $binary = self::sniff_binary_container($file_path);
        if ($binary !== '') {
            $this->unsupported_reason = $binary;
            myfeeds_log("Feed Reader: {$binary}", 'error');
            return false;
        }

        $this->format = $this->detect_format($file_path, $format_hint);

        // A Rakuten catalogue says what it is in its first four bytes.
        // That beats a hint and the delimiter vote: an advertiser name
        // with commas in it out-votes the three pipes of the HDR record.
        if (self::starts_with_rakuten_header($file_path)) {
            $this->format = 'psv';
        }

        switch ($this->format) {
            case 'csv':
            case 'tsv':
            case 'ssv':
            case 'psv':
                return $this->open_delimited();

            case 'xml':
                return $this->open_xml();

            case 'json':
                return $this->open_json();

            case 'json_lines':
                return $this->open_json_lines();
        }

        myfeeds_log("Feed Reader: Unknown format '{$this->format}'", 'error');
        return false;
    }

    /**
     * Why the last open() failed, in words a site owner can act on.
     *
     * @return string Empty when the file opened fine.
     */
    public function get_unsupported_reason() {
        return $this->unsupported_reason;
    }

    /**
     * Recognise files that are not text at all.
     *
     * detect_format() ends on `return 'csv'`, so anything unrecognised
     * used to be parsed as CSV. An archive or a PDF then produced zero
     * products and no error - the operator saw an import that "worked"
     * and an empty catalogue. Naming the file type is the whole point.
     *
     * @param string $file_path Absolute path to the cached feed file.
     * @return string Empty when the file looks like text.
     */
    private static function sniff_binary_container($file_path) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Need raw read for signature detection
        $fh = @fopen($file_path, 'rb');
        if (!$fh) {
            return '';
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $head = (string) fread($fh, 32);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($fh);

        $signatures = array(
            "PK\x03\x04" => 'This feed is a .zip archive. Unpack it and point MyFeeds at the file inside, or ask your host to enable PHP zip support.',
            "PK\x05\x06" => 'This feed is an empty .zip archive.',
            "\x1f\x8b"    => 'This feed is still gzip-compressed and could not be unpacked. Point MyFeeds at the uncompressed file.',
            'BZh'         => 'This feed is a .bz2 archive, which MyFeeds cannot unpack. Extract it and use the file inside.',
            "Rar!"        => 'This feed is a .rar archive, which MyFeeds cannot unpack. Extract it and use the file inside.',
            "7z\xbc\xaf"  => 'This feed is a .7z archive, which MyFeeds cannot unpack. Extract it and use the file inside.',
            '%PDF'        => 'This file is a PDF, not a product feed. Check the feed URL.',
        );

        foreach ($signatures as $magic => $reason) {
            if (strncmp($head, $magic, strlen($magic)) === 0) {
                return $reason;
            }
        }

        // An HTML error page served with HTTP 200 is the other common
        // case: the network answered, just not with a feed.
        // Only <!DOCTYPE html is a web page; <!DOCTYPE products SYSTEM ...
        // is a perfectly good XML feed with a DTD.
        $lower = strtolower(ltrim($head));
        if (strncmp($lower, '<!doctype html', 14) === 0 || strncmp($lower, '<html', 5) === 0) {
            return 'The feed URL returned a web page, not a product feed. Check that the link points straight at the file and needs no login.';
        }

        return '';
    }

    /**
     * Return the field names (header row for delimited, keys of first item for XML/JSON).
     *
     * @return array
     */
    public function get_headers() {
        if ($this->format === 'xml') {
            return $this->xml_first_headers ?? array();
        }
        return $this->header;
    }

    /**
     * Count total product entries.
     *
     * @return int
     */
    public function count_items() {
        if ($this->total_items >= 0) {
            return $this->total_items;
        }

        switch ($this->format) {
            case 'csv':
            case 'tsv':
            case 'ssv':
            case 'psv':
                return $this->count_delimited();

            case 'xml':
                return $this->count_xml();

            case 'json':
                return $this->count_json();

            case 'json_lines':
                return $this->count_json_lines();
        }

        return 0;
    }

    /**
     * Skip to the n-th entry (0-based). Used for crash-resume.
     *
     * @param int $offset
     */
    public function skip_to($offset) {
        if ($offset <= 0) {
            return;
        }

        switch ($this->format) {
            case 'csv':
            case 'tsv':
            case 'ssv':
            case 'psv':
                for ($i = 0; $i < $offset; $i++) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
                    if (fgetcsv($this->fh, 0, $this->delimiter) === false) {
                        break;
                    }
                }
                break;

            case 'xml':
                if ($this->xml_string_items !== null) {
                    // Regex fallback: direct index jump (O(1) instead of O(n))
                    $this->xml_string_index = min($offset, count($this->xml_string_items));
                } else {
                    for ($i = 0; $i < $offset; $i++) {
                        if ($this->read_next_xml_item() === false) {
                            break;
                        }
                    }
                }
                break;

            case 'json':
                $this->skip_json($offset);
                break;

            case 'json_lines':
                for ($i = 0; $i < $offset; $i++) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
                    if (fgets($this->fh) === false) {
                        break;
                    }
                }
                break;
        }
    }

    /**
     * Read the next entry as an associative array.
     *
     * @return array|false Associative array or false at EOF.
     */
    public function read_next() {
        switch ($this->format) {
            case 'csv':
            case 'tsv':
            case 'ssv':
            case 'psv':
                return $this->read_next_delimited();

            case 'xml':
                return $this->read_next_xml_item();

            case 'json':
                $row = $this->read_next_json();
                return $row === false ? false : self::flatten_offer_row($row);

            case 'json_lines':
                $row = $this->read_next_json_lines();
                return $row === false ? false : self::flatten_offer_row($row);
        }

        return false;
    }

    /**
     * How far through the file we are, in bytes.
     *
     * count_items() is the honest way to say "row 5000 of 18943", but it
     * costs a second pass over the whole file before a single row is read -
     * measured at 1.9 seconds of a 5.6 second sync, with the bar frozen for
     * all of it. The read position needs no pass at all.
     *
     * Only formats that stream off a file handle can answer; the XML reader
     * has its own handle or may be walking a string, so it returns null and
     * the caller falls back to counting.
     *
     * @return array|null array(bytes read, bytes total) or null
     */
    public function byte_progress() {
        if (!$this->fh || !is_resource($this->fh)) {
            return null;
        }
        $total = @filesize($this->file_path);
        if (!$total) {
            return null;
        }
        $position = @ftell($this->fh);
        if ($position === false) {
            return null;
        }

        return array((int) $position, (int) $total);
    }

    /**
     * Close file handles and free resources.
     */
    public function close() {
        if ($this->fh && is_resource($this->fh)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($this->fh);
            $this->fh = null;
        }

        if ($this->xml_reader) {
            $this->xml_reader->close();
            $this->xml_reader = null;
        }

        $this->json_buf = '';
        $this->json_pos = 0;
        $this->json_base = 0;
        $this->json_single = null;

        $this->xml_string_items = null;
        $this->xml_string_index = 0;
        $this->xml_on_unread_node = false;
    }

    /**
     * Return the detected format string.
     *
     * @return string csv|tsv|ssv|psv|xml|json|json_lines
     */
    public function get_detected_format() {
        return $this->format;
    }

    // =========================================================================
    // FORMAT DETECTION
    // =========================================================================

    /**
     * Detect format from file content or use hint.
     */
    private function detect_format($file_path, $hint) {
        // Normalise common hint aliases
        if ($hint === 'csv_gz') {
            $hint = 'csv';
        }

        $valid = array('csv', 'tsv', 'ssv', 'psv', 'xml', 'json', 'json_lines');
        if ($hint && in_array($hint, $valid, true)) {
            return $hint;
        }

        // Read a much larger window (32 KB) — affiliate feed headers
        // with 80+ columns can easily push 3 KB on their own, and the
        // first data row may also exceed a few KB. A small sniff window
        // means the "first newline" we find is often inside a quoted
        // description, which then makes the delimiter vote include the
        // description text and pick the wrong character.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Need raw read for format detection
        $sniff_fh = fopen($file_path, 'r');
        if (!$sniff_fh) {
            return 'csv'; // Fallback
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $head = fread($sniff_fh, 32768);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($sniff_fh);

        // Strip UTF-8 BOM if present so the structural sniff below
        // looks at the actual content.
        if (strncmp($head, "\xEF\xBB\xBF", 3) === 0) {
            $head = substr($head, 3);
        }
        $head = ltrim($head);

        // XML detection
        if (substr($head, 0, 5) === '<?xml' || substr($head, 0, 1) === '<') {
            return 'xml';
        }

        // JSON detection
        if (substr($head, 0, 1) === '[') {
            return 'json';
        }
        if (substr($head, 0, 1) === '{') {
            // Could be JSON object or JSON-Lines — peek at second line
            $nl = strpos($head, "\n");
            if ($nl !== false && isset($head[$nl + 1]) && $head[$nl + 1] === '{') {
                return 'json_lines';
            }
            return 'json';
        }

        // Delimited — sniff the first UNQUOTED line so the vote runs
        // on the header row only, not on a body row that landed in
        // the sniff buffer with semicolons / pipes inside descriptions.
        $first_line = self::sniff_unquoted_first_line($head);

        $tab_count   = substr_count($first_line, "\t");
        $semi_count  = substr_count($first_line, ';');
        $pipe_count  = substr_count($first_line, '|');
        $comma_count = substr_count($first_line, ',');

        // Comma wins ties — CSV is by far the most common affiliate
        // feed format and tied counts almost always come from quoted
        // text leaking in.
        $max = max($tab_count, $semi_count, $pipe_count, $comma_count);
        if ($max === 0) {
            return 'csv';
        }
        if ($comma_count === $max) {
            return 'csv';
        }
        if ($tab_count === $max) {
            return 'tsv';
        }
        if ($semi_count === $max) {
            return 'ssv';
        }
        if ($pipe_count === $max) {
            return 'psv';
        }
        return 'csv';
    }

    /**
     * Return the bytes up to the first newline that is NOT inside a
     * double-quoted field. Walks the head buffer character-by-character
     * tracking quote state with an RFC4180-style "" escape rule.
     * Falls back to the full buffer (= old behaviour) if no unquoted
     * newline is found.
     */
    private static function sniff_unquoted_first_line($head) {
        $len = strlen($head);
        $in_quotes = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $head[$i];
            if ($c === '"') {
                if ($in_quotes && isset($head[$i + 1]) && $head[$i + 1] === '"') {
                    $i++; // Escaped quote inside quoted field.
                    continue;
                }
                $in_quotes = !$in_quotes;
                continue;
            }
            if (!$in_quotes && ($c === "\n" || $c === "\r")) {
                return substr($head, 0, $i);
            }
        }
        return $head;
    }

    // =========================================================================
    // RAKUTEN PRODUCT CATALOGUE (pipe-delimited, columns by position)
    // =========================================================================

    /**
     * Rakuten's catalogue file has no row of column names. It is
     *
     *   HDR|<advertiser id>|<advertiser name>|<mm/dd/yyyy hh:mm:ss>
     *   one line per product, fields by position
     *   TRL|<number of products>
     *
     * (Product Catalog Data Feed Implementation Guidelines, Appendix A
     * and D, read 2026-10-09). Read like any other delimited file, the
     * HDR record became a four-column header, every product line had a
     * different field count and was skipped as "malformed", and the
     * import ended with no products and no reason.
     *
     * @param array|false $first_row The file's first line, split on pipes.
     */
    private static function is_rakuten_header_record($first_row) {
        return is_array($first_row)
            && count($first_row) === 4
            && trim((string) $first_row[0], "\xEF\xBB\xBF \t") === 'HDR'
            && ctype_digit(trim((string) $first_row[1]));
    }

    private static function starts_with_rakuten_header($file_path) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Need raw read for format detection
        $fh = @fopen($file_path, 'r');
        if (!$fh) {
            return false;
        }
        $line = fgets($fh, 2048);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($fh);

        return is_string($line) && self::is_rakuten_header_record(explode('|', rtrim($line, "\r\n")));
    }

    /**
     * The 28 primary fields in the order Appendix A lists them, under
     * names the mapper already understands. Three are not Rakuten's own
     * words, on purpose:
     *
     * - `price` is "Retail Price" (required, before discounts) and
     *   `sale_price` is "Sale Price" (optional, after them). That is the
     *   pair every import path already reads the same way round.
     * - `discount_value` is "Discount". Called `discount` it would be
     *   read as a percentage, and it is an amount whenever
     *   `discount_type` says so.
     * - `cart_url` is "Buy URL", the shopping cart, so that it is never
     *   taken for the product link.
     *
     * @return string[]
     */
    private static function rakuten_catalogue_columns() {
        return array(
            'product_id', 'product_name', 'sku', 'primary_category', 'secondary_category',
            'product_url', 'product_image_url', 'cart_url', 'short_description', 'long_description',
            'discount_value', 'discount_type', 'sale_price', 'price', 'begin_date', 'end_date',
            'brand', 'shipping', 'keywords', 'manufacturer_part_number', 'manufacturer_name',
            'shipping_information', 'availability', 'upc', 'class_id', 'currency', 'm1', 'pixel',
        );
    }

    /** Appendix A: ten attribute fields close a record, 22 for credit cards. */
    const RAKUTEN_ATTRIBUTES = 10;

    /**
     * @return string[] The header a Rakuten catalogue is read under.
     */
    private function rakuten_header() {
        $header = self::rakuten_catalogue_columns();
        for ($i = 1; $i <= self::RAKUTEN_ATTRIBUTES; $i++) {
            $header[] = 'attribute_' . $i;
        }
        $header[] = 'merchant_name';
        return $header;
    }

    /**
     * One product line as a row, or null for a line that is not one.
     *
     * @param array $fields
     * @return array|null
     */
    private function rakuten_row(array $fields) {
        $primary = self::rakuten_catalogue_columns();
        $n = count($fields);

        if ($n <= 2 && isset($fields[0]) && trim((string) $fields[0]) === 'TRL') {
            return null;
        }
        // Up to the two prices, or there is nothing to make a product of.
        if ($n < 14) {
            return null;
        }

        // A delta file ends every record with one more field, Modification
        // (I, U or D): 39 fields, 51 for credit cards. A deleted product
        // is not a product.
        if (($n === 39 || $n === 51) && in_array($fields[$n - 1], array('I', 'U', 'D'), true)) {
            if ($fields[$n - 1] === 'D') {
                return null;
            }
            array_pop($fields);
            $n--;
        }

        $row = array();
        foreach ($primary as $i => $name) {
            $row[$name] = isset($fields[$i]) ? $fields[$i] : '';
        }
        $attributes = max(self::RAKUTEN_ATTRIBUTES, $n - count($primary));
        for ($i = 1; $i <= $attributes; $i++) {
            $at = count($primary) + $i - 1;
            $row['attribute_' . $i] = isset($fields[$at]) ? $fields[$at] : '';
        }
        $row['merchant_name'] = $this->positional_merchant;

        return $row;
    }

    // =========================================================================
    // DELIMITED (CSV / TSV / SSV / PSV)
    // =========================================================================

    private function open_delimited() {
        $delimiters = array(
            'csv' => ',',
            'tsv' => "\t",
            'ssv' => ';',
            'psv' => '|',
        );
        $this->delimiter = $delimiters[$this->format] ?? ',';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming required for large feed files
        $this->fh = fopen($this->file_path, 'r');
        if (!$this->fh) {
            myfeeds_log("Feed Reader: Cannot open delimited file: {$this->file_path}", 'error');
            return false;
        }

        // Read header via fgetcsv (handles quoted fields correctly)
        $header_row = fgetcsv($this->fh, 0, $this->delimiter);
        if ($header_row === false) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($this->fh);
            $this->fh = null;
            myfeeds_log('Feed Reader: Empty delimited file (no header)', 'error');
            return false;
        }

        if ($this->delimiter === '|' && self::is_rakuten_header_record($header_row)) {
            $this->positional = true;
            $this->positional_merchant = trim((string) $header_row[2]);
            $this->header = $this->rakuten_header();
            return true;
        }

        // Trim BOM and whitespace from header fields
        $this->header = array_map(function ($h) {
            return trim($h, "\xEF\xBB\xBF \t\n\r\0\x0B\"");
        }, $header_row);

        return true;
    }

    private function count_delimited() {
        if (!$this->fh) {
            return 0;
        }

        // Remember position after header
        $pos = ftell($this->fh);

        // Count remaining rows using fgetcsv (handles multiline fields!)
        $count = 0;
        while (($fields = fgetcsv($this->fh, 0, $this->delimiter)) !== false) {
            if ($this->positional && $this->rakuten_row($fields) === null) {
                continue;
            }
            $count++;
        }

        // Seek back
        fseek($this->fh, $pos);

        $this->total_items = $count;
        return $count;
    }

    private function read_next_delimited() {
        if (!$this->fh) {
            return false;
        }

        // Loop to skip malformed rows
        while (true) {
            $fields = fgetcsv($this->fh, 0, $this->delimiter);
            if ($fields === false) {
                return false; // EOF
            }

            if ($this->positional) {
                $row = $this->rakuten_row($fields);
                if ($row !== null) {
                    return $row;
                }
                continue;
            }

            if (count($fields) === count($this->header)) {
                return array_combine($this->header, $fields);
            }

            // Field count mismatch — skip row silently (common in dirty feeds)
        }
    }

    // =========================================================================
    // XML (Streaming via XMLReader)
    // =========================================================================

    private function open_xml() {
        // Detect the item element tag by scanning the first 50 KB
        $this->xml_item_tag = $this->detect_xml_item_tag();

        if (empty($this->xml_item_tag)) {
            myfeeds_log('Feed Reader: Could not detect XML item tag', 'error');
            return false;
        }

        myfeeds_log("Feed Reader: XML mode, item tag = <{$this->xml_item_tag}>", 'debug');

        // Try XMLReader first (fast, streaming, memory-efficient)
        $this->xml_on_unread_node = false;
        $this->xml_reader = new XMLReader();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- XMLReader requires direct file path
        if (!@$this->xml_reader->open($this->file_path)) {
            myfeeds_log("Feed Reader: XMLReader cannot open, falling back to regex", 'info');
            return $this->open_xml_regex_fallback();
        }

        $this->xml_items_read = 0;

        // Test: Try to read the first item with expand()
        $first = $this->read_next_xml_item_xmlreader();

        if ($first === false) {
            // XMLReader failed to read even one item — switch to regex fallback
            myfeeds_log("Feed Reader: XMLReader could not read first item, falling back to regex", 'info');
            $this->xml_reader->close();
            $this->xml_reader = null;
            return $this->open_xml_regex_fallback();
        }

        // XMLReader works! Use it for the rest
        $this->xml_first_headers = array_keys($first);

        // ALWAYS create a completely fresh XMLReader for the actual reading
        // This ensures no error state carries over from the header-reading phase
        $this->xml_reader->close();
        $this->xml_on_unread_node = false;
        $this->xml_reader = new XMLReader();
        if (!@$this->xml_reader->open($this->file_path)) {
            myfeeds_log("Feed Reader: XMLReader cannot reopen, falling back to regex", 'info');
            return $this->open_xml_regex_fallback();
        }
        $this->xml_items_read = 0;

        return true;
    }

    /**
     * Fallback: Open XML file using regex-based item extraction.
     * Used when XMLReader fails (truncated XML, namespace issues).
     */
    private function open_xml_regex_fallback() {
        // Close XMLReader if open
        if ($this->xml_reader) {
            $this->xml_reader->close();
            $this->xml_reader = null;
        }

        $this->xml_string_items = $this->extract_xml_items_by_regex();
        $this->xml_string_index = 0;
        $this->total_items = count($this->xml_string_items);

        if ($this->total_items === 0) {
            myfeeds_log("Feed Reader: Regex fallback found 0 items", 'error');
            return false;
        }

        // Read first item for headers
        $first = $this->read_next_xml_item_regex();
        if ($first !== false) {
            $this->xml_first_headers = array_keys($first);
        }

        // Reset index for actual reading
        $this->xml_string_index = 0;

        myfeeds_log("Feed Reader: Using regex fallback mode ({$this->total_items} items)", 'info');

        return true;
    }

    /**
     * Detect which XML element represents a product by scanning the head.
     */
    private function detect_xml_item_tag() {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Need raw read for tag detection
        $sniff_fh = fopen($this->file_path, 'r');
        if (!$sniff_fh) {
            return '';
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $head = fread($sniff_fh, 51200); // 50 KB
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($sniff_fh);

        // Tradedoubler's Products API nests <offer> inside <product>. By
        // the order below it would be read per offer: a link and a price
        // per row, and the name left behind on the parent.
        $this->xml_dialect = '';
        if (strpos($head, 'urn:com:tradedoubler:pf:model:xml') !== false) {
            $this->xml_dialect = 'tradedoubler';
            return 'product';
        }

        // Priority order — first match wins
        $candidates = array(
            'item',    // Google Shopping RSS, generic RSS
            'entry',   // Google Shopping Atom
            'offer',   // YML/Yandex (Admitad)
            'product', // Generic feeds, TradeDoubler (ns2:product matches localName 'product')
        );

        foreach ($candidates as $tag) {
            // Match <tag> or <tag  or <ns:tag> or <ns:tag 
            if (preg_match('/<(?:[a-zA-Z0-9]+:)?' . preg_quote($tag, '/') . '[\s>\/]/i', $head)) {
                return $tag;
            }
        }

        return '';
    }

    /**
     * Read the next XML item — routes to XMLReader or regex fallback.
     */
    private function read_next_xml_item() {
        // If using regex fallback mode
        if ($this->xml_string_items !== null) {
            return $this->read_next_xml_item_regex();
        }

        // Normal XMLReader mode
        return $this->read_next_xml_item_xmlreader();
    }

    /**
     * Read next item via XMLReader + expand() + DOM.
     * Used for well-formed XML files.
     */
    private function read_next_xml_item_xmlreader() {
        if (!$this->xml_reader) {
            return false;
        }

        while ($this->xml_step()) {
            if (
                $this->xml_reader->nodeType === XMLReader::ELEMENT
                && $this->xml_reader->localName === $this->xml_item_tag
            ) {
                $dom_node = @$this->xml_reader->expand();

                if ($dom_node === false) {
                    $this->xml_on_unread_node = @$this->xml_reader->next();
                    continue;
                }

                $doc = new DOMDocument();
                $imported = @$doc->importNode($dom_node, true);
                if (!$imported) {
                    $this->xml_on_unread_node = @$this->xml_reader->next();
                    continue;
                }
                @$doc->appendChild($imported);

                $node = @simplexml_import_dom($doc->documentElement);
                if (!$node) {
                    $this->xml_on_unread_node = @$this->xml_reader->next();
                    continue;
                }

                $item = $this->xml_item_to_row($node);
                $this->xml_items_read++;

                $this->xml_on_unread_node = @$this->xml_reader->next();
                return $item;
            }
        }

        return false;
    }

    /**
     * Move the XMLReader on by one node.
     *
     * next() steps over an item and lands on whatever follows it. In a
     * feed written without whitespace between its items - what an API
     * usually sends - that is the next item itself, and a read() on top
     * of it steps inside and past it: every second product was missing,
     * from the import and from the count alike.
     *
     * @return bool False at the end of the file.
     */
    private function xml_step() {
        if ($this->xml_on_unread_node) {
            $this->xml_on_unread_node = false;
            return true;
        }
        return @$this->xml_reader->read();
    }

    /**
     * One item element as a row.
     *
     * @param SimpleXMLElement $node
     * @return array
     */
    private function xml_item_to_row($node) {
        if ($this->xml_dialect === 'tradedoubler') {
            $element = dom_import_simplexml($node);
            if ($element) {
                return self::flatten_offer_row(self::tradedoubler_product($element));
            }
        }
        return $this->xml_node_to_array($node);
    }

    /**
     * Read next item from pre-extracted regex strings.
     * Used for truncated XML files where XMLReader fails.
     */
    private function read_next_xml_item_regex() {
        if ($this->xml_string_items === null) {
            return false;
        }

        while ($this->xml_string_index < count($this->xml_string_items)) {
            $xml_string = $this->xml_string_items[$this->xml_string_index];
            $this->xml_string_index++;

            $node = @simplexml_load_string($xml_string);
            if (!$node) {
                continue; // Skip unparseable items
            }

            // Get the actual item node (first child of _root wrapper)
            $children = $node->children();
            if (count($children) === 0) {
                // Try with namespaces — the item might be in a namespace
                foreach ($node->getNamespaces(true) as $prefix => $uri) {
                    if (!empty($prefix)) {
                        continue;
                    }
                    $children = $node->children($uri);
                    if (count($children) > 0) {
                        break;
                    }
                }
            }
            $item_node = count($children) > 0 ? $children[0] : $node;

            $item = $this->xml_item_to_row($item_node);
            $this->xml_items_read++;

            if (!empty($item)) {
                return $item;
            }
        }

        return false;
    }

    /**
     * Extract XML item elements from file content using regex.
     * Fallback for when XMLReader fails due to truncated XML.
     * Only loads file content once, extracts all complete item blocks.
     *
     * @return array Array of XML strings, one per item element (wrapped in _root with namespaces)
     */
    private function extract_xml_items_by_regex() {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Need full content for regex extraction
        $content = @file_get_contents($this->file_path);
        if (empty($content)) {
            return array();
        }

        $tag = preg_quote($this->xml_item_tag, '/');
        $items = array();

        // Extract all root-level namespace declarations for wrapping
        $root_ns = '';
        if (preg_match('/<[^>]+\s(xmlns[^>]+)>/s', $content, $ns_match)) {
            // Get all xmlns:xxx="..." and xmlns="..." declarations
            preg_match_all('/xmlns(?::[a-zA-Z0-9]+)?="[^"]*"/', $ns_match[1], $ns_attrs);
            if (!empty($ns_attrs[0])) {
                $root_ns = ' ' . implode(' ', array_unique($ns_attrs[0]));
            }
        }

        // Match complete <tag>...</tag> blocks (non-greedy)
        // Pattern handles: <entry>...</entry>, <item>...</item>, <offer ...>...</offer>
        $pattern = '/<(?:[a-zA-Z0-9]+:)?' . $tag . '(?:\s[^>]*)?>.*?<\/(?:[a-zA-Z0-9]+:)?' . $tag . '\s*>/s';

        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[0] as $item_xml) {
                // Wrap each item with root namespaces so SimpleXML can resolve prefixes
                $items[] = '<_root' . $root_ns . '>' . $item_xml . '</_root>';
            }
        }

        unset($content); // Free memory

        myfeeds_log("Feed Reader: Regex fallback extracted " . count($items) . " <{$this->xml_item_tag}> items", 'info');

        return $items;
    }

    /**
     * Convert a SimpleXMLElement to a flat associative array.
     *
     * Handles: attributes, child elements, nested children (flattened),
     * repeated elements, namespaced children (g:id → id), and YML <param name="X">Y</param>.
     */
    private function xml_node_to_array($node) {
        $result = array();

        // 1. Attributes of the element (e.g. <offer id="123" available="true">)
        foreach ($node->attributes() as $attr_name => $attr_value) {
            $result[(string) $attr_name] = (string) $attr_value;
        }

        // 2. Non-namespaced child elements
        foreach ($node->children() as $child) {
            $tag = $child->getName();

            // Special case: <param name="X">Y</param> (YML/Yandex)
            if ($tag === 'param' && isset($child['name'])) {
                $param_name  = (string) $child['name'];
                $param_value = (string) $child;
                $result['param_' . $param_name] = $param_value;
                continue;
            }

            if ($child->count() > 0) {
                // Nested element → flatten: tag_subtag
                foreach ($child->children() as $grandchild) {
                    $sub_tag = $grandchild->getName();
                    $result[$tag . '_' . $sub_tag] = (string) $grandchild;
                }
            } else {
                $value = (string) $child;
                // Handle repeated elements (e.g. multiple <picture>)
                if (isset($result[$tag])) {
                    if (!is_array($result[$tag])) {
                        $result[$tag] = array($result[$tag]);
                    }
                    $result[$tag][] = $value;
                } else {
                    $result[$tag] = $value;
                }
            }
        }

        // 3. Namespaced child elements (g:id, g:title, ns2:name, etc.)
        $namespaces = $node->getNamespaces(true);
        foreach ($namespaces as $prefix => $uri) {
            if (empty($prefix)) {
                continue; // Default namespace already handled above
            }
            foreach ($node->children($uri) as $child) {
                $tag = $child->getName(); // Returns "id", "title" — without prefix

                if ($child->count() > 0) {
                    // Nested namespaced elements (e.g. g:shipping/g:country)
                    foreach ($child->children($uri) as $grandchild) {
                        $sub_tag = $grandchild->getName();
                        if (!isset($result[$tag . '_' . $sub_tag])) {
                            $result[$tag . '_' . $sub_tag] = (string) $grandchild;
                        }
                    }
                    // Also check non-namespaced children
                    foreach ($child->children() as $grandchild) {
                        $sub_tag = $grandchild->getName();
                        if (!isset($result[$tag . '_' . $sub_tag])) {
                            $result[$tag . '_' . $sub_tag] = (string) $grandchild;
                        }
                    }
                } else {
                    $value = (string) $child;
                    if (!empty($value) || $value === '0') {
                        if (isset($result[$tag])) {
                            if (!is_array($result[$tag])) {
                                $result[$tag] = array($result[$tag]);
                            }
                            $result[$tag][] = $value;
                        } else {
                            $result[$tag] = $value;
                        }
                    }
                }
            }
        }

        return $result;
    }

    private function count_xml() {
        // If regex fallback is active, count is already known
        if ($this->xml_string_items !== null) {
            $this->total_items = count($this->xml_string_items);
            return $this->total_items;
        }

        // Use a fresh XMLReader to count items
        $counter = new XMLReader();
        if (!@$counter->open($this->file_path)) {
            return 0;
        }

        // Same walk as xml_step(): after next() the counter already stands
        // on the following node.
        $count = 0;
        $more = @$counter->read();
        while ($more) {
            if (
                $counter->nodeType === XMLReader::ELEMENT
                && $counter->localName === $this->xml_item_tag
            ) {
                $count++;
                $more = @$counter->next();
                continue;
            }
            $more = @$counter->read();
        }
        @$counter->close();

        $this->total_items = $count;
        return $count;
    }

    // =========================================================================
    // JSON (Array)
    // =========================================================================

    /**
     * Open a JSON feed without loading it.
     *
     * This used to be file_get_contents() and json_decode() over the
     * whole file, refused above 50 MB. A decoded feed takes several
     * times its size in memory, so the limit was never the safe side
     * of anything - a 40 MB file could end the import with a fatal
     * error, and a 60 MB one was turned away with a line in the log
     * and "Cannot open cached feed file" on the screen.
     *
     * The file is now walked: find the list of products, then hand out
     * one element at a time. A file cut off in the middle - the first
     * 32 KB the feed test downloads - yields every product that is
     * complete.
     */
    private function open_json() {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming required for large feed files
        $this->fh = @fopen($this->file_path, 'rb');
        if (!$this->fh) {
            myfeeds_log("Feed Reader: Cannot open JSON file: {$this->file_path}", 'error');
            return false;
        }

        $this->json_single = null;
        $list = $this->json_find_list();

        if ($list === false) {
            myfeeds_log('Feed Reader: JSON file does not start with an object or a list', 'error');
            return false;
        }

        if ($list === null) {
            return $this->open_json_single_object();
        }

        $this->json_list_at = $list['at'];
        $this->total_items  = $list['count'];

        $this->json_rewind();
        $first = $this->read_next_json();
        if (is_array($first)) {
            $this->header = array_keys(self::flatten_offer_row($first));
        }
        $this->json_rewind();

        return true;
    }

    /**
     * A JSON file that is one object and holds no list we know: the
     * object is the one row, as it always was.
     */
    private function open_json_single_object() {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($this->fh);
        $this->fh = null;

        $size = (int) @filesize($this->file_path);
        if ($size > self::JSON_SINGLE_OBJECT_MAX) {
            $this->unsupported_reason = 'This JSON feed has no list of products MyFeeds recognises. It looks for one named '
                . implode(', ', self::JSON_LIST_KEYS) . '.';
            myfeeds_log("Feed Reader: {$this->unsupported_reason}", 'error');
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Small file, decoded whole
        $data = json_decode((string) @file_get_contents($this->file_path), true);
        if (!is_array($data)) {
            myfeeds_log('Feed Reader: JSON decode failed or not an array', 'error');
            return false;
        }

        $this->json_single = array($data);
        $this->total_items = 1;
        $this->header      = array_keys(self::flatten_offer_row($data));

        return true;
    }

    /**
     * Where the list of products starts.
     *
     * A file that is a list is that list. A file that is an object is
     * searched for the keys feeds use, in the order of JSON_LIST_KEYS -
     * the order they were tried in when the file was still decoded
     * whole - and the first that holds a non-empty list wins.
     *
     * @return array{at:int,count:int}|null|false Null: an object without
     *         such a list. False: not JSON we can read. A count of -1
     *         means "not counted yet".
     */
    private function json_find_list() {
        $this->json_seek(0);
        $c = $this->json_peek();

        if ($c === '[') {
            return array('at' => $this->json_offset(), 'count' => -1);
        }
        if ($c !== '{') {
            return false;
        }

        $this->json_pos++;
        $found = array();

        while (true) {
            $c = $this->json_peek();
            if ($c === ',') {
                $this->json_pos++;
                continue;
            }
            if ($c !== '"') {
                break;
            }
            $raw_key = $this->json_take_value(true);
            $key = $raw_key === null ? null : json_decode($raw_key);
            if (!is_string($key) || $this->json_peek() !== ':') {
                break;
            }
            $this->json_pos++;

            if ($this->json_peek() === '[' && in_array($key, self::JSON_LIST_KEYS, true) && !isset($found[$key])) {
                $at    = $this->json_offset();
                $count = $this->json_count_list();
                if ($count > 0) {
                    $found[$key] = array('at' => $at, 'count' => $count);
                    if ($key === self::JSON_LIST_KEYS[0]) {
                        break;
                    }
                }
                continue;
            }

            if ($this->json_take_value(false) === null) {
                break;
            }
        }

        foreach (self::JSON_LIST_KEYS as $key) {
            if (isset($found[$key])) {
                return $found[$key];
            }
        }
        return null;
    }

    private function count_json() {
        if ($this->total_items >= 0) {
            return $this->total_items;
        }
        if (!$this->fh) {
            return 0;
        }

        $back_to = $this->json_offset();
        $this->json_seek($this->json_list_at);
        $this->json_peek();
        $this->total_items = $this->json_count_list();
        $this->json_seek($back_to);

        return $this->total_items;
    }

    private function skip_json($offset) {
        if ($this->json_single !== null) {
            if ($offset > 0) {
                $this->json_single = array();
            }
            return;
        }
        if (!$this->fh) {
            return;
        }
        for ($i = 0; $i < $offset; $i++) {
            $c = $this->json_next_element_start();
            if ($c === '' || $this->json_take_value(false) === null) {
                break;
            }
        }
    }

    private function read_next_json() {
        if ($this->json_single !== null) {
            $row = array_shift($this->json_single);
            return $row === null ? false : $row;
        }
        if (!$this->fh) {
            return false;
        }

        while (true) {
            if ($this->json_next_element_start() === '') {
                return false;
            }
            $raw = $this->json_take_value(true);
            if ($raw === null) {
                return false; // cut off inside this element
            }
            $item = json_decode($raw, true);
            if (is_array($item)) {
                return $item;
            }
            // A bare value in a list of products is not a product.
        }
    }

    /** Put the read position on the first element of the list. */
    private function json_rewind() {
        $this->json_seek($this->json_list_at);
        if ($this->json_peek() === '[') {
            $this->json_pos++;
        }
    }

    /**
     * Step over separators to the start of the next element.
     *
     * @return string Its first byte, or '' when the list has ended. The
     *                closing bracket is not consumed, so the answer
     *                stays the same however often it is asked.
     */
    private function json_next_element_start() {
        while (true) {
            $c = $this->json_peek();
            if ($c === ',') {
                $this->json_pos++;
                continue;
            }
            return ($c === ']') ? '' : $c;
        }
    }

    /**
     * Count the elements of the list the read position stands on, and
     * leave the position behind it. An element the file ends inside of
     * is not counted.
     */
    private function json_count_list() {
        $this->json_pos++;
        $count = 0;
        while (true) {
            $c = $this->json_peek();
            if ($c === ',') {
                $this->json_pos++;
                continue;
            }
            if ($c === ']') {
                $this->json_pos++;
                break;
            }
            if ($c === '' || $this->json_take_value(false) === null) {
                break;
            }
            $count++;
        }
        return $count;
    }

    private function json_seek($offset) {
        fseek($this->fh, $offset);
        $this->json_buf  = '';
        $this->json_pos  = 0;
        $this->json_base = $offset;
    }

    private function json_offset() {
        return $this->json_base + $this->json_pos;
    }

    private function json_more() {
        if (!$this->fh || feof($this->fh)) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming required for large feed files
        $chunk = fread($this->fh, self::JSON_CHUNK);
        if ($chunk === false || $chunk === '') {
            return false;
        }
        $this->json_buf .= $chunk;
        return true;
    }

    /**
     * Skip whitespace and a byte-order mark.
     *
     * The only place the buffer is cut: between two values, where no
     * one holds a position inside it.
     *
     * @return string The next byte, not consumed; '' at the end of the file.
     */
    private function json_peek() {
        while (true) {
            if ($this->json_pos > 65536) {
                $this->json_buf  = (string) substr($this->json_buf, $this->json_pos);
                $this->json_base += $this->json_pos;
                $this->json_pos  = 0;
            }
            $this->json_pos += strspn($this->json_buf, " \t\r\n\xEF\xBB\xBF", $this->json_pos);
            if (isset($this->json_buf[$this->json_pos])) {
                return $this->json_buf[$this->json_pos];
            }
            if (!$this->json_more()) {
                return '';
            }
        }
    }

    /**
     * Read one JSON value - object, list, string or bare word - off the
     * file and move behind it.
     *
     * Only quotes and brackets are looked at; everything between them
     * is skipped by strcspn(), so walking a feed costs about as much as
     * reading it.
     *
     * @param bool $capture Return the value's text. Counting and
     *                      skipping do not need it.
     * @return string|null Null when the file ends inside the value.
     */
    private function json_take_value($capture) {
        $c = $this->json_peek();
        if ($c === '') {
            return null;
        }
        $start = $this->json_pos;

        if ($c === '"') {
            if (!$this->json_skip_string()) {
                return null;
            }
        } elseif ($c === '{' || $c === '[') {
            $depth = 0;
            while (true) {
                $this->json_pos += strcspn($this->json_buf, '"{}[]', $this->json_pos);
                if (!isset($this->json_buf[$this->json_pos])) {
                    if (!$this->json_more()) {
                        return null;
                    }
                    continue;
                }
                $ch = $this->json_buf[$this->json_pos];
                if ($ch === '"') {
                    if (!$this->json_skip_string()) {
                        return null;
                    }
                    continue;
                }
                $this->json_pos++;
                if ($ch === '{' || $ch === '[') {
                    $depth++;
                } elseif (--$depth === 0) {
                    break;
                }
            }
        } else {
            while (true) {
                $this->json_pos += strcspn($this->json_buf, ",]}: \t\r\n", $this->json_pos);
                if (isset($this->json_buf[$this->json_pos]) || !$this->json_more()) {
                    break;
                }
            }
            if ($this->json_pos === $start) {
                return null;
            }
        }

        return $capture ? substr($this->json_buf, $start, $this->json_pos - $start) : '';
    }

    /**
     * Move behind the string the read position stands on. A quote is
     * the end of it unless an odd number of backslashes come before.
     */
    private function json_skip_string() {
        $open = $this->json_pos;
        $from = $open + 1;
        while (true) {
            $quote = strpos($this->json_buf, '"', $from);
            if ($quote === false) {
                $from = strlen($this->json_buf);
                if (!$this->json_more()) {
                    return false;
                }
                continue;
            }
            $slashes = 0;
            for ($k = $quote - 1; $k > $open && $this->json_buf[$k] === '\\'; $k--) {
                $slashes++;
            }
            if ($slashes % 2 === 0) {
                $this->json_pos = $quote + 1;
                return true;
            }
            $from = $quote + 1;
        }
    }

    // =========================================================================
    // PRODUCTS THAT KEEP THEIR OFFERS ONE LEVEL DOWN (Tradedoubler)
    // =========================================================================

    /**
     * Turn a product with nested offers into the flat row everything
     * after the reader expects.
     *
     * Tradedoubler's Products API answers with a product - name,
     * picture, description - and puts what a card is made of under
     * offers[]: the id, the tracked link, the price as a history of
     * changes, the stock. The importer looks for an id on the top level
     * of a row and skipped every product for not having one.
     *
     * One offer is chosen (pick_offer) and its values are lifted to the
     * top; id, price and link always come from the same offer. Nothing
     * nested is left behind, because nothing downstream reads below the
     * top level.
     *
     * A row is only touched when it has offers that carry `productUrl`.
     * Every other row passes through as it came.
     *
     * @param mixed $row
     * @return mixed
     */
    public static function flatten_offer_row($row) {
        if (!is_array($row) || empty($row['offers']) || !is_array($row['offers'])) {
            return $row;
        }

        $offers = array();
        foreach ($row['offers'] as $offer) {
            if (is_array($offer) && !empty($offer['productUrl']) && is_string($offer['productUrl'])) {
                $offers[] = $offer;
            }
        }
        if (empty($offers)) {
            return $row;
        }

        $offer = self::pick_offer($offers);
        $flat  = array();

        foreach ($row as $key => $value) {
            if (is_scalar($value)) {
                $flat[$key] = $value;
            }
        }
        foreach ($offer as $key => $value) {
            if (is_scalar($value)) {
                $flat[$key] = $value;
            }
        }

        $price = self::offer_price($offer);
        unset($flat['price'], $flat['previousPrice']);
        if ($price['value'] !== null) {
            $flat['price'] = $price['value'];
        }
        if ($price['currency'] !== '') {
            $flat['currency'] = $price['currency'];
        }
        if ($price['previous'] !== null) {
            $flat['previousPrice'] = $price['previous'];
        }

        if (isset($row['productImage']['url']) && is_string($row['productImage']['url'])) {
            $flat['productImage'] = $row['productImage']['url'];
        }

        if (!empty($row['categories']) && is_array($row['categories'])) {
            foreach (array('name', 'tdCategoryName') as $part) {
                foreach ($row['categories'] as $category) {
                    if (is_array($category) && !empty($category[$part]) && is_scalar($category[$part])) {
                        if (!isset($flat['category'])) {
                            $flat['category'] = (string) $category[$part];
                        }
                        if ($part === 'tdCategoryName' && !isset($flat['tdCategoryName'])) {
                            $flat['tdCategoryName'] = (string) $category[$part];
                        }
                        break;
                    }
                }
            }
        }

        if (!empty($row['identifiers']) && is_array($row['identifiers'])) {
            foreach ($row['identifiers'] as $key => $value) {
                if (is_scalar($value) && !isset($flat[$key])) {
                    $flat[$key] = $value;
                }
            }
        }

        // fields[] is whatever the advertiser called its extras. Lifted
        // under their own names, one called store_price or
        // merchant_deep_link would be taken for the price or the link.
        if (!empty($row['fields']) && is_array($row['fields'])) {
            $attributes = array('color', 'colour', 'size', 'gender', 'material');
            foreach ($row['fields'] as $field) {
                if (!is_array($field) || !isset($field['name'], $field['value']) || !is_scalar($field['name']) || !is_scalar($field['value'])) {
                    continue;
                }
                $name  = trim((string) $field['name']);
                $lower = strtolower($name);
                if ($name === '') {
                    continue;
                }
                if (in_array($lower, $attributes, true)) {
                    if (!isset($flat[$lower])) {
                        $flat[$lower] = $field['value'];
                    }
                    continue;
                }
                $flat['field_' . $name] = $field['value'];
            }
        }

        return $flat;
    }

    /**
     * The offer a card shows: one that can be bought before one that
     * cannot, then the cheapest, then the first in the feed. An offer
     * without a price comes last.
     *
     * With `fid` in the address a product has one offer and there is
     * nothing to choose. Several appear when Tradedoubler groups the
     * same product across advertisers.
     *
     * @param array $offers Non-empty list of offers.
     * @return array
     */
    private static function pick_offer(array $offers) {
        $best = null;
        $best_rank = null;
        foreach (array_values($offers) as $position => $offer) {
            $price  = self::offer_price($offer);
            $amount = is_numeric($price['value']) ? (float) $price['value'] : 0.0;
            $rank   = array(
                self::offer_in_stock($offer) ? 0 : 1,
                $amount > 0 ? 0 : 1,
                $amount,
                $position,
            );
            if ($best_rank === null || $rank < $best_rank) {
                $best = $offer;
                $best_rank = $rank;
            }
        }
        return $best;
    }

    /**
     * Can this offer be bought? Unknown counts as yes, as it does for
     * every other feed.
     */
    private static function offer_in_stock(array $offer) {
        if (isset($offer['inStock']) && is_numeric($offer['inStock'])) {
            return ((float) $offer['inStock']) > 0;
        }
        $said = (isset($offer['availability']) && is_scalar($offer['availability']))
            ? strtolower(trim((string) $offer['availability']))
            : '';
        if ($said === '') {
            return true;
        }
        if (in_array($said, array('no', 'n', 'false', '0'), true)) {
            return false;
        }
        return !preg_match('/out|sold|unavail|not avail|discontinu|pre-?order|back-?order|ausverkauft|nicht/', $said);
    }

    /**
     * An offer's price, its currency, and the price to strike through.
     *
     * The documentation lists a `price` field; its example response
     * has only priceHistory, "all price changes during the life of the
     * product". Both are read. The latest change is the current price.
     *
     * The price to strike through is the lowest one that applied in the
     * thirty days before that change, and only if it was higher - the
     * reference a shop in the EU must use itself when it announces a
     * reduction. 49.90, then 79.90, then 54.90 inside a month is not
     * "was 79.90". Without dates there is no telling, and no old price.
     *
     * @param array $offer
     * @return array{value:mixed,currency:string,previous:mixed}
     */
    private static function offer_price(array $offer) {
        $out = array('value' => null, 'currency' => '', 'previous' => null);

        $history = array();
        $dated   = true;
        if (!empty($offer['priceHistory']) && is_array($offer['priceHistory'])) {
            foreach ($offer['priceHistory'] as $entry) {
                if (!is_array($entry) || !isset($entry['price'])) {
                    continue;
                }
                $raw      = is_array($entry['price']) ? (isset($entry['price']['value']) ? $entry['price']['value'] : null) : $entry['price'];
                $currency = is_array($entry['price']) && isset($entry['price']['currency']) ? $entry['price']['currency']
                          : (isset($entry['currency']) ? $entry['currency'] : '');
                if (!is_numeric($raw)) {
                    continue;
                }
                $time = self::offer_time(isset($entry['date']) ? $entry['date'] : null);
                if ($time === null) {
                    $dated = false;
                }
                $history[] = array('raw' => $raw, 'amount' => (float) $raw, 'currency' => is_scalar($currency) ? (string) $currency : '', 'time' => $time);
            }
        }
        if ($dated && count($history) > 1) {
            usort($history, function ($a, $b) {
                return $a['time'] <=> $b['time'];
            });
        }
        $latest = empty($history) ? null : $history[count($history) - 1];

        if (isset($offer['price'])) {
            if (is_array($offer['price'])) {
                if (isset($offer['price']['value']) && is_numeric($offer['price']['value'])) {
                    $out['value'] = $offer['price']['value'];
                }
                if (isset($offer['price']['currency']) && is_scalar($offer['price']['currency'])) {
                    $out['currency'] = (string) $offer['price']['currency'];
                }
            } elseif (is_numeric($offer['price'])) {
                $out['value'] = $offer['price'];
            }
        }
        if ($out['value'] === null && $latest !== null) {
            $out['value'] = $latest['raw'];
        }
        if ($out['currency'] === '' && $latest !== null) {
            $out['currency'] = $latest['currency'];
        }
        if ($out['currency'] === '' && isset($offer['currency']) && is_scalar($offer['currency'])) {
            $out['currency'] = (string) $offer['currency'];
        }

        if ($dated && count($history) > 1 && is_numeric($out['value'])) {
            $window_opens = $latest['time'] - 30 * 86400;
            $lowest = null;
            for ($i = count($history) - 2; $i >= 0; $i--) {
                if ($lowest === null || $history[$i]['amount'] < $lowest['amount']) {
                    $lowest = $history[$i];
                }
                if ($history[$i]['time'] <= $window_opens) {
                    break;
                }
            }
            if ($lowest !== null && $lowest['amount'] > (float) $out['value']) {
                $out['previous'] = $lowest['raw'];
            }
        }

        return $out;
    }

    /**
     * A date from the API as a Unix time. Milliseconds since 1970 by
     * default, ISO 8601 when the address asks for it.
     *
     * @param mixed $date
     * @return int|null
     */
    private static function offer_time($date) {
        if (is_numeric($date)) {
            $n = (float) $date;
            return (int) ($n > 100000000000 ? $n / 1000 : $n);
        }
        if (is_string($date) && $date !== '') {
            $time = strtotime($date);
            return $time === false ? null : $time;
        }
        return null;
    }

    /**
     * A <product> of Tradedoubler's XML in the shape of its JSON.
     *
     * The same data, laid out differently: identifiers are plain
     * children, a category's name and an offer's id are attributes, a
     * price is an element whose date and currency are attributes. Once
     * it has the JSON's shape, one function flattens both.
     *
     * @param DOMElement $product
     * @return array
     */
    private static function tradedoubler_product($product) {
        $row = self::dom_attributes($product);

        foreach (self::dom_elements($product) as $child) {
            switch ($child->localName) {
                case 'offers':
                    $row['offers'] = array();
                    foreach (self::dom_elements($child) as $offer_el) {
                        $offer = self::dom_attributes($offer_el);
                        if (isset($offer['modifiedDate'])) {
                            $offer['modified'] = $offer['modifiedDate'];
                        }
                        foreach (self::dom_elements($offer_el) as $part) {
                            if ($part->localName !== 'priceHistory') {
                                $offer[$part->localName] = trim($part->textContent);
                                continue;
                            }
                            foreach (self::dom_elements($part) as $price) {
                                $offer['priceHistory'][] = array(
                                    'price' => array(
                                        'value'    => trim($price->textContent),
                                        'currency' => $price->getAttribute('currency'),
                                    ),
                                    'date'  => $price->getAttribute('date'),
                                );
                            }
                        }
                        $row['offers'][] = $offer;
                    }
                    break;

                case 'categories':
                    foreach (self::dom_elements($child) as $category) {
                        $row['categories'][] = self::dom_attributes($category);
                    }
                    break;

                case 'fields':
                    foreach (self::dom_elements($child) as $field) {
                        $row['fields'][] = array(
                            'name'  => $field->getAttribute('name'),
                            'value' => trim($field->textContent),
                        );
                    }
                    break;

                default:
                    $row[$child->localName] = trim($child->textContent);
            }
        }

        return $row;
    }

    private static function dom_attributes($element) {
        $out = array();
        if ($element->hasAttributes()) {
            foreach ($element->attributes as $attribute) {
                $out[$attribute->localName] = $attribute->value;
            }
        }
        return $out;
    }

    private static function dom_elements($element) {
        $out = array();
        foreach ($element->childNodes as $node) {
            if ($node->nodeType === XML_ELEMENT_NODE) {
                $out[] = $node;
            }
        }
        return $out;
    }

    // =========================================================================
    // JSON-Lines (one JSON object per line)
    // =========================================================================

    private function open_json_lines() {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming required for JSON-Lines
        $this->fh = fopen($this->file_path, 'r');
        if (!$this->fh) {
            myfeeds_log("Feed Reader: Cannot open JSON-Lines file: {$this->file_path}", 'error');
            return false;
        }

        // Read first line to build header
        $first_line = fgets($this->fh);
        if ($first_line === false) {
            return false;
        }

        $first_obj = json_decode(trim($first_line), true);
        if (is_array($first_obj)) {
            $this->header = array_keys($first_obj);
        }

        // Rewind so read_next starts from line 1
        rewind($this->fh);

        return true;
    }

    private function count_json_lines() {
        if (!$this->fh) {
            return 0;
        }

        $pos = ftell($this->fh);
        rewind($this->fh);

        $count = 0;
        while (fgets($this->fh) !== false) {
            $count++;
        }

        fseek($this->fh, $pos);

        $this->total_items = $count;
        return $count;
    }

    private function read_next_json_lines() {
        if (!$this->fh) {
            return false;
        }

        while (true) {
            $line = fgets($this->fh);
            if ($line === false) {
                return false; // EOF
            }

            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $obj = json_decode($line, true);
            if (is_array($obj)) {
                return $obj;
            }
            // Malformed line — skip
        }
    }
}
