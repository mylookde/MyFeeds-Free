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
        // Jetpack's image CDN rewrites every <img> in post content to
        // i0.wp.com and drops the query string on the way. Merchant CDNs
        // need it: Shopify serves .../file.jpg?v=1788598473 and answers
        // 404 to the same path without it. The card showed a broken image
        // while the editor, which loads the original URL, showed the
        // product (mylook.com.de, 2026-09-06). Feed images are hosted by
        // the merchant and are not ours to re-encode.
        add_filter('jetpack_photon_skip_image', [$this, 'skip_image_cdn_for_cards'], 10, 3);
    }

    /**
     * Tell Jetpack's image CDN to leave a card image alone.
     *
     * @param bool        $skip      What earlier filters decided.
     * @param string      $image_url The image URL.
     * @param string|null $tag       The full <img> tag when the CDN is
     *                               rewriting content; null elsewhere.
     * @return bool
     */
    public function skip_image_cdn_for_cards($skip, $image_url, $tag = null) {
        if ($skip) {
            return true;
        }
        return is_string($tag) && strpos($tag, 'myfeeds') !== false;
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
        // Every price the editor shows goes through the site's price format,
        // the same rule the card on the page uses.
        $bundle_deps = ['wp-blocks', 'wp-components', 'wp-element', 'wp-i18n', myfeeds_price_format_script()];
        // Rakuten's part of the picker, registered where Rakuten runs.
        $source_handles = class_exists('MyFeeds_Source_Modules')
            ? MyFeeds_Source_Modules::editor_handles()
            : array('scripts' => array(), 'styles' => array());
        // Amazon's API in the Amazon tab and the Look, where it runs.
        $amazon_handles = class_exists('MyFeeds_Amazon_Api')
            ? MyFeeds_Amazon_Api::editor_handles()
            : array('scripts' => array(), 'styles' => array());

        // The sidebar: the display (grid, carousel, recommendation card,
        // comparison table) and the texts of the last two. A dependency of
        // the bundle so it loads first: the `blocks.registerBlockType`
        // filter in it has to be in place before the bundle registers the
        // block, otherwise every attribute but selectedProducts is dropped
        // on save.
        $inspector_path = MYFEEDS_PLUGIN_DIR . 'assets/picker-inspector.js';
        if (file_exists($inspector_path)) {
            wp_register_script(
                'myfeeds-picker-inspector',
                MYFEEDS_PLUGIN_URL . 'assets/picker-inspector.js',
                ['wp-hooks', 'wp-compose', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-api-fetch', myfeeds_price_format_script()],
                filemtime($inspector_path),
                true
            );
            $bundle_deps[] = 'myfeeds-picker-inspector';
            $bundle_deps = array_merge($bundle_deps, $amazon_handles['scripts']);
            // Rakuten's line under the picker's search.
            $bundle_deps = array_merge($bundle_deps, $source_handles['scripts']);
        }

        $script_registered = wp_register_script(
            'myfeeds-product-picker-editor',
            MYFEEDS_PLUGIN_URL . 'build/index.js',
            $bundle_deps,
            $script_ver,
            true
        );
        
        if (!$script_registered && class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("BLOCK DEBUG: Failed to register JavaScript!");
        }

        // Inline `before` script that patches the block's attribute schema
        // the moment the bundle calls registerBlockType(). The same filter
        // exists in picker-inspector.js too, but this inline version ships
        // with the bundle handle and prints right before its <script> tag,
        // whatever order the scripts load in. One list for both and for
        // the render: myfeeds_picker_block_attributes().
        if ($script_registered) {
            wp_add_inline_script('myfeeds-product-picker-editor', myfeeds_picker_attributes_inline_js(), 'before');
        }

        // The editor says what the card says ("Price on request"), in the
        // site's language once the language pack carries the bundle's strings.
        if ($script_registered && function_exists('wp_set_script_translations')) {
            wp_set_script_translations('myfeeds-product-picker-editor', 'myfeeds-affiliate-feed-manager');
        }
        
        // The Amazon tab of the sidebar, scoped under .myfeeds-amz. A style
        // the editor's iframe gets, so a dependency of the block's own.
        $amz_css_path = MYFEEDS_PLUGIN_DIR . 'assets/amazon-picker.css';
        wp_register_style(
            'myfeeds-amazon-picker',
            MYFEEDS_PLUGIN_URL . 'assets/amazon-picker.css',
            [],
            file_exists($amz_css_path) ? filemtime($amz_css_path) : MYFEEDS_VERSION
        );

        // The texts of a recommendation card or comparison table. A style
        // the editor's iframe gets, so a dependency of the block's own.
        $pick_css_path = MYFEEDS_PLUGIN_DIR . 'assets/pick-panel.css';
        wp_register_style(
            'myfeeds-pick-panel',
            MYFEEDS_PLUGIN_URL . 'assets/pick-panel.css',
            [],
            file_exists($pick_css_path) ? filemtime($pick_css_path) : MYFEEDS_VERSION
        );

        // Register block styles
        $editor_style_registered = wp_register_style(
            'myfeeds-product-picker-editor',
            MYFEEDS_PLUGIN_URL . 'assets/editor.css',
            array_merge(['myfeeds-amazon-picker'], $amazon_handles['styles'], ['myfeeds-pick-panel'], $source_handles['styles']),
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
            // Wide and Full in the block toolbar. Without an alignment
            // the grid or carousel is as wide as the text around it; the
            // theme decides what Wide and Full mean. src/index.js declares
            // the same, because the editor takes the script's settings
            // over these.
            'supports' => [
                'align' => ['wide', 'full'],
            ],
            'attributes' => myfeeds_picker_block_attributes(),
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
        
        // Pass data to JavaScript. The sidebar loads first and reads the
        // same object, so it gets it too.
        $localize_data = [
            'apiUrl' => rest_url('myfeeds/v1/'),
            'pluginUrl' => MYFEEDS_PLUGIN_URL,
            'nonce' => wp_create_nonce('wp_rest'),
            'displays' => class_exists('MyFeeds_Picker_Displays') ? MyFeeds_Picker_Displays::editor_config() : null,
            // The Amazon tab: products by link or ASIN with the site's
            // partner tag.
            'amazon' => class_exists('MyFeeds_Amazon_Modules') ? MyFeeds_Amazon_Modules::picker_config() : array('allowed' => false),
            // Rakuten as the source: a search that finds little in a
            // feed's snapshot may pull the same words from Rakuten.
            'sources' => class_exists('MyFeeds_Source_Modules') ? MyFeeds_Source_Modules::picker_config() : array('allowed' => false),
        ];
        if (wp_script_is('myfeeds-picker-inspector', 'registered')) {
            wp_localize_script('myfeeds-picker-inspector', 'myfeedsData', $localize_data);
        }
        $localize_result = wp_localize_script('myfeeds-product-picker-editor', 'myfeedsData', $localize_data);
        
        if (!$localize_result && class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("ASSETS DEBUG: Failed to localize script data!");
        } elseif (class_exists('MyFeeds_External_Debug')) {
            MyFeeds_External_Debug::log("ASSETS DEBUG: Script data localized successfully");
        }
    }
    
    public function enqueue_frontend_assets() {
        if (has_block('myfeeds/product-picker')) {
            wp_enqueue_style('myfeeds-product-picker-frontend');
            MyFeeds_Picker_Carousel::enqueue_for_post();
        }
    }

    /**
     * The class attribute of the block's outer element - the grid, the
     * carousel or the editor's empty note - with a leading space, ready
     * to go into the opening tag.
     *
     * Wide and Full are drawn by the theme (.alignwide / .alignfull), so
     * the block only has to carry the class. While WordPress renders the
     * block, get_block_wrapper_attributes() supplies it, together with the
     * "Additional CSS class(es)" from the Advanced panel, which a dynamic
     * block otherwise drops on the floor. Called anywhere else, the
     * alignment comes straight from the attribute.
     *
     * @param array  $attrs   Block attributes.
     * @param string $classes The element's own classes.
     * @return string
     */
    public static function wrapper_attributes($attrs, $classes) {
        if (function_exists('get_block_wrapper_attributes')
            && class_exists('WP_Block_Supports')
            && !empty(WP_Block_Supports::$block_to_render)) {
            $attributes = get_block_wrapper_attributes(['class' => $classes]);
            if ($attributes !== '') {
                return ' ' . $attributes;
            }
        }

        $align = self::align_class($attrs);
        return ' class="' . esc_attr($align === '' ? $classes : $classes . ' ' . $align) . '"';
    }

    /**
     * 'alignwide' or 'alignfull' for the two alignments the block offers,
     * '' for anything else - no alignment, or a value the block does not
     * support.
     *
     * @param array $attrs Block attributes.
     * @return string
     */
    public static function align_class($attrs) {
        $align = (is_array($attrs) && isset($attrs['align']) && is_string($attrs['align'])) ? $attrs['align'] : '';
        return in_array($align, ['wide', 'full'], true) ? 'align' . $align : '';
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
     * - The placeholder helper (render_missing_product_placeholder)
     *   wraps its inputs with intval / esc_attr / esc_html via sprintf.
     * - The carousel container's two dynamic attributes are built by
     *   MyFeeds_Picker_Carousel and go through esc_attr: the arrow colour
     *   after sanitize_hex_color, and the Splide options after
     *   wp_json_encode.
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

        // Amazon products are stored apart and carry an ASIN only, plus
        // the author's own words for the card (brand, name, note) - never
        // anything copied from Amazon. After the feed products, which is
        // the order the editor shows them in.
        if (!empty($attrs['amazonProducts']) && is_array($attrs['amazonProducts'])
            && class_exists('MyFeeds_Amazon_Modules') && MyFeeds_Amazon_Modules::links_active()) {
            foreach ($attrs['amazonProducts'] as $entry) {
                $id = is_array($entry) && isset($entry['id']) ? (string) $entry['id'] : '';
                if ($id !== '' && MyFeeds_Amazon_Compliance::is_amazon_id($id)) {
                    $products[] = array('id' => $id, 'amz_manual' => array(
                        'brand' => isset($entry['brand']) ? sanitize_text_field((string) $entry['brand']) : '',
                        'name'  => isset($entry['name'])  ? sanitize_text_field((string) $entry['name'])  : '',
                        'note'  => isset($entry['note'])  ? sanitize_text_field((string) $entry['note'])  : '',
                    ));
                }
            }
        }

        if (empty($products)) {
            return '<div' . self::wrapper_attributes($attrs, 'myfeeds-no-products') . '>' . esc_html__('No products selected.', 'myfeeds-affiliate-feed-manager') . '</div>';
        }

        $placeholder_url = MYFEEDS_PLUGIN_URL . 'assets/placeholder.png';
        $use_db = class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode();

        static $cached_card_design = null;
        if ($cached_card_design === null && class_exists('MyFeeds_Settings_Manager')) {
            $cached_card_design = MyFeeds_Settings_Manager::get_card_design();
        }

        $is_carousel = (($attrs['displayMode'] ?? 'grid') === 'carousel');

        // Recommendation card or comparison table: the loop below still
        // decides which products survive, and they are drawn after it,
        // because a label is a statement about all of them.
        $pick_style = class_exists('MyFeeds_Picker_Displays') ? MyFeeds_Picker_Displays::style_for($attrs, false) : '';
        $pick_items = array();
        $render_card = function ($product) use ($placeholder_url, $cached_card_design) {
            return $this->render_product_card($product, $placeholder_url, $cached_card_design);
        };
        $click_block = $is_carousel ? 'picker-carousel' : 'picker-grid';
        if ($pick_style !== '') {
            $click_block = MyFeeds_Picker_Displays::click_block($pick_style);
        }

        $output = $is_carousel
            ? MyFeeds_Picker_Carousel::open($attrs)
            : '<div' . self::wrapper_attributes($attrs, 'myfeeds-product-grid') . '>';
        $rendered_count = 0;
        $rendered_products = array();

        // Amazon ids are fetched once for the whole block. Resolving them
        // one by one would mean one API call per card against an account
        // that starts at a single request per second.
        $amazon_map = array();
        if (class_exists('MyFeeds_Amazon_Compliance') && class_exists('MyFeeds_Amazon_Products')) {
            $amazon_ids = array();
            foreach ($products as $candidate) {
                if (is_array($candidate) && !empty($candidate['id'])
                    && MyFeeds_Amazon_Compliance::is_amazon_id($candidate['id'])) {
                    $amazon_ids[] = (string) $candidate['id'];
                }
            }
            if (!empty($amazon_ids)) {
                $amazon_map = MyFeeds_Amazon_Products::get_by_ids($amazon_ids, 'missing');
            }
        }

        for ($idx = 0; $idx < count($products); $idx++) {
            $current_item = $products[$idx];
            $product_data = null;
            $data_source = 'none';
            
            if (!is_array($current_item) || empty($current_item['id'])) {
                continue;
            }
            
            $requested_id = (string)$current_item['id'];
            // What a label or favourite is stored by, whatever the card
            // resolves to below.
            $slot_id      = $requested_id;
            $own_words    = isset($current_item['amz_manual']) && is_array($current_item['amz_manual']) ? $current_item['amz_manual'] : array();

            // Amazon products came out of the batched prewarm above. They
            // never fall back to a saved snapshot: there is none, and the
            // licence would not let us keep one.
            if (isset($amazon_map[$requested_id])) {
                if ($pick_style !== '') {
                    $pick_items[] = MyFeeds_Picker_Displays::item($slot_id, $amazon_map[$requested_id], 'amazon', $own_words);
                    $rendered_products[] = $amazon_map[$requested_id];
                    continue;
                }
                $card_html = $render_card($amazon_map[$requested_id]);
                $output .= $is_carousel ? MyFeeds_Picker_Carousel::slide($card_html) : $card_html;
                $rendered_products[] = $amazon_map[$requested_id];
                $rendered_count++;
                continue;
            }

            if (class_exists('MyFeeds_Amazon_Compliance') && MyFeeds_Amazon_Compliance::is_amazon_id($requested_id)) {
                // Amazon said the ASIN is invalid: its page answers 404.
                // Left out like a product gone from a feed (rule 17).
                if (class_exists('MyFeeds_Amazon_Products') && MyFeeds_Amazon_Products::is_gone($requested_id)) {
                    continue;
                }
                // No data from Amazon (API not unlocked, or not set up):
                // the author's own card, linked with the partner tag.
                if ($pick_style !== '') {
                    if (trim((string) ($own_words['name'] ?? '')) !== '' && MyFeeds_Amazon_Card::can_link($requested_id)) {
                        $pick_items[] = MyFeeds_Picker_Displays::item($slot_id, array('id' => $requested_id), 'text', $own_words);
                    }
                    continue;
                }
                $text_card = (!empty($current_item['amz_manual']) && class_exists('MyFeeds_Amazon_Card'))
                    ? MyFeeds_Amazon_Card::render_text_card($requested_id, $current_item['amz_manual'], array('click_block' => $click_block))
                    : '';
                if ($text_card !== '') {
                    $output .= $is_carousel ? MyFeeds_Picker_Carousel::slide($text_card) : $text_card;
                    $rendered_count++;
                    continue;
                }
                // Nothing to show a reader: no Amazon data and no name typed
                // yet. Left out of the post like any product that cannot
                // be shown (rule 17), wherever the post is built - also in
                // the admin and through REST: the editor never reads this
                // output, a theme that loads more posts through
                // admin-ajax.php does, and so does content.rendered of the
                // REST API.
                continue;
            }

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
                $output .= $is_carousel ? MyFeeds_Picker_Carousel::slide($placeholder_html) : $placeholder_html;
                continue;
            }

            // Gone for good: the merchant dropped it, or the affiliate
            // programme behind the whole feed ended. It used to render a
            // grey card reading "This product is no longer available",
            // which to a reader is a broken tile in the middle of a row
            // about something they cannot act on. Leave it out instead.
            //
            // The comparison is exactly 'unavailable'. 'archived' is a
            // different thing - a product kept alive on a site that lost
            // its licence - and it has to keep rendering.
            if (isset($product_data['status']) && $product_data['status'] === 'unavailable') {
                continue;
            }

            // A sold-out size is not a sold-out product. Feeds ship a row
            // per size and the block points at one of them; the stored
            // link carries ?variant=<that size>, so a reader following a
            // card whose size has gone lands on exactly the size that is
            // gone. The card here keeps rendering either way - what
            // changes is that it now leads somewhere they can buy.
            if (isset($product_data['in_stock']) && (int) $product_data['in_stock'] === 0) {
                $sibling = class_exists('MyFeeds_Variants') ? MyFeeds_Variants::buyable_sibling($product_data) : null;
                if ($sibling) {
                    myfeeds_log('PP_variant_swap: ' . ($product_data['id'] ?? '?')
                        . ' -> ' . ($sibling['id'] ?? '?'), 'info');
                    $product_data = $sibling;
                    // The id check below would otherwise call this
                    // deliberate swap a mismatch and render a
                    // placeholder where the card should be.
                    $requested_id = (string) ($sibling['id'] ?? $requested_id);
                } elseif ($pick_style !== ''
                    && (!isset($product_data['status']) || $product_data['status'] === 'active')) {
                    // A recommendation card or comparison table names a
                    // pick and prices the others against it: a statement
                    // about products a reader can buy, so one sold out in
                    // every size is left out (rule 17), as the other
                    // MyFeeds plugin does. The grid and the carousel keep
                    // such a card, as this plugin's grid always has; this
                    // plugin marks nothing in the editor that would tell
                    // the author a card went missing. An archived row
                    // renders as it was, sold out or not (rule 12).
                    continue;
                }
            }

            $returned_id = isset($product_data['id']) ? (string)$product_data['id'] : '';
            if ($returned_id !== $requested_id && $returned_id !== '') {
                myfeeds_log('PP_id_mismatch: requested=' . $requested_id . ', got=' . $returned_id, 'error');
                $placeholder_html = $this->render_missing_product_placeholder($idx, 'id_mismatch', $requested_id);
                $output .= $is_carousel ? MyFeeds_Picker_Carousel::slide($placeholder_html) : $placeholder_html;
                continue;
            }

            if ($pick_style !== '') {
                $pick_item    = MyFeeds_Picker_Displays::item($slot_id, $product_data, $data_source === 'fallback' ? 'copy' : 'feed');
                $pick_items[] = $pick_item;
                // The structured data names what the card names.
                $rendered_products[] = array('title' => MyFeeds_Picker_Displays::card_title($pick_item)) + $product_data;
                continue;
            }

            $card_html = $render_card($product_data);
            $output .= $is_carousel ? MyFeeds_Picker_Carousel::slide($card_html) : $card_html;
            $rendered_products[] = $product_data;
            $rendered_count++;
        }

        if ($pick_style !== '') {
            $output = MyFeeds_Picker_Displays::render(
                $pick_items,
                $attrs,
                $pick_style,
                isset($products[0]['id']) ? (string) $products[0]['id'] : '',
                $render_card,
                function ($item, $pick) use ($click_block) {
                    return MyFeeds_Amazon_Card::render_text_card($item['key'], $item['manual'], array('click_block' => $click_block, 'pick' => $pick));
                },
                self::wrapper_attributes($attrs, MyFeeds_Picker_Displays::wrapper_classes($pick_style))
            );
            if ($output === '') {
                return '';
            }
            if (class_exists('MyFeeds_Schema_Generator') && !empty($rendered_products)) {
                $output .= MyFeeds_Schema_Generator::product_list_jsonld($rendered_products);
            }
            return $output;
        }

        $output .= $is_carousel ? MyFeeds_Picker_Carousel::close() : '</div>';

        // Schema.org JSON-LD ItemList — Google reads this and surfaces the
        // cards as a product list with rich snippets. Keep it after the
        // grid/carousel so the visual content always renders even if the
        // schema module is missing for some reason.
        if (class_exists('MyFeeds_Schema_Generator') && !empty($rendered_products)) {
            $output .= MyFeeds_Schema_Generator::product_list_jsonld($rendered_products);
        }

        myfeeds_log('PP_render_complete: total=' . count($products) . ', rendered=' . $rendered_count, 'info');

        // Nothing survived the filters. Return nothing at all rather than
        // an empty grid: the container carries margins and a gap, so an
        // empty one leaves a hole in the post where a product row used to
        // be, which reads as a rendering fault.
        if ($rendered_count === 0) {
            return '';
        }

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
     * The price a card shows and the one it strikes through. One function,
     * because a label like "Budget pick" compares what the card says and
     * must not read a different number from the same row.
     *
     * @param array $product
     * @return array{0:float,1:float} price, old price
     */
    public static function effective_prices(array $product) {
        $price      = (float) ($product['price']      ?? 0);
        $sale_price = (float) ($product['sale_price'] ?? 0);
        $old_price  = (float) ($product['old_price']  ?? 0);

        if ($old_price === 0.0 && $sale_price > 0 && $sale_price < $price) {
            $old_price = $price;
            $price = $sale_price;
        }
        if ($old_price > 0 && $sale_price > 0 && $sale_price < $old_price) {
            $price = $sale_price;
        }
        return array($price, $old_price);
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
     * myfeeds_format_price() and format_shipping_info_raw() return raw
     * strings by contract; the caller wraps their return values in
     * esc_html() at the concat site.
     *
     * @param array       $product         Resolved product data (raw).
     * @param string      $placeholder_url Fallback image URL.
     * @param array|null  $card_design     The site's card settings (how the picture is fitted).
     * @return string                       Fully escaped HTML.
     */
    private function render_product_card($product, $placeholder_url, $card_design = null) {
        $title          = (string) ($product['title']          ?? '');
        $image_url      = (string) ($product['image_url']      ?? $placeholder_url);
        $brand          = (string) ($product['brand']          ?? '');
        $merchant       = (string) ($product['merchant']       ?? '');
        $affiliate_link = myfeeds_repair_saved_link((string) ($product['affiliate_link'] ?? '#'));

        list($price, $old_price) = self::effective_prices($product);

        if (class_exists('MyFeeds_Affiliate_Product_Picker')) {
            $product_id_for_log = isset($product['id']) ? (string) $product['id'] : '';
            $discount_log = isset($product['discount_percentage']) ? (string) $product['discount_percentage'] : 'none';
            MyFeeds_Affiliate_Product_Picker::log("FRONTEND RENDER: {$product_id_for_log} - price: $price, old_price: $old_price, discount_percentage: {$discount_log}");
        }

        $currency = (string) ($product['currency'] ?? 'EUR');

        $discount_percent = 0;
        if (!empty($product['discount_percentage']) && (float) $product['discount_percentage'] > 0) {
            $discount_percent = (int) round((float) $product['discount_percentage']);
        } elseif ($old_price > $price && $price > 0) {
            $discount_percent = (int) round((($old_price - $price) / $old_price) * 100);
        }

        // Worked out from the feed's own shipping value on every render, not
        // read from the stored shipping_text: the importer wrote that once,
        // in whatever currency the row had then, and read "3-4 days" as a
        // cost of 3.00. The editor tile follows the same rule.
        $shipping_text_raw = $this->format_shipping_info_raw($product['shipping'] ?? null, $currency);

        // ── Output composition. Every dynamic value is escaped here. ──
        $card_class = empty($product['pick']['favourite']) ? 'myfeeds-product-card' : 'myfeeds-product-card is-favourite';
        $card_html  = '<a class="' . $card_class . '" href="' . esc_url($affiliate_link) . '" target="_blank" rel="nofollow sponsored noopener">';

        // A price the page cannot vouch for, and the discount and shipping
        // cost beside it.
        $hide_price = !empty($product['hide_price']);

        $card_html .= '<div class="myfeeds-product-image">';
        if ($discount_percent > 0 && !$hide_price) {
            $card_html .= '<div class="myfeeds-discount-badge">-' . intval($discount_percent) . '%</div>';
        }
        // Best-effort URL upgrade — handles known CDN patterns (Shopify,
        // Cloudinary, BigCommerce, AWIN productserve, WP uploads) so we
        // serve a sharp variant instead of whatever thumbnail size the
        // feed happened to ship. Falls through unchanged for unknown CDNs.
        //
        // A card sits in somebody's post, in a layout this plugin has never
        // seen - so no computed `sizes`. The lazy card lets the browser
        // measure it ("auto", see myfeeds_image_render_attrs()); the
        // fallback behind it errs large, so a browser without "auto" gets
        // a file at least as sharp as the single 800px one it got before.
        // A cover-fit card gets no srcset at all: it draws a landscape
        // photo wider than its box.
        if ($card_design === null && class_exists('MyFeeds_Settings_Manager')) {
            $card_design = MyFeeds_Settings_Manager::get_card_design();
        }
        $img_fit  = (is_array($card_design) && ($card_design['image_object_fit'] ?? '') === 'cover') ? 'cover' : 'contain';
        $img_data = function_exists('myfeeds_image_render_attrs')
            ? myfeeds_image_render_attrs($image_url, array(
                'sizes' => '(max-width: 800px) 100vw, 800px',
                'fit'   => $img_fit,
            ))
            : array('src' => $image_url, 'attrs' => 'loading="lazy" decoding="async"');
        $img_responsive = '';
        if (!empty($img_data['srcset']) && !empty($img_data['sizes'])) {
            $img_responsive = ' srcset="' . esc_attr($img_data['srcset']) . '" sizes="' . esc_attr($img_data['sizes']) . '"';
        }
        // Whatever fails on the way to the picture - a resized URL one
        // merchant does not serve, or an image CDN on this site that
        // the merchant turns away - the card goes back to the feed's
        // own URL once, and only then gives up and shows its
        // placeholder. Every card carries that way back: the plugin
        // cannot know which optimisation plugin rewrites the src after
        // it, and most of them never ask.
        //
        // The give-up script is also what keeps Jetpack's image CDN off
        // the card: skip_image_cdn_for_cards() looks for the plugin's
        // name in the tag, and the class name set here carries it. A
        // card image is always served by the merchant.
        $img_give_up  = 'this.parentNode.classList.add(\'myfeeds-img-error\');this.style.display=\'none\';';
        $img_fallback = function_exists('myfeeds_image_origin_fallback_attrs')
            ? myfeeds_image_origin_fallback_attrs($image_url, $img_give_up)
            : ' onerror="' . $img_give_up . '"';
        $card_html .= '<img src="' . esc_url($img_data['src']) . '"' . $img_responsive . ' alt="' . esc_attr($title) . '" ' . $img_data['attrs']
            . $img_fallback . '>';
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
                    // Amazon beside another shop: see the top of
                    // class-picker-displays.php.
                    if ($hide_price || !empty($product['pick']['hide_price'])) {
                        break;
                    }
                    $card_html .= '<div class="myfeeds-product-price">';
                    if ($old_price > $price && $price > 0) {
                        $card_html .= '<span class="myfeeds-old-price">' . esc_html(myfeeds_format_price($old_price, $currency)) . '</span> ';
                        $card_html .= '<span class="myfeeds-current-price has-discount">' . esc_html(myfeeds_format_price($price, $currency)) . '</span>';
                    } elseif ($price > 0) {
                        $card_html .= '<span class="myfeeds-current-price">' . esc_html(myfeeds_format_price($price, $currency)) . '</span>';
                    } else {
                        $card_html .= '<span class="myfeeds-price-unavailable">' . esc_html__('Price on request', 'myfeeds-affiliate-feed-manager') . '</span>';
                    }
                    $card_html .= '</div>';
                    break;

                case 'shipping':
                    if ($shipping_text_raw !== '' && !$hide_price) {
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

        // Insertion point at the foot of the details block: the label,
        // sentence and button of a recommendation card or comparison
        // table (MyFeeds_Picker_Displays::card_extra()).
        $card_html .= apply_filters('myfeeds_product_card_details_extra', '', $product);

        $card_html .= '</div>';
        $card_html .= '</a>';

        return $card_html;
    }

    /**
     * Format shipping information into a human-readable string. Returns
     * a raw, translated string — the caller is responsible for escaping
     * (esc_html) at the concat site.
     *
     * Only a real 0 is free shipping (myfeeds_shipping_amount()); a value
     * that names no cost - empty, "3-4 days", "normal" - says that costs
     * may apply.
     *
     * @param mixed  $shipping_raw The feed's shipping field (string, number or null).
     * @param string $currency     ISO currency code of the product.
     * @return string              Raw translated shipping text.
     */
    private function format_shipping_info_raw($shipping_raw, $currency) {
        $amount = myfeeds_shipping_amount($shipping_raw);
        if ($amount !== null) {
            return $amount > 0
                /* translators: %s: formatted shipping cost with currency */
                ? sprintf(__('Shipping: %s', 'myfeeds-affiliate-feed-manager'), myfeeds_format_price($amount, $currency))
                : __('Free Shipping', 'myfeeds-affiliate-feed-manager');
        }

        if (is_string($shipping_raw) && stripos($shipping_raw, 'free') !== false) {
            return __('Free Shipping', 'myfeeds-affiliate-feed-manager');
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