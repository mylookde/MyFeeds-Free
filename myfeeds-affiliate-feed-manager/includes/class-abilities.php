<?php
/**
 * What MyFeeds offers an AI assistant: its product search and a product
 * preview, through the WordPress Abilities API (WordPress 6.9).
 *
 * An assistant that helps someone write a post does not know their
 * affiliate products, so it names one from memory, with a price that was
 * never true. With these two abilities it asks the site instead. An
 * adapter the user installs (WordPress' MCP Adapter, for one) hands them
 * to the assistant; MyFeeds ships no server of its own.
 *
 * Both go through the editor's own routes, myfeeds/v1/products and
 * myfeeds/v1/product: an assistant finds what the picker finds, under the
 * picker's permission (edit_posts), and a change to the search reaches it
 * without a second copy here. Those routes read the site's products
 * table, which never holds an Amazon product (the Associates licence caps
 * how long Amazon's data may be kept), and nothing here stores what they
 * answer.
 *
 * Read only: no ability here changes a post, a feed or a setting.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Abilities {

    const CATEGORY = 'myfeeds';
    const SEARCH = 'myfeeds/search-products';
    const PRODUCT = 'myfeeds/get-product';

    /** The search route answers 50 at a time; an assistant writing a post needs a handful. */
    const DEFAULT_LIMIT = 10;
    const MAX_LIMIT = 50;

    /** A feed's description can run to pages; the start says what the product is. */
    const DESCRIPTION_LENGTH = 1000;

    const SORTS = array('relevance', 'price_asc', 'price_desc', 'discount_desc', 'newest');

    /**
     * How a route is asked: rest_do_request() on a site. The suite answers
     * in its place, because it runs without WordPress' REST server.
     *
     * @var callable|null function(string $route, array $params): mixed|WP_Error
     */
    private static $dispatcher = null;

    public static function init() {
        if (!self::available()) {
            return;
        }
        add_action('wp_abilities_api_categories_init', array(__CLASS__, 'register_category'));
        add_action('wp_abilities_api_init', array(__CLASS__, 'register'));
    }

    /**
     * WordPress 6.9 and later. Before 6.9 the API was a separate plugin
     * with other hooks and no categories; asking for the version as well
     * as the functions keeps MyFeeds off it.
     */
    public static function available() {
        global $wp_version;
        return function_exists('wp_register_ability')
            && function_exists('wp_register_ability_category')
            && is_string($wp_version)
            && version_compare($wp_version, '6.9', '>=');
    }

    /**
     * @param callable|null $dispatcher function(string $route, array $params): mixed|WP_Error
     */
    public static function set_dispatcher($dispatcher) {
        self::$dispatcher = is_callable($dispatcher) ? $dispatcher : null;
    }

    public static function register_category() {
        // Both editions of MyFeeds name this category, and another plugin
        // may have taken the slug; a second register is _doing_it_wrong().
        if (function_exists('wp_has_ability_category') && wp_has_ability_category(self::CATEGORY)) {
            return;
        }
        wp_register_ability_category(self::CATEGORY, array(
            'label'       => __('MyFeeds products', 'myfeeds-affiliate-feed-manager'),
            'description' => __('The affiliate products MyFeeds imports and keeps current on this site.', 'myfeeds-affiliate-feed-manager'),
        ));
    }

    public static function register() {
        wp_register_ability(self::SEARCH, array(
            'label'               => __('Search products', 'myfeeds-affiliate-feed-manager'),
            'description'         => __('Searches the affiliate products on this site the way the MyFeeds product picker does and returns the best matches with price, picture, merchant and link. Use it to find a real product for a post instead of naming one from memory. Only products in stock, unless in_stock is false.', 'myfeeds-affiliate-feed-manager'),
            'category'            => self::CATEGORY,
            'input_schema'        => self::search_input_schema(),
            'output_schema'       => self::search_output_schema(),
            'execute_callback'    => array(__CLASS__, 'search'),
            'permission_callback' => array(__CLASS__, 'can_read'),
            'meta'                => self::meta(),
        ));

        wp_register_ability(self::PRODUCT, array(
            'label'               => __('Product preview', 'myfeeds-affiliate-feed-manager'),
            'description'         => __('Returns one product of this site with everything MyFeeds knows about it: price, sale price, stock, picture, merchant, link and the description from its feed. Pass the id and feed_id a search returned.', 'myfeeds-affiliate-feed-manager'),
            'category'            => self::CATEGORY,
            'input_schema'        => self::product_input_schema(),
            'output_schema'       => self::product_output_schema(),
            'execute_callback'    => array(__CLASS__, 'product'),
            'permission_callback' => array(__CLASS__, 'can_read'),
            'meta'                => self::meta(),
        ));
    }

    /**
     * Read only, for the REST API and an MCP adapter alike. The adapter
     * shows an ability only with mcp.public set.
     */
    public static function meta() {
        return array(
            'annotations'  => array(
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ),
            'show_in_rest' => true,
            'mcp'          => array(
                'public' => true,
                'type'   => 'tool',
            ),
        );
    }

    /**
     * Whoever may write a post: what the picker's routes ask.
     *
     * @param mixed $input Unused; WordPress hands the input to every permission check.
     */
    public static function can_read($input = null) {
        return current_user_can('edit_posts');
    }

    /**
     * @param array $input See search_input_schema().
     * @return array|WP_Error
     */
    public static function search($input = array()) {
        $input = is_array($input) ? $input : array();
        $query = isset($input['query']) && is_scalar($input['query']) ? trim((string) $input['query']) : '';
        $limit = self::bounded($input['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $sort  = isset($input['sort']) && in_array($input['sort'], self::SORTS, true) ? $input['sort'] : 'relevance';

        // The route's own parameters, as the picker sends them.
        $params = array(
            'q'         => $query,
            'offset'    => max(0, (int) ($input['offset'] ?? 0)),
            'with_meta' => 1,
            'sort'      => $sort,
            'in_stock'  => array_key_exists('in_stock', $input) ? (bool) $input['in_stock'] : true,
            'on_sale'   => !empty($input['on_sale']),
        );
        foreach (array('merchant', 'brand') as $list) {
            if (!empty($input[$list]) && is_array($input[$list])) {
                $params[$list] = array_values(array_map('sanitize_text_field', array_filter($input[$list], 'is_scalar')));
            }
        }
        foreach (array('min_price', 'max_price') as $bound) {
            if (isset($input[$bound]) && is_numeric($input[$bound])) {
                $params[$bound] = (float) $input[$bound];
            }
        }

        $data = self::dispatch('/myfeeds/v1/products', $params);
        if (is_wp_error($data)) {
            return $data;
        }

        // The search engine answers with a wrapper. A site that has not
        // moved its products into the database yet answers with the list.
        if (is_array($data) && isset($data['products']) && is_array($data['products'])) {
            $rows  = $data['products'];
            $total = isset($data['total']) ? (int) $data['total'] : count($rows);
        } else {
            $rows  = is_array($data) ? array_values($data) : array();
            $total = count($rows);
        }

        $products = array();
        foreach (array_slice($rows, 0, $limit) as $row) {
            if (is_array($row)) {
                $products[] = self::product_out($row);
            }
        }

        return array(
            'query'    => $query,
            'total'    => max($total, count($products)),
            'products' => $products,
        );
    }

    /**
     * @param array $input See product_input_schema().
     * @return array|WP_Error
     */
    public static function product($input = array()) {
        $input   = is_array($input) ? $input : array();
        $id      = isset($input['id']) && is_scalar($input['id']) ? trim((string) $input['id']) : '';
        $feed_id = isset($input['feed_id']) && is_numeric($input['feed_id']) ? max(0, (int) $input['feed_id']) : 0;

        // Two feeds can hold one id for two products: the feed says which.
        $data = self::dispatch('/myfeeds/v1/product', array(
            'id'       => $id,
            'feed_id'  => $feed_id,
            'merchant' => isset($input['merchant']) && is_scalar($input['merchant']) ? sanitize_text_field((string) $input['merchant']) : '',
        ));
        if (is_wp_error($data) && !in_array($data->get_error_code(), array('not_found', 'missing_id', 'missing_index'), true)) {
            return $data;
        }
        if (is_wp_error($data) || !is_array($data) || self::text($data['id'] ?? '') === '') {
            return new WP_Error(
                'myfeeds_product_not_found',
                __('MyFeeds has no product with this id. Search first and pass the id and feed_id the search returned.', 'myfeeds-affiliate-feed-manager')
            );
        }

        $product = self::product_out($data);
        // The route's answer does not name its feed. Found under the feed
        // asked for, it is that feed's product.
        if ($feed_id > 0) {
            $product['feed_id'] = $feed_id;
        }
        $product['status'] = self::text($data['status'] ?? 'active');
        $product['description'] = self::description($data);
        $product['last_updated'] = self::text($data['last_updated'] ?? '');

        return $product;
    }

    /**
     * One product as an assistant reads it: the same fields from a search
     * and from a preview, each always there and always of its type (the
     * output schema rejects a null where a string belongs).
     *
     * @param array $p A product as the routes answer it.
     * @return array
     */
    public static function product_out(array $p) {
        $price = is_numeric($p['price'] ?? null) ? (float) $p['price'] : 0.0;
        $old   = $p['old_price'] ?? ($p['original_price'] ?? 0);
        $old   = is_numeric($old) ? (float) $old : 0.0;
        $link  = self::text($p['affiliate_link'] ?? '');

        // A search collapses the sizes of one garment into one row; any
        // size in stock is a product a reader can buy.
        $in_stock = isset($p['in_stock_any'])
            ? (bool) $p['in_stock_any']
            : (int) ($p['in_stock'] ?? 1) === 1;

        return array(
            'id'        => self::text($p['id'] ?? ''),
            'feed_id'   => (int) ($p['feed_id'] ?? 0),
            'title'     => self::text($p['title'] ?? ''),
            'brand'     => self::text($p['brand'] ?? ''),
            'merchant'  => self::text($p['merchant'] ?? ''),
            'category'  => self::text($p['category'] ?? ''),
            'colour'    => self::text($p['colour'] ?? ''),
            'price'     => $price,
            'old_price' => $old > $price ? $old : 0.0,
            'currency'  => self::text($p['currency'] ?? ''),
            'in_stock'  => $in_stock,
            'image_url' => self::text($p['image_url'] ?? ''),
            'link'      => $link,
            'sample'    => class_exists('MyFeeds_Demo_Content') && MyFeeds_Demo_Content::is_sample_link($link),
        );
    }

    /**
     * Asks one of the editor's routes, as the editor would.
     *
     * @return mixed|WP_Error The route's data.
     */
    private static function dispatch($route, array $params) {
        if (self::$dispatcher !== null) {
            return call_user_func(self::$dispatcher, $route, $params);
        }

        $request = new WP_REST_Request('GET', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $response = rest_do_request($request);
        if ($response->is_error()) {
            return $response->as_error();
        }
        return $response->get_data();
    }

    /** The feed's description as plain text, cut where a word ends. */
    private static function description(array $data) {
        $text = '';
        foreach (array('description', 'product_short_description', 'short_description') as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                $text = (string) $data[$key];
                break;
            }
        }
        // A feed can declare one encoding and send another; a `u` pattern
        // on such a string returns nothing at all (rule from 10.09.).
        $text = wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
        $text = function_exists('myfeeds_preg_replace_text')
            ? myfeeds_preg_replace_text('/\s+/u', ' ', $text)
            : (string) preg_replace('/[ \t\r\n]+/', ' ', $text);
        $text = trim($text);
        if (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') <= self::DESCRIPTION_LENGTH : strlen($text) <= self::DESCRIPTION_LENGTH) {
            return $text;
        }
        $cut = function_exists('mb_substr') ? mb_substr($text, 0, self::DESCRIPTION_LENGTH, 'UTF-8') : substr($text, 0, self::DESCRIPTION_LENGTH);
        $space = strrpos($cut, ' ');
        return rtrim($space !== false ? substr($cut, 0, $space) : $cut, " ,;:-") . ' ...';
    }

    private static function text($value) {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function bounded($value, $min, $max) {
        $value = is_numeric($value) ? (int) $value : $min;
        return max($min, min($max, $value));
    }

    // =========================================================================
    // SCHEMAS
    // =========================================================================

    private static function search_input_schema() {
        $words = array(
            'type'     => 'array',
            'items'    => array('type' => 'string'),
            'maxItems' => 20,
        );
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'query'     => array(
                    'type'        => 'string',
                    'minLength'   => 2,
                    'maxLength'   => 200,
                    'description' => __('What to look for, in the words a reader would use: "white leather sneakers", "linen dress", "rain jacket men".', 'myfeeds-affiliate-feed-manager'),
                ),
                'limit'     => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
                    'description' => __('How many products to return.', 'myfeeds-affiliate-feed-manager'),
                ),
                'offset'    => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'default'     => 0,
                    'description' => __('How many of the best matches to skip, for the next page.', 'myfeeds-affiliate-feed-manager'),
                ),
                'merchant'  => $words + array('description' => __('Only these merchants, named as a search returns them.', 'myfeeds-affiliate-feed-manager')),
                'brand'     => $words + array('description' => __('Only these brands.', 'myfeeds-affiliate-feed-manager')),
                'min_price' => array(
                    'type'        => 'number',
                    'minimum'     => 0,
                    'description' => __('Lowest price, in the currency of the products.', 'myfeeds-affiliate-feed-manager'),
                ),
                'max_price' => array(
                    'type'        => 'number',
                    'minimum'     => 0,
                    'description' => __('Highest price, in the currency of the products.', 'myfeeds-affiliate-feed-manager'),
                ),
                'on_sale'   => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __('Only products that cost less than before.', 'myfeeds-affiliate-feed-manager'),
                ),
                'in_stock'  => array(
                    'type'        => 'boolean',
                    'default'     => true,
                    'description' => __('Only products a reader can buy now. A post leaves sold-out products out.', 'myfeeds-affiliate-feed-manager'),
                ),
                'sort'      => array(
                    'type'        => 'string',
                    'enum'        => self::SORTS,
                    'default'     => 'relevance',
                    'description' => __('The order of the results.', 'myfeeds-affiliate-feed-manager'),
                ),
            ),
            'required'             => array('query'),
            'additionalProperties' => false,
        );
    }

    private static function product_input_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'id'       => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'maxLength'   => 255,
                    'description' => __('The product id a search returned.', 'myfeeds-affiliate-feed-manager'),
                ),
                'feed_id'  => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => __('The feed_id a search returned with the id. Two feeds can use one id for two products.', 'myfeeds-affiliate-feed-manager'),
                ),
                'merchant' => array(
                    'type'        => 'string',
                    'maxLength'   => 200,
                    'description' => __('The merchant, when the feed_id is not known.', 'myfeeds-affiliate-feed-manager'),
                ),
            ),
            'required'             => array('id'),
            'additionalProperties' => false,
        );
    }

    /** The fields every product carries, in a search and in a preview. */
    private static function product_properties() {
        $string = function ($description) {
            return array('type' => 'string', 'description' => $description);
        };
        return array(
            'id'        => $string(__('The product id in its feed.', 'myfeeds-affiliate-feed-manager')),
            'feed_id'   => array('type' => 'integer', 'description' => __('The feed the product comes from; with the id, it names the product.', 'myfeeds-affiliate-feed-manager')),
            'title'     => $string(__('The product name from the feed.', 'myfeeds-affiliate-feed-manager')),
            'brand'     => $string(__('The brand, empty when the feed names none.', 'myfeeds-affiliate-feed-manager')),
            'merchant'  => $string(__('The shop that sells it.', 'myfeeds-affiliate-feed-manager')),
            'category'  => $string(__('The category from the feed.', 'myfeeds-affiliate-feed-manager')),
            'colour'    => $string(__('The colour, empty when the feed names none.', 'myfeeds-affiliate-feed-manager')),
            'price'     => array('type' => 'number', 'description' => __('The price now, in currency.', 'myfeeds-affiliate-feed-manager')),
            'old_price' => array('type' => 'number', 'description' => __('The price before a sale; 0 when the product is not on sale.', 'myfeeds-affiliate-feed-manager')),
            'currency'  => $string(__('The currency code from the feed, never converted.', 'myfeeds-affiliate-feed-manager')),
            'in_stock'  => array('type' => 'boolean', 'description' => __('Whether a reader can buy it now, in at least one size.', 'myfeeds-affiliate-feed-manager')),
            'image_url' => $string(__('The product picture on the merchant\'s server.', 'myfeeds-affiliate-feed-manager')),
            'link'      => $string(__('The affiliate link to the shop.', 'myfeeds-affiliate-feed-manager')),
            'sample'    => array('type' => 'boolean', 'description' => __('A sample product MyFeeds shows before a real source is connected. Not a real offer: never recommend it.', 'myfeeds-affiliate-feed-manager')),
        );
    }

    private static function search_output_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'query'    => array('type' => 'string'),
                'total'    => array('type' => 'integer', 'description' => __('How many products match in all.', 'myfeeds-affiliate-feed-manager')),
                'products' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => self::product_properties(),
                    ),
                ),
            ),
        );
    }

    private static function product_output_schema() {
        return array(
            'type'       => 'object',
            'properties' => self::product_properties() + array(
                'status'       => array('type' => 'string', 'description' => __('active; unavailable when the merchant no longer sells it; archived when its feed was removed and the posts keep its last price.', 'myfeeds-affiliate-feed-manager')),
                'description'  => array('type' => 'string', 'description' => __('The description from the feed as plain text, shortened.', 'myfeeds-affiliate-feed-manager')),
                'last_updated' => array('type' => 'string', 'description' => __('When the last import updated the product.', 'myfeeds-affiliate-feed-manager')),
            ),
        );
    }
}
