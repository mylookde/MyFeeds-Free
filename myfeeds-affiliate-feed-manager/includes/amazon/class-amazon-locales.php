<?php
/**
 * The Amazon stores a link can go to: their address, name, region,
 * currency and language, and where the store's Associates Central and its
 * licence live. Verified 2026-08-07 against Amazon's locale reference.
 *
 * Shared byte for byte by both plugins.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Locales {

    private static function table() {
        return array(
            'www.amazon.com'    => array('label' => 'United States',        'region' => 'NA', 'currency' => 'USD', 'language' => 'en_US', 'central' => 'https://affiliate-program.amazon.com',    'license' => 'https://affiliate-program.amazon.com/help/operating/policies/#Associates%20Program%20IP%20License'),
            'www.amazon.ca'     => array('label' => 'Canada',               'region' => 'NA', 'currency' => 'CAD', 'language' => 'en_CA', 'central' => 'https://associates.amazon.ca',             'license' => 'https://associates.amazon.ca/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.com.mx' => array('label' => 'Mexico',               'region' => 'NA', 'currency' => 'MXN', 'language' => 'es_MX', 'central' => 'https://afiliados.amazon.com.mx',          'license' => 'https://afiliados.amazon.com.mx/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.com.br' => array('label' => 'Brazil',               'region' => 'NA', 'currency' => 'BRL', 'language' => 'pt_BR', 'central' => 'https://associados.amazon.com.br',         'license' => 'https://associados.amazon.com.br/help/operating/agreement'),
            'www.amazon.de'     => array('label' => 'Germany',              'region' => 'EU', 'currency' => 'EUR', 'language' => 'de_DE', 'central' => 'https://partnernet.amazon.de',             'license' => 'https://partnernet.amazon.de/help/operating/license'),
            'www.amazon.co.uk'  => array('label' => 'United Kingdom',       'region' => 'EU', 'currency' => 'GBP', 'language' => 'en_GB', 'central' => 'https://affiliate-program.amazon.co.uk',   'license' => 'https://affiliate-program.amazon.co.uk/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.fr'     => array('label' => 'France',               'region' => 'EU', 'currency' => 'EUR', 'language' => 'fr_FR', 'central' => 'https://partenaires.amazon.fr',            'license' => 'https://partenaires.amazon.fr/help/operating/license'),
            'www.amazon.it'     => array('label' => 'Italy',                'region' => 'EU', 'currency' => 'EUR', 'language' => 'it_IT', 'central' => 'https://programma-affiliazione.amazon.it', 'license' => 'https://programma-affiliazione.amazon.it/help/operating/license'),
            'www.amazon.es'     => array('label' => 'Spain',                'region' => 'EU', 'currency' => 'EUR', 'language' => 'es_ES', 'central' => 'https://afiliados.amazon.es',              'license' => 'https://afiliados.amazon.es/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.nl'     => array('label' => 'Netherlands',          'region' => 'EU', 'currency' => 'EUR', 'language' => 'nl_NL', 'central' => 'https://affiliate-program.amazon.nl',      'license' => 'https://affiliate-program.amazon.nl/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.com.be' => array('label' => 'Belgium',              'region' => 'EU', 'currency' => 'EUR', 'language' => 'nl_BE', 'central' => 'https://affiliate-program.amazon.com.be',  'license' => 'https://affiliate-program.amazon.com.be/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.ie'     => array('label' => 'Ireland',              'region' => 'EU', 'currency' => 'EUR', 'language' => 'en_IE', 'central' => 'https://affiliate-program.amazon.ie',      'license' => 'https://affiliate-program.amazon.ie/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.pl'     => array('label' => 'Poland',               'region' => 'EU', 'currency' => 'PLN', 'language' => 'pl_PL', 'central' => 'https://affiliate-program.amazon.pl',      'license' => 'https://affiliate-program.amazon.pl/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.se'     => array('label' => 'Sweden',               'region' => 'EU', 'currency' => 'SEK', 'language' => 'sv_SE', 'central' => 'https://affiliate-program.amazon.se',      'license' => 'https://affiliate-program.amazon.se/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.com.tr' => array('label' => 'Turkey',               'region' => 'EU', 'currency' => 'TRY', 'language' => 'tr_TR', 'central' => 'https://gelirortakligi.amazon.com.tr',     'license' => 'https://gelirortakligi.amazon.com.tr/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.ae'     => array('label' => 'United Arab Emirates', 'region' => 'EU', 'currency' => 'AED', 'language' => 'en_AE', 'central' => 'https://affiliate-program.amazon.ae',      'license' => 'https://affiliate-program.amazon.ae/help/operating/policies/#Associates%20Program%20IP%20License'),
            'www.amazon.sa'     => array('label' => 'Saudi Arabia',         'region' => 'EU', 'currency' => 'SAR', 'language' => 'ar_AE', 'central' => 'https://affiliate-program.amazon.sa',      'license' => 'https://affiliate-program.amazon.sa/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.eg'     => array('label' => 'Egypt',                'region' => 'EU', 'currency' => 'EGP', 'language' => 'ar_AE', 'central' => 'https://affiliate-program.amazon.eg',      'license' => 'https://affiliate-program.amazon.eg/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.in'     => array('label' => 'India',                'region' => 'EU', 'currency' => 'INR', 'language' => 'en_IN', 'central' => 'https://affiliate-program.amazon.in',      'license' => 'https://affiliate-program.amazon.in/help/operating/agreement'),
            'www.amazon.co.jp'  => array('label' => 'Japan',                'region' => 'FE', 'currency' => 'JPY', 'language' => 'ja_JP', 'central' => 'https://affiliate.amazon.co.jp',           'license' => 'https://affiliate.amazon.co.jp/help/operating/paapilicenseagreement'),
            'www.amazon.com.au' => array('label' => 'Australia',            'region' => 'FE', 'currency' => 'AUD', 'language' => 'en_AU', 'central' => 'https://affiliate-program.amazon.com.au',  'license' => 'https://affiliate-program.amazon.com.au/help/operating/policies#Associates%20Program%20IP%20License'),
            'www.amazon.sg'     => array('label' => 'Singapore',            'region' => 'FE', 'currency' => 'SGD', 'language' => 'en_SG', 'central' => 'https://affiliate-program.amazon.sg',      'license' => 'https://affiliate-program.amazon.sg/help/operating/policies#Associates%20Program%20IP%20License'),
        );
    }

    public static function all() {
        return self::table();
    }

    public static function exists($marketplace) {
        $table = self::table();
        return isset($table[$marketplace]);
    }

    public static function get($marketplace) {
        $table = self::table();
        return isset($table[$marketplace]) ? $table[$marketplace] : null;
    }

    public static function default_marketplace() {
        return 'www.amazon.com';
    }

    /**
     * Short code used inside namespaced product ids (amzn:de:B0...).
     * Derived from the marketplace host so it stays stable without a
     * second lookup table to keep in sync.
     */
    public static function code($marketplace) {
        $host = str_replace('www.amazon.', '', (string) $marketplace);
        return sanitize_key(str_replace('.', '', $host));
    }

    public static function marketplace_from_code($code) {
        foreach (array_keys(self::table()) as $marketplace) {
            if (self::code($marketplace) === $code) {
                return $marketplace;
            }
        }
        return null;
    }

    public static function currency($marketplace) {
        $entry = self::get($marketplace);
        return $entry ? $entry['currency'] : 'USD';
    }

    public static function language($marketplace) {
        $entry = self::get($marketplace);
        return $entry ? $entry['language'] : 'en_US';
    }

    public static function label($marketplace) {
        $entry = self::get($marketplace);
        return $entry ? $entry['label'] : (string) $marketplace;
    }

    /**
     * Associates Central for this locale: where the owner finds the
     * partner tag and the official badge.
     */
    public static function associates_central_url($marketplace) {
        $entry = self::get($marketplace);
        return $entry ? $entry['central'] : 'https://affiliate-program.amazon.com';
    }

    public static function license_url($marketplace) {
        $entry = self::get($marketplace);
        return $entry ? $entry['license'] : 'https://affiliate-program.amazon.com/help/operating/policies/#Associates%20Program%20IP%20License';
    }

    /**
     * Human name of the Amazon site ("Amazon.de"), capitalised the way
     * Amazon writes its own brand in the texts its licence prescribes.
     */
    public static function site_name($marketplace) {
        return ucfirst(preg_replace('/^www\./', '', (string) $marketplace));
    }
}
