<?php
/**
 * MyFeeds Schema.org JSON-LD Generator
 *
 * Emits structured data for product cards so Google can recognise them
 * as a product list (rich snippets, listicle treatment in search).
 *
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Schema_Generator {

    /**
     * Build a Schema.org ItemList JSON-LD <script> tag for a list of
     * resolved products. Returns '' if none of the products carry the
     * minimum fields Google needs (name + image + price + currency +
     * url) — emitting an incomplete schema would only earn a Search
     * Console warning.
     */
    public static function product_list_jsonld(array $products) {
        $items = array();
        $position = 0;
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $node = self::product_node($product);
            if ($node === null) {
                continue;
            }
            $position++;
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $position,
                'item'     => $node,
            );
        }
        if (empty($items)) {
            return '';
        }
        $payload = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'itemListElement' => $items,
        );
        $payload = apply_filters('myfeeds_schema_itemlist', $payload, $products);
        // Encode with flags that prevent the JSON payload from breaking
        // out of the surrounding <script> block. JSON_HEX_TAG escapes
        // every < and > to its \u00XX form, so a "</script>" sequence
        // inside any product field cannot terminate the script element.
        // JSON_HEX_AMP escapes ampersands to keep them inert if the
        // markup is later filtered as HTML. JSON_UNESCAPED_SLASHES is
        // intentionally NOT used: forward slashes are escaped to "\/",
        // which is permitted by the JSON-LD spec and adds another layer
        // of defence against "</script>" sequences.
        $json = wp_json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
        if ($json === false) {
            return '';
        }
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * Build the Product schema node for a single resolved product, or
     * return null when required Google Merchant fields are missing.
     */
    private static function product_node(array $product) {
        $name = isset($product['title']) ? trim((string) $product['title']) : '';
        $image = isset($product['image_url']) ? trim((string) $product['image_url']) : '';
        $url = isset($product['affiliate_link']) ? trim((string) $product['affiliate_link']) : '';

        // Pricing: prefer sale price when it's a real discount, fall back
        // to the regular price. Mirrors the same logic used in the visual
        // card so the schema matches what the user sees.
        $price = (float) ($product['price'] ?? 0);
        $sale  = (float) ($product['sale_price'] ?? 0);
        if ($sale > 0 && $sale < $price) {
            $price = $sale;
        }
        $currency = strtoupper(trim((string) ($product['currency'] ?? '')));

        if ($name === '' || $image === '' || $url === '' || $price <= 0 || $currency === '') {
            return null;
        }

        $offer = array(
            '@type'         => 'Offer',
            'url'           => $url,
            'price'         => number_format($price, 2, '.', ''),
            'priceCurrency' => $currency,
            'availability'  => 'https://schema.org/InStock',
        );

        if (!empty($product['merchant'])) {
            $offer['seller'] = array(
                '@type' => 'Organization',
                'name'  => (string) $product['merchant'],
            );
        }

        $node = array(
            '@type'  => 'Product',
            'name'   => $name,
            'image'  => $image,
            'offers' => $offer,
        );

        if (!empty($product['brand'])) {
            $node['brand'] = array(
                '@type' => 'Brand',
                'name'  => (string) $product['brand'],
            );
        }

        if (!empty($product['id'])) {
            $node['sku'] = (string) $product['id'];
        }

        if (!empty($product['description'])) {
            $node['description'] = wp_strip_all_tags((string) $product['description']);
        }

        // Power-user hook: extend or override per-product schema fields.
        $node = apply_filters('myfeeds_schema_product', $node, $product);

        return is_array($node) ? $node : null;
    }
}
