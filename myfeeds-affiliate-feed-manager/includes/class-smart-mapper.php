<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Universal Smart Mapper Class
 * Automatically detects and maps product fields from ANY affiliate network feed
 */
class MyFeeds_Smart_Mapper {
    
    private $universal_field_patterns;
    private $network_signatures;
    private $confidence_weights;
    private $last_detected_network = '';
    
    public function get_last_detected_network() {
        return $this->last_detected_network;
    }
    
    public function __construct() {
        $this->init_universal_patterns();
        $this->init_network_signatures();
        $this->init_confidence_weights();
    }
    
    /**
     * Initialize universal field patterns for automatic detection
     */
    private function init_universal_patterns() {
        $this->universal_field_patterns = [
            // Product ID - most critical field
            'id' => [
                'exact_matches' => ['id', 'product_id', 'item_id', 'pid', 'product_code', 'sku', 'offer_id', 'compositeId'],
                'contains_patterns' => ['prod_id', 'productid', 'item_id', 'ean', 'gtin', '_id'],
                'suffix_patterns' => ['_id', 'Id', '_ID', '_pid'],
                'weight' => 100
            ],
            
            // Product Title - essential
            'title' => [
                'exact_matches' => ['title', 'name', 'product_name', 'item_name', 'product_title', 'n'],
                'contains_patterns' => ['title', 'name', '_name', 'label'],
                'suffix_patterns' => ['_title', '_name', 'Name', 'Title'],
                'weight' => 95
            ],
            
            // Current Price - essential
            'price' => [
                'exact_matches' => ['price', 'current_price', 'sale_price', 'final_price'],
                'contains_patterns' => ['price', 'cost', 'amount'],
                'suffix_patterns' => ['_price', '_cost', 'Price', 'Cost'],
                'exclusion_patterns' => ['old_', 'original_', 'rrp', 'msrp', 'retail'],
                'weight' => 90
            ],
            
            // Old Price (crossed-out price)
            'old_price' => [
                'exact_matches' => ['old_price', 'original_price', 'rrp', 'msrp', 'retail_price', 'list_price', 'oldprice', 'was_price'],
                'contains_patterns' => ['old_price', 'original', 'rrp', 'msrp', 'retail', 'list_price', 'oldprice'],
                'prefix_patterns' => ['old_', 'original_', 'prev_', 'before_'],
                'weight' => 80
            ],
            
            // Brand/Manufacturer
            'brand' => [
                'exact_matches' => ['brand', 'manufacturer', 'brand_name', 'make', 'vendor'],
                'contains_patterns' => ['brand', 'manufacturer', 'make', 'vendor'],
                'suffix_patterns' => ['_brand', '_manufacturer', 'Brand'],
                'weight' => 75
            ],
            
            // Merchant/Store (where the link goes)
            'merchant' => [
                'exact_matches' => ['merchant_name', 'advertiser_name', 'merchant', 'store_name', 'shop_name', 'advertiser', 'retailer', 'vendor', 'shopname', 'program_name'],
                'contains_patterns' => ['merchant_name', 'advertiser_name', 'store_name', 'shop_name', 'merchant', 'advertiser', 'retailer', 'program_name'],
                'suffix_patterns' => ['_name', '_merchant', '_store', '_shop', '_advertiser'],
                'exclusion_patterns' => ['_id', 'merchant_id', 'advertiser_id', 'store_id'],
                'weight' => 70
            ],
            
            // Product Images
            'image_url' => [
                'exact_matches' => ['image', 'image_url', 'img_url', 'picture', 'photo', 'image_link'],
                'contains_patterns' => ['image', 'img', 'picture', 'photo', 'image_link'],
                'suffix_patterns' => ['_image', '_img', '_url', 'Image', 'Img'],
                'weight' => 85
            ],
            
            // Additional Images for detail view
            'additional_images' => [
                'exact_matches' => ['images', 'additional_images', 'gallery', 'photos', 'additional_image_link'],
                'contains_patterns' => ['images', 'gallery', 'photos', 'additional'],
                'prefix_patterns' => ['additional_', 'extra_', 'more_'],
                'weight' => 60
            ],
            
            // Affiliate Link
            'affiliate_link' => [
                'exact_matches' => ['link', 'url', 'affiliate_link', 'product_url', 'deep_link', 'aw_deep_link', 'merchant_deep_link'],
                'contains_patterns' => ['link', 'url', '_link', 'deep_link'],
                'suffix_patterns' => ['_link', '_url', 'Link', 'URL'],
                'weight' => 95
            ],
            
            // Description
            'description' => [
                'exact_matches' => ['description', 'desc', 'details', 'product_description'],
                'contains_patterns' => ['description', 'desc', 'details', 'summary'],
                'suffix_patterns' => ['_description', '_desc', '_details'],
                'weight' => 65
            ],
            
            // Category
            'category' => [
                'exact_matches' => ['category', 'cat', 'type', 'product_type', 'product_category', 'categoryId', 'google_product_category_text'],
                'contains_patterns' => ['category', 'cat', 'type', 'product_type'],
                'suffix_patterns' => ['_category', '_cat', '_type', 'Category'],
                'weight' => 60
            ],
            
            // Currency
            'currency' => [
                'exact_matches' => ['currency', 'price_currency', 'curr', 'currencyId'],
                'contains_patterns' => ['currency', 'curr'],
                'suffix_patterns' => ['_currency', '_curr', 'Currency'],
                'weight' => 50
            ],
            
            // Shipping Costs
            'shipping' => [
                'exact_matches' => ['shipping', 'delivery_cost', 'shipping_cost', 'postage'],
                'contains_patterns' => ['shipping', 'delivery', 'postage', 'freight'],
                'prefix_patterns' => ['shipping_', 'delivery_'],
                'weight' => 55
            ],
            
            // Availability/Stock
            'availability' => [
                'exact_matches' => ['availability', 'stock', 'in_stock', 'available'],
                'contains_patterns' => ['stock', 'available', 'availability'],
                'suffix_patterns' => ['_stock', '_available', 'Stock'],
                'weight' => 45
            ],
            
            // Product Attributes
            'attributes' => [
                'color' => [
                    'exact_matches' => ['color', 'colour', 'product_color'],
                    'contains_patterns' => ['color', 'colour'],
                    'weight' => 40
                ],
                'size' => [
                    'exact_matches' => ['size', 'sizes', 'product_size'],
                    'contains_patterns' => ['size'],
                    'weight' => 40
                ],
                'material' => [
                    'exact_matches' => ['material', 'fabric', 'composition'],
                    'contains_patterns' => ['material', 'fabric'],
                    'weight' => 35
                ],
                'gender' => [
                    'exact_matches' => ['gender', 'target_gender', 'for_gender'],
                    'contains_patterns' => ['gender'],
                    'weight' => 30
                ]
            ]
        ];
    }
    
    /**
     * Initialize network signature patterns for automatic network detection
     */
    private function init_network_signatures() {
        $this->network_signatures = [
            'awin' => [
                'signature_fields' => ['aw_product_id', 'aw_deep_link', 'aw_image_url', 'merchant_product_id'],
                'url_patterns' => ['awin.com', 'affiliate-window'],
                'field_prefixes' => ['aw_', 'awin_'],
                'confidence_boost' => 20,
                // AWIN-specific field mappings (hard-coded for accuracy)
                'field_mappings' => [
                    'id' => 'aw_product_id',
                    'title' => 'product_name',
                    'price' => ['search_price', 'display_price', 'store_price'],
                    'old_price' => ['rrp_price', 'product_price_old'],
                    'brand' => 'brand_name',
                    'merchant' => 'merchant_name', // CRITICAL: Use merchant_name, NOT merchant_id
                    'image_url' => ['aw_image_url', 'merchant_image_url'],
                    'affiliate_link' => 'aw_deep_link',
                    'description' => ['product_short_description', 'description'],
                    'category' => 'category_name',
                    'currency' => 'currency',
                    'shipping' => 'delivery_cost',
                    'availability' => ['in_stock', 'stock_status'],
                    'discount_percentage' => 'savings_percent' // Direct discount field!
                ]
            ],
            'webgains' => [
                'signature_fields' => ['program_name', 'image_link', 'sale_price', 'google_product_category'],
                'url_patterns' => ['webgains.com', 'platform-api.webgains.com', 'ikhnaie.link'],
                'field_prefixes' => [],
                'confidence_boost' => 20,
                'field_mappings' => [
                    'id' => 'id',
                    'title' => 'title',
                    'price' => ['sale_price', 'price'],
                    'old_price' => 'price',
                    'brand' => 'brand',
                    'merchant' => 'program_name',
                    'image_url' => 'image_link',
                    'affiliate_link' => 'link',
                    'description' => 'description',
                    'category' => ['google_product_category_text', 'google_product_category'],
                    'currency' => 'currencyId',
                    'shipping' => 'shipping',
                    'availability' => 'availability',
                    'attributes' => [
                        'color' => 'color',
                        'size' => 'size',
                        'gender' => 'gender',
                    ],
                ]
            ],
            'admitad' => [
                'signature_fields' => ['vendor', 'currencyId', 'categoryId', 'modified_time'],
                'url_patterns' => ['admitad.com', 'export.admitad.com', 'bywiola.com'],
                'field_prefixes' => [],
                'confidence_boost' => 20,
                'field_mappings' => [
                    'id' => 'id',
                    'title' => ['name', 'n', 'title'],
                    'price' => 'price',
                    'old_price' => 'oldprice',
                    'brand' => 'vendor',
                    'merchant' => ['program_name', 'custom_label_3'],
                    'image_url' => ['image_link', 'picture'],
                    'affiliate_link' => ['link', 'url'],
                    'description' => 'description',
                    'category' => ['product_type', 'categoryId'],
                    'currency' => 'currencyId',
                    'availability' => ['availability', 'available'],
                    'attributes' => [
                        'size' => ['size', 'param_size'],
                        'gender' => ['gender', 'param_gender'],
                        'color' => ['color', 'param_color'],
                        'material' => ['material', 'param_material'],
                    ],
                ]
            ],
            'google_shopping' => [
                'signature_fields' => ['title', 'link', 'image_link', 'availability', 'condition'],
                'url_patterns' => [],
                'field_prefixes' => ['g_'],
                'confidence_boost' => 30,
                'field_mappings' => [
                    'id' => 'id',
                    'title' => 'title',
                    'price' => ['sale_price', 'price'],
                    'old_price' => 'price',
                    'brand' => 'brand',
                    'merchant' => ['program_name', 'source'],
                    'image_url' => 'image_link',
                    'additional_images' => 'additional_image_link',
                    'affiliate_link' => 'link',
                    'description' => 'description',
                    'category' => ['product_type', 'google_product_category_text', 'google_product_category'],
                    'currency' => 'currency',
                    'availability' => 'availability',
                    'attributes' => [
                        'color' => 'color',
                        'size' => 'size',
                        'gender' => 'gender',
                        'material' => 'material',
                    ],
                ]
            ],
            'tradedoubler' => [
                'signature_fields' => ['TDProductId', 'TD_PROD_ID', 'TD_PRODUCT_URL', 'productUrl'],
                'url_patterns' => ['tradedoubler.com', 'clkuk.tradedoubler'],
                'field_prefixes' => ['TD_', 'td_'],
                'confidence_boost' => 20
            ],
            'amazon' => [
                'signature_fields' => ['ASIN', 'amazon_product_id', 'amzn_'],
                'url_patterns' => ['amazon.', 'amzn.'],
                'field_prefixes' => ['amazon_', 'amzn_', 'aws_'],
                'confidence_boost' => 15
            ],
            'commissionjunction' => [
                'signature_fields' => ['cj_product_id', 'commission_junction'],
                'url_patterns' => ['cj.com', 'commission-junction', 'dpbolvw.net', 'anrdoezrs.net', 'jdoqocy.com'],
                'field_prefixes' => ['cj_'],
                'confidence_boost' => 15
            ],
            'shareASale' => [
                'signature_fields' => ['sas_product_id', 'shareasale'],
                'url_patterns' => ['shareasale.com'],
                'field_prefixes' => ['sas_', 'sa_'],
                'confidence_boost' => 15
            ],
            'impact' => [
                'signature_fields' => ['impact_product_id', 'campaign_id'],
                'url_patterns' => ['impact.com', 'impactradius.com', 'sjv.io'],
                'field_prefixes' => [],
                'confidence_boost' => 15
            ],
            'partnerize' => [
                'signature_fields' => ['partnerize_id', 'camref'],
                'url_patterns' => ['partnerize.com', 'performancehorizon.com', 'prf.hn'],
                'field_prefixes' => [],
                'confidence_boost' => 15
            ],
            'rakuten' => [
                'signature_fields' => ['linksynergy_id', 'mid'],
                'url_patterns' => ['rakutenadvertising.com', 'linksynergy.com', 'click.linksynergy.com'],
                'field_prefixes' => [],
                'confidence_boost' => 15
            ],
            'ebay' => [
                'signature_fields' => ['ebay_id', 'item_id', 'epid'],
                'url_patterns' => ['ebay.', 'rover.ebay'],
                'field_prefixes' => ['ebay_'],
                'confidence_boost' => 15
            ],
            'generic_csv' => [
                'signature_fields' => ['product_id', 'title', 'price', 'link'],
                'confidence_boost' => 5
            ]
        ];
    }
    
    /**
     * Initialize confidence weights for field matching
     */
    private function init_confidence_weights() {
        $this->confidence_weights = [
            'exact_match' => 100,
            'case_insensitive_match' => 95,
            'contains_pattern' => 80,
            'prefix_pattern' => 75,
            'suffix_pattern' => 75,
            'partial_match' => 60,
            'position_bonus' => 10, // Fields appearing earlier in CSV get bonus
            'network_signature_bonus' => 20
        ];
    }
    
    /**
     * Main method: Automatically detect and map fields from ANY feed
     */
    public function auto_map_fields($sample_data, $feed_url = null) {
        if (!is_array($sample_data) || empty($sample_data)) {
            MyFeeds_Affiliate_Product_Picker::log('❌ Smart Mapper: Invalid sample data provided');
            return false;
        }
        
        $available_fields = array_keys($sample_data);
        MyFeeds_Affiliate_Product_Picker::log('🧠 Smart Mapper: Analyzing ' . count($available_fields) . ' fields');
        
        // Detect network for enhanced mapping
        $detected_network = $this->detect_network_advanced($available_fields, $feed_url);
        $this->last_detected_network = $detected_network ?: '';
        MyFeeds_Affiliate_Product_Picker::log('🔍 Detected Network: ' . ($detected_network ?: 'Universal'));
        
        // Generate universal mapping with confidence scoring
        $mapping = $this->generate_universal_mapping($available_fields, $detected_network);
        
        if (!$mapping) {
            MyFeeds_Affiliate_Product_Picker::log('❌ Smart Mapper: Could not generate mapping');
            return false;
        }
        
        // Validate critical fields
        $validation_result = $this->validate_critical_fields($mapping);
        if (is_wp_error($validation_result)) {
            MyFeeds_Affiliate_Product_Picker::log('❌ Smart Mapper: ' . $validation_result->get_error_message());
            return false;
        }
        
        MyFeeds_Affiliate_Product_Picker::log('✅ Smart Mapper: Successfully mapped ' . count($mapping) . ' fields');
        return $mapping;
    }
    
    /**
     * Advanced network detection with multiple methods
     */
    private function detect_network_advanced($available_fields, $feed_url = null) {
        $network_scores = [];
        
        // Method 1: URL Pattern Analysis
        if ($feed_url) {
            foreach ($this->network_signatures as $network => $config) {
                if (isset($config['url_patterns'])) {
                    foreach ($config['url_patterns'] as $pattern) {
                        if (stripos($feed_url, $pattern) !== false) {
                            $network_scores[$network] = ($network_scores[$network] ?? 0) + $config['confidence_boost'];
                            MyFeeds_Affiliate_Product_Picker::log("🔗 URL pattern '$pattern' matches network: $network");
                        }
                    }
                }
            }
        }
        
        // Method 2: Signature Field Analysis
        foreach ($this->network_signatures as $network => $config) {
            $signature_matches = 0;
            foreach ($config['signature_fields'] as $signature_field) {
                if (in_array($signature_field, $available_fields)) {
                    $signature_matches++;
                }
            }
            
            if ($signature_matches > 0) {
                $score = ($signature_matches / count($config['signature_fields'])) * $config['confidence_boost'];
                $network_scores[$network] = ($network_scores[$network] ?? 0) + $score;
                MyFeeds_Affiliate_Product_Picker::log("🏷️ Network '$network' signature match: $signature_matches fields");
            }
        }
        
        // Method 3: Field Prefix Analysis
        foreach ($this->network_signatures as $network => $config) {
            if (isset($config['field_prefixes'])) {
                $prefix_matches = 0;
                foreach ($available_fields as $field) {
                    foreach ($config['field_prefixes'] as $prefix) {
                        if (stripos($field, $prefix) === 0) {
                            $prefix_matches++;
                            break;
                        }
                    }
                }
                
                if ($prefix_matches > 0) {
                    $score = min($prefix_matches * 5, $config['confidence_boost']);
                    $network_scores[$network] = ($network_scores[$network] ?? 0) + $score;
                }
            }
        }
        
        // Return network with highest confidence
        if (!empty($network_scores)) {
            arsort($network_scores);
            $best_network = array_key_first($network_scores);
            
            // Google Shopping override: ONLY if no network was detected via URL pattern.
            // Many networks (Webgains, Admitad, etc.) serve Google Shopping format feeds.
            // URL-based detection is more reliable than field-based detection in these cases.
            if ($best_network !== 'google_shopping' && isset($network_scores['google_shopping'])) {
                // Check if the current best_network was detected via URL (has URL-based score)
                $best_has_url_score = false;
                if (isset($this->network_signatures[$best_network]['url_patterns'])) {
                    foreach ($this->network_signatures[$best_network]['url_patterns'] as $pattern) {
                        if ($feed_url && stripos($feed_url, $pattern) !== false) {
                            $best_has_url_score = true;
                            break;
                        }
                    }
                }

                // Only override if the best network was NOT detected via URL
                if (!$best_has_url_score) {
                    $gs_sig_matches = 0;
                    $gs_sig_fields = $this->network_signatures['google_shopping']['signature_fields'] ?? [];
                    foreach ($gs_sig_fields as $sf) {
                        if (in_array($sf, $available_fields)) {
                            $gs_sig_matches++;
                        }
                    }
                    if ($gs_sig_matches >= 4) {
                        $best_network = 'google_shopping';
                        $network_scores['google_shopping'] = max($network_scores) + 1;
                        MyFeeds_Affiliate_Product_Picker::log("🔄 Google Shopping override: {$gs_sig_matches} signature fields matched, overriding non-URL-based detection");
                    }
                } else {
                    MyFeeds_Affiliate_Product_Picker::log("🔒 Google Shopping override SKIPPED: {$best_network} was detected via URL pattern (more reliable)");
                }
            }
            
            MyFeeds_Affiliate_Product_Picker::log("🎯 Best network match: $best_network (score: {$network_scores[$best_network]})");
            return $best_network;
        }
        
        return null;
    }
    
    /**
     * Generate universal field mapping with confidence scoring
     */
    private function generate_universal_mapping($available_fields, $detected_network = null) {
        $mapping = [];
        $field_usage = []; // Track which fields have been used
        
        // First, check if we have network-specific hard-coded mappings (e.g., AWIN)
        if ($detected_network && isset($this->network_signatures[$detected_network]['field_mappings'])) {
            $network_mappings = $this->network_signatures[$detected_network]['field_mappings'];
            
            foreach ($network_mappings as $standard_field => $network_field) {
                // Special handling for nested 'attributes' mapping
                // e.g. 'attributes' => ['color' => 'color', 'size' => 'size', 'gender' => 'gender']
                if ($standard_field === 'attributes' && is_array($network_field)) {
                    $attr_mapping = [];
                    foreach ($network_field as $attr_name => $attr_field) {
                        // Each attribute can also be an array of alternatives
                        if (is_array($attr_field)) {
                            foreach ($attr_field as $attr_option) {
                                if (in_array($attr_option, $available_fields)) {
                                    $attr_mapping[$attr_name] = $attr_option;
                                    $field_usage[$attr_option] = "attributes.{$attr_name}";
                                    MyFeeds_Affiliate_Product_Picker::log("✅ Mapped 'attributes.{$attr_name}' → '{$attr_option}' (network-specific)");
                                    break;
                                }
                            }
                        } else {
                            if (in_array($attr_field, $available_fields)) {
                                $attr_mapping[$attr_name] = $attr_field;
                                $field_usage[$attr_field] = "attributes.{$attr_name}";
                                MyFeeds_Affiliate_Product_Picker::log("✅ Mapped 'attributes.{$attr_name}' → '{$attr_field}' (network-specific)");
                            }
                        }
                    }
                    if (!empty($attr_mapping)) {
                        $mapping['attributes'] = $attr_mapping;
                    }
                    continue;
                }

                // Handle array of possible fields (priority order)
                if (is_array($network_field)) {
                    foreach ($network_field as $field_option) {
                        if (in_array($field_option, $available_fields)) {
                            $mapping[$standard_field] = $field_option;
                            $field_usage[$field_option] = $standard_field;
                            MyFeeds_Affiliate_Product_Picker::log("✅ Mapped '$standard_field' → '$field_option' (network-specific)");
                            break;
                        }
                    }
                } else {
                    // Single field mapping
                    if (in_array($network_field, $available_fields)) {
                        $mapping[$standard_field] = $network_field;
                        $field_usage[$network_field] = $standard_field;
                        MyFeeds_Affiliate_Product_Picker::log("✅ Mapped '$standard_field' → '$network_field' (network-specific)");
                    }
                }
            }
        }
        
        // Then, use universal patterns for any unmapped fields
        foreach ($this->universal_field_patterns as $standard_field => $pattern_config) {
            // Skip if already mapped by network-specific rules
            if (isset($mapping[$standard_field])) {
                continue;
            }
            
            if ($standard_field === 'attributes') {
                $mapping['attributes'] = $this->map_attribute_fields($available_fields, $pattern_config, $field_usage);
                continue;
            }
            
            $best_match = $this->find_best_field_match($available_fields, $pattern_config, $field_usage, $detected_network);
            
            if ($best_match) {
                $mapping[$standard_field] = $best_match['field'];
                $field_usage[$best_match['field']] = $standard_field;
                
                MyFeeds_Affiliate_Product_Picker::log("✅ Mapped '$standard_field' → '{$best_match['field']}' (confidence: {$best_match['confidence']})");
            } else {
                MyFeeds_Affiliate_Product_Picker::log("⚠️ No mapping found for '$standard_field'");
            }
        }
        
        return $mapping;
    }
    
    /**
     * Find best field match with confidence scoring
     */
    private function find_best_field_match($available_fields, $pattern_config, $field_usage, $detected_network) {
        $candidates = [];
        
        foreach ($available_fields as $index => $field) {
            // Skip if field already used
            if (isset($field_usage[$field])) {
                continue;
            }
            
            $confidence = 0;
            
            // Exact match
            if (in_array(strtolower($field), array_map('strtolower', $pattern_config['exact_matches']))) {
                $confidence += $this->confidence_weights['exact_match'];
            }
            
            // Contains pattern
            if (isset($pattern_config['contains_patterns'])) {
                foreach ($pattern_config['contains_patterns'] as $pattern) {
                    if (stripos($field, $pattern) !== false) {
                        $confidence += $this->confidence_weights['contains_pattern'];
                        break;
                    }
                }
            }
            
            // Prefix pattern
            if (isset($pattern_config['prefix_patterns'])) {
                foreach ($pattern_config['prefix_patterns'] as $pattern) {
                    if (stripos($field, $pattern) === 0) {
                        $confidence += $this->confidence_weights['prefix_pattern'];
                        break;
                    }
                }
            }
            
            // Suffix pattern
            if (isset($pattern_config['suffix_patterns'])) {
                foreach ($pattern_config['suffix_patterns'] as $pattern) {
                    if (stripos($field, $pattern) === (strlen($field) - strlen($pattern))) {
                        $confidence += $this->confidence_weights['suffix_pattern'];
                        break;
                    }
                }
            }
            
            // Exclusion patterns (negative scoring)
            if (isset($pattern_config['exclusion_patterns'])) {
                foreach ($pattern_config['exclusion_patterns'] as $exclusion) {
                    if (stripos($field, $exclusion) !== false) {
                        $confidence -= 50; // Heavy penalty for exclusions
                        break;
                    }
                }
            }
            
            // Position bonus (earlier fields often more important)
            $position_bonus = (count($available_fields) > 0) ? max(0, (count($available_fields) - $index) / count($available_fields) * $this->confidence_weights['position_bonus']) : 0;
            $confidence += $position_bonus;
            
            // Network-specific bonus
            if ($detected_network && isset($this->network_signatures[$detected_network]['field_prefixes'])) {
                foreach ($this->network_signatures[$detected_network]['field_prefixes'] as $prefix) {
                    if (stripos($field, $prefix) === 0) {
                        $confidence += $this->confidence_weights['network_signature_bonus'];
                        break;
                    }
                }
            }
            
            // Only consider candidates with positive confidence
            if ($confidence > 30) {
                $candidates[] = [
                    'field' => $field,
                    'confidence' => $confidence
                ];
            }
        }
        
        // Return best candidate
        if (!empty($candidates)) {
            usort($candidates, function($a, $b) {
                return $b['confidence'] <=> $a['confidence'];
            });
            
            return $candidates[0];
        }
        
        return null;
    }
    
    /**
     * Map attribute fields (color, size, etc.)
     */
    private function map_attribute_fields($available_fields, $attributes_config, &$field_usage) {
        $attribute_mapping = [];
        
        foreach ($attributes_config as $attr_name => $attr_pattern) {
            $best_match = $this->find_best_field_match($available_fields, $attr_pattern, $field_usage, null);
            
            if ($best_match) {
                $attribute_mapping[$attr_name] = $best_match['field'];
                $field_usage[$best_match['field']] = "attributes.$attr_name";
            }
        }
        
        return $attribute_mapping;
    }
    
    /**
     * Validate that critical fields are mapped
     */
    private function validate_critical_fields($mapping) {
        $critical_fields = ['id', 'title', 'price', 'affiliate_link'];
        $missing_fields = [];
        
        foreach ($critical_fields as $field) {
            if (!isset($mapping[$field]) || empty($mapping[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            return new WP_Error('missing_critical_fields', 
                'Critical fields missing from mapping: ' . implode(', ', $missing_fields));
        }
        
        return true;
    }
    
    /**
     * Apply universal mapping to product data with intelligent processing
     */
    public function map_product($product_data, $mapping) {
        if (!is_array($product_data) || !is_array($mapping)) {
            return $product_data;
        }
        
        $mapped_product = [];
        
        foreach ($mapping as $standard_field => $field_path) {
            if ($standard_field === 'attributes' && is_array($field_path)) {
                $mapped_product['attributes'] = [];
                foreach ($field_path as $attr_name => $attr_path) {
                    $value = $this->extract_field_value($product_data, $attr_path);
                    if ($value !== null) {
                        $mapped_product['attributes'][$attr_name] = $this->process_attribute_value($value);
                    }
                }
            } else {
                $value = $this->extract_field_value($product_data, $field_path);
                if ($value !== null) {
                    $mapped_product[$standard_field] = $this->process_field_value($standard_field, $value);
                }
            }
        }
        
        // Post-process for intelligent price handling
        $mapped_product = $this->intelligent_price_processing($mapped_product);
        
        // Process shipping information
        $mapped_product = $this->process_shipping_info($mapped_product);
        
        // Ensure required fallbacks
        $mapped_product = $this->apply_fallbacks($mapped_product);
        
        return $mapped_product;
    }
    
    /**
     * Apply intelligent processing to already-mapped product data
     * (Price calculation, shipping info, fallbacks)
     * 
     * This is called by Feed Manager during rebuild to enhance products
     * with discount_percentage and other calculated fields
     */
    public function apply_intelligent_processing($mapped_product) {
        if (!is_array($mapped_product)) {
            return $mapped_product;
        }
        
        // Apply intelligent price processing (calculates discount_percentage)
        $mapped_product = $this->intelligent_price_processing($mapped_product);
        
        // Process shipping information
        $mapped_product = $this->process_shipping_info($mapped_product);
        
        // Ensure required fallbacks
        $mapped_product = $this->apply_fallbacks($mapped_product);
        
        return $mapped_product;
    }
    
    /**
     * Intelligent price processing to handle various price scenarios
     */
    private function intelligent_price_processing($product) {
        $price = floatval($product['price'] ?? 0);
        $old_price = floatval($product['old_price'] ?? 0);
        $sale_price = floatval($product['sale_price'] ?? 0);
        $direct_discount = floatval($product['discount_percentage'] ?? 0);
        
        // If we have sale_price but no old_price, move current price to old_price
        if ($sale_price > 0 && $old_price == 0 && $price > $sale_price) {
            $old_price = $price;
            $price = $sale_price;
            $product['old_price'] = $old_price;
            $product['price'] = $price;
        }
        
        // If sale_price is higher than price, swap them
        if ($sale_price > 0 && $price > 0 && $sale_price > $price) {
            $temp = $price;
            $price = $sale_price;
            $old_price = $temp;
            $product['price'] = $price;
            $product['old_price'] = $old_price;
        }
        
        // Now calculate or validate discount percentage
        if ($direct_discount > 0 && $old_price > 0 && $price > 0) {
            // Use AWIN's pre-calculated discount (most reliable)
            $product['discount_percentage'] = round($direct_discount);
        } elseif ($direct_discount > 0 && $direct_discount < 100 && $price > 0 && $old_price == 0) {
            // Calculate old_price backwards from discount percentage
            $old_price = $price / (1 - ($direct_discount / 100));
            $product['old_price'] = round($old_price, 2);
            $product['discount_percentage'] = round($direct_discount);
        } elseif ($old_price > $price && $price > 0) {
            // Calculate discount from actual prices
            $discount = (($old_price - $price) / $old_price) * 100;
            $product['discount_percentage'] = round($discount);
        }
        
        return $product;
    }
    
    /**
     * Process shipping information intelligently
     */
    private function process_shipping_info($product) {
        $shipping = $product['shipping'] ?? '';
        
        if (empty($shipping)) {
            $product['shipping_text'] = __('Shipping costs may apply', 'myfeeds-affiliate-feed-manager');
            return $product;
        }
        
        // Try to parse shipping cost
        if (is_numeric($shipping)) {
            $cost = floatval($shipping);
            if ($cost == 0) {
                $product['shipping_text'] = __('Free Shipping', 'myfeeds-affiliate-feed-manager');
            } else {
                $currency = $product['currency'] ?? 'EUR';
                /* translators: %1$s: shipping cost, %2$s: currency */
                $product['shipping_text'] = sprintf(__('Shipping: %1$s %2$s', 'myfeeds-affiliate-feed-manager'), 
                    number_format($cost, 2), $currency);
            }
        } else {
            // Handle complex shipping formats like "DE::Ground:3.49"
            if (preg_match('/(\d+\.?\d*)/', $shipping, $matches)) {
                $cost = floatval($matches[1]);
                if ($cost == 0) {
                    $product['shipping_text'] = __('Free Shipping', 'myfeeds-affiliate-feed-manager');
                } else {
                    $currency = $product['currency'] ?? 'EUR';
                    /* translators: %1$s: shipping cost, %2$s: currency */
                    $product['shipping_text'] = sprintf(__('Shipping: %1$s %2$s', 'myfeeds-affiliate-feed-manager'), 
                        number_format($cost, 2), $currency);
                }
            } else {
                $product['shipping_text'] = __('Shipping costs may apply', 'myfeeds-affiliate-feed-manager');
            }
        }
        
        return $product;
    }
    
    /**
     * Apply fallbacks for missing data
     */
    private function apply_fallbacks($product) {
        // Fallback currency
        if (empty($product['currency'])) {
            $product['currency'] = 'EUR';
        }
        
        // Fallback availability
        if (empty($product['availability'])) {
            $product['availability'] = 'in stock';
        }
        
        // Clean title
        if (!empty($product['title'])) {
            $product['title'] = $this->clean_product_title($product['title']);
        }
        
        // Process additional images
        if (!empty($product['additional_images'])) {
            $product['additional_images'] = $this->process_image_urls($product['additional_images']);
        }
        
        return $product;
    }
    
    /**
     * Clean and optimize product title
     */
    private function clean_product_title($title) {
        // Remove excessive whitespace
        $title = preg_replace('/\s+/', ' ', trim($title));
        
        // Remove common unwanted characters
        $title = preg_replace('/[™®©]/', '', $title);
        
        // Limit length for better display
        if (strlen($title) > 200) {
            $title = substr($title, 0, 197) . '...';
        }
        
        return $title;
    }
    
    /**
     * Process image URLs (handle multiple images)
     */
    private function process_image_urls($images) {
        if (is_string($images)) {
            // Handle comma-separated or pipe-separated URLs
            $images = preg_split('/[,|;]/', $images);
        }
        
        if (is_array($images)) {
            return array_filter(array_map('trim', $images));
        }
        
        return [];
    }
    
    /**
     * Process individual field values based on field type
     */
    private function process_field_value($field_name, $value) {
        switch ($field_name) {
            case 'price':
            case 'old_price':
            case 'sale_price':
                return $this->clean_price_value($value);
                
            case 'image_url':
                return $this->clean_url($value);
                
            case 'affiliate_link':
                return $this->clean_url($value);
                
            case 'title':
                return $this->clean_product_title($value);
                
            case 'description':
                return $this->clean_description($value);
                
            default:
                return is_array($value) ? (isset($value[0]) ? trim((string)$value[0]) : '') : trim((string)$value);
        }
    }
    
    /**
     * Clean price values (remove currency symbols, etc.)
     */
    private function clean_price_value($price) {
        if (is_numeric($price)) {
            return floatval($price);
        }
        
        // Remove common currency symbols AND currency codes
        $price = preg_replace('/[€$£¥₹,\s]/', '', $price);
        $price = preg_replace('/\b(EUR|USD|GBP|CHF|AED|SAR|JPY|CNY|AUD|CAD|SEK|NOK|DKK|PLN|CZK|HUF|RON|TRY|BRL|MXN|KRW|INR)\b/i', '', $price);
        $price = trim($price);
        
        // Handle different decimal separators
        $price = str_replace(',', '.', $price);
        
        // Extract numeric value
        if (preg_match('/(\d+\.?\d*)/', $price, $matches)) {
            return floatval($matches[1]);
        }
        
        return 0;
    }
    
    /**
     * Clean URLs
     */
    private function clean_url($url) {
        $url = trim($url);
        
        // Add protocol if missing
        if (!empty($url) && !preg_match('/^https?:\/\//', $url)) {
            $url = 'http://' . $url;
        }
        
        return $url;
    }
    
    /**
     * Clean description text
     */
    private function clean_description($description) {
        // Remove HTML tags
        $description = wp_strip_all_tags($description);
        
        // Remove excessive whitespace
        $description = preg_replace('/\s+/', ' ', trim($description));
        
        // Limit length
        if (strlen($description) > 500) {
            $description = substr($description, 0, 497) . '...';
        }
        
        return $description;
    }
    
    /**
     * Process attribute values (for size, color, etc.)
     */
    private function process_attribute_value($value) {
        if (is_string($value)) {
            // Handle comma-separated values
            if (strpos($value, ',') !== false) {
                return array_map('trim', explode(',', $value));
            }
            return [trim($value)];
        }
        
        if (is_array($value)) {
            return array_map('trim', $value);
        }
        
        return [$value];
    }
    
    /**
     * Extract field value using various notation methods (public method)
     */
    public function extract_field_value($item, $path) {
        if (!is_string($path)) {
            return null;
        }
        
        // Handle array notation like 'images[0].url'
        if (strpos($path, '[') !== false) {
            return $this->extract_array_path($item, $path);
        }
        
        // Handle dot notation like 'product.name'
        if (strpos($path, '.') !== false) {
            return $this->extract_dot_path($item, $path);
        }
        
        // Direct field access
        return isset($item[$path]) ? $item[$path] : null;
    }
    
    /**
     * Extract value using array path notation
     */
    private function extract_array_path($item, $path) {
        $segments = preg_split('/\.(?![^\[]*\])/', $path);
        $current = $item;
        
        foreach ($segments as $segment) {
            if (preg_match('/(.+)\[(\d+)\]$/', $segment, $matches)) {
                $key = $matches[1];
                $index = (int)$matches[2];
                
                if (isset($current[$key][$index])) {
                    $current = $current[$key][$index];
                } else {
                    return null;
                }
            } elseif (isset($current[$segment])) {
                $current = $current[$segment];
            } else {
                return null;
            }
        }
        
        return $current;
    }
    
    /**
     * Extract value using dot notation
     */
    private function extract_dot_path($item, $path) {
        $segments = explode('.', $path);
        $current = $item;
        
        foreach ($segments as $segment) {
            if (isset($current[$segment])) {
                $current = $current[$segment];
            } else {
                return null;
            }
        }
        
        return $current;
    }
    
    /**
     * Get confidence score for mapping quality
     */
    public function get_mapping_confidence($mapping) {
        $total_weight = 0;
        $mapped_weight = 0;
        
        foreach ($this->universal_field_patterns as $field => $config) {
            if ($field !== 'attributes') {
                $weight = $config['weight'];
                $total_weight += $weight;
                
                if (isset($mapping[$field])) {
                    $mapped_weight += $weight;
                }
            }
        }
        
        return $total_weight > 0 ? ($mapped_weight / $total_weight) * 100 : 0;
    }
    
    /**
     * Get supported networks for backwards compatibility
     */
    public function get_supported_networks() {
        return array_keys($this->network_signatures);
    }
}