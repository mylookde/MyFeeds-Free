<?php
/**
 * The attributes of the product picker block, in one list.
 *
 * The block is described twice: here for the render, and in the editor
 * script for the editor. When the editor saves a block it keeps only the
 * attributes its own list names and drops the rest from the post. A block
 * written with more attributes than the editor knows - a carousel's
 * settings, a comparison's texts, a block that fills itself from a shop
 * category - came back from one save with nothing but its products.
 *
 * So every attribute any editor writes into this block is named here, also
 * the ones this plugin does not offer itself, and the editor gets the same
 * list as an inline script right before the block's own (see
 * myfeeds_picker_attributes_inline_js()). assets/picker-inspector.js
 * carries it a second time, in case the inline script is stripped by an
 * optimiser.
 *
 * Shared byte for byte by both plugins (FREE-SYNC.md).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('myfeeds_picker_block_attributes')) {
    /**
     * @return array<string,array{type:string,default:mixed,items?:array}>
     */
    function myfeeds_picker_block_attributes() {
        $strings = array('type' => 'array', 'default' => array(), 'items' => array('type' => 'string'));
        return array(
            'selectedProducts' => array('type' => 'array', 'default' => array()),
            // Amazon products carry an id and nothing else: the Associates
            // licence lets a site keep the ASIN, not Amazon's text.
            'amazonProducts'   => array('type' => 'array', 'default' => array()),
            'displayMode'      => array('type' => 'string', 'default' => 'grid'),
            // Recommendation card and comparison table. Label and sentence
            // are worked out on render; these hold only what the author
            // overwrote, by the id the block holds.
            'comparisonStyle'  => array('type' => 'string', 'default' => 'featured'),
            'pickTexts'        => array('type' => 'object', 'default' => array()),
            'pickFavourite'    => array('type' => 'string', 'default' => ''),
            'autoplay'         => array('type' => 'boolean', 'default' => false),
            'autoplayInterval' => array('type' => 'number', 'default' => 3),
            'arrowColor'       => array('type' => 'string', 'default' => '#333333'),
            // A block that fills itself from a shop category keeps its
            // hand-picked products, so switching back loses nothing.
            'sourceMode'       => array('type' => 'string', 'default' => 'manual'),
            'categoryId'       => array('type' => 'number', 'default' => 0),
            'categorySlugPath' => array('type' => 'string', 'default' => ''),
            'categoryLabel'    => array('type' => 'string', 'default' => ''),
            'categoryCount'    => array('type' => 'number', 'default' => 10),
            'dynamicSort'      => array('type' => 'string', 'default' => 'popular'),
            'showMoreButton'   => array('type' => 'boolean', 'default' => true),
            'moreButtonText'   => array('type' => 'string', 'default' => 'More products'),
            'moreButtonColor'  => array('type' => 'string', 'default' => ''),
            'filterBrands'     => $strings,
            'filterColours'    => $strings,
            'filterMerchants'  => $strings,
            'filterStyles'     => $strings,
            'filterSearch'     => array('type' => 'string', 'default' => ''),
            'filterMinPrice'   => array('type' => 'number', 'default' => 0),
            'filterMaxPrice'   => array('type' => 'number', 'default' => 0),
            'filterOnSale'     => array('type' => 'boolean', 'default' => false),
            'filterInStock'    => array('type' => 'boolean', 'default' => false),
        );
    }
}

if (!function_exists('myfeeds_picker_attributes_inline_js')) {
    /**
     * The list for the editor, as a filter on the block's registration.
     * Printed before the block's own script, so it is in place when the
     * script registers the block; selectedProducts is the script's own.
     *
     * @return string
     */
    function myfeeds_picker_attributes_inline_js() {
        $attributes = myfeeds_picker_block_attributes();
        unset($attributes['selectedProducts']);
        foreach ($attributes as $name => $spec) {
            // An empty PHP array is [] in JSON; an object's default is {}.
            if ($spec['type'] === 'object' && $spec['default'] === array()) {
                $attributes[$name]['default'] = new stdClass();
            }
        }
        return '(function(){if(!window.wp||!wp.hooks||!wp.hooks.addFilter)return;'
            . 'wp.hooks.addFilter("blocks.registerBlockType","myfeeds/picker-attributes-inline",function(s,n){'
            . 'if(n!=="myfeeds/product-picker")return s;'
            . 's.attributes=Object.assign({},s.attributes||{},' . wp_json_encode($attributes) . ');return s;});})();';
    }
}
