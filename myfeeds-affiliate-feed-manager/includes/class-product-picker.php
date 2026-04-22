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
            MyFeeds_External_Debug::log("📦 BLOCK DEBUG: Starting Gutenberg block registration...");
        }
        
        // Check if required files exist
        $script_path = MYFEEDS_PLUGIN_DIR . 'build/index.js';
        $editor_css_path = MYFEEDS_PLUGIN_DIR . 'assets/editor.css';
        $frontend_css_path = MYFEEDS_PLUGIN_DIR . 'assets/style.css';
        
        if (!file_exists($script_path)) {
            if (class_exists('MyFeeds_External_Debug')) {
                MyFeeds_External_Debug::log("❌ BLOCK DEBUG: JavaScript file missing: " . $script_path);
            }
            return;
        }
        
        if (!file_exists($editor_css_path)) {
            if (class_exists('MyFeeds_External_Debug')) {
                MyFeeds_External_Debug::log("⚠️ BLOCK DEBUG: Editor CSS file missing: " . $editor_css_path);
            }
        }
        
        if (!file_exists($frontend_css_path)) {
            if (class_exists('MyFeeds_External_Debug')) {
                MyFeeds_External_Debug::log("⚠️ BLOCK DEBUG: Frontend CSS file missing: " . $frontend_css_path);
            }
        }
        
        // Resolve versions with filemtime for cache-busting (safe, no URL changes)
        $script_ver = file_exists($script_path) ? filemtime($script_path) : MYFEEDS_VERSION;
        $editor_css_ver = file_exists($editor_css_path) ? filemtime($editor_css_path) : MYFEEDS_VERSION;
        $frontend_css_ver = file_exists($frontend_css_path) ? filemtime($frontend_css_path) : MYFEEDS_VERSION;

        if (class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("📜 BLOCK DEBUG: Registering script with version: " . $script_ver);
        }

        // Register block script
        $script_registered = wp_register_script(
            'myfeeds-product-picker-editor',
            MYFEEDS_PLUGIN_URL . 'build/index.js',
            ['wp-blocks', 'wp-components', 'wp-element', 'wp-editor', 'wp-data'],
            $script_ver,
            true
        );
        
        if (!$script_registered && class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("❌ BLOCK DEBUG: Failed to register JavaScript!");
        }
        
        // Register block styles
        $editor_style_registered = wp_register_style(
            'myfeeds-product-picker-editor',
            MYFEEDS_PLUGIN_URL . 'assets/editor.css',
            [],
            $editor_css_ver
        );
        
        if (!$editor_style_registered && class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("❌ BLOCK DEBUG: Failed to register editor CSS!");
        }
        
        wp_register_style(
            'myfeeds-product-picker-frontend',
            MYFEEDS_PLUGIN_URL . 'assets/style.css',
            [],
            $frontend_css_ver
        );
        
        if (class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("🎯 BLOCK DEBUG: Attempting to register block type 'myfeeds/product-picker'...");
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
                'displayMode' => [
                    'type' => 'string',
                    'default' => 'grid',
                ],
                'autoplay' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
                'autoplayInterval' => [
                    'type' => 'number',
                    'default' => 3,
                ],
                'arrowColor' => [
                    'type' => 'string',
                    'default' => '#333333',
                ],
            ],
        ]);
        
        if ($block_registered && class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("✅ BLOCK DEBUG: Block 'myfeeds/product-picker' registered successfully!");
        } elseif (!$block_registered && class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("❌ BLOCK DEBUG: Failed to register block 'myfeeds/product-picker'!");
        }
    }
    
    public function enqueue_editor_assets() {
        if (class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("📜 ASSETS DEBUG: Enqueuing editor assets...");
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
            MyFeeds_External_Debug::log("❌ ASSETS DEBUG: Failed to localize script data!");
        } elseif (class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("✅ ASSETS DEBUG: Script data localized successfully");
        }
    }
    
    public function enqueue_frontend_assets() {
        if (has_block('myfeeds/product-picker')) {
            wp_enqueue_style('myfeeds-product-picker-frontend');
            
            // Card design customizations (custom CSS vars, Google Fonts, @font-face)
            // are a Premium-tier feature and intentionally not emitted in the Free plugin.


            // Check if any product-picker block on this page uses carousel mode
            global $post;
            $needs_carousel = false;
            if ($post && has_blocks($post->post_content)) {
                $blocks = parse_blocks($post->post_content);
                $needs_carousel = $this->blocks_need_carousel($blocks);
            }
            
            if ($needs_carousel) {
                // Splide CSS (core only — bundled locally)
                wp_enqueue_style(
                    'splide-core',
                    MYFEEDS_PLUGIN_URL . 'assets/vendor/splide-core.min.css',
                    array(),
                    '4.1.4'
                );
                
                // Splide JS (bundled locally)
                wp_enqueue_script(
                    'splide',
                    MYFEEDS_PLUGIN_URL . 'assets/vendor/splide.min.js',
                    array(),
                    '4.1.4',
                    true
                );
                
                // Our carousel styles
                wp_enqueue_style('myfeeds-carousel', MYFEEDS_PLUGIN_URL . 'assets/carousel.css', array('splide-core'), MYFEEDS_VERSION);
            }
        }
    }
    
    /**
     * Recursively check if any product-picker block uses carousel mode
     */
    private function blocks_need_carousel($blocks) {
        // Carousel display is a Pro-tier feature; the Free plugin always
        // renders as grid, so carousel assets never need to load.
        foreach ($blocks as $block) {
            if (!empty($block['innerBlocks'])) {
                if ($this->blocks_need_carousel($block['innerBlocks'])) {
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
     * Render callback for block
     */
    public function render_callback($attrs) {
        $products = isset($attrs['selectedProducts']) ? $attrs['selectedProducts'] : array();
        
        if (empty($products)) {
            return '<div class="myfeeds-no-products">' . __('No products selected.', 'myfeeds-affiliate-feed-manager') . '</div>';
        }
        
        // Enqueue Google Fonts properly (only once per page)
        if (!empty($this->google_fonts_url)) {
            wp_enqueue_style('myfeeds-google-fonts', $this->google_fonts_url, array(), null);
            $this->google_fonts_url = ''; // Only enqueue once
        }
        
        $placeholder_url = MYFEEDS_PLUGIN_URL . 'assets/placeholder.png';
        $use_db = class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode();
        
        $display_mode = $attrs['displayMode'] ?? 'grid';
        
        // Cache card design for this render pass (avoid repeated DB reads)
        static $cached_card_design = null;
        if ($cached_card_design === null && class_exists('MyFeeds_Settings_Manager')) {
            $cached_card_design = MyFeeds_Settings_Manager::get_card_design();
        }
        $autoplay = !empty($attrs['autoplay']);
        $autoplay_interval = intval($attrs['autoplayInterval'] ?? 3);

        // Carousel display mode is a Pro-tier feature.
        $display_mode = 'grid';
        $is_carousel = false;
        $arrow_color = sanitize_hex_color($attrs['arrowColor'] ?? '#333333') ?: '#333333';
        
        if ($is_carousel) {
            $unique_id = 'myfeeds-carousel-' . wp_unique_id();
            $output = '<div id="' . esc_attr($unique_id) . '" class="myfeeds-product-carousel splide">';
            $output .= '<div class="splide__track">';
            $output .= '<div class="splide__list">';
        } else {
            $output = '<div class="myfeeds-product-grid">';
        }
        $rendered_count = 0;
        
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
                $placeholder_html = $this->render_missing_product_placeholder($idx, 'not_found', $requested_id);
                $output .= $is_carousel ? '<div class="splide__slide">' . $placeholder_html . '</div>' : $placeholder_html;
                continue;
            }
            
            // Check if product is marked as unavailable
            if (isset($product_data['status']) && $product_data['status'] === 'unavailable') {
                $placeholder_html = $this->render_unavailable_product_placeholder($idx, $requested_id);
                $output .= $is_carousel ? '<div class="splide__slide">' . $placeholder_html . '</div>' : $placeholder_html;
                continue;
            }
            
            // Verify ID match
            $returned_id = isset($product_data['id']) ? (string)$product_data['id'] : '';
            if ($returned_id !== $requested_id && $returned_id !== '') {
                myfeeds_log('PP_id_mismatch: requested=' . $requested_id . ', got=' . $returned_id, 'error');
                $placeholder_html = $this->render_missing_product_placeholder($idx, 'id_mismatch', $requested_id);
                $output .= $is_carousel ? '<div class="splide__slide">' . $placeholder_html . '</div>' : $placeholder_html;
                continue;
            }
            
            $card_html = $this->render_product_card($product_data, $placeholder_url, $cached_card_design);
            if ($is_carousel) {
                $output .= '<div class="splide__slide">' . $card_html . '</div>';
            } else {
                $output .= $card_html;
            }
            $rendered_count++;
        }
        
        if ($is_carousel) {
            $output .= '</div>'; // Close splide__list
            $output .= '</div>'; // Close splide__track
            $output .= '</div>'; // Close splide container
            
            // Dynamic arrow color via inline CSS (overrides carousel.css defaults)
            $output .= '<style>
                #' . esc_attr($unique_id) . ' .splide__arrow svg { fill: ' . esc_attr($arrow_color) . ' !important; }
                #' . esc_attr($unique_id) . ' .splide__arrow { border-color: ' . esc_attr($arrow_color) . ' !important; }
                #' . esc_attr($unique_id) . ' .splide__arrow:hover { background: ' . esc_attr($arrow_color) . ' !important; border-color: ' . esc_attr($arrow_color) . ' !important; }
                #' . esc_attr($unique_id) . ' .splide__arrow:hover svg { fill: #fff !important; }
            </style>';
            
            // Force arrow visibility on all screen sizes via ID selector (highest specificity)
            $output .= '<style>
                #' . esc_attr($unique_id) . ' .splide__arrow {
                    display: flex !important;
                    visibility: visible !important;
                    opacity: 1 !important;
                    position: absolute !important;
                    pointer-events: all !important;
                }
                #' . esc_attr($unique_id) . ' .splide__arrow--prev {
                    transform: translateY(-50%) scaleX(-1) !important;
                }
                @media (max-width: 480px) {
                    #' . esc_attr($unique_id) . ' .splide__arrow {
                        display: flex !important;
                        visibility: visible !important;
                        opacity: 1 !important;
                        width: 32px !important;
                        height: 32px !important;
                        z-index: 20 !important;
                        background: transparent !important;
                        border: 2px solid ' . esc_attr($arrow_color) . ' !important;
                        border-radius: 50% !important;
                        box-shadow: none !important;
                        align-items: center !important;
                        justify-content: center !important;
                        padding: 0 !important;
                    }
                    #' . esc_attr($unique_id) . ' .splide__arrow svg {
                        fill: ' . esc_attr($arrow_color) . ' !important;
                        width: 14px !important;
                        height: 14px !important;
                    }
                    #' . esc_attr($unique_id) . ' .splide__arrow--prev {
                        left: -2px !important;
                        top: 45% !important;
                        transform: scaleX(-1) !important;
                    }
                    #' . esc_attr($unique_id) . ' .splide__arrow--next {
                        right: -2px !important;
                        top: 45% !important;
                        transform: none !important;
                    }
                    #' . esc_attr($unique_id) . ' .splide__track {
                        padding: 0 !important;
                    }
                }
            </style>';
            
            // Inline JS to initialize this carousel instance
            $splide_options = array(
                'type' => 'loop',
                'perPage' => 3,
                'perMove' => 1,
                'gap' => '16px',
                'pagination' => false,
                'arrows' => true,
                'autoHeight' => true,
                'breakpoints' => array(
                    768 => array('perPage' => 2),
                    480 => array('perPage' => 2),
                ),
            );
            
            if ($autoplay) {
                $splide_options['autoplay'] = true;
                $splide_options['interval'] = $autoplay_interval * 1000;
                $splide_options['pauseOnHover'] = true;
                $splide_options['pauseOnFocus'] = true;
            }
            
            $options_json = wp_json_encode($splide_options);
            
            $output .= '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    if (typeof Splide === "undefined") return;
                    
                    var el = document.getElementById("' . esc_js($unique_id) . '");
                    if (!el) return;
                    
                    var splide = new Splide("#' . esc_js($unique_id) . '", ' . $options_json . ');
                    splide.mount();
                    ' . ($autoplay ? '
                    // Smart autoplay resume: after any user interaction, pause briefly
                    // then resume. Uses interval + 3 seconds bonus time.
                    var baseMs = ' . ($autoplay_interval * 1000) . ';
                    var bonusMs = baseMs + 3000;
                    var timer = null;
                    var autoplayComp = splide.Components.Autoplay;
                    
                    function onUserInteraction() {
                        if (timer) clearTimeout(timer);
                        autoplayComp.pause();
                        timer = setTimeout(function() {
                            autoplayComp.play();
                        }, bonusMs);
                    }
                    
                    // Direct DOM listeners on arrow buttons (most reliable)
                    var arrows = el.querySelectorAll(".splide__arrow");
                    for (var i = 0; i < arrows.length; i++) {
                        arrows[i].addEventListener("click", onUserInteraction);
                    }
                    
                    // Touch/drag interaction on the track
                    var track = el.querySelector(".splide__track");
                    if (track) {
                        track.addEventListener("pointerdown", onUserInteraction);
                        track.addEventListener("touchstart", onUserInteraction, { passive: true });
                    }
                    ' : '') . '
                });
            </script>';
        } else {
            $output .= '</div>'; // Close myfeeds-product-grid
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
     * Render individual product card
     */
    private function render_product_card($product, $placeholder_url, $card_design = null) {
        $title = esc_html($product['title'] ?? '');
        $image_url = esc_url($product['image_url'] ?? $placeholder_url);
        $brand = esc_html($product['brand'] ?? '');
        $merchant = esc_html($product['merchant'] ?? '');
        $affiliate_link = esc_url($product['affiliate_link'] ?? '#');
        
        // Price handling
        $price = floatval($product['price'] ?? 0);
        $sale_price = floatval($product['sale_price'] ?? 0);
        $old_price = floatval($product['old_price'] ?? 0);
        
        // DEBUG LOGGING
        MyFeeds_Affiliate_Product_Picker::log("🎨 FRONTEND RENDER: {$product['id']} - price: $price, old_price: $old_price, sale_price: $sale_price, discount_percentage: " . ($product['discount_percentage'] ?? 'none'));
        
        // Price logic
        if ($old_price === 0 && $sale_price > 0 && $sale_price < $price) {
            $old_price = $price;
            $price = $sale_price;
        }
        
        if ($old_price > 0 && $sale_price > 0 && $sale_price < $old_price) {
            $price = $sale_price;
        }
        
        $currency = esc_html($product['currency'] ?? 'EUR');
        $currency_symbol = $this->get_currency_symbol($currency);
        
        // Discount calculation - prefer pre-calculated discount from Smart Mapper
        $discount_percent = 0;
        if (!empty($product['discount_percentage']) && floatval($product['discount_percentage']) > 0) {
            // Use pre-calculated discount from AWIN or Smart Mapper
            $discount_percent = round(floatval($product['discount_percentage']));
        } elseif ($old_price > $price && $price > 0) {
            // Calculate discount from prices if not provided
            $discount_percent = round((($old_price - $price) / $old_price) * 100);
        } else {
        }
        
        // Shipping - check for pre-formatted shipping_text first, then raw shipping data
        $shipping_info = !empty($product['shipping_text']) 
            ? esc_html($product['shipping_text']) 
            : $this->format_shipping_info($product['shipping'] ?? '', $currency_symbol);
        
        // Start building card HTML
        $card_html = '<a class="myfeeds-product-card" href="' . $affiliate_link . '" target="_blank" rel="nofollow noopener">';
        
        // Product image with discount badge inside
        $card_html .= '<div class="myfeeds-product-image">';
        
        // Discount badge - inside image container for proper positioning
        if ($discount_percent > 0) {
            $card_html .= '<div class="myfeeds-discount-badge">-' . $discount_percent . '%</div>';
        }
        
        $card_html .= '<img src="' . $image_url . '" alt="' . $title . '" loading="lazy" onerror="this.parentNode.classList.add(\'myfeeds-img-error\');this.style.display=\'none\';">';
        $card_html .= '</div>';
        
        // Product details — rendered in user-defined order
        $card_html .= '<div class="myfeeds-product-details">';

        // Element order customization belongs to the Premium Card Design
        // Editor; Free ships with the default order.
        $element_order = array('brand', 'title', 'price', 'shipping', 'merchant');

        // Render elements in saved order
        foreach ($element_order as $element) {
            switch ($element) {
                case 'brand':
                    if ($brand) {
                        $card_html .= '<div class="myfeeds-product-brand">' . $brand . '</div>';
                    }
                    break;
                    
                case 'title':
                    $card_html .= '<div class="myfeeds-product-title">' . $title . '</div>';
                    break;
                    
                case 'price':
                    $card_html .= '<div class="myfeeds-product-price">';
                    if ($old_price > $price && $price > 0) {
                        $card_html .= '<span class="myfeeds-old-price">' . $this->format_price($old_price, $currency_symbol) . '</span> ';
                        $card_html .= '<span class="myfeeds-current-price has-discount">' . $this->format_price($price, $currency_symbol) . '</span>';
                    } elseif ($price > 0) {
                        $card_html .= '<span class="myfeeds-current-price">' . $this->format_price($price, $currency_symbol) . '</span>';
                    } else {
                        $card_html .= '<span class="myfeeds-price-unavailable">' . __('Price on request', 'myfeeds-affiliate-feed-manager') . '</span>';
                    }
                    $card_html .= '</div>';
                    break;
                    
                case 'shipping':
                    if ($shipping_info) {
                        $card_html .= '<div class="myfeeds-shipping-info">' . $shipping_info . '</div>';
                    }
                    break;
                    
                case 'merchant':
                    if ($merchant) {
                        $card_html .= '<div class="myfeeds-merchant">' . $merchant . '</div>';
                    }
                    break;
            }
        }

        $card_html .= '</div>'; // Close details
        $card_html .= '</a>'; // Close card
        
        return $card_html;
    }
    
    /**
     * Get currency symbol
     */
    private function get_currency_symbol($currency_code) {
        $symbols = [
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'JPY' => '¥',
            'CHF' => 'CHF',
            'CAD' => 'C$',
            'AUD' => 'A$',
        ];
        
        return $symbols[strtoupper($currency_code)] ?? $currency_code;
    }
    
    /**
     * Format price with currency
     */
    private function format_price($amount, $symbol) {
        return number_format($amount, 2, ',', '.') . ' ' . $symbol;
    }
    
    /**
     * Format shipping information
     */
    private function format_shipping_info($shipping_raw, $currency_symbol) {
        // If shipping data is empty or null, return fallback text
        if (empty($shipping_raw)) { 
            return __('Shipping costs may apply', 'myfeeds-affiliate-feed-manager'); 
        }
        
        // Handle numeric shipping costs
        if (is_numeric($shipping_raw)) {
            $shipping_val = floatval($shipping_raw);
            return $shipping_val > 0 
                /* translators: %s: formatted shipping cost with currency */
                ? sprintf(__('Shipping: %s', 'myfeeds-affiliate-feed-manager'), $this->format_price($shipping_val, $currency_symbol))
                : __('Free Shipping', 'myfeeds-affiliate-feed-manager');
        }
        
        // Handle complex shipping formats like "DE::Ground:3.49" or "0.00"
        if (is_string($shipping_raw)) {
            // Check if string contains "free" (case insensitive)
            if (stripos($shipping_raw, 'free') !== false) {
                return __('Free Shipping', 'myfeeds-affiliate-feed-manager');
            }
            
            // Try to extract numeric value from string
            if (preg_match('/(\d+\.?\d*)/', $shipping_raw, $matches)) {
                $val = floatval($matches[1]);
                return $val > 0 
                    /* translators: %s: formatted shipping cost with currency */
                    ? sprintf(__('Shipping: %s', 'myfeeds-affiliate-feed-manager'), $this->format_price($val, $currency_symbol))
                    : __('Free Shipping', 'myfeeds-affiliate-feed-manager');
            }
        }
        
        // Default fallback
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
    $upload = wp_upload_dir();
    $index_path = $upload['basedir'] . '/myfeeds-feed-index.json';
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