<?php
/**
 * Amazon's Creators API per store: the login endpoint of its region, the
 * credential version Amazon hands out there, the search categories it
 * takes. The stores themselves are MyFeeds_Amazon_Locales, which links
 * use too; this part only the API needs.
 *
 * Source: https://affiliate-program.amazon.com/creatorsapi/docs/en-us/concepts/common-request-headers-and-parameters
 * and the per-locale pages under .../locale-reference/. Verified 2026-08-07.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Api_Locales {

    /**
     * The credential version is assigned by Amazon from the store's home
     * region and only selects the Login-with-Amazon token endpoint. It does
     * not restrict which marketplaces a credential may call.
     */
    const REGIONS = array(
        'NA' => array(
            'version'        => '3.1',
            'token_endpoint' => 'https://api.amazon.com/auth/o2/token',
        ),
        'EU' => array(
            'version'        => '3.2',
            'token_endpoint' => 'https://api.amazon.co.uk/auth/o2/token',
        ),
        'FE' => array(
            'version'        => '3.3',
            'token_endpoint' => 'https://api.amazon.co.jp/auth/o2/token',
        ),
    );

    const API_BASE = 'https://creatorsapi.amazon';

    public static function region($marketplace) {
        $entry = MyFeeds_Amazon_Locales::get($marketplace);
        return ($entry && isset(self::REGIONS[$entry['region']])) ? $entry['region'] : 'NA';
    }

    public static function token_endpoint($marketplace) {
        return self::REGIONS[self::region($marketplace)]['token_endpoint'];
    }

    public static function expected_version($marketplace) {
        return self::REGIONS[self::region($marketplace)]['version'];
    }

    /**
     * Credentials can only be created in Associates Central, by the
     * primary account owner, under Tools > Creators API.
     */
    public static function creators_api_url($marketplace) {
        return MyFeeds_Amazon_Locales::associates_central_url($marketplace) . '/creatorsapi';
    }

    /**
     * Search categories accepted by SearchItems. These differ per locale;
     * anything not listed here is rejected by Amazon with a validation
     * error, so the picker only ever offers the locale's own set.
     *
     * Only the two marketplaces we can verify against the locale-reference
     * pages ship a full list. Everything else falls back to the shared core
     * that exists in every locale.
     */
    public static function search_indices($marketplace) {
        $core = array(
            'All'                   => __('All categories', 'myfeeds-affiliate-feed-manager'),
            'Apparel'               => __('Clothing', 'myfeeds-affiliate-feed-manager'),
            'Automotive'            => __('Automotive', 'myfeeds-affiliate-feed-manager'),
            'Baby'                  => __('Baby', 'myfeeds-affiliate-feed-manager'),
            'Beauty'                => __('Beauty', 'myfeeds-affiliate-feed-manager'),
            'Books'                 => __('Books', 'myfeeds-affiliate-feed-manager'),
            'Computers'             => __('Computers', 'myfeeds-affiliate-feed-manager'),
            'Electronics'           => __('Electronics', 'myfeeds-affiliate-feed-manager'),
            'Fashion'               => __('Fashion', 'myfeeds-affiliate-feed-manager'),
            'GardenAndOutdoor'      => __('Garden and outdoor', 'myfeeds-affiliate-feed-manager'),
            'GroceryAndGourmetFood' => __('Grocery', 'myfeeds-affiliate-feed-manager'),
            'HealthPersonalCare'    => __('Health and personal care', 'myfeeds-affiliate-feed-manager'),
            'HomeAndKitchen'        => __('Home and kitchen', 'myfeeds-affiliate-feed-manager'),
            'Jewelry'               => __('Jewellery', 'myfeeds-affiliate-feed-manager'),
            'Luggage'               => __('Luggage', 'myfeeds-affiliate-feed-manager'),
            'MusicalInstruments'    => __('Musical instruments', 'myfeeds-affiliate-feed-manager'),
            'OfficeProducts'        => __('Office products', 'myfeeds-affiliate-feed-manager'),
            'PetSupplies'           => __('Pet supplies', 'myfeeds-affiliate-feed-manager'),
            'Photo'                 => __('Camera and photo', 'myfeeds-affiliate-feed-manager'),
            'Shoes'                 => __('Shoes and handbags', 'myfeeds-affiliate-feed-manager'),
            'Software'              => __('Software', 'myfeeds-affiliate-feed-manager'),
            'SportsAndOutdoors'     => __('Sports and outdoors', 'myfeeds-affiliate-feed-manager'),
            'ToolsAndHomeImprovement' => __('Tools and home improvement', 'myfeeds-affiliate-feed-manager'),
            'ToysAndGames'          => __('Toys and games', 'myfeeds-affiliate-feed-manager'),
            'VideoGames'            => __('Video games', 'myfeeds-affiliate-feed-manager'),
            'Watches'               => __('Watches', 'myfeeds-affiliate-feed-manager'),
        );

        $extra = array(
            'www.amazon.de' => array(
                'Appliances'  => __('Large appliances', 'myfeeds-affiliate-feed-manager'),
                'Handmade'    => __('Handmade', 'myfeeds-affiliate-feed-manager'),
                'Industrial'  => __('Industrial and scientific', 'myfeeds-affiliate-feed-manager'),
                'Lighting'    => __('Lighting', 'myfeeds-affiliate-feed-manager'),
                'LuxuryBeauty' => __('Luxury beauty', 'myfeeds-affiliate-feed-manager'),
                'MoviesAndTV' => __('Movies and TV', 'myfeeds-affiliate-feed-manager'),
                'Music'       => __('Music', 'myfeeds-affiliate-feed-manager'),
            ),
            'www.amazon.com' => array(
                'Appliances'  => __('Appliances', 'myfeeds-affiliate-feed-manager'),
                'Handmade'    => __('Handmade', 'myfeeds-affiliate-feed-manager'),
                'Industrial'  => __('Industrial and scientific', 'myfeeds-affiliate-feed-manager'),
                'MoviesAndTV' => __('Movies and TV', 'myfeeds-affiliate-feed-manager'),
                'Music'       => __('Music', 'myfeeds-affiliate-feed-manager'),
            ),
        );

        $indices = isset($extra[$marketplace]) ? array_merge($core, $extra[$marketplace]) : $core;
        asort($indices);

        return array('All' => $core['All']) + $indices;
    }
}
