<?php
/**
 * Prices as the site's language writes them.
 *
 * One rule for every place that shows a price - the cards in a post, the
 * carousel, the shop tiles, the Look block, the Amazon live price - and,
 * through the spec handed to assets/price-format.js, every price the editor
 * shows. Until October 2026 each of those had its own formatter, and the
 * card one wrote every currency the German way: "150,00 £" on an English
 * site, while the picker tile next to it said "150.00 GBP".
 *
 * The locale is the SITE's (get_locale()), not the editing user's
 * (determine_locale() in wp-admin): the picker must show the price the way
 * the visitor will read it on the page. Multilingual plugins filter
 * get_locale(), so a translated page follows its own language.
 *
 * Separators and the symbol's side come from ICU (PHP intl) when it is
 * installed, from a table of the common WordPress locales when it is not.
 * The table is written to agree with ICU, and a test holds the two
 * together, so a site without intl prints the same thing.
 *
 * Deliberately NOT taken from ICU:
 *  - the symbols. ICU writes "CA$" and "US$" where this plugin has always
 *    written "C$" and "$". One table, plus the site's own currency (so a
 *    Swedish site writes "kr" and a Canadian one "$"), keeps every host
 *    printing the same symbol.
 *  - the decimals. Always two, as before.
 *  - the space between number and symbol. A plain space, as the cards have
 *    always printed it ("59,90 €"). A space INSIDE a number (French,
 *    Swedish, Polish thousands) is a no-break space, whatever ICU version
 *    the host runs, so a price never breaks across two lines.
 *
 * No translatable strings in here, so the file is byte-identical in Free
 * and Pro (FREE-SYNC.md).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('myfeeds_price_locale')) {
    /**
     * The locale prices are written in: the site's, filterable.
     *
     * @return string
     */
    function myfeeds_price_locale() {
        $locale = function_exists('get_locale') ? (string) get_locale() : '';
        if (function_exists('apply_filters')) {
            $locale = (string) apply_filters('myfeeds_price_locale', $locale);
        }
        return $locale !== '' ? $locale : 'en_US';
    }
}

if (!function_exists('myfeeds_price_symbols')) {
    /**
     * Currency code => symbol. Exactly the table the cards used before,
     * so an existing site keeps its symbols. Anything else prints its code.
     *
     * @return array<string,string>
     */
    function myfeeds_price_symbols() {
        return array(
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'JPY' => '¥',
            'CHF' => 'CHF',
            'CAD' => 'C$',
            'AUD' => 'A$',
        );
    }
}

if (!function_exists('myfeeds_price_locale_candidates')) {
    /**
     * "de_DE_formal" -> ["de_DE_formal", "de_DE", "de"]. WordPress locales
     * carry variants ICU and the table do not know.
     *
     * @param string $locale
     * @return string[]
     */
    function myfeeds_price_locale_candidates($locale) {
        $parts = explode('_', str_replace('-', '_', (string) $locale));
        $out = array();
        for ($i = count($parts); $i >= 1; $i--) {
            $out[] = implode('_', array_slice($parts, 0, $i));
        }
        return array_values(array_unique(array_filter($out, 'strlen')));
    }
}

if (!function_exists('myfeeds_price_table')) {
    /**
     * The fallback for hosts without intl, copied from ICU 77 (CLDR 47).
     * [decimal, group, symbol first, space between symbol and number].
     * A whitespace group is always written as a no-break space.
     *
     * @return array{languages:array<string,array>,locales:array<string,array>,own:array<string,array>}
     */
    function myfeeds_price_table() {
        $nbsp = "\xC2\xA0";
        $apos = "\xE2\x80\x99";
        $after_dot   = array(',', '.', false, true);
        $after_space = array(',', $nbsp, false, true);
        $english     = array('.', ',', true, false);
        return array(
            'languages' => array(
                'en' => $english,
                'ja' => $english,
                'zh' => $english,
                'ko' => $english,
                'th' => $english,
                'he' => array('.', ',', false, true),
                'ar' => array('.', ',', false, true),
                'de' => $after_dot,
                'es' => $after_dot,
                'it' => $after_dot,
                'da' => $after_dot,
                'ro' => $after_dot,
                'el' => $after_dot,
                'hr' => $after_dot,
                'sl' => $after_dot,
                'ca' => $after_dot,
                'vi' => $after_dot,
                'fr' => $after_space,
                'pt' => $after_space,
                'sv' => $after_space,
                'nb' => $after_space,
                'nn' => $after_space,
                'fi' => $after_space,
                'pl' => $after_space,
                'cs' => $after_space,
                'sk' => $after_space,
                'hu' => $after_space,
                'ru' => $after_space,
                'uk' => $after_space,
                'bg' => $after_space,
                'lt' => $after_space,
                'lv' => $after_space,
                'et' => $after_space,
                'nl' => array(',', '.', true, true),
                'tr' => array(',', '.', true, false),
                'id' => array(',', '.', true, false),
            ),
            'locales' => array(
                'en_ZA' => array(',', $nbsp, true, false),
                'de_AT' => array(',', '.', true, true),
                'de_CH' => array('.', $apos, true, true),
                'it_CH' => array('.', $apos, true, true),
                'fr_CH' => array('.', $nbsp, false, true),
                'es_MX' => array('.', ',', true, false),
                'es_AR' => array(',', '.', true, true),
                'es_CO' => array(',', '.', true, true),
                'pt_BR' => array(',', '.', true, true),
            ),
            'own' => array(
                'en_US' => array('USD', '$'),
                'en_GB' => array('GBP', '£'),
                'en_CA' => array('CAD', '$'),
                'en_AU' => array('AUD', '$'),
                'en_NZ' => array('NZD', '$'),
                'en_IE' => array('EUR', '€'),
                'en_ZA' => array('ZAR', 'R'),
                'en_IN' => array('INR', '₹'),
                'de_DE' => array('EUR', '€'),
                'de_AT' => array('EUR', '€'),
                'de_CH' => array('CHF', 'CHF'),
                'fr_FR' => array('EUR', '€'),
                'fr_BE' => array('EUR', '€'),
                'fr_CA' => array('CAD', '$'),
                'fr_CH' => array('CHF', 'CHF'),
                'es_ES' => array('EUR', '€'),
                'es_MX' => array('MXN', '$'),
                'it_IT' => array('EUR', '€'),
                'pt_PT' => array('EUR', '€'),
                'pt_BR' => array('BRL', 'R$'),
                'nl_NL' => array('EUR', '€'),
                'nl_BE' => array('EUR', '€'),
                'sv_SE' => array('SEK', 'kr'),
                'nb_NO' => array('NOK', 'kr'),
                'nn_NO' => array('NOK', 'kr'),
                'da_DK' => array('DKK', 'kr.'),
                'pl_PL' => array('PLN', 'zł'),
                'cs_CZ' => array('CZK', 'Kč'),
                'hu_HU' => array('HUF', 'Ft'),
                'tr_TR' => array('TRY', '₺'),
                'zh_CN' => array('CNY', '¥'),
                'ko_KR' => array('KRW', '₩'),
                'he_IL' => array('ILS', '₪'),
            ),
        );
    }
}

if (!function_exists('myfeeds_price_spec_from_table')) {
    /**
     * @param string $locale
     * @return array{locale:string,decimal:string,group:string,symbol_first:bool,space:bool,own:array}
     */
    function myfeeds_price_spec_from_table($locale) {
        $table = myfeeds_price_table();
        $row = null;
        $own = array();
        foreach (myfeeds_price_locale_candidates($locale) as $candidate) {
            if ($row === null && isset($table['locales'][$candidate])) {
                $row = $table['locales'][$candidate];
            }
            if ($row === null && isset($table['languages'][$candidate])) {
                $row = $table['languages'][$candidate];
            }
            if (!$own && isset($table['own'][$candidate])) {
                $own = array($table['own'][$candidate][0] => $table['own'][$candidate][1]);
            }
        }
        if ($row === null) {
            $row = $table['languages']['en'];
        }
        return array(
            'locale'       => (string) $locale,
            'decimal'      => $row[0],
            'group'        => $row[1],
            'symbol_first' => $row[2],
            'space'        => $row[3],
            'own'          => $own,
        );
    }
}

if (!function_exists('myfeeds_price_spec_from_intl')) {
    /**
     * What ICU says about this locale, or null when intl is missing, does
     * not know the locale (it falls back to en_US_POSIX, which writes
     * "$ 0.00" without grouping) or names separators that do not go with
     * Latin digits.
     *
     * @param string $locale
     * @return array{locale:string,decimal:string,group:string,symbol_first:bool,space:bool,own:array}|null
     */
    function myfeeds_price_spec_from_intl($locale) {
        if (!class_exists('NumberFormatter') || !class_exists('Locale')) {
            return null;
        }
        try {
            $f = NumberFormatter::create((string) $locale, NumberFormatter::CURRENCY);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$f) {
            return null;
        }
        $actual = (string) $f->getLocale(Locale::ACTUAL_LOCALE);
        if ($actual === '' || $actual === 'root' || strpos($actual, 'en_US_POSIX') === 0) {
            return null;
        }

        $marks   = '/[\x{200E}\x{200F}\x{061C}]/u';
        $pattern = explode(';', (string) $f->getPattern());
        $pattern = (string) preg_replace($marks, '', $pattern[0]);
        if (!preg_match('/^(.*?)([#0-9][#0-9,.]*)(.*)$/su', $pattern, $m)) {
            return null;
        }
        $first = strpos($m[1], "\xC2\xA4") !== false;
        if (!$first && strpos($m[3], "\xC2\xA4") === false) {
            return null;
        }
        $gap = $first
            ? substr($m[1], strrpos($m[1], "\xC2\xA4") + 2)
            : substr($m[3], 0, strpos($m[3], "\xC2\xA4"));

        $decimal = (string) preg_replace($marks, '', (string) $f->getSymbol(NumberFormatter::MONETARY_SEPARATOR_SYMBOL));
        $group   = (string) preg_replace($marks, '', (string) $f->getSymbol(NumberFormatter::MONETARY_GROUPING_SEPARATOR_SYMBOL));
        if (preg_match('/^[\s\x{00A0}\x{2007}\x{2009}\x{202F}]$/u', $group)) {
            $group = "\xC2\xA0";
        }
        if (!in_array($decimal, array('.', ','), true)
            || !in_array($group, array('.', ',', "'", "\xE2\x80\x99", "\xC2\xA0"), true)
            || $decimal === $group) {
            return null;
        }

        $own  = array();
        $code = (string) $f->getTextAttribute(NumberFormatter::CURRENCY_CODE);
        $sym  = trim((string) preg_replace($marks, '', (string) $f->getSymbol(NumberFormatter::CURRENCY_SYMBOL)));
        if (preg_match('/^[A-Z]{3}$/', $code) && $code !== 'XXX' && $sym !== '' && $sym !== "\xC2\xA4") {
            $own = array($code => $sym);
        }

        return array(
            'locale'       => (string) $locale,
            'decimal'      => $decimal,
            'group'        => $group,
            'symbol_first' => $first,
            'space'        => preg_match('/[\s\x{00A0}\x{2007}\x{2009}\x{202F}]/u', $gap) === 1,
            'own'          => $own,
        );
    }
}

if (!function_exists('myfeeds_price_format_spec')) {
    /**
     * Everything needed to write a price for a locale - the one rule the
     * PHP side applies and the editor scripts receive.
     *
     * @param string|null $locale Null = the site's.
     * @return array{locale:string,decimal:string,group:string,symbol_first:bool,space:bool,symbols:array<string,string>}
     */
    function myfeeds_price_format_spec($locale = null) {
        static $cache = array();
        $locale = ($locale === null || $locale === '') ? myfeeds_price_locale() : (string) $locale;

        if (!isset($cache[$locale])) {
            $spec = myfeeds_price_spec_from_intl($locale);
            if ($spec === null) {
                $spec = myfeeds_price_spec_from_table($locale);
            }
            $spec['symbols'] = array_merge(myfeeds_price_symbols(), $spec['own']);
            unset($spec['own']);
            $cache[$locale] = $spec;
        }

        $spec = $cache[$locale];
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('myfeeds_price_format', $spec, $locale);
            if (is_array($filtered)) {
                $spec = array_merge($spec, $filtered);
            }
        }
        return $spec;
    }
}

if (!function_exists('myfeeds_currency_symbol')) {
    /**
     * @param string     $currency ISO code; a stored symbol ("€") passes through.
     * @param array|null $spec     From myfeeds_price_format_spec(); null = the site's.
     * @return string Raw, unescaped.
     */
    function myfeeds_currency_symbol($currency, $spec = null) {
        $code = trim((string) $currency);
        if ($code === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z]{3}$/', $code)) {
            $code = strtoupper($code);
        }
        if (!is_array($spec)) {
            $spec = myfeeds_price_format_spec();
        }
        return isset($spec['symbols'][$code]) ? (string) $spec['symbols'][$code] : $code;
    }
}

if (!function_exists('myfeeds_format_price_with_spec')) {
    /**
     * The rule itself. assets/price-format.js applies exactly this to the
     * same spec; keep the two in step.
     *
     * @param float|int|string $amount
     * @param string           $currency
     * @param array            $spec
     * @return string Raw, unescaped.
     */
    function myfeeds_format_price_with_spec($amount, $currency, array $spec) {
        $amount = (float) $amount;
        if (!is_finite($amount)) {
            $amount = 0.0;
        }
        $abs    = round(abs($amount), 2);
        $number = number_format($abs, 2, (string) $spec['decimal'], (string) $spec['group']);
        $symbol = myfeeds_currency_symbol($currency, $spec);

        $out = $number;
        if ($symbol !== '') {
            $first = !empty($spec['symbol_first']);
            // "CHF150.00" reads as one word; a symbol that is letters gets
            // a space wherever it touches the number, as ICU does it.
            $touching_letter = preg_match($first ? '/\p{L}$/u' : '/^\p{L}/u', $symbol) === 1;
            $gap = (!empty($spec['space']) || $touching_letter) ? ' ' : '';
            $out = $first ? $symbol . $gap . $number : $number . $gap . $symbol;
        }
        return ($amount < 0 && $abs > 0) ? '-' . $out : $out;
    }
}

if (!function_exists('myfeeds_format_price')) {
    /**
     * A price as the site's language writes it. Raw - escape at the
     * output site.
     *
     * @param float|int|string $amount
     * @param string           $currency ISO code, e.g. "GBP".
     * @param string|null      $locale   Null = the site's.
     * @return string
     */
    function myfeeds_format_price($amount, $currency, $locale = null) {
        return myfeeds_format_price_with_spec($amount, $currency, myfeeds_price_format_spec($locale));
    }
}

if (!function_exists('myfeeds_price_format_script')) {
    /**
     * Registers assets/price-format.js with the site's spec in front of it
     * and returns its handle, for scripts that show prices to list as a
     * dependency. Safe to call more than once.
     *
     * @return string
     */
    function myfeeds_price_format_script() {
        $handle = 'myfeeds-price-format';
        if (!function_exists('wp_register_script') || (function_exists('wp_script_is') && wp_script_is($handle, 'registered'))) {
            return $handle;
        }
        $rel = 'assets/price-format.js';
        $ver = function_exists('myfeeds_asset_ver')
            ? myfeeds_asset_ver($rel)
            : (file_exists(MYFEEDS_PLUGIN_DIR . $rel) ? (string) filemtime(MYFEEDS_PLUGIN_DIR . $rel) : MYFEEDS_VERSION);
        wp_register_script($handle, MYFEEDS_PLUGIN_URL . $rel, array(), $ver, true);
        wp_add_inline_script($handle, 'window.myfeedsPriceFormat = ' . wp_json_encode(myfeeds_price_format_spec()) . ';', 'before');
        return $handle;
    }
}
