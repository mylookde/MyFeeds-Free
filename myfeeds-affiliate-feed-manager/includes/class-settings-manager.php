<?php
/**
 * MyFeeds Settings Manager
 * Handles all plugin settings including API keys and mapping templates
 * 
 * All settings are stored per-installation using WordPress options API
 * This ensures the plugin works independently on thousands of customer sites
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Settings_Manager {
    
    // Option keys for different settings groups
    const OPTION_API_KEYS = 'myfeeds_api_keys';
    const OPTION_MAPPING_TEMPLATES = 'myfeeds_mapping_templates';
    const OPTION_GENERAL_SETTINGS = 'myfeeds_general_settings';
    const OPTION_CARD_DESIGN = 'myfeeds_card_design';
    
    // Standard fields that can be mapped from any feed
    // These are the destination fields for the Universal Mapper
    public static $standard_fields = array(
        // Essential fields (required for display)
        'id' => array(
            'label' => 'Product ID',
            'required' => true,
            'description' => 'Unique identifier for the product',
            'group' => 'essential'
        ),
        'title' => array(
            'label' => 'Product Title',
            'required' => true,
            'description' => 'Product name/title',
            'group' => 'essential'
        ),
        'price' => array(
            'label' => 'Current Price',
            'required' => true,
            'description' => 'Current selling price',
            'group' => 'essential'
        ),
        'affiliate_link' => array(
            'label' => 'Affiliate Link',
            'required' => true,
            'description' => 'Link to the product (affiliate tracking URL)',
            'group' => 'essential'
        ),
        'image_url' => array(
            'label' => 'Main Image URL',
            'required' => true,
            'description' => 'Primary product image',
            'group' => 'essential'
        ),
        
        // Important fields (recommended)
        'brand' => array(
            'label' => 'Brand',
            'required' => false,
            'description' => 'Product brand/manufacturer',
            'group' => 'important'
        ),
        'merchant' => array(
            'label' => 'Merchant/Shop Name',
            'required' => false,
            'description' => 'Name of the shop selling the product',
            'group' => 'important'
        ),
        'old_price' => array(
            'label' => 'Original/Old Price',
            'required' => false,
            'description' => 'Original price before discount (for strikethrough)',
            'group' => 'important'
        ),
        'discount_percentage' => array(
            'label' => 'Discount Percentage',
            'required' => false,
            'description' => 'Discount percentage (e.g., 20 for 20% off)',
            'group' => 'important'
        ),
        'description' => array(
            'label' => 'Description',
            'required' => false,
            'description' => 'Product description text',
            'group' => 'important'
        ),
        
        // Additional images
        'additional_images' => array(
            'label' => 'Additional Images',
            'required' => false,
            'description' => 'Additional product images (comma-separated or array)',
            'group' => 'images'
        ),
        
        // Product attributes
        'color' => array(
            'label' => 'Color',
            'required' => false,
            'description' => 'Product color(s)',
            'group' => 'attributes'
        ),
        'size' => array(
            'label' => 'Size',
            'required' => false,
            'description' => 'Product size(s)',
            'group' => 'attributes'
        ),
        'material' => array(
            'label' => 'Material',
            'required' => false,
            'description' => 'Product material/fabric',
            'group' => 'attributes'
        ),
        'gender' => array(
            'label' => 'Gender',
            'required' => false,
            'description' => 'Target gender (men, women, unisex)',
            'group' => 'attributes'
        ),
        
        // Additional info
        'category' => array(
            'label' => 'Category',
            'required' => false,
            'description' => 'Product category',
            'group' => 'additional'
        ),
        'currency' => array(
            'label' => 'Currency',
            'required' => false,
            'description' => 'Price currency (EUR, USD, etc.)',
            'group' => 'additional'
        ),
        'shipping' => array(
            'label' => 'Shipping Cost',
            'required' => false,
            'description' => 'Shipping cost or "free"',
            'group' => 'additional'
        ),
        'availability' => array(
            'label' => 'Availability',
            'required' => false,
            'description' => 'Stock status (in stock, out of stock)',
            'group' => 'additional'
        ),
        'ean' => array(
            'label' => 'EAN/GTIN',
            'required' => false,
            'description' => 'European Article Number / Global Trade Item Number',
            'group' => 'additional'
        ),
        'sku' => array(
            'label' => 'SKU',
            'required' => false,
            'description' => 'Stock Keeping Unit',
            'group' => 'additional'
        ),
    );
    
    /**
     * Get API keys for current installation
     */
    public static function get_api_keys() {
        $defaults = array(
            'supabase_url' => '',
            'supabase_anon_key' => '',
            'supabase_service_key' => '',
            'openai_api_key' => '',
        );
        
        $keys = get_option(self::OPTION_API_KEYS, array());
        return wp_parse_args($keys, $defaults);
    }
    
    /**
     * Save API keys for current installation
     */
    public static function save_api_keys($keys) {
        // Sanitize all keys
        $sanitized = array(
            'supabase_url' => esc_url_raw($keys['supabase_url'] ?? ''),
            'supabase_anon_key' => sanitize_text_field($keys['supabase_anon_key'] ?? ''),
            'supabase_service_key' => sanitize_text_field($keys['supabase_service_key'] ?? ''),
            'openai_api_key' => sanitize_text_field($keys['openai_api_key'] ?? ''),
        );
        
        return update_option(self::OPTION_API_KEYS, $sanitized);
    }
    
    /**
     * Get all mapping templates
     */
    public static function get_mapping_templates() {
        return get_option(self::OPTION_MAPPING_TEMPLATES, array());
    }
    
    /**
     * Get a specific mapping template by ID
     */
    public static function get_mapping_template($template_id) {
        $templates = self::get_mapping_templates();
        return isset($templates[$template_id]) ? $templates[$template_id] : null;
    }
    
    /**
     * Save a mapping template
     * 
     * @param string $template_id Unique ID for the template
     * @param string $name Display name for the template
     * @param array $mapping The field mapping configuration
     * @param string $network Optional network identifier (awin, tradedoubler, etc.)
     */
    public static function save_mapping_template($template_id, $name, $mapping, $network = '') {
        $templates = self::get_mapping_templates();
        
        $templates[$template_id] = array(
            'id' => $template_id,
            'name' => sanitize_text_field($name),
            'mapping' => $mapping,
            'network' => sanitize_text_field($network),
            'created_at' => isset($templates[$template_id]['created_at']) 
                ? $templates[$template_id]['created_at'] 
                : current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );
        
        return update_option(self::OPTION_MAPPING_TEMPLATES, $templates);
    }
    
    /**
     * Delete a mapping template
     */
    public static function delete_mapping_template($template_id) {
        $templates = self::get_mapping_templates();
        
        if (isset($templates[$template_id])) {
            unset($templates[$template_id]);
            return update_option(self::OPTION_MAPPING_TEMPLATES, $templates);
        }
        
        return false;
    }
    
    /**
     * Create a template from an existing feed's mapping
     */
    public static function create_template_from_feed($feed_key, $template_name) {
        $feeds = get_option('myfeeds_feeds', array());
        
        if (!isset($feeds[$feed_key]) || empty($feeds[$feed_key]['mapping'])) {
            return new WP_Error('invalid_feed', 'Feed not found or has no mapping');
        }
        
        $feed = $feeds[$feed_key];
        $template_id = 'template_' . sanitize_title($template_name) . '_' . time();
        
        return self::save_mapping_template(
            $template_id,
            $template_name,
            $feed['mapping'],
            $feed['detected_network'] ?? ''
        );
    }
    
    /**
     * Apply a template to a feed
     */
    public static function apply_template_to_feed($template_id, $feed_key) {
        $template = self::get_mapping_template($template_id);
        
        if (!$template) {
            return new WP_Error('invalid_template', 'Template not found');
        }
        
        $feeds = get_option('myfeeds_feeds', array());
        
        if (!isset($feeds[$feed_key])) {
            return new WP_Error('invalid_feed', 'Feed not found');
        }
        
        $feeds[$feed_key]['mapping'] = $template['mapping'];
        $feeds[$feed_key]['mapping_template'] = $template_id;
        $feeds[$feed_key]['last_mapping_update'] = current_time('mysql');
        
        return update_option('myfeeds_feeds', $feeds);
    }
    
    /**
     * Get general settings
     */
    public static function get_general_settings() {
        $defaults = array(
            'batch_size' => 100,
            'enable_background_import' => true,
            'auto_rebuild_interval' => 'daily',
            'enable_pro_features' => false,
            'debug_mode' => false,
        );
        
        $settings = get_option(self::OPTION_GENERAL_SETTINGS, array());
        return wp_parse_args($settings, $defaults);
    }
    
    /**
     * Save general settings
     */
    public static function save_general_settings($settings) {
        $sanitized = array(
            'batch_size' => intval($settings['batch_size'] ?? 100),
            'enable_background_import' => (bool) ($settings['enable_background_import'] ?? true),
            'auto_rebuild_interval' => sanitize_text_field($settings['auto_rebuild_interval'] ?? 'daily'),
            'enable_pro_features' => (bool) ($settings['enable_pro_features'] ?? false),
            'debug_mode' => (bool) ($settings['debug_mode'] ?? false),
        );
        
        return update_option(self::OPTION_GENERAL_SETTINGS, $sanitized);
    }
    
    /**
     * Get field groups for display
     */
    public static function get_field_groups() {
        return array(
            'essential' => array(
                'label' => __('Essential Fields', 'myfeeds'),
                'description' => __('Required for basic product display', 'myfeeds'),
            ),
            'important' => array(
                'label' => __('Important Fields', 'myfeeds'),
                'description' => __('Recommended for better product presentation', 'myfeeds'),
            ),
            'images' => array(
                'label' => __('Images', 'myfeeds'),
                'description' => __('Additional product images', 'myfeeds'),
            ),
            'attributes' => array(
                'label' => __('Product Attributes', 'myfeeds'),
                'description' => __('Product variations and characteristics', 'myfeeds'),
            ),
            'additional' => array(
                'label' => __('Additional Info', 'myfeeds'),
                'description' => __('Extra product information', 'myfeeds'),
            ),
        );
    }

    /**
     * Get card design settings with defaults matching current CSS
     */
    public static function get_card_design() {
        $defaults = array(
            // Card container
            'card_bg_color'        => '#ffffff',
            'card_border_radius'   => 0,
            'card_border_width'    => 0,
            'card_border_color'    => '#e0e0e0',
            'card_shadow_strength' => 3,
            'card_shadow_blur'     => 6,
            'card_hover_shadow_strength' => 6,
            'card_hover_shadow_blur'     => 20,
            'card_hover_scale'     => 102,
            'card_hover_radius'    => 4,
            'card_min_width'       => 200,
            'card_max_width'       => 280,
            
            // Image
            'image_ratio'          => 125,
            'image_bg_color'       => '#f8f8f8',
            'image_object_fit'     => 'contain',
            
            // Discount badge
            'badge_visible'        => true,
            'badge_bg_color'       => '#ffffff',
            'badge_text_color'     => '#e74c3c',
            'badge_font_size'      => 13,
            'badge_font_weight'    => 700,
            'badge_border_radius'  => 20,
            'badge_text_transform' => 'none',
            'badge_position_top'   => 3,
            'badge_position_left'  => 3,
            
            // Brand
            'brand_visible'        => true,
            'brand_font_size'      => 11,
            'brand_font_weight'    => 600,
            'brand_color'          => '#222222',
            'brand_text_transform' => 'none',
            
            // Title
            'title_visible'        => true,
            'title_font_size'      => 12,
            'title_font_weight'    => 400,
            'title_color'          => '#666666',
            'title_text_transform' => 'none',
            
            // Price
            'price_visible'        => true,
            'price_font_size'      => 15,
            'price_font_weight'    => 600,
            'price_color'          => '#333333',
            'price_discount_color' => '#e74c3c',
            'old_price_font_size'  => 15,
            'old_price_font_weight'=> 700,
            'old_price_color'      => '#222222',
            
            // Shipping
            'shipping_visible'     => true,
            'shipping_font_size'   => 10,
            'shipping_color'       => '#999999',
            'shipping_text_transform' => 'uppercase',
            
            // Merchant
            'merchant_visible'     => true,
            'merchant_font_size'   => 10,
            'merchant_color'       => '#666666',
            'merchant_text_transform' => 'uppercase',
            
            // Details padding
            'details_padding_x'    => 10,
            'details_padding_y'    => 12,
            
            // Font family per element (value = font key from get_available_fonts or 'custom:FontName')
            'badge_font_family'    => '__system__',
            'brand_font_family'    => '__system__',
            'title_font_family'    => '__system__',
            'price_font_family'    => '__system__',
            'old_price_font_family'=> '__system__',
            'shipping_font_family' => '__system__',
            'merchant_font_family' => '__system__',
            
            // Element order (top to bottom, image is always first and not included here)
            'element_order' => array('brand', 'title', 'price', 'shipping', 'merchant'),
        );
        
        $saved = get_option(self::OPTION_CARD_DESIGN, array());
        return wp_parse_args($saved, $defaults);
    }

    /**
     * Save card design settings
     */
    public static function save_card_design($settings) {
        $defaults = self::get_card_design();
        $sanitized = array();
        
        foreach ($defaults as $key => $default) {
            if (!isset($settings[$key])) {
                $sanitized[$key] = $default;
                continue;
            }
            
            $val = $settings[$key];
            
            // Boolean fields
            if (is_bool($default)) {
                $sanitized[$key] = (bool) $val;
                continue;
            }
            
            // Integer fields
            if (is_int($default)) {
                $sanitized[$key] = intval($val);
                continue;
            }
            
            // Float fields
            if (is_float($default)) {
                $sanitized[$key] = floatval($val);
                continue;
            }
            
            // Color fields (contain 'color' in key name)
            if (strpos($key, 'color') !== false) {
                $sanitized[$key] = sanitize_hex_color($val) ?: $default;
                continue;
            }
            
            // Shadow fields removed — now integer-based (card_shadow_strength etc.)
            
            // String fields
            $sanitized[$key] = sanitize_text_field($val);
        }
        
        // Special handling for element_order (array of strings)
        if (isset($settings['element_order'])) {
            $valid_elements = array('brand', 'title', 'price', 'shipping', 'merchant');
            $order = $settings['element_order'];
            if (is_string($order)) {
                $order = explode(',', $order);
            }
            $order = array_map('sanitize_text_field', (array) $order);
            $order = array_values(array_intersect($order, $valid_elements));
            // Ensure all elements are present
            foreach ($valid_elements as $el) {
                if (!in_array($el, $order)) {
                    $order[] = $el;
                }
            }
            $sanitized['element_order'] = $order;
        }
        
        return update_option(self::OPTION_CARD_DESIGN, $sanitized);
    }

    /**
     * Generate CSS custom properties string from card design settings.
     * Used both in frontend output and live preview.
     */
    public static function get_card_design_css_vars() {
        $d = self::get_card_design();
        
        $vars = array(
            '--myfeeds-card-bg'              => $d['card_bg_color'],
            '--myfeeds-card-radius'          => $d['card_border_radius'] . 'px',
            '--myfeeds-card-border-width'    => $d['card_border_width'] . 'px',
            '--myfeeds-card-border-color'    => $d['card_border_color'],
            '--myfeeds-card-shadow'          => '0 0 ' . $d['card_shadow_blur'] . 'px ' . $d['card_shadow_strength'] . 'px rgba(0,0,0,' . round(min($d['card_shadow_strength'] * 0.03, 0.25), 2) . ')',
            '--myfeeds-card-hover-shadow'    => '0 0 ' . $d['card_hover_shadow_blur'] . 'px ' . $d['card_hover_shadow_strength'] . 'px rgba(0,0,0,' . round(min($d['card_hover_shadow_strength'] * 0.03, 0.3), 2) . ')',
            '--myfeeds-card-hover-scale'     => ($d['card_hover_scale'] / 100),
            '--myfeeds-card-hover-radius'    => $d['card_hover_radius'] . 'px',
            '--myfeeds-card-min-w'           => $d['card_min_width'] . 'px',
            '--myfeeds-card-max-w'           => $d['card_max_width'] . 'px',
            '--myfeeds-img-ratio'            => $d['image_ratio'] . '%',
            '--myfeeds-img-bg'               => $d['image_bg_color'],
            '--myfeeds-img-fit'              => $d['image_object_fit'],
            '--myfeeds-badge-display'        => $d['badge_visible'] ? 'block' : 'none',
            '--myfeeds-badge-bg'             => $d['badge_bg_color'],
            '--myfeeds-badge-color'          => $d['badge_text_color'],
            '--myfeeds-badge-size'           => $d['badge_font_size'] . 'px',
            '--myfeeds-badge-weight'         => $d['badge_font_weight'],
            '--myfeeds-badge-radius'         => $d['badge_border_radius'] . 'px',
            '--myfeeds-badge-top'            => $d['badge_position_top'] . '%',
            '--myfeeds-badge-left'           => $d['badge_position_left'] . '%',
            '--myfeeds-badge-transform'      => $d['badge_text_transform'],
            '--myfeeds-brand-display'        => $d['brand_visible'] ? 'block' : 'none',
            '--myfeeds-brand-size'           => $d['brand_font_size'] . 'px',
            '--myfeeds-brand-weight'         => $d['brand_font_weight'],
            '--myfeeds-brand-color'          => $d['brand_color'],
            '--myfeeds-brand-transform'      => $d['brand_text_transform'],
            '--myfeeds-title-display'        => $d['title_visible'] ? 'block' : 'none',
            '--myfeeds-title-size'           => $d['title_font_size'] . 'px',
            '--myfeeds-title-weight'         => $d['title_font_weight'],
            '--myfeeds-title-color'          => $d['title_color'],
            '--myfeeds-title-transform'      => $d['title_text_transform'],
            '--myfeeds-price-display'        => $d['price_visible'] ? 'flex' : 'none',
            '--myfeeds-price-size'           => $d['price_font_size'] . 'px',
            '--myfeeds-price-weight'         => $d['price_font_weight'],
            '--myfeeds-price-color'          => $d['price_color'],
            '--myfeeds-price-discount-color' => $d['price_discount_color'],
            '--myfeeds-old-price-size'       => $d['old_price_font_size'] . 'px',
            '--myfeeds-old-price-weight'     => $d['old_price_font_weight'],
            '--myfeeds-old-price-color'      => $d['old_price_color'],
            '--myfeeds-shipping-display'     => $d['shipping_visible'] ? 'block' : 'none',
            '--myfeeds-shipping-size'        => $d['shipping_font_size'] . 'px',
            '--myfeeds-shipping-color'       => $d['shipping_color'],
            '--myfeeds-shipping-transform'   => $d['shipping_text_transform'],
            '--myfeeds-merchant-display'     => $d['merchant_visible'] ? 'block' : 'none',
            '--myfeeds-merchant-size'        => $d['merchant_font_size'] . 'px',
            '--myfeeds-merchant-color'       => $d['merchant_color'],
            '--myfeeds-merchant-transform'   => $d['merchant_text_transform'],
            '--myfeeds-details-px'           => $d['details_padding_x'] . 'px',
            '--myfeeds-details-py'           => $d['details_padding_y'] . 'px',
            '--myfeeds-badge-font'           => self::resolve_font_family($d['badge_font_family'] ?? '__system__'),
            '--myfeeds-brand-font'           => self::resolve_font_family($d['brand_font_family'] ?? '__system__'),
            '--myfeeds-title-font'           => self::resolve_font_family($d['title_font_family'] ?? '__system__'),
            '--myfeeds-price-font'           => self::resolve_font_family($d['price_font_family'] ?? '__system__'),
            '--myfeeds-old-price-font'       => self::resolve_font_family($d['old_price_font_family'] ?? '__system__'),
            '--myfeeds-shipping-font'        => self::resolve_font_family($d['shipping_font_family'] ?? '__system__'),
            '--myfeeds-merchant-font'        => self::resolve_font_family($d['merchant_font_family'] ?? '__system__'),
            // Element order as CSS custom properties (flexbox order values)
            '--myfeeds-order-brand'    => array_search('brand', $d['element_order'] ?? array()) !== false ? array_search('brand', $d['element_order']) : 0,
            '--myfeeds-order-title'    => array_search('title', $d['element_order'] ?? array()) !== false ? array_search('title', $d['element_order']) : 1,
            '--myfeeds-order-price'    => array_search('price', $d['element_order'] ?? array()) !== false ? array_search('price', $d['element_order']) : 2,
            '--myfeeds-order-shipping' => array_search('shipping', $d['element_order'] ?? array()) !== false ? array_search('shipping', $d['element_order']) : 3,
            '--myfeeds-order-merchant' => array_search('merchant', $d['element_order'] ?? array()) !== false ? array_search('merchant', $d['element_order']) : 4,
        );
        
        $css = ':root {' . "\n";
        foreach ($vars as $prop => $value) {
            $css .= "  {$prop}: {$value};\n";
        }
        $css .= '}';
        
        return $css;
    }

    /**
     * Resolve a font key to a CSS font-family value with fallback stack
     */
    public static function resolve_font_family($font_key) {
        if (empty($font_key) || $font_key === '__system__') {
            return '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        }
        
        // Custom font
        if (strpos($font_key, 'custom:') === 0) {
            $name = substr($font_key, 7);
            return '"' . $name . '", sans-serif';
        }
        
        // Google font
        $fonts = self::get_available_fonts();
        if (isset($fonts[$font_key])) {
            $category = $fonts[$font_key]['category'] ?? 'sans-serif';
            return '"' . $font_key . '", ' . $category;
        }
        
        return 'inherit';
    }

    /**
     * Curated list of Google Fonts bundled with the plugin.
     * Covers ~70 popular fonts across categories: sans-serif, serif, display, handwriting, monospace.
     * Each entry: 'Font Name' => 'category' (for CSS fallback stack).
     * 
     * To add more: just append to this array. The font is auto-loaded from Google Fonts CDN.
     */
    public static function get_available_fonts() {
        return array(
            // Default (system font stack — no Google Fonts load needed)
            '__system__' => array('label' => 'System Default', 'category' => 'sans-serif', 'google' => false),
            
            // === SANS-SERIF ===
            'Inter' => array('label' => 'Inter', 'category' => 'sans-serif', 'google' => true),
            'Roboto' => array('label' => 'Roboto', 'category' => 'sans-serif', 'google' => true),
            'Open Sans' => array('label' => 'Open Sans', 'category' => 'sans-serif', 'google' => true),
            'Lato' => array('label' => 'Lato', 'category' => 'sans-serif', 'google' => true),
            'Montserrat' => array('label' => 'Montserrat', 'category' => 'sans-serif', 'google' => true, 'weights' => '100;200;300;400;500;600;700;800;900'),
            'Poppins' => array('label' => 'Poppins', 'category' => 'sans-serif', 'google' => true),
            'Raleway' => array('label' => 'Raleway', 'category' => 'sans-serif', 'google' => true, 'weights' => '100;200;300;400;500;600;700;800;900'),
            'Nunito' => array('label' => 'Nunito', 'category' => 'sans-serif', 'google' => true),
            'Nunito Sans' => array('label' => 'Nunito Sans', 'category' => 'sans-serif', 'google' => true),
            'Work Sans' => array('label' => 'Work Sans', 'category' => 'sans-serif', 'google' => true),
            'DM Sans' => array('label' => 'DM Sans', 'category' => 'sans-serif', 'google' => true),
            'Manrope' => array('label' => 'Manrope', 'category' => 'sans-serif', 'google' => true),
            'League Spartan' => array('label' => 'League Spartan', 'category' => 'sans-serif', 'google' => true),
            'Outfit' => array('label' => 'Outfit', 'category' => 'sans-serif', 'google' => true),
            'Plus Jakarta Sans' => array('label' => 'Plus Jakarta Sans', 'category' => 'sans-serif', 'google' => true),
            'Figtree' => array('label' => 'Figtree', 'category' => 'sans-serif', 'google' => true),
            'Source Sans 3' => array('label' => 'Source Sans 3', 'category' => 'sans-serif', 'google' => true),
            'Rubik' => array('label' => 'Rubik', 'category' => 'sans-serif', 'google' => true),
            'Karla' => array('label' => 'Karla', 'category' => 'sans-serif', 'google' => true),
            'Barlow' => array('label' => 'Barlow', 'category' => 'sans-serif', 'google' => true),
            'Barlow Condensed' => array('label' => 'Barlow Condensed', 'category' => 'sans-serif', 'google' => true),
            'Mulish' => array('label' => 'Mulish', 'category' => 'sans-serif', 'google' => true),
            'Josefin Sans' => array('label' => 'Josefin Sans', 'category' => 'sans-serif', 'google' => true),
            'Quicksand' => array('label' => 'Quicksand', 'category' => 'sans-serif', 'google' => true),
            'Ubuntu' => array('label' => 'Ubuntu', 'category' => 'sans-serif', 'google' => true),
            'Cabin' => array('label' => 'Cabin', 'category' => 'sans-serif', 'google' => true),
            'Archivo' => array('label' => 'Archivo', 'category' => 'sans-serif', 'google' => true),
            'Oswald' => array('label' => 'Oswald', 'category' => 'sans-serif', 'google' => true),
            'Titillium Web' => array('label' => 'Titillium Web', 'category' => 'sans-serif', 'google' => true),
            'Overpass' => array('label' => 'Overpass', 'category' => 'sans-serif', 'google' => true),
            'Lexend' => array('label' => 'Lexend', 'category' => 'sans-serif', 'google' => true),
            'Space Grotesk' => array('label' => 'Space Grotesk', 'category' => 'sans-serif', 'google' => true),
            'Sora' => array('label' => 'Sora', 'category' => 'sans-serif', 'google' => true),
            
            // === SERIF ===
            'Playfair Display' => array('label' => 'Playfair Display', 'category' => 'serif', 'google' => true),
            'Merriweather' => array('label' => 'Merriweather', 'category' => 'serif', 'google' => true),
            'Lora' => array('label' => 'Lora', 'category' => 'serif', 'google' => true),
            'PT Serif' => array('label' => 'PT Serif', 'category' => 'serif', 'google' => true),
            'Noto Serif' => array('label' => 'Noto Serif', 'category' => 'serif', 'google' => true),
            'Noto Serif Display' => array('label' => 'Noto Serif Display', 'category' => 'serif', 'google' => true),
            'Source Serif 4' => array('label' => 'Source Serif 4', 'category' => 'serif', 'google' => true),
            'Libre Baskerville' => array('label' => 'Libre Baskerville', 'category' => 'serif', 'google' => true),
            'EB Garamond' => array('label' => 'EB Garamond', 'category' => 'serif', 'google' => true),
            'Crimson Text' => array('label' => 'Crimson Text', 'category' => 'serif', 'google' => true),
            'Bitter' => array('label' => 'Bitter', 'category' => 'serif', 'google' => true),
            'DM Serif Display' => array('label' => 'DM Serif Display', 'category' => 'serif', 'google' => true),
            'Cormorant Garamond' => array('label' => 'Cormorant Garamond', 'category' => 'serif', 'google' => true),
            'Bodoni Moda' => array('label' => 'Bodoni Moda', 'category' => 'serif', 'google' => true),
            
            // === DISPLAY / DECORATIVE ===
            'Abril Fatface' => array('label' => 'Abril Fatface', 'category' => 'display', 'google' => true),
            'Bebas Neue' => array('label' => 'Bebas Neue', 'category' => 'display', 'google' => true),
            'Anton' => array('label' => 'Anton', 'category' => 'display', 'google' => true),
            'Righteous' => array('label' => 'Righteous', 'category' => 'display', 'google' => true),
            'Alfa Slab One' => array('label' => 'Alfa Slab One', 'category' => 'display', 'google' => true),
            'Passion One' => array('label' => 'Passion One', 'category' => 'display', 'google' => true),
            'Permanent Marker' => array('label' => 'Permanent Marker', 'category' => 'display', 'google' => true),
            'Fredoka' => array('label' => 'Fredoka', 'category' => 'display', 'google' => true),
            'Bungee' => array('label' => 'Bungee', 'category' => 'display', 'google' => true),
            
            // === HANDWRITING / SCRIPT ===
            'Pacifico' => array('label' => 'Pacifico', 'category' => 'handwriting', 'google' => true),
            'Dancing Script' => array('label' => 'Dancing Script', 'category' => 'handwriting', 'google' => true),
            'Caveat' => array('label' => 'Caveat', 'category' => 'handwriting', 'google' => true),
            'Lobster' => array('label' => 'Lobster', 'category' => 'handwriting', 'google' => true),
            'Sacramento' => array('label' => 'Sacramento', 'category' => 'handwriting', 'google' => true),
            'Great Vibes' => array('label' => 'Great Vibes', 'category' => 'handwriting', 'google' => true),
            'Satisfy' => array('label' => 'Satisfy', 'category' => 'handwriting', 'google' => true),
            'Kalam' => array('label' => 'Kalam', 'category' => 'handwriting', 'google' => true),
            
            // === MONOSPACE ===
            'JetBrains Mono' => array('label' => 'JetBrains Mono', 'category' => 'monospace', 'google' => true),
            'Fira Code' => array('label' => 'Fira Code', 'category' => 'monospace', 'google' => true),
            'IBM Plex Mono' => array('label' => 'IBM Plex Mono', 'category' => 'monospace', 'google' => true),
            'Source Code Pro' => array('label' => 'Source Code Pro', 'category' => 'monospace', 'google' => true),
        );
    }

    /**
     * Get custom fonts uploaded by user.
     * Stored as: [ ['name' => 'My Font', 'url' => 'https://...font.woff2'], ... ]
     */
    public static function get_custom_fonts() {
        return get_option('myfeeds_custom_fonts', array());
    }

    /**
     * Save custom fonts list
     */
    public static function save_custom_fonts($fonts) {
        $sanitized = array();
        foreach ($fonts as $font) {
            if (empty($font['name']) || empty($font['url'])) continue;
            $sanitized[] = array(
                'name' => sanitize_text_field($font['name']),
                'url'  => esc_url_raw($font['url']),
            );
        }
        return update_option('myfeeds_custom_fonts', $sanitized);
    }

    /**
     * Build the full font options array (Google + Custom) for dropdowns
     */
    public static function get_all_font_options() {
        $options = array();
        
        // System default first
        $options['__system__'] = 'System Default';
        
        // Google fonts grouped by category
        $fonts = self::get_available_fonts();
        foreach ($fonts as $key => $font) {
            if ($key === '__system__') continue;
            $options[$key] = $font['label'];
        }
        
        // Custom fonts
        $custom = self::get_custom_fonts();
        if (!empty($custom)) {
            foreach ($custom as $cf) {
                $options['custom:' . $cf['name']] = $cf['name'] . ' (Custom)';
            }
        }
        
        return $options;
    }

    /**
     * Get Google Fonts CDN URL for a set of font names.
     * Combines multiple fonts into one request for performance.
     * 
     * @param array $font_names Array of font names to load
     * @return string Google Fonts CSS URL, or empty string if no Google fonts needed
     */
    public static function get_google_fonts_url($font_names) {
        $fonts = self::get_available_fonts();
        $families = array();
        
        foreach ($font_names as $name) {
            if ($name === '__system__' || empty($name) || strpos($name, 'custom:') === 0) continue;
            if (!isset($fonts[$name]) || !$fonts[$name]['google']) continue;
            
            $weights = $fonts[$name]['weights'] ?? '400;500;600;700';
            $family = str_replace(' ', '+', $name) . ':wght@' . $weights;
            $families[] = $family;
        }
        
        if (empty($families)) return '';
        
        return 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $families) . '&display=swap';
    }
}
