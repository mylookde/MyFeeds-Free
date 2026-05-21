<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product Picker Class
 * Handles Gutenberg block for product selection and frontend display
 */
class MyFeeds_Product_Picker {
    
    public function init() {
        add_action('init', [$this, 'register_block']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets'], 99);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
    }
    
    /**
     * Register Gutenberg block
     */
    public function register_block() {
        if (class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("BLOCK DEBUG: Starting Gutenberg block registration...");
        }
        
        // Check if required files exist
        $script_path = MYFEEDS_PLUGIN_DIR . 'build/index.js';
        $editor_css_path = MYFEEDS_PLUGIN_DIR . 'assets/editor.css';
        $frontend_css_path = MYFEEDS_PLUGIN_DIR . 'assets/style.css';
        
        if (!file_exists($script_path)) {
            if (class_exists('MyFeeds_External_Debug')) {
                MyFeeds_External_Debug::log("BLOCK DEBUG: JavaScript file missing: " . $script_path);
            }
            return;
        }
        
        if (!file_exists($editor_css_path)) {
            if (class_exists('MyFeeds_External_Debug')) {
                MyFeeds_External_Debug::log("BLOCK DEBUG: Editor CSS file missing: " . $editor_css_path);
            }
        }
        
        if (!file_exists($frontend_css_path)) {
            if (class_exists('MyFeeds_External_Debug')) {
                MyFeeds_External_Debug::log("BLOCK DEBUG: Frontend CSS file missing: " . $frontend_css_path);
            }
        }
        
        // Resolve versions with filemtime for cache-busting (safe, no URL changes)
        $script_ver = file_exists($script_path) ? filemtime($script_path) : MYFEEDS_VERSION;
        $editor_css_ver = file_exists($editor_css_path) ? filemtime($editor_css_path) : MYFEEDS_VERSION;
        $frontend_css_ver = file_exists($frontend_css_path) ? filemtime($frontend_css_path) : MYFEEDS_VERSION;

        if (class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("BLOCK DEBUG: Registering script with version: " . $script_ver);
        }

        // Register block script
        $script_registered = wp_register_script(
            'myfeeds-product-picker-editor',
            MYFEEDS_PLUGIN_URL . 'build/index.js',
            ['wp-blocks', 'wp-components', 'wp-element'],
            $script_ver,
            true
        );
        
        if (!$script_registered && class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("BLOCK DEBUG: Failed to register JavaScript!");
        }
        
        // Register block styles
        $editor_style_registered = wp_register_style(
            'myfeeds-product-picker-editor',
            MYFEEDS_PLUGIN_URL . 'assets/editor.css',
            [],
            $editor_css_ver
        );
        
        if (!$editor_style_registered && class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("BLOCK DEBUG: Failed to register editor CSS!");
        }
        
        wp_register_style(
            'myfeeds-product-picker-frontend',
            MYFEEDS_PLUGIN_URL . 'assets/style.css',
            [],
            $frontend_css_ver
        );
        
        if (class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("BLOCK DEBUG: Attempting to register block type 'myfeeds/product-picker'...");
        }
        
        $block_registered = register_block_type('myfeeds/product-picker', [
            'editor_script' => 'myfeeds-product-picker-editor',
            'editor_style' => 'myfeeds-product-picker-editor',
            'style' => 'myfeeds-product-picker-frontend',
            'render_callback' => [$this, 'render_callback'],
            'attributes' => [
                'selectedProducts' => [
                    'type' => 'array',
                    'default' => [],
                ],
            ],
        ]);
        
        if ($block_registered && class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("BLOCK DEBUG: Block 'myfeeds/product-picker' registered successfully!");
        } elseif (!$block_registered && class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("BLOCK DEBUG: Failed to register block 'myfeeds/product-picker'!");
        }
    }
    
    public function enqueue_editor_assets() {
        if (class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("ASSETS DEBUG: Enqueuing editor assets...");
        }
        
        // Pass data to JavaScript
        $localize_result = wp_localize_script('myfeeds-product-picker-editor', 'myfeedsData', [
            'apiUrl' => rest_url('myfeeds/v1/'),
            'pluginUrl' => MYFEEDS_PLUGIN_URL,
            'nonce' => wp_create_nonce('wp_rest'),
            'isFree' => true,
            'isPro' => false,
            'isPremium' => false,
        ]);
        
        if (!$localize_result && class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("ASSETS DEBUG: Failed to localize script data!");
        } elseif (class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("ASSETS DEBUG: Script data localized successfully");
        }
    }
    
    public function enqueue_frontend_assets() {
        if (has_block('myfeeds/product-picker')) {
            wp_enqueue_style('myfeeds-product-picker-frontend');
        }
    }

    /**
     * Block render callback. Returns HTML that WordPress outputs directly,
     * so every dynamic value in the returned string is escaped at the
     * point of HTML composition using the appropriate WordPress escaping
     * function for its context (esc_html for text content, esc_attr for
     * HTML attribute values, esc_url for URLs in href/src).
     *
     * - The empty-state markup uses esc_html__ for the translated string.
     * - Each product card is built by render_product_card(), which keeps
     *   incoming product fields raw and escapes them at every concat
     *   point (see that method's docblock).
     * - The placeholder helpers (render_missing_product_placeholder /
     *   render_unavailable_product_placeholder) wrap their inputs with
     *   intval / esc_attr / esc_html via sprintf.
     * - The optional Schema.org JSON-LD <script> appended at the end is
     *   built by MyFeeds_Schema_Generator::product_list_jsonld(), which
     *   uses wp_json_encode with JSON_HEX_TAG | JSON_HEX_AMP so embedded
     *   "</script>" sequences in product data cannot break out of the
     *   <script> block.
     *
     * @param array $attrs Block attributes from the editor.
     * @return string       Fully escaped HTML, safe for direct output.
     */
    public function render_callback($attrs) {
        $products = isset($attrs['selectedProducts']) ? $attrs['selectedProducts'] : array();

        if (empty($products)) {
            return '<div class="myfeeds-no-products">' . esc_html__('No products selected.', 'myfeeds-affiliate-feed-manager') . '</div>';
        }

        $placeholder_url = MYFEEDS_PLUGIN_URL . 'assets/placeholder.png';
        $use_db = class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode();

        static $cached_card_design = null;
        if ($cached_card_design === null && class_exists('MyFeeds_Settings_Manager')) {
            $cached_card_design = MyFeeds_Settings_Manager::get_card_design();
        }

        $output = '<div class="myfeeds-product-grid">';
        $rendered_count = 0;
        $rendered_products = array();

        for ($idx = 0; $idx < count($products); $idx++) {
            $current_item = $products[$idx];
            $product_data = null;
            $data_source = 'none';
            
            if (!is_array($current_item) || empty($current_item['id'])) {
                continue;
            }
            
            $requested_id = (string)$current_item['id'];
            
            // Use multi-source resolver (single DB query in DB mode)
            if (class_exists('MyFeeds_Product_Resolver')) {
                $product_data = MyFeeds_Product_Resolver::resolve($requested_id, array(
                    'color' => $current_item['color'] ?? '',
                    'image_url' => $current_item['image_url'] ?? '',
                ));
                if ($product_data) {
                    $data_source = $use_db ? 'db' : 'json';
                }
            } else {
                $product_data = $this->resolve_product_safe($current_item, $idx);
                if ($product_data) {
                    $data_source = 'api';
                }
            }
            
            // Fallback to original block data if resolver fails but block has data
            if (!$product_data && !empty($current_item['title'])) {
                $product_data = $current_item;
                $data_source = 'fallback';
            }
            
            // Log source per product (info level — summary only)
            $price_resolved = ($product_data && isset($product_data['price'])) ? floatval($product_data['price']) : 0;
            $price_fallback = isset($current_item['price']) ? floatval($current_item['price']) : 0;
            myfeeds_log('PP_product_source: id=' . $requested_id
                . ', source=' . $data_source
                . ', price=' . $price_resolved
                . ($data_source === 'fallback' ? ', price_fallback=' . $price_fallback : ''),
                'info'
            );
            
            if (!$product_data) {
                myfeeds_log('PP_resolve_failed: id=' . $requested_id, 'error');
                $output .= $this->render_missing_product_placeholder($idx, 'not_found', $requested_id);
                continue;
            }

            if (isset($product_data['status']) && $product_data['status'] === 'unavailable') {
                $output .= $this->render_unavailable_product_placeholder($idx, $requested_id);
                continue;
            }

            $returned_id = isset($product_data['id']) ? (string)$product_data['id'] : '';
            if ($returned_id !== $requested_id && $returned_id !== '') {
                myfeeds_log('PP_id_mismatch: requested=' . $requested_id . ', got=' . $returned_id, 'error');
                $output .= $this->render_missing_product_placeholder($idx, 'id_mismatch', $requested_id);
                continue;
            }

            $output .= $this->render_product_card($product_data, $placeholder_url, $cached_card_design);
            $rendered_products[] = $product_data;
            $rendered_count++;
        }

        $output .= '</div>';

        // Schema.org JSON-LD ItemList — Google reads this and surfaces the
        // cards as a product list with rich snippets. Keep it after the
        // grid so the visual content always renders even if the schema
        // module is missing for some reason.
        if (class_exists('MyFeeds_Schema_Generator') && !empty($rendered_products)) {
            $output .= MyFeeds_Schema_Generator::product_list_jsonld($rendered_products);
        }

        myfeeds_log('PP_render_complete: total=' . count($products) . ', rendered=' . $rendered_count, 'info');

        return $output;
    }
    
    /**
     * Render a placeholder for missing/unavailable products
     * NEVER duplicate another product!
     */
    private function render_missing_product_placeholder($index, $reason, $product_id = '') {
        $message = __('Product temporarily unavailable', 'myfeeds-affiliate-feed-manager');
        
        return sprintf(
            '<div class="myfeeds-product-card myfeeds-missing-product" data-index="%d" data-reason="%s" data-id="%s">
                <div class="myfeeds-missing-icon">&#8987;</div>
                <div class="myfeeds-missing-text">%s</div>
            </div>',
            intval($index),
            esc_attr($reason),
            esc_attr($product_id),
            esc_html($message)
        );
    }
    
    /**
     * FIX 5: Render a placeholder for products marked as unavailable in feed
     * Shows a clear message without price, image, or affiliate link.
     * Layout remains intact to prevent shifting of other product cards.
     */
    private function render_unavailable_product_placeholder($index, $product_id = '') {
        $message = __('This product is no longer available', 'myfeeds-affiliate-feed-manager');
        
        return sprintf(
            '<div class="myfeeds-product-card myfeeds-unavailable-product" data-index="%d" data-status="unavailable" data-id="%s">
                <div class="myfeeds-unavailable-image">
                    <div class="myfeeds-unavailable-icon">&#10006;</div>
                </div>
                <div class="myfeeds-product-details">
                    <div class="myfeeds-unavailable-text">%s</div>
                </div>
            </div>',
            intval($index),
            esc_attr($product_id),
            esc_html($message)
        );
    }
    
    /**
     * Safe debug logging for render process
     */
    private function log_render_debug($event, $data) {
        $message = 'MYFEEDS_PP_' . $event . ': ' . wp_json_encode($data, JSON_UNESCAPED_SLASHES);
        myfeeds_log($message, 'debug');
    }
    
    /**
     * Resolve product data SAFELY - NO fallback to wrong products
     * Returns null if product cannot be properly resolved
     */
    private function resolve_product_safe($item, $idx) {
        $id = (string)$item['id'];
        $color = isset($item['color']) ? $item['color'] : '';
        $image_url = isset($item['image_url']) ? $item['image_url'] : '';
        
        // Create cache key - MUST be unique per product
        // Using ID as primary key, with color/image as secondary differentiation
        $cache_key = 'myfeeds_product_' . md5($id . '|' . $color . '|' . $image_url);
        
        $this->log_render_debug('resolve_start', array(
            'index' => $idx,
            'input_id' => $id,
            'input_color' => $color,
            'cache_key' => $cache_key,
        ));
        
        // Check cache FIRST
        $cached_data = get_transient($cache_key);
        
        if (false !== $cached_data && is_array($cached_data)) {
            // VERIFY cached data has correct ID
            $cached_id = isset($cached_data['id']) ? (string)$cached_data['id'] : '';
            if ($cached_id === $id) {
                $this->log_render_debug('cache_hit_valid', array(
                    'index' => $idx,
                    'cached_id' => $cached_id,
                ));
                return $cached_data;
            } else {
                // Cache has WRONG data - delete it!
                $this->log_render_debug('cache_hit_INVALID', array(
                    'index' => $idx,
                    'expected_id' => $id,
                    'cached_id' => $cached_id,
                    'action' => 'deleting_invalid_cache',
                ));
                delete_transient($cache_key);
            }
        }
        
        $this->log_render_debug('cache_miss', array(
            'index' => $idx,
            'cache_key' => $cache_key,
        ));
        
        // Fetch from API
        $api_url = rest_url('myfeeds/v1/product');
        $query_args = array('id' => $id);
        
        if (!empty($color)) {
            $query_args['color'] = $color;
        }
        
        $request_url = add_query_arg($query_args, $api_url);
        
        $this->log_render_debug('api_call', array(
            'index' => $idx,
            'url' => $request_url,
        ));
        
        $response = wp_remote_get($request_url, array(
            'timeout' => 30,
            'headers' => array(
                'X-WP-Nonce' => wp_create_nonce('wp_rest')
            )
        ));
        
        if (is_wp_error($response)) {
            $this->log_render_debug('api_error', array(
                'index' => $idx,
                'error' => $response->get_error_message(),
                'action' => 'returning_null_NOT_fallback',
            ));
            // DO NOT fallback to $item - return null instead
            return null;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $this->log_render_debug('api_response', array(
            'index' => $idx,
            'http_code' => $http_code,
            'response_id' => isset($data['id']) ? $data['id'] : 'MISSING',
            'response_type' => gettype($data),
        ));
        
        // Validate response
        if (!is_array($data) || !isset($data['id'])) {
            $this->log_render_debug('api_invalid_response', array(
                'index' => $idx,
                'action' => 'returning_null_NOT_fallback',
            ));
            // DO NOT fallback to $item - return null instead
            return null;
        }
        
        // CRITICAL: Verify we got the RIGHT product
        $received_id = (string)$data['id'];
        if ($received_id !== $id) {
            $this->log_render_debug('api_id_mismatch', array(
                'index' => $idx,
                'requested_id' => $id,
                'received_id' => $received_id,
                'action' => 'returning_null_NOT_caching_wrong_data',
            ));
            // DO NOT cache wrong data, DO NOT return wrong product
            return null;
        }
        
        // Success! Cache and return
        set_transient($cache_key, $data, DAY_IN_SECONDS);
        
        return $data;
    }
    
    /**
     * Render an individual product card.
     *
     * Inputs from $product are kept in raw form throughout this method;
     * every dynamic value is escaped at the moment it is concatenated
     * into $card_html using the function appropriate for its context:
     *   - esc_url()  for href and src attribute values
     *   - esc_attr() for any other HTML attribute value
     *   - esc_html() for text inside element bodies
     *   - intval()   for numeric values that must remain integers
     * The helper methods used here (get_currency_symbol_raw,
     * format_price_raw, format_shipping_info_raw) all return raw strings
     * by contract; the caller wraps their return values in esc_html() at
     * the concat site.
     *
     * @param array       $product         Resolved product data (raw).
     * @param string      $placeholder_url Fallback image URL.
     * @param array|null  $card_design     Reserved for Premium card-design overrides.
     * @return string                       Fully escaped HTML.
     */
    private function render_product_card($product, $placeholder_url, $card_design = null) {
        $title          = (string) ($product['title']          ?? '');
        $image_url      = (string) ($product['image_url']      ?? $placeholder_url);
        $brand          = (string) ($product['brand']          ?? '');
        $merchant       = (string) ($product['merchant']       ?? '');
        $affiliate_link = (string) ($product['affiliate_link'] ?? '#');

        $price      = (float) ($product['price']      ?? 0);
        $sale_price = (float) ($product['sale_price'] ?? 0);
        $old_price  = (float) ($product['old_price']  ?? 0);

        if (class_exists('MyFeeds_Affiliate_Product_Picker')) {
            $product_id_for_log = isset($product['id']) ? (string) $product['id'] : '';
            $discount_log = isset($product['discount_percentage']) ? (string) $product['discount_percentage'] : 'none';
            MyFeeds_Affiliate_Product_Picker::log("FRONTEND RENDER: {$product_id_for_log} - price: $price, old_price: $old_price, sale_price: $sale_price, discount_percentage: {$discount_log}");
        }

        if ($old_price === 0.0 && $sale_price > 0 && $sale_price < $price) {
            $old_price = $price;
            $price = $sale_price;
        }
        if ($old_price > 0 && $sale_price > 0 && $sale_price < $old_price) {
            $price = $sale_price;
        }

        $currency_symbol_raw = $this->get_currency_symbol_raw((string) ($product['currency'] ?? 'EUR'));

        $discount_percent = 0;
        if (!empty($product['discount_percentage']) && (float) $product['discount_percentage'] > 0) {
            $discount_percent = (int) round((float) $product['discount_percentage']);
        } elseif ($old_price > $price && $price > 0) {
            $discount_percent = (int) round((($old_price - $price) / $old_price) * 100);
        }

        $shipping_text_raw = !empty($product['shipping_text'])
            ? (string) $product['shipping_text']
            : $this->format_shipping_info_raw((string) ($product['shipping'] ?? ''), $currency_symbol_raw);

        // ── Output composition. Every dynamic value is escaped here. ──
        $card_html  = '<a class="myfeeds-product-card" href="' . esc_url($affiliate_link) . '" target="_blank" rel="nofollow sponsored noopener">';

        $card_html .= '<div class="myfeeds-product-image">';
        if ($discount_percent > 0) {
            $card_html .= '<div class="myfeeds-discount-badge">-' . intval($discount_percent) . '%</div>';
        }
        $card_html .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($title) . '" loading="lazy" onerror="this.parentNode.classList.add(\'myfeeds-img-error\');this.style.display=\'none\';">';
        $card_html .= '</div>';

        $card_html .= '<div class="myfeeds-product-details">';
        $element_order = array('brand', 'title', 'price', 'shipping', 'merchant');
        foreach ($element_order as $element) {
            switch ($element) {
                case 'brand':
                    if ($brand !== '') {
                        $card_html .= '<div class="myfeeds-product-brand">' . esc_html($brand) . '</div>';
                    }
                    break;

                case 'title':
                    $card_html .= '<div class="myfeeds-product-title">' . esc_html($title) . '</div>';
                    break;

                case 'price':
                    $card_html .= '<div class="myfeeds-product-price">';
                    if ($old_price > $price && $price > 0) {
                        $card_html .= '<span class="myfeeds-old-price">' . esc_html($this->format_price_raw($old_price, $currency_symbol_raw)) . '</span> ';
                        $card_html .= '<span class="myfeeds-current-price has-discount">' . esc_html($this->format_price_raw($price, $currency_symbol_raw)) . '</span>';
                    } elseif ($price > 0) {
                        $card_html .= '<span class="myfeeds-current-price">' . esc_html($this->format_price_raw($price, $currency_symbol_raw)) . '</span>';
                    } else {
                        $card_html .= '<span class="myfeeds-price-unavailable">' . esc_html__('Price on request', 'myfeeds-affiliate-feed-manager') . '</span>';
                    }
                    $card_html .= '</div>';
                    break;

                case 'shipping':
                    if ($shipping_text_raw !== '') {
                        $card_html .= '<div class="myfeeds-shipping-info">' . esc_html($shipping_text_raw) . '</div>';
                    }
                    break;

                case 'merchant':
                    if ($merchant !== '') {
                        $card_html .= '<div class="myfeeds-merchant">' . esc_html($merchant) . '</div>';
                    }
                    break;
            }
        }
        $card_html .= '</div>';
        $card_html .= '</a>';

        return $card_html;
    }

    /**
     * Return the raw currency symbol for a currency code. Falls back to
     * the code itself for unknown currencies. The return value is NOT
     * escaped — callers must wrap it in the appropriate escape function
     * for the output context.
     *
     * @param string $currency_code ISO 4217-style code, e.g. 'EUR'.
     * @return string                Raw symbol or the original code.
     */
    private function get_currency_symbol_raw($currency_code) {
        $symbols = [
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'JPY' => '¥',
            'CHF' => 'CHF',
            'CAD' => 'C$',
            'AUD' => 'A$',
        ];
        return $symbols[strtoupper((string) $currency_code)] ?? (string) $currency_code;
    }

    /**
     * Format a price amount with its currency symbol. Returns a raw
     * string — the caller must wrap this in esc_html() (or similar) at
     * the concat site.
     *
     * @param float|int|string $amount      Numeric amount.
     * @param string           $symbol_raw  Raw currency symbol.
     * @return string                         Raw formatted string.
     */
    private function format_price_raw($amount, $symbol_raw) {
        return number_format((float) $amount, 2, ',', '.') . ' ' . $symbol_raw;
    }

    /**
     * Format shipping information into a human-readable string. Returns
     * a raw, translated string — the caller is responsible for escaping
     * (esc_html) at the concat site.
     *
     * @param string $shipping_raw           Raw shipping field from feed.
     * @param string $currency_symbol_raw    Raw currency symbol.
     * @return string                          Raw translated shipping text.
     */
    private function format_shipping_info_raw($shipping_raw, $currency_symbol_raw) {
        if (empty($shipping_raw)) {
            return __('Shipping costs may apply', 'myfeeds-affiliate-feed-manager');
        }

        if (is_numeric($shipping_raw)) {
            $shipping_val = (float) $shipping_raw;
            return $shipping_val > 0
                /* translators: %s: formatted shipping cost with currency */
                ? sprintf(__('Shipping: %s', 'myfeeds-affiliate-feed-manager'), $this->format_price_raw($shipping_val, $currency_symbol_raw))
                : __('Free Shipping', 'myfeeds-affiliate-feed-manager');
        }

        if (is_string($shipping_raw)) {
            if (stripos($shipping_raw, 'free') !== false) {
                return __('Free Shipping', 'myfeeds-affiliate-feed-manager');
            }
            if (preg_match('/(\d+\.?\d*)/', $shipping_raw, $matches)) {
                $val = (float) $matches[1];
                return $val > 0
                    /* translators: %s: formatted shipping cost with currency */
                    ? sprintf(__('Shipping: %s', 'myfeeds-affiliate-feed-manager'), $this->format_price_raw($val, $currency_symbol_raw))
                    : __('Free Shipping', 'myfeeds-affiliate-feed-manager');
            }
        }

        return __('Shipping costs may apply', 'myfeeds-affiliate-feed-manager');
    }
}

/**
 * Helper function to get single product (for backwards compatibility)
 * Uses DB in DB mode, JSON file in JSON mode.
 */
function myfeeds_get_single_product($id) {
    // DB mode: direct query
    if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode()) {
        return MyFeeds_DB_Manager::get_product((string) $id);
    }
    
    // JSON mode: file-based lookup
    $index_path = myfeeds_uploads_dir() . '/myfeeds-feed-index.json';
    if (!file_exists($index_path)) { return null; }
    $index = json_decode(file_get_contents($index_path), true);
    if (!is_array($index) || !isset($index['items'])) { return null; }
    $key = (string) $id;
    if (isset($index['items'][$key])) { return $index['items'][$key]; }
    foreach ($index['items'] as $item) {
        if (isset($item['id']) && (string)$item['id'] === (string)$id) { return $item; }
    }
    return null;
}