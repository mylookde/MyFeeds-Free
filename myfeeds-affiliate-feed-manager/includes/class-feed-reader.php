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


    // -- JSON state --
    /** @var array All decoded items (JSON array mode) */
    private $json_items = array();

    /** @var int Current index in json_items */
    private $json_index = 0;

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

        $this->format = $this->detect_format($file_path, $format_hint);

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
                $this->total_items = count($this->json_items);
                return $this->total_items;

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
                $this->json_index = min($offset, count($this->json_items));
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
                return $this->read_next_json();

            case 'json_lines':
                return $this->read_next_json_lines();
        }

        return false;
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

        $this->json_items = array();
        $this->json_index = 0;

        $this->xml_string_items = null;
        $this->xml_string_index = 0;
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

        // Read first 4 KB for sniffing
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Need raw read for format detection
        $sniff_fh = fopen($file_path, 'r');
        if (!$sniff_fh) {
            return 'csv'; // Fallback
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $head = fread($sniff_fh, 4096);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($sniff_fh);

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

        // Delimited — sniff first line for delimiter
        $first_line_end = strpos($head, "\n");
        $first_line = $first_line_end !== false ? substr($head, 0, $first_line_end) : $head;

        $tab_count   = substr_count($first_line, "\t");
        $semi_count  = substr_count($first_line, ';');
        $pipe_count  = substr_count($first_line, '|');
        $comma_count = substr_count($first_line, ',');

        if ($tab_count > $comma_count && $tab_count > $semi_count && $tab_count > $pipe_count) {
            return 'tsv';
        }
        if ($semi_count > $comma_count && $semi_count > $tab_count && $semi_count > $pipe_count) {
            return 'ssv';
        }
        if ($pipe_count > $comma_count && $pipe_count > $tab_count && $pipe_count > $semi_count) {
            return 'psv';
        }

        return 'csv';
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
        while (fgetcsv($this->fh, 0, $this->delimiter) !== false) {
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

        while (@$this->xml_reader->read()) {
            if (
                $this->xml_reader->nodeType === XMLReader::ELEMENT
                && $this->xml_reader->localName === $this->xml_item_tag
            ) {
                $dom_node = @$this->xml_reader->expand();

                if ($dom_node === false) {
                    @$this->xml_reader->next();
                    continue;
                }

                $doc = new DOMDocument();
                $imported = @$doc->importNode($dom_node, true);
                if (!$imported) {
                    @$this->xml_reader->next();
                    continue;
                }
                @$doc->appendChild($imported);

                $node = @simplexml_import_dom($doc->documentElement);
                if (!$node) {
                    @$this->xml_reader->next();
                    continue;
                }

                $item = $this->xml_node_to_array($node);
                $this->xml_items_read++;

                @$this->xml_reader->next();
                return $item;
            }
        }

        return false;
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

            $item = $this->xml_node_to_array($item_node);
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

        $count = 0;
        while (@$counter->read()) {
            if (
                $counter->nodeType === XMLReader::ELEMENT
                && $counter->localName === $this->xml_item_tag
            ) {
                $count++;
                @$counter->next();
            }
        }
        @$counter->close();

        $this->total_items = $count;
        return $count;
    }

    // =========================================================================
    // JSON (Array)
    // =========================================================================

    private function open_json() {
        $size = filesize($this->file_path);
        if ($size > 50 * 1024 * 1024) { // 50 MB guard
            myfeeds_log("Feed Reader: JSON file too large ({$size} bytes), aborting", 'error');
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Need full JSON for decode
        $raw = file_get_contents($this->file_path);
        $data = json_decode($raw, true);
        unset($raw);

        if (!is_array($data)) {
            myfeeds_log('Feed Reader: JSON decode failed or not an array', 'error');
            return false;
        }

        // If it's a JSON object with a nested array, try common keys
        if (!isset($data[0])) {
            foreach (array('products', 'items', 'data', 'results', 'offers', 'entries') as $key) {
                if (isset($data[$key]) && is_array($data[$key]) && isset($data[$key][0])) {
                    $data = $data[$key];
                    break;
                }
            }
        }

        if (!isset($data[0])) {
            // Single object — wrap it
            $data = array($data);
        }

        $this->json_items = $data;
        $this->json_index = 0;
        $this->total_items = count($data);

        // Build header from first item
        if (!empty($data[0]) && is_array($data[0])) {
            $this->header = array_keys($data[0]);
        }

        return true;
    }

    private function read_next_json() {
        if ($this->json_index >= count($this->json_items)) {
            return false;
        }

        return $this->json_items[$this->json_index++];
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
