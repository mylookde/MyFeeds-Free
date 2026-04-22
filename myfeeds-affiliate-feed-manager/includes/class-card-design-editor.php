<?php
/**
 * MyFeeds Card Design Editor
 * Settings page tab for visual product card customization (Pro feature)
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Card_Design_Editor {

    public function init() {
        add_action('admin_menu', array($this, 'register_submenu'), 25);
        add_action('wp_ajax_myfeeds_save_card_design', array($this, 'ajax_save'));
        add_action('wp_ajax_myfeeds_reset_card_design', array($this, 'ajax_reset'));
        add_action('wp_ajax_myfeeds_add_custom_font', array($this, 'ajax_add_custom_font'));
        add_action('wp_ajax_myfeeds_remove_custom_font', array($this, 'ajax_remove_custom_font'));
        add_action('wp_ajax_myfeeds_undo_reset_card_design', array($this, 'ajax_undo_reset'));
        add_action('wp_ajax_myfeeds_dismiss_undo_banner', array($this, 'ajax_dismiss_undo'));
    }

    public function register_submenu() {
        add_submenu_page(
            'myfeeds-feeds',
            __('Design', 'myfeeds'),
            __('Design', 'myfeeds'),
            'manage_options',
            'myfeeds-design',
            array($this, 'render_page')
        );
    }

    /**
     * Get a real product from the newest feed for preview, or placeholder data
     */
    private function get_preview_product() {
        if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode() && MyFeeds_DB_Manager::table_exists()) {
            global $wpdb;
            $table = MyFeeds_DB_Manager::table_name();
            
            // Get all feed names to try different sources
            $feed_names = $wpdb->get_col("SELECT DISTINCT feed_name FROM {$table} WHERE status = 'active'");
            
            foreach ($feed_names as $feed_name) {
                // Priority 1: Product with discount AND real image from this feed
                $like_no_image     = '%' . $wpdb->esc_like('no-image') . '%';
                $like_placeholder  = '%' . $wpdb->esc_like('placeholder') . '%';
                $like_not_avail    = '%' . $wpdb->esc_like('not-available') . '%';
                $like_noimage      = '%' . $wpdb->esc_like('noimage') . '%';
                $like_small_thumb  = '%' . $wpdb->esc_like('w=200&h=200') . '%';

                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE status = 'active' 
                     AND feed_name = %s
                     AND image_url IS NOT NULL AND image_url != '' 
                     AND image_url NOT LIKE %s 
                     AND image_url NOT LIKE %s 
                     AND image_url NOT LIKE %s 
                     AND image_url NOT LIKE %s
                     AND image_url NOT LIKE %s
                     AND original_price > 0 AND price > 0 AND original_price > price 
                     AND brand IS NOT NULL AND brand != ''
                     ORDER BY RAND() LIMIT 1",
                    $feed_name,
                    $like_no_image,
                    $like_placeholder,
                    $like_not_avail,
                    $like_noimage,
                    $like_small_thumb
                ), ARRAY_A);
                
                if ($row && !empty($row['image_url'])) {
                    return $this->build_preview_from_row($row, true);
                }
            }
            
            // Priority 2: Any product with real image (no discount required)
            foreach ($feed_names as $feed_name) {
                $like_no_image     = '%' . $wpdb->esc_like('no-image') . '%';
                $like_placeholder  = '%' . $wpdb->esc_like('placeholder') . '%';
                $like_not_avail    = '%' . $wpdb->esc_like('not-available') . '%';
                $like_noimage      = '%' . $wpdb->esc_like('noimage') . '%';
                $like_small_thumb  = '%' . $wpdb->esc_like('w=200&h=200') . '%';

                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE status = 'active' 
                     AND feed_name = %s
                     AND image_url IS NOT NULL AND image_url != '' 
                     AND image_url NOT LIKE %s 
                     AND image_url NOT LIKE %s 
                     AND image_url NOT LIKE %s 
                     AND image_url NOT LIKE %s
                     AND image_url NOT LIKE %s
                     AND brand IS NOT NULL AND brand != ''
                     ORDER BY RAND() LIMIT 1",
                    $feed_name,
                    $like_no_image,
                    $like_placeholder,
                    $like_not_avail,
                    $like_noimage,
                    $like_small_thumb
                ), ARRAY_A);
                
                if ($row && !empty($row['image_url'])) {
                    return $this->build_preview_from_row($row, false);
                }
            }
            
            // Priority 3: Absolutely any product with image
            $row = $wpdb->get_row(
                "SELECT * FROM {$table} WHERE status = 'active' AND image_url IS NOT NULL AND image_url != '' ORDER BY RAND() LIMIT 1",
                ARRAY_A
            );
            if ($row) {
                return $this->build_preview_from_row($row, false);
            }
        }

        // Placeholder when no feed data exists
        return $this->get_placeholder_preview();
    }

    /**
     * Build preview data from a DB row
     */
    private function build_preview_from_row($row, $has_real_discount) {
        $price = floatval($row['price'] ?: 0);
        $old_price = floatval($row['original_price'] ?: 0);
        $discount = 0;
        
        if ($has_real_discount && $old_price > $price && $price > 0) {
            $discount = round(($old_price - $price) / $old_price * 100);
        }
        
        // If no real discount data, use placeholder values so user sees all elements
        if (!$has_real_discount || $discount <= 0) {
            $old_price = $price > 0 ? round($price * 1.33, 2) : 100.00;
            $price = $price > 0 ? $price : 75.00;
            $discount = 25;
        }
        
        return array(
            'image_url'    => $row['image_url'],
            'brand'        => $row['brand'] ?: 'Brand Name',
            'title'        => $row['product_name'] ?: 'Product Title',
            'price'        => $price,
            'old_price'    => $old_price,
            'currency'     => $row['currency'] ?: 'EUR',
            'shipping'     => 'Free Shipping',
            'merchant'     => $row['feed_name'] ?: 'Shop Name',
            'discount'     => $discount,
            'has_real_discount' => $has_real_discount,
        );
    }

    /**
     * Get placeholder preview when no products exist
     */
    private function get_placeholder_preview() {
        return array(
            'image_url'    => '',
            'brand'        => 'Brand',
            'title'        => 'Product Title',
            'price'        => 75.00,
            'old_price'    => 100.00,
            'currency'     => 'EUR',
            'shipping'     => 'Free Shipping',
            'merchant'     => 'Shop Name',
            'discount'     => 25,
            'has_real_discount' => false,
        );
    }

    public function render_page() {
        wp_enqueue_media(); // Required for custom font upload
        wp_enqueue_script('jquery-ui-sortable');
        $design = MyFeeds_Settings_Manager::get_card_design();
        $product = $this->get_preview_product();
        $is_locked = class_exists('MyFeeds_Plan_Limits') && !MyFeeds_Plan_Limits::design_editor_allowed();
        $nonce = wp_create_nonce('myfeeds_nonce');

        $currency_symbols = array('EUR' => "\xe2\x82\xac", 'USD' => '$', 'GBP' => "\xc2\xa3");
        $sym = $currency_symbols[$product['currency']] ?? "\xe2\x82\xac";
        $discount = 0;
        if ($product['old_price'] > $product['price'] && $product['price'] > 0) {
            $discount = round(($product['old_price'] - $product['price']) / $product['old_price'] * 100);
        }

        // Preset colors (same as carousel arrow color picker in build/index.js)
        $preset_colors = array('#333333','#ffffff','#e74c3c','#e67e22','#f1c40f','#2ecc71','#1abc9c','#3498db','#9b59b6','#667eea');
        ?>
        <div class="wrap myfeeds-design-wrap">
            <h1 style="margin-bottom: 24px;"><?php esc_html_e('MyFeeds – Card Design Editor', 'myfeeds'); ?></h1>
        <?php if (get_option('myfeeds_card_design_backup')): ?>
        <div id="myfeeds-undo-reset-banner" class="myfeeds-undo-reset-banner">
            <span><?php esc_html_e('Design was reset to defaults.', 'myfeeds'); ?></span>
            <button type="button" id="myfeeds-undo-reset-btn" class="myfeeds-btn-outline" style="margin-left:12px; padding:4px 14px; font-size:12px;">
                <?php esc_html_e('Undo Reset', 'myfeeds'); ?>
            </button>
            <button type="button" id="myfeeds-dismiss-undo-btn" style="background:none; border:none; color:#999; cursor:pointer; margin-left:8px; font-size:16px;" title="Dismiss">&times;</button>
        </div>
        <?php endif; ?>

            <?php if ($is_locked): ?>
            <div class="myfeeds-design-upgrade-overlay">
                <div class="myfeeds-design-upgrade-box">
                    <span style="font-size:32px;">&#128274;</span>
                    <h3><?php esc_html_e('Card Design Editor is a Premium Feature', 'myfeeds'); ?></h3>
                    <p><?php esc_html_e('Customize every element of your product cards — colors, fonts, sizes, visibility, drag & drop ordering, and more.', 'myfeeds'); ?></p>
                    <a href="<?php echo esc_url(MyFeeds_Plan_Limits::get_premium_upgrade_url()); ?>" class="myfeeds-btn-save" style="display:inline-block; margin-top:12px; text-decoration:none;">
                        <?php esc_html_e('Upgrade to Premium', 'myfeeds'); ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <div class="myfeeds-design-layout <?php echo $is_locked ? 'is-locked' : ''; ?>">
                <!-- LEFT: Sidebar Settings -->
                <div class="myfeeds-design-sidebar">
                    <?php $this->render_sidebar_sections($design, $preset_colors); ?>

                    <div class="myfeeds-design-actions">
                        <button type="button" id="myfeeds-design-save" class="myfeeds-btn-save" <?php echo $is_locked ? 'disabled' : ''; ?>>
                            <?php esc_html_e('Save Changes', 'myfeeds'); ?>
                        </button>
                        <button type="button" id="myfeeds-design-reset" class="myfeeds-btn-reset" <?php echo $is_locked ? 'disabled' : ''; ?>>
                            <?php esc_html_e('Reset to Default', 'myfeeds'); ?>
                        </button>
                    </div>
                </div>

                <!-- RIGHT: Live Preview -->
                <div class="myfeeds-design-preview-area">
                    <h3 style="margin:0 0 16px; text-align:center;"><?php esc_html_e('Live Preview', 'myfeeds'); ?></h3>
                    <div class="myfeeds-design-preview-bg">
                        <div id="myfeeds-design-preview-card" class="myfeeds-product-card" style="width:260px;">
                            <div class="myfeeds-product-image">
                                <div class="myfeeds-discount-badge" id="preview-badge">-<?php echo esc_html($discount); ?>%</div>
                                <?php if (!empty($product['image_url'])): ?>
                                    <img src="<?php echo esc_url($product['image_url']); ?>" alt="Preview" onerror="this.parentNode.classList.add('myfeeds-img-error');this.style.display='none';">
                                <?php else: ?>
                                    <div style="position:absolute;top:0;left:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f5f5f5,#e8e8e8);">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="myfeeds-product-details">
                                <div class="myfeeds-product-brand" id="preview-brand"><?php echo esc_html($product['brand']); ?></div>
                                <div class="myfeeds-product-title" id="preview-title"><?php echo esc_html($product['title']); ?></div>
                                <div class="myfeeds-product-price">
                                    <span class="myfeeds-old-price" id="preview-old-price"><?php echo esc_html(number_format($product['old_price'], 2, ',', '.') . ' ' . $sym); ?></span>
                                    <span class="myfeeds-current-price has-discount" id="preview-price"><?php echo esc_html(number_format($product['price'], 2, ',', '.') . ' ' . $sym); ?></span>
                                </div>
                                <div class="myfeeds-shipping-info" id="preview-shipping"><?php echo esc_html($product['shipping']); ?></div>
                                <div class="myfeeds-merchant" id="preview-merchant"><?php echo esc_html($product['merchant']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo esc_attr($nonce); ?>';
            var $card = $('#myfeeds-design-preview-card');

            // =====================================================================
            // LIVE PREVIEW: Every input change instantly updates preview card CSS
            // =====================================================================
            function updatePreview() {
                // Card container
                $card.css({
                    'background': v('card_bg_color'),
                    'border-radius': v('card_border_radius') + 'px',
                    'border': v('card_border_width') + 'px solid ' + v('card_border_color'),
                    'box-shadow': '0 0 ' + v('card_shadow_blur') + 'px ' + v('card_shadow_strength') + 'px rgba(0,0,0,' + Math.min(parseFloat(v('card_shadow_strength')) * 0.03, 0.25).toFixed(2) + ')'
                });
                $card.css('min-width', v('card_min_width') + 'px');
                $card.css('max-width', v('card_max_width') + 'px');
                $card.css('width', v('card_max_width') + 'px');

                // Image
                $card.find('.myfeeds-product-image').css({
                    'padding-bottom': v('image_ratio') + '%',
                    'background': v('image_bg_color')
                });
                $card.find('.myfeeds-product-image img').css('object-fit', v('image_object_fit'));

                // Badge
                var $badge = $card.find('.myfeeds-discount-badge');
                $badge.toggle(vBool('badge_visible'));
                $badge.css({
                    'background': v('badge_bg_color'),
                    'color': v('badge_text_color'),
                    'font-size': v('badge_font_size') + 'px',
                    'font-weight': v('badge_font_weight'),
                    'border-radius': v('badge_border_radius') + 'px',
                    'top': v('badge_position_top') + '%',
                    'left': v('badge_position_left') + '%',
                    'text-transform': v('badge_text_transform')
                });
                $badge.css('font-family', resolveFontFamily(v('badge_font_family')));

                // Brand
                var $brand = $card.find('.myfeeds-product-brand');
                $brand.toggle(vBool('brand_visible'));
                $brand.css({
                    'font-size': v('brand_font_size') + 'px',
                    'font-weight': v('brand_font_weight'),
                    'color': v('brand_color'),
                    'text-transform': v('brand_text_transform')
                });
                $brand.css('font-family', resolveFontFamily(v('brand_font_family')));

                // Title
                var $title = $card.find('.myfeeds-product-title');
                $title.toggle(vBool('title_visible'));
                $title.css({
                    'font-size': v('title_font_size') + 'px',
                    'font-weight': v('title_font_weight'),
                    'color': v('title_color'),
                    'text-transform': v('title_text_transform')
                });
                $title.css('font-family', resolveFontFamily(v('title_font_family')));

                // Price
                var $priceWrap = $card.find('.myfeeds-product-price');
                $priceWrap.toggle(vBool('price_visible'));
                $card.find('.myfeeds-current-price').css({
                    'font-size': v('price_font_size') + 'px',
                    'font-weight': v('price_font_weight'),
                    'color': v('price_discount_color')
                });
                $card.find('.myfeeds-current-price').css('font-family', resolveFontFamily(v('price_font_family')));
                $card.find('.myfeeds-old-price').css({
                    'font-size': v('old_price_font_size') + 'px',
                    'font-weight': v('old_price_font_weight'),
                    'color': v('old_price_color')
                });
                $card.find('.myfeeds-old-price').css('font-family', resolveFontFamily(v('old_price_font_family')));

                // Shipping
                var $shipping = $card.find('.myfeeds-shipping-info');
                $shipping.toggle(vBool('shipping_visible'));
                $shipping.css({
                    'font-size': v('shipping_font_size') + 'px',
                    'color': v('shipping_color'),
                    'text-transform': v('shipping_text_transform')
                });
                $shipping.css('font-family', resolveFontFamily(v('shipping_font_family')));

                // Merchant
                var $merchant = $card.find('.myfeeds-merchant');
                $merchant.toggle(vBool('merchant_visible'));
                $merchant.css({
                    'font-size': v('merchant_font_size') + 'px',
                    'color': v('merchant_color'),
                    'text-transform': v('merchant_text_transform')
                });
                $merchant.css('font-family', resolveFontFamily(v('merchant_font_family')));

                // Details padding
                $card.find('.myfeeds-product-details').css({
                    'padding': v('details_padding_y') + 'px ' + v('details_padding_x') + 'px'
                });
            }

            function v(name) { return $('#mf_' + name).val(); }
            function vBool(name) { return $('#mf_' + name).is(':checked'); }

            // Bind all inputs to live preview
            $('.myfeeds-design-sidebar').on('input change', 'input, select', function() {
                updatePreview();
            });

            // =====================================================================
            // FONT SYSTEM: Load Google Fonts dynamically, apply to preview
            // =====================================================================
            var loadedFonts = {};
            var googleFontsData = <?php echo wp_json_encode(MyFeeds_Settings_Manager::get_available_fonts()); ?>;
            var customFontsData = <?php echo wp_json_encode(MyFeeds_Settings_Manager::get_custom_fonts()); ?>;

            function loadGoogleFont(fontKey) {
                if (!fontKey || fontKey === '__system__' || fontKey.indexOf('custom:') === 0) return;
                if (loadedFonts[fontKey]) return;
                
                var fontData = googleFontsData[fontKey];
                if (!fontData || !fontData.google) return;
                
                var weights = fontData.weights || '400;500;600;700';
                var family = fontKey.replace(/ /g, '+') + ':wght@' + weights;
                var url = 'https://fonts.googleapis.com/css2?family=' + family + '&display=swap';
                
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = url;
                document.head.appendChild(link);
                
                loadedFonts[fontKey] = true;
            }

            function loadCustomFont(fontKey) {
                if (!fontKey || fontKey.indexOf('custom:') !== 0) return;
                if (loadedFonts[fontKey]) return;
                
                var fontName = fontKey.substring(7);
                var fontUrl = null;
                for (var i = 0; i < customFontsData.length; i++) {
                    if (customFontsData[i].name === fontName) {
                        fontUrl = customFontsData[i].url;
                        break;
                    }
                }
                if (!fontUrl) return;
                
                var format = 'woff2';
                if (fontUrl.indexOf('.woff2') === -1) {
                    format = fontUrl.indexOf('.ttf') > -1 ? 'truetype' : 'woff';
                }
                
                var style = document.createElement('style');
                style.textContent = '@font-face { font-family: "' + fontName + '"; src: url("' + fontUrl + '") format("' + format + '"); font-display: swap; }';
                document.head.appendChild(style);
                
                loadedFonts[fontKey] = true;
            }

            function resolveFontFamily(fontKey) {
                if (!fontKey || fontKey === '__system__') {
                    return '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
                }
                if (fontKey.indexOf('custom:') === 0) {
                    loadCustomFont(fontKey);
                    return '"' + fontKey.substring(7) + '", sans-serif';
                }
                loadGoogleFont(fontKey);
                var data = googleFontsData[fontKey];
                var cat = data ? data.category : 'sans-serif';
                return '"' + fontKey + '", ' + cat;
            }

            // Fill range sliders up to thumb position (WebKit doesn't support ::-webkit-slider-progress)
            function updateSliderFill($input) {
                var min = parseFloat($input.attr('min')) || 0;
                var max = parseFloat($input.attr('max')) || 100;
                var val = parseFloat($input.val()) || 0;
                var pct = ((val - min) / (max - min)) * 100;
                $input.css('background', 'linear-gradient(to right, #667eea 0%, #764ba2 ' + pct + '%, #e2e4e7 ' + pct + '%, #e2e4e7 100%)');
            }

            // Apply to all sliders on init and on input
            $('.myfeeds-design-sidebar input[type="range"]').each(function() {
                updateSliderFill($(this));
            }).on('input', function() {
                updateSliderFill($(this));
            });

            // Initial preview render
            updatePreview();

            // =====================================================================
            // DRAG & DROP: Sortable sections with live preview order update
            // =====================================================================
            var $sortable = $('#myfeeds-sortable-sections');
            var elementToPreviewSelector = {
                'brand': '.myfeeds-product-brand',
                'title': '.myfeeds-product-title',
                'price': '.myfeeds-product-price',
                'shipping': '.myfeeds-shipping-info',
                'merchant': '.myfeeds-merchant'
            };

            $sortable.sortable({
                handle: '.myfeeds-sortable-grip',
                axis: 'y',
                placeholder: 'ui-sortable-placeholder',
                tolerance: 'pointer',
                opacity: 0.9,
                revert: 150,
                start: function(e, ui) {
                    ui.placeholder.height(ui.item.outerHeight() - 4);
                },
                update: function(e, ui) {
                    var newOrder = [];
                    $sortable.find('.myfeeds-sortable-section').each(function() {
                        newOrder.push($(this).data('element'));
                    });
                    $('#mf_element_order').val(newOrder.join(','));
                    updatePreviewOrder(newOrder);
                }
            });

            function updatePreviewOrder(order) {
                for (var i = 0; i < order.length; i++) {
                    var selector = elementToPreviewSelector[order[i]];
                    if (selector) {
                        $card.find(selector).css('order', i);
                    }
                }
            }

            // Apply initial order to preview
            var initialOrder = $('#mf_element_order').val().split(',');
            updatePreviewOrder(initialOrder);

            // Live hover effect on preview card
            $card.on('mouseenter', function() {
                var hoverShadow = '0 0 ' + v('card_hover_shadow_blur') + 'px ' + v('card_hover_shadow_strength') + 'px rgba(0,0,0,' + Math.min(parseFloat(v('card_hover_shadow_strength')) * 0.03, 0.3).toFixed(2) + ')';
                $(this).css({
                    'transform': 'scale(' + (parseFloat(v('card_hover_scale')) / 100) + ')',
                    'box-shadow': hoverShadow,
                    'border-radius': v('card_hover_radius') + 'px'
                });
            }).on('mouseleave', function() {
                var normalShadow = '0 0 ' + v('card_shadow_blur') + 'px ' + v('card_shadow_strength') + 'px rgba(0,0,0,' + Math.min(parseFloat(v('card_shadow_strength')) * 0.03, 0.25).toFixed(2) + ')';
                $(this).css({
                    'transform': 'scale(1)',
                    'box-shadow': normalShadow,
                    'border-radius': v('card_border_radius') + 'px'
                });
            });

            // =====================================================================
            // COLOR PICKER: Preset swatches + hex input (same as carousel picker)
            // =====================================================================
            $(document).on('click', '.myfeeds-color-swatch', function() {
                var color = $(this).data('color');
                var $wrap = $(this).closest('.myfeeds-color-picker-wrap');
                var $input = $wrap.find('input[type="text"]');
                $input.val(color).trigger('input');
                $wrap.find('.myfeeds-color-swatch').removeClass('active');
                $(this).addClass('active');
                // Swatch selected -> dot loses active ring
                $wrap.find('.myfeeds-color-preview-dot').css('background', color).removeClass('active');
            });

            $(document).on('input', '.myfeeds-color-picker-wrap input[type="text"]', function() {
                var val = $(this).val().toLowerCase();
                var $wrap = $(this).closest('.myfeeds-color-picker-wrap');
                var matchedSwatch = false;
                $wrap.find('.myfeeds-color-swatch').each(function() {
                    var isMatch = $(this).data('color').toLowerCase() === val;
                    $(this).toggleClass('active', isMatch);
                    if (isMatch) matchedSwatch = true;
                });
                // Update preview dot color and active state
                var $dot = $wrap.find('.myfeeds-color-preview-dot');
                $dot.css('background', val);
                $dot.toggleClass('active', !matchedSwatch && val.length === 7 && val.charAt(0) === '#');
            });

            $('.myfeeds-color-picker-wrap input[type="text"]').each(function() {
                var val = $(this).val();
                $(this).closest('.myfeeds-color-picker-wrap').find('.myfeeds-color-preview-dot').css('background', val);
                $(this).trigger('input');
            });

            // =====================================================================
            // SAVE
            // =====================================================================
            $('#myfeeds-design-save').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Saving...');

                var data = { action: 'myfeeds_save_card_design', nonce: nonce };
                $('.myfeeds-design-sidebar').find('input, select').each(function() {
                    var name = $(this).attr('name');
                    if (!name) return;
                    if ($(this).attr('type') === 'checkbox') {
                        data[name] = $(this).is(':checked') ? '1' : '0';
                    } else {
                        data[name] = $(this).val();
                    }
                });

                $.post(ajaxurl, data, function(resp) {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Save Changes', 'myfeeds')); ?>');
                    if (resp.success) {
                        $btn.text('<?php echo esc_js(__('Saved!', 'myfeeds')); ?>');
                        setTimeout(function() { $btn.text('<?php echo esc_js(__('Save Changes', 'myfeeds')); ?>'); }, 2000);
                    } else {
                        alert(resp.data ? resp.data.message : 'Save failed');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Save Changes', 'myfeeds')); ?>');
                    alert('Server error');
                });
            });

            // =====================================================================
            // RESET
            // =====================================================================
            $('#myfeeds-design-reset').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Reset all design settings to default?', 'myfeeds')); ?>')) return;
                var $btn = $(this);
                $btn.prop('disabled', true);
                $.post(ajaxurl, { action: 'myfeeds_reset_card_design', nonce: nonce }, function(resp) {
                    if (resp.success) { location.reload(); }
                    else { $btn.prop('disabled', false); alert('Reset failed'); }
                });
            });
            // =====================================================================
            // UNDO/REDO per section (v3 — mousedown/mouseup for sliders, immediate for others)
            // Sliders: snapshot on mousedown, commit on mouseup/touchend.
            // Swatches/selects/checkboxes: snapshot before, commit after (immediate).
            // =====================================================================
            var sectionHistories = {};

            function getSectionKey($section) {
                // Strip undo/redo arrow chars from the title text
                return $section.find('h4').first().contents().filter(function() {
                    return this.nodeType === 3;
                }).text().trim();
            }

            function captureSnapshot($section) {
                var snapshot = {};
                $section.find('input, select').each(function() {
                    var name = $(this).attr('name');
                    if (!name) return;
                    if ($(this).attr('type') === 'checkbox') {
                        snapshot[name] = $(this).is(':checked');
                    } else {
                        snapshot[name] = $(this).val();
                    }
                });
                return snapshot;
            }

            function snapshotsEqual(a, b) {
                return JSON.stringify(a) === JSON.stringify(b);
            }

            function applySnapshot($section, snapshot) {
                $section.find('input, select').each(function() {
                    var name = $(this).attr('name');
                    if (!name || !(name in snapshot)) return;
                    if ($(this).attr('type') === 'checkbox') {
                        $(this).prop('checked', snapshot[name]);
                    } else {
                        $(this).val(snapshot[name]);
                    }
                });
                $section.find('input[type="range"]').trigger('input');
                updatePreview();
            }

            function pushUndo(key, snapshot) {
                var hist = sectionHistories[key];
                if (!hist) return;
                hist.undo.push(snapshot);
                hist.redo = [];
                if (hist.undo.length > 30) hist.undo.shift();
            }

            // Initialize
            $('.myfeeds-design-section').each(function() {
                var key = getSectionKey($(this));
                sectionHistories[key] = {
                    undo: [],
                    redo: [],
                    last: captureSnapshot($(this)),
                    dragSnapshot: null
                };
            });

            // SLIDERS: mousedown = capture start, mouseup/touchend = commit
            $('.myfeeds-design-section').on('mousedown touchstart', 'input[type="range"]', function() {
                var $section = $(this).closest('.myfeeds-design-section');
                var key = getSectionKey($section);
                var hist = sectionHistories[key];
                if (!hist) return;
                hist.dragSnapshot = captureSnapshot($section);
            });

            $(document).on('mouseup touchend', function() {
                $('.myfeeds-design-section').each(function() {
                    var $section = $(this);
                    var key = getSectionKey($section);
                    var hist = sectionHistories[key];
                    if (!hist || !hist.dragSnapshot) return;

                    var current = captureSnapshot($section);
                    if (!snapshotsEqual(hist.dragSnapshot, current)) {
                        pushUndo(key, hist.dragSnapshot);
                        hist.last = current;
                        updateUndoRedoButtons($section, key);
                    }
                    hist.dragSnapshot = null;
                });
            });

            // NON-SLIDER INPUTS: swatches, selects, checkboxes, number inputs, text inputs
            // Capture before-state, commit after change
            $('.myfeeds-design-section').on('mousedown', 'input:not([type="range"]), select, .myfeeds-color-swatch', function() {
                var $section = $(this).closest('.myfeeds-design-section');
                var key = getSectionKey($section);
                var hist = sectionHistories[key];
                if (!hist) return;
                hist._preClickSnapshot = captureSnapshot($section);
            });

            $('.myfeeds-design-section').on('change', 'input:not([type="range"]), select', function() {
                var $section = $(this).closest('.myfeeds-design-section');
                var key = getSectionKey($section);
                var hist = sectionHistories[key];
                if (!hist || !hist._preClickSnapshot) return;

                var current = captureSnapshot($section);
                if (!snapshotsEqual(hist._preClickSnapshot, current)) {
                    pushUndo(key, hist._preClickSnapshot);
                    hist.last = current;
                    updateUndoRedoButtons($section, key);
                }
                hist._preClickSnapshot = null;
            });

            // Swatch clicks: commit after the value propagates
            $(document).on('click', '.myfeeds-color-swatch', function() {
                var $section = $(this).closest('.myfeeds-design-section');
                var key = getSectionKey($section);
                var hist = sectionHistories[key];
                if (!hist) return;

                setTimeout(function() {
                    var current = captureSnapshot($section);
                    if (hist._preClickSnapshot && !snapshotsEqual(hist._preClickSnapshot, current)) {
                        pushUndo(key, hist._preClickSnapshot);
                        hist.last = current;
                        updateUndoRedoButtons($section, key);
                    }
                    hist._preClickSnapshot = null;
                }, 50);
            });

            // Undo
            $(document).on('click', '.myfeeds-undo-btn', function(e) {
                e.preventDefault();
                var $section = $(this).closest('.myfeeds-design-section');
                var key = getSectionKey($section);
                var hist = sectionHistories[key];
                if (!hist || hist.undo.length === 0) return;

                hist.redo.push(captureSnapshot($section));
                var prev = hist.undo.pop();
                hist.last = prev;
                applySnapshot($section, prev);
                updateUndoRedoButtons($section, key);
            });

            // Redo
            $(document).on('click', '.myfeeds-redo-btn', function(e) {
                e.preventDefault();
                var $section = $(this).closest('.myfeeds-design-section');
                var key = getSectionKey($section);
                var hist = sectionHistories[key];
                if (!hist || hist.redo.length === 0) return;

                hist.undo.push(captureSnapshot($section));
                var next = hist.redo.pop();
                hist.last = next;
                applySnapshot($section, next);
                updateUndoRedoButtons($section, key);
            });

            function updateUndoRedoButtons($section, key) {
                var hist = sectionHistories[key];
                if (!hist) return;
                $section.find('.myfeeds-undo-btn').prop('disabled', hist.undo.length === 0);
                $section.find('.myfeeds-redo-btn').prop('disabled', hist.redo.length === 0);
            }

            // Initialize all buttons
            $('.myfeeds-design-section').each(function() {
                var key = getSectionKey($(this));
                updateUndoRedoButtons($(this), key);
            });

            // =====================================================================
            // CUSTOM FONT UPLOAD via WordPress Media Library
            // =====================================================================
            $('#myfeeds-upload-font-btn').on('click', function(e) {
                e.preventDefault();
                
                // Open WP media uploader
                var frame = wp.media({
                    title: 'Upload Custom Font',
                    button: { text: 'Use This Font' },
                    multiple: false,
                    library: { type: ['font/woff2', 'font/woff', 'font/ttf', 'application/x-font-woff', 'application/x-font-woff2', 'application/x-font-ttf', 'application/octet-stream'] }
                });
                
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    var url = attachment.url;
                    var filename = attachment.filename || '';
                    
                    // Extract font name from filename (strip extension)
                    var fontName = filename.replace(/\.(woff2?|ttf|otf)$/i, '').replace(/[-_]/g, ' ');
                    fontName = prompt('Font name:', fontName);
                    if (!fontName) return;
                    
                    // Save via AJAX
                    $.post(ajaxurl, {
                        action: 'myfeeds_add_custom_font',
                        nonce: nonce,
                        font_name: fontName,
                        font_url: url
                    }, function(resp) {
                        if (resp.success) {
                            location.reload(); // Reload to update all font dropdowns
                        } else {
                            alert(resp.data ? resp.data.message : 'Upload failed');
                        }
                    });
                });
                
                frame.open();
            });

            // Remove custom font
            $(document).on('click', '.myfeeds-remove-custom-font', function() {
                var idx = $(this).data('idx');
                if (!confirm('Remove this custom font?')) return;
                
                $.post(ajaxurl, {
                    action: 'myfeeds_remove_custom_font',
                    nonce: nonce,
                    font_index: idx
                }, function(resp) {
                    if (resp.success) {
                        location.reload();
                    }
                });
            });

            // =====================================================================
            // UNDO RESET: Restore previous design after a reset
            // =====================================================================
            $('#myfeeds-undo-reset-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Restoring...');
                
                $.post(ajaxurl, {
                    action: 'myfeeds_undo_reset_card_design',
                    nonce: nonce
                }, function(resp) {
                    if (resp.success) {
                        location.reload();
                    } else {
                        alert(resp.data ? resp.data.message : 'Undo failed');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Undo Reset', 'myfeeds')); ?>');
                    }
                }).fail(function() {
                    alert('Server error');
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Undo Reset', 'myfeeds')); ?>');
                });
            });

            // Dismiss the undo banner (also clears the backup)
            $('#myfeeds-dismiss-undo-btn').on('click', function() {
                $('#myfeeds-undo-reset-banner').fadeOut(300);
                $.post(ajaxurl, {
                    action: 'myfeeds_dismiss_undo_banner',
                    nonce: nonce
                });
            });
        });
        </script>

        <style>
        .myfeeds-design-wrap { max-width: 1200px; }
        .myfeeds-design-layout { display: flex; gap: 30px; margin-top: 20px; position: relative; }
        .myfeeds-design-layout.is-locked { opacity: 0.5; filter: blur(1px); pointer-events: none; }
        .myfeeds-design-layout.is-locked .myfeeds-design-sidebar { pointer-events: auto; overflow-y: auto; }
        .myfeeds-design-layout.is-locked .myfeeds-design-sidebar input,
        .myfeeds-design-layout.is-locked .myfeeds-design-sidebar select,
        .myfeeds-design-layout.is-locked .myfeeds-design-sidebar button,
        .myfeeds-design-layout.is-locked .myfeeds-design-sidebar .myfeeds-color-swatch,
        .myfeeds-design-layout.is-locked .myfeeds-design-sidebar .myfeeds-sortable-grip { pointer-events: none; }
        .myfeeds-design-sidebar { width: 420px; flex-shrink: 0; max-height: 80vh; overflow-y: auto; padding-right: 10px; }
        .myfeeds-design-preview-area { flex: 1; position: sticky; top: 40px; align-self: flex-start; }
        .myfeeds-design-preview-bg { background: #f0f0f0; padding: 40px; border-radius: 8px; display: flex; justify-content: center; align-items: flex-start; min-height: 400px; }

        /* Preview card needs its own non-!important base styles for JS overrides */
        #myfeeds-design-preview-card { display: flex; flex-direction: column; text-decoration: none; color: inherit; overflow: hidden; transition: all 0.25s ease; cursor: pointer; }
        #myfeeds-design-preview-card .myfeeds-product-image { position: relative; width: 100%; overflow: hidden; }
        #myfeeds-design-preview-card .myfeeds-product-image img { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: contain; object-position: center; display: block; }
        #myfeeds-design-preview-card .myfeeds-discount-badge { position: absolute; padding: 6px 16px; box-shadow: 0 3px 10px rgba(0,0,0,0.2); line-height: 1; letter-spacing: -0.3px; z-index: 10; }
        #myfeeds-design-preview-card .myfeeds-product-details { display: flex; flex-direction: column; flex: 1; min-width: 0; overflow: hidden; }
        #myfeeds-design-preview-card .myfeeds-product-brand { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 4px; line-height: 1.3; }
        #myfeeds-design-preview-card .myfeeds-product-title { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 8px; line-height: 1.3; }
        #myfeeds-design-preview-card .myfeeds-product-price { display: flex; align-items: baseline; gap: 8px; margin-bottom: 6px; }
        #myfeeds-design-preview-card .myfeeds-old-price { text-decoration: line-through; white-space: nowrap; }
        #myfeeds-design-preview-card .myfeeds-current-price { white-space: nowrap; }
        #myfeeds-design-preview-card .myfeeds-shipping-info { margin-bottom: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; letter-spacing: 0.3px; }
        #myfeeds-design-preview-card .myfeeds-merchant { text-align: right; margin-top: auto; padding-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; letter-spacing: 0.3px; }

        /* Sidebar styling */
        .myfeeds-design-section h4 { margin: 0 0 16px; font-size: 15px; font-weight: 700; color: #1d2327; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .myfeeds-design-section { background: #fff; border: 1px solid #e2e4e7; border-radius: 6px; padding: 20px; margin-bottom: 16px; }
        .myfeeds-design-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .myfeeds-design-row label { font-size: 13px; color: #444; flex: 1; font-weight: 500; }
        .myfeeds-design-row input[type="number"] { width: 70px; }
        .myfeeds-design-row input[type="text"] { width: 100px; }
        .myfeeds-design-row select { width: 120px; }
        .myfeeds-design-row input[type="range"] { width: 100px; }
        .myfeeds-design-actions {
            position: sticky;
            bottom: 0;
            z-index: 10;
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
            border: 1px solid #c4b5fd;
            border-radius: 8px;
            padding: 20px;
            margin-top: 16px;
            display: flex;
            gap: 16px;
            justify-content: center;
            align-items: center;
        }

        .myfeeds-btn-save {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: none;
            padding: 10px 28px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
        }
        .myfeeds-btn-save:hover { opacity: 0.9; transform: translateY(-1px); color: #fff !important; }
        a.myfeeds-btn-save:hover, a.myfeeds-btn-save:focus, a.myfeeds-btn-save:active { color: #fff !important; text-decoration: none !important; }
        .myfeeds-btn-save:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        .myfeeds-btn-reset {
            background: #fff;
            color: #555;
            border: 1px solid #c4b5fd;
            padding: 10px 28px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .myfeeds-btn-reset:hover { background: #f5f3ff; border-color: #667eea; color: #333; }
        .myfeeds-btn-reset:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Color picker (same pattern as carousel arrow picker) */
        .myfeeds-color-picker-wrap { display: flex; flex-direction: column; gap: 0; padding: 4px 0; }
        .myfeeds-color-swatches-row { display: flex; flex-wrap: nowrap; gap: 6px; align-items: center; }
        .myfeeds-color-hex-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
        .myfeeds-color-preview-dot { width: 22px; height: 22px; border-radius: 50%; border: 1px solid #ccc; flex-shrink: 0; transition: background 0.15s, border-color 0.15s; }
        .myfeeds-color-preview-dot.active { border: 2.5px solid #333333; }
        .myfeeds-color-picker-wrap input[type="text"] { width: 80px; font-size: 12px; font-family: monospace; }
        .myfeeds-color-swatch { width: 22px; height: 22px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; transition: border-color 0.15s, transform 0.1s; }
        .myfeeds-color-swatch:hover { transform: scale(1.15); }
        .myfeeds-color-swatch:hover { border-color: #999; }
        .myfeeds-color-swatch.active { border-color: #333333; border-width: 2.5px; }
        .myfeeds-color-swatch[data-color="#ffffff"] { border: 1px solid #ccc; }

        /* Upgrade overlay */
        .myfeeds-design-upgrade-overlay { position: relative; z-index: 100; background: rgba(255,255,255,0.95); border: 2px solid #667eea; border-radius: 8px; padding: 40px; text-align: center; margin: 0 0 20px; }
        .myfeeds-design-upgrade-box h3 { margin: 12px 0 8px; }
        .myfeeds-design-upgrade-box p { color: #666; }

        .myfeeds-design-row-color {
            flex-direction: column !important;
            align-items: flex-start !important;
        }
        .myfeeds-design-row-color label {
            width: 100% !important;
            margin-bottom: 8px !important;
        }
        .myfeeds-design-row-color .myfeeds-color-picker-wrap {
            width: 100%;
        }

        .myfeeds-section-undo-redo { display: flex; gap: 4px; }
        .myfeeds-section-undo-redo button { background: none; border: 1px solid #ddd; border-radius: 50%; width: 30px; height: 30px; cursor: pointer; font-size: 15px; color: #888; display: flex; align-items: center; justify-content: center; transition: all 0.15s; }
        .myfeeds-section-undo-redo button:hover:not(:disabled) { background: #f5f3ff; border-color: #667eea; color: #667eea; }
        .myfeeds-section-undo-redo button:disabled { opacity: 0.25; cursor: default; }

        .myfeeds-font-select {
            width: 100% !important;
            max-width: 200px;
            font-size: 12px;
            padding: 4px 8px;
        }
        .myfeeds-design-row-font {
            flex-wrap: wrap;
        }
        .myfeeds-design-row-font label {
            width: 100%;
            margin-bottom: 6px;
        }
        .myfeeds-custom-font-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 10px;
            background: #f8f9fa;
            border: 1px solid #e2e4e7;
            border-radius: 4px;
            margin-bottom: 6px;
            font-size: 12px;
        }
        .myfeeds-custom-font-name { font-weight: 500; }
        .myfeeds-remove-custom-font {
            background: none;
            border: none;
            color: #d63638;
            font-size: 18px;
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
        }
        .myfeeds-remove-custom-font:hover { color: #a00; }

        .myfeeds-btn-outline {
            background: #fff;
            color: #667eea;
            border: 1.5px solid #667eea;
            padding: 6px 16px;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .myfeeds-btn-outline:hover {
            background: #f5f3ff;
            border-color: #764ba2;
            color: #764ba2;
        }
        .myfeeds-undo-reset-banner {
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
            border: 1px solid #c4b5fd;
            border-radius: 6px;
            padding: 12px 20px;
            margin-bottom: 16px;
            font-size: 13px;
            color: #444;
        }

        #myfeeds-sortable-sections {
            position: relative;
        }
        .myfeeds-sortable-section {
            position: relative;
            padding-left: 36px !important;
            cursor: default;
        }
        .myfeeds-sortable-grip {
            position: absolute;
            left: 8px;
            top: 18px;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #bbb;
            cursor: grab;
            user-select: none;
            transition: color 0.15s;
            letter-spacing: 2px;
        }
        .myfeeds-sortable-grip:hover {
            color: #667eea;
        }
        .myfeeds-sortable-section.ui-sortable-helper {
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3) !important;
            border-color: #667eea !important;
            background: #fefeff !important;
            transform: scale(1.02);
            z-index: 1000;
        }
        .myfeeds-sortable-section.ui-sortable-helper .myfeeds-sortable-grip {
            color: #667eea;
            cursor: grabbing;
        }
        #myfeeds-sortable-sections .ui-sortable-placeholder {
            visibility: visible !important;
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%) !important;
            border: 2px dashed #c4b5fd !important;
            border-radius: 6px;
            margin-bottom: 16px;
            min-height: 60px;
        }
        /* Range slider — MyFeeds purple design with filled track */
        .myfeeds-design-sidebar input[type="range"] {
            -webkit-appearance: none;
            appearance: none;
            width: 120px;
            height: 6px;
            background: #e2e4e7;
            border-radius: 3px;
            outline: none;
            cursor: pointer;
            margin: 8px 0;
        }

        .myfeeds-design-sidebar input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            cursor: pointer;
            border: 2px solid #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.2);
            margin-top: -6px;
        }

        .myfeeds-design-sidebar input[type="range"]::-moz-range-thumb {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            cursor: pointer;
            border: 2px solid #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.2);
        }

        .myfeeds-design-sidebar input[type="range"]::-webkit-slider-runnable-track {
            height: 6px;
            border-radius: 3px;
            background: #e2e4e7;
        }

        .myfeeds-design-sidebar input[type="range"]::-moz-range-track {
            height: 6px;
            border-radius: 3px;
            background: #e2e4e7;
        }

        .myfeeds-design-sidebar input[type="range"]::-moz-range-progress {
            height: 6px;
            border-radius: 3px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .myfeeds-design-sidebar input[type="range"]:focus::-webkit-slider-thumb {
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.3);
        }

        .myfeeds-design-sidebar input[type="range"]:focus::-moz-range-thumb {
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.3);
        }
        </style>
        <?php
    }

    /**
     * Render all sidebar sections
     */
    private function render_sidebar_sections($d, $colors) {
        // Card Container
        $this->section_start('Card Container');
        $this->row_color('card_bg_color', 'Background', $d, $colors);
        $this->row_range('card_border_radius', 'Border Radius', $d, 0, 30, 'px');
        $this->row_range('card_border_width', 'Border Width', $d, 0, 5, 'px');
        $this->row_color('card_border_color', 'Border Color', $d, $colors);
        $this->row_range('card_shadow_strength', 'Shadow Size', $d, 0, 20, 'px');
        $this->row_range('card_shadow_blur', 'Shadow Blur', $d, 0, 40, 'px');
        $this->row_range('card_hover_shadow_strength', 'Hover Shadow Size', $d, 0, 20, 'px');
        $this->row_range('card_hover_shadow_blur', 'Hover Shadow Blur', $d, 0, 50, 'px');
        $this->row_range('card_hover_scale', 'Hover Scale', $d, 100, 115, '%');
        $this->row_range('card_hover_radius', 'Hover Border Radius', $d, 0, 30, 'px');
        $this->row_number('card_min_width', 'Min Width', $d, 100, 400, 'px');
        $this->row_number('card_max_width', 'Max Width', $d, 150, 500, 'px');
        $this->section_end();

        // Image
        $this->section_start('Image');
        $this->row_range('image_ratio', 'Aspect Ratio (height %)', $d, 80, 180, '%');
        $this->row_color('image_bg_color', 'Background', $d, $colors);
        $this->row_select('image_object_fit', 'Object Fit', $d, array('contain' => 'Contain', 'cover' => 'Cover', 'fill' => 'Fill'));
        $this->section_end();

        // Discount Badge
        $this->section_start('Discount Badge');
        $this->row_toggle('badge_visible', 'Show Badge', $d);
        $this->row_font('badge_font_family', 'Font', $d);
        $this->row_color('badge_bg_color', 'Background', $d, $colors);
        $this->row_color('badge_text_color', 'Text Color', $d, $colors);
        $this->row_range('badge_font_size', 'Font Size', $d, 8, 20, 'px');
        $this->row_select('badge_font_weight', 'Font Weight', $d, array(400 => 'Normal', 600 => 'Semi-Bold', 700 => 'Bold'));
        $this->row_range('badge_border_radius', 'Border Radius', $d, 0, 30, 'px');
        $this->row_select('badge_text_transform', 'Transform', $d, array('none' => 'None', 'uppercase' => 'Uppercase', 'capitalize' => 'Capitalize', 'lowercase' => 'Lowercase'));
        $this->row_range('badge_position_top', 'Vertical Position', $d, 0, 100, '%');
        $this->row_range('badge_position_left', 'Horizontal Position', $d, 0, 100, '%');
        $this->section_end();

        // Sortable element sections — order from saved settings
        $element_order = $d['element_order'] ?? array('brand', 'title', 'price', 'shipping', 'merchant');
        $preset_colors = $colors;
        echo '<div id="myfeeds-sortable-sections">';
        echo '<input type="hidden" id="mf_element_order" name="element_order" value="' . esc_attr(implode(',', $element_order)) . '">';

        foreach ($element_order as $element_key) {
            switch ($element_key) {
                case 'brand':
                    $this->render_brand_section($d, $preset_colors);
                    break;
                case 'title':
                    $this->render_title_section($d, $preset_colors);
                    break;
                case 'price':
                    $this->render_price_section($d, $preset_colors);
                    break;
                case 'shipping':
                    $this->render_shipping_section($d, $preset_colors);
                    break;
                case 'merchant':
                    $this->render_merchant_section($d, $preset_colors);
                    break;
            }
        }

        echo '</div>'; // close #myfeeds-sortable-sections

        // Spacing
        $this->section_start('Spacing');
        $this->row_range('details_padding_x', 'Horizontal Padding', $d, 0, 30, 'px');
        $this->row_range('details_padding_y', 'Vertical Padding', $d, 0, 30, 'px');
        $this->section_end();

        // Custom Fonts Upload
        $this->section_start_plain('Custom Fonts');
        $custom_fonts = MyFeeds_Settings_Manager::get_custom_fonts();
        echo '<p style="font-size:12px;color:#666;margin:0 0 12px;">Upload .woff2, .woff, or .ttf files. They will appear in all font dropdowns.</p>';
        echo '<div id="myfeeds-custom-fonts-list">';
        if (!empty($custom_fonts)) {
            foreach ($custom_fonts as $idx => $cf) {
                echo '<div class="myfeeds-custom-font-item" data-idx="' . esc_attr($idx) . '">';
                echo '<span class="myfeeds-custom-font-name">' . esc_html($cf['name']) . '</span>';
                echo '<button type="button" class="myfeeds-remove-custom-font" data-idx="' . esc_attr($idx) . '" title="Remove">&times;</button>';
                echo '</div>';
            }
        }
        echo '</div>';
        echo '<button type="button" id="myfeeds-upload-font-btn" class="myfeeds-btn-outline" style="margin-top:8px;">+ Upload Font</button>';
        $this->section_end();
    }

    // =====================================================================
    // SIDEBAR FIELD RENDERERS
    // =====================================================================

    private function section_start($title) {
        echo '<div class="myfeeds-design-section">';
        echo '<h4 style="display:flex;justify-content:space-between;align-items:center;">';
        echo esc_html($title);
        echo '<span class="myfeeds-section-undo-redo">';
        echo '<button type="button" class="myfeeds-undo-btn" title="Undo" disabled>&#x21A9;</button>';
        echo '<button type="button" class="myfeeds-redo-btn" title="Redo" disabled>&#x21AA;</button>';
        echo '</span>';
        echo '</h4>';
    }
    private function section_start_plain($title) {
        echo '<div class="myfeeds-design-section">';
        echo '<h4>' . esc_html($title) . '</h4>';
    }
    private function section_start_sortable($title, $element_key) {
        echo '<div class="myfeeds-design-secds-sortable-section" data-element="' . esc_attr($element_key) . '">';
        echo '<div class="myfeeds-sortable-grip" title="Drag to reorder">&#x2807;&#x2807;</div>';
        echo '<h4 style="display:flex;justify-content:space-between;align-items:center;">';
        echo esc_html($title);
        echo '<span class="myfeeds-section-undo-redo">';
        echo '<button type="button" class="myfeeds-undo-btn" title="Undo" disabled>&#x21A9;</button>';
        echo '<button type="button" class="myfeeds-redo-btn" title="Redo" disabled>&#x21AA;</button>';
        echo '</span>';
        echo '</h4>';
    }
    private function section_end() { echo '</div>'; }

    private function render_brand_section($d, $colors) {
        $this->section_start_sortable('Brand', 'brand');
        $this->row_toggle('brand_visible', 'Show Brand', $d);
        $this->row_font('brand_font_family', 'Font', $d);
        $this->row_range('brand_font_size', 'Font Size', $d, 8, 20, 'px');
        $this->row_select('brand_font_weight', 'Font Weight', $d, array(400 => 'Normal', 500 => 'Medium', 600 => 'Semi-Bold', 700 => 'Bold'));
        $this->row_color('brand_color', 'Color', $d, $colors);
        $this->row_select('brand_text_transform', 'Transform', $d, array('none' => 'None', 'uppercase' => 'Uppercase', 'capitalize' => 'Capitalize'));
        $this->section_end();
    }

    private function render_title_section($d, $colors) {
        $this->section_start_sortable('Title', 'title');
        $this->row_toggle('title_visible', 'Show Title', $d);
        $this->row_font('title_font_family', 'Font', $d);
        $this->row_range('title_font_size', 'Font Size', $d, 8, 24, 'px');
        $this->row_select('title_font_weight', 'Font Weight', $d, array(300 => 'Light', 400 => 'Normal', 500 => 'Medium', 600 => 'Semi-Bold'));
        $this->row_color('title_color', 'Color', $d, $colors);
        $this->row_select('title_text_transform', 'Transform', $d, array('none' => 'None', 'uppercase' => 'Uppercase', 'capitalize' => 'Capitalize', 'lowercase' => 'Lowercase'));
        $this->section_end();
    }

    private function render_price_section($d, $colors) {
        $this->section_start_sortable('Price', 'price');
        $this->row_toggle('price_visible', 'Show Price', $d);
        $this->row_font('price_font_family', 'Font (Current Price)', $d);
        $this->row_range('price_font_size', 'Current Price Size', $d, 10, 28, 'px');
        $this->row_select('price_font_weight', 'Current Price Weight', $d, array(400 => 'Normal', 600 => 'Semi-Bold', 700 => 'Bold'));
        $this->row_color('price_color', 'Current Price Color', $d, $colors);
        $this->row_color('price_discount_color', 'Discount Price Color', $d, $colors);
        $this->row_font('old_price_font_family', 'Font (Old Price)', $d);
        $this->row_range('old_price_font_size', 'Old Price Size', $d, 10, 28, 'px');
        $this->row_select('old_price_font_weight', 'Old Price Weight', $d, array(400 => 'Normal', 500 => 'Medium', 600 => 'Semi-Bold', 700 => 'Bold'));
        $this->row_color('old_price_color', 'Old Price Color', $d, $colors);
        $this->section_end();
    }

    private function render_shipping_section($d, $colors) {
        $this->section_start_sortable('Shipping', 'shipping');
        $this->row_toggle('shipping_visible', 'Show Shipping', $d);
        $this->row_font('shipping_font_family', 'Font', $d);
        $this->row_range('shipping_font_size', 'Font Size', $d, 8, 16, 'px');
        $this->row_color('shipping_color', 'Color', $d, $colors);
        $this->row_select('shipping_text_transform', 'Transform', $d, array('none' => 'None', 'uppercase' => 'Uppercase'));
        $this->section_end();
    }

    private function render_merchant_section($d, $colors) {
        $this->section_start_sortable('Merchant', 'merchant');
        $this->row_toggle('merchant_visible', 'Show Merchant', $d);
        $this->row_font('merchant_font_family', 'Font', $d);
        $this->row_range('merchant_font_size', 'Font Size', $d, 8, 16, 'px');
        $this->row_color('merchant_color', 'Color', $d, $colors);
        $this->row_select('merchant_text_transform', 'Transform', $d, array('none' => 'None', 'uppercase' => 'Uppercase'));
        $this->section_end();
    }

    private function row_toggle($key, $label, $d) {
        $checked = !empty($d[$key]) ? ' checked' : '';
        echo '<div class="myfeeds-design-row"><label>' . esc_html($label) . '</label>';
        echo '<input type="checkbox" id="mf_' . esc_attr($key) . '" name="' . esc_attr($key) . '"' . esc_attr($checked) . '></div>';
    }

    private function row_range($key, $label, $d, $min, $max, $unit = '') {
        $val = intval($d[$key] ?? $min);
        echo '<div class="myfeeds-design-row"><label>' . esc_html($label) . '</label>';
        echo '<input type="range" id="mf_' . esc_attr($key) . '" name="' . esc_attr($key) . '" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" value="' . esc_attr($val) . '">';
        echo '<span id="mf_' . esc_attr($key) . '_val" style="width:45px;text-align:right;font-size:12px;">' . esc_html($val) . esc_html($unit) . '</span>';
        echo '</div>';
        // Live value display
        echo '<script>jQuery(function($){$("#mf_' . esc_js($key) . '").on("input",function(){$("#mf_' . esc_js($key) . '_val").text(this.value+"' . esc_js($unit) . '")})});</script>';
    }

    private function row_number($key, $label, $d, $min, $max, $unit = '') {
        $val = intval($d[$key] ?? $min);
        echo '<div class="myfeeds-design-row"><label>' . esc_html($label) . '</label>';
        echo '<input type="number" id="mf_' . esc_attr($key) . '" name="' . esc_attr($key) . '" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" value="' . esc_attr($val) . '" step="1">';
        if ($unit) echo '<span style="font-size:12px;margin-left:4px;">' . esc_html($unit) . '</span>';
        echo '</div>';
    }

    private function row_text($key, $label, $d) {
        $val = esc_attr($d[$key] ?? '');
        echo '<div class="myfeeds-design-row"><label>' . esc_html($label) . '</label>';
        echo '<input type="text" id="mf_' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" style="width:160px;font-size:11px;">';
        echo '</div>';
    }

    private function row_select($key, $label, $d, $options) {
        $current = $d[$key] ?? '';
        echo '<div class="myfeeds-design-row"><label>' . esc_html($label) . '</label>';
        echo '<select id="mf_' . esc_attr($key) . '" name="' . esc_attr($key) . '">';
        foreach ($options as $val => $text) {
            $sel = ((string)$current === (string)$val) ? ' selected' : '';
            echo '<option value="' . esc_attr($val) . '"' . esc_attr($sel) . '>' . esc_html($text) . '</option>';
        }
        echo '</select></div>';
    }

    private function row_color($key, $label, $d, $preset_colors) {
        $val = $d[$key] ?? '#333333';
        echo '<div class="myfeeds-design-row myfeeds-design-row-color"><label>' . esc_html($label) . '</label>';
        echo '<div class="myfeeds-color-picker-wrap">';
        echo '<div class="myfeeds-color-swatches-row">';
        foreach ($preset_colors as $c) {
            $active = (strtolower($val) === strtolower($c)) ? ' active' : '';
            echo '<span class="myfeeds-color-swatch' . esc_attr($active) . '" data-color="' . esc_attr($c) . '" style="background:' . esc_attr($c) . ';"></span>';
        }
        echo '</div>';
        echo '<div class="myfeeds-color-hex-row">';
        echo '<span class="myfeeds-color-preview-dot" id="mf_' . esc_attr($key) . '_dot" style="background:' . esc_attr($val) . ';"></span>';
        echo '<input type="text" id="mf_' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" maxlength="7" placeholder="#hex">';
        echo '</div>';
        echo '</div></div>';
    }

    private function row_font($key, $label, $d) {
        $current = $d[$key] ?? '__system__';
        $all_fonts = MyFeeds_Settings_Manager::get_all_font_options();
        $google_fonts = MyFeeds_Settings_Manager::get_available_fonts();
        
        echo '<div class="myfeeds-design-row myfeeds-design-row-font">';
        echo '<label>' . esc_html($label) . '</label>';
        echo '<select id="mf_' . esc_attr($key) . '" name="' . esc_attr($key) . '" class="myfeeds-font-select">';
        
        // Group by category
        $groups = array(
            'System' => array(),
            'Sans-Serif' => array(),
            'Serif' => array(),
            'Display' => array(),
            'Handwriting' => array(),
            'Monospace' => array(),
            'Custom' => array(),
        );
        
        foreach ($all_fonts as $font_key => $font_label) {
            if ($font_key === '__system__') {
                $groups['System'][$font_key] = $font_label;
            } elseif (strpos($font_key, 'custom:') === 0) {
                $groups['Custom'][$font_key] = $font_label;
            } elseif (isset($google_fonts[$font_key])) {
                $cat = $google_fonts[$font_key]['category'] ?? 'sans-serif';
                if ($cat === 'sans-serif') $groups['Sans-Serif'][$font_key] = $font_label;
                elseif ($cat === 'serif') $groups['Serif'][$font_key] = $font_label;
                elseif ($cat === 'display') $groups['Display'][$font_key] = $font_label;
                elseif ($cat === 'handwriting') $groups['Handwriting'][$font_key] = $font_label;
                elseif ($cat === 'monospace') $groups['Monospace'][$font_key] = $font_label;
                else $groups['Sans-Serif'][$font_key] = $font_label;
            }
        }
        
        foreach ($groups as $group_label => $fonts) {
            if (empty($fonts)) continue;
            echo '<optgroup label="' . esc_attr($group_label) . '">';
            foreach ($fonts as $fkey => $flabel) {
                $sel = ($current === $fkey) ? ' selected' : '';
                echo '<option value="' . esc_attr($fkey) . '"' . esc_attr($sel) . '>' . esc_html($flabel) . '</option>';
            }
            echo '</optgroup>';
        }
        
        echo '</select>';
        echo '</div>';
    }

    // =====================================================================
    // AJAX HANDLERS
    // =====================================================================

    public function ajax_save() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        // Collect all design fields from POST
        $design = array();
        $defaults = MyFeeds_Settings_Manager::get_card_design();
        
        // element_order comes as comma-separated string from hidden input
        if (isset($_POST['element_order'])) {
            $design['element_order'] = sanitize_text_field(wp_unslash($_POST['element_order']));
        }
        
        foreach ($defaults as $key => $default) {
            if ($key === 'element_order') continue; // handled above
            if (is_bool($default)) {
                $design[$key] = !empty($_POST[$key]) && $_POST[$key] !== '0';
            } elseif (isset($_POST[$key])) {
                $design[$key] = sanitize_text_field(wp_unslash($_POST[$key]));
            }
        }

        MyFeeds_Settings_Manager::save_card_design($design);
        wp_send_json_success(array('message' => 'Design saved'));
    }

    public function ajax_reset() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        // Backup current settings before reset (for undo)
        $current = get_option(MyFeeds_Settings_Manager::OPTION_CARD_DESIGN, array());
        if (!empty($current)) {
            update_option('myfeeds_card_design_backup', $current);
        }
        
        delete_option(MyFeeds_Settings_Manager::OPTION_CARD_DESIGN);
        wp_send_json_success(array('message' => 'Reset to defaults'));
    }

    public function ajax_add_custom_font() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $name = sanitize_text_field(wp_unslash($_POST['font_name'] ?? ''));
        $url = esc_url_raw(wp_unslash($_POST['font_url'] ?? ''));
        
        if (empty($name) || empty($url)) {
            wp_send_json_error(array('message' => 'Font name and URL are required'));
        }
        
        $fonts = MyFeeds_Settings_Manager::get_custom_fonts();
        $fonts[] = array('name' => $name, 'url' => $url);
        MyFeeds_Settings_Manager::save_custom_fonts($fonts);
        
        wp_send_json_success(array('message' => 'Font added'));
    }

    public function ajax_remove_custom_font() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $idx = intval($_POST['font_index'] ?? -1);
        $fonts = MyFeeds_Settings_Manager::get_custom_fonts();
        
        if (!isset($fonts[$idx])) {
            wp_send_json_error(array('message' => 'Font not found'));
        }
        
        array_splice($fonts, $idx, 1);
        MyFeeds_Settings_Manager::save_custom_fonts($fonts);
        
        wp_send_json_success(array('message' => 'Font removed'));
    }

    public function ajax_undo_reset() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $backup = get_option('myfeeds_card_design_backup', array());
        if (empty($backup)) {
            wp_send_json_error(array('message' => 'No backup found'));
        }
        
        update_option(MyFeeds_Settings_Manager::OPTION_CARD_DESIGN, $backup);
        delete_option('myfeeds_card_design_backup');
        wp_send_json_success(array('message' => 'Previous design restored'));
    }

    public function ajax_dismiss_undo() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_die(); }
        delete_option('myfeeds_card_design_backup');
        wp_send_json_success();
    }
}
