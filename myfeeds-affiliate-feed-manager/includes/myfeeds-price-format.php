<?php
/**
 * Prices the way their currency is written.
 *
 * One rule for every place that shows a price - the cards in a post, the
 * carousel, the shop tiles, the Look block, the Amazon live price - and,
 * through the payload handed to assets/price-format.js, every price the
 * editor shows. Until October 2026 each of those had its own formatter, and
 * the card one wrote every currency the German way: "150,00 £" on an English
 * site, while the picker tile next to it said "150.00 GBP".
 *
 * Which convention a price is written in (October 2026):
 *  - the site's language, when that language's own currency IS the price's
 *    currency: a German site writes euros "59,90 €" exactly as before, a
 *    French one "1 234,50 €", a British one "£150.00";
 *  - otherwise the currency's home convention (myfeeds_price_currency_locales()):
 *    a pound is "£150.00" on a German site too, a dollar "$150.00" - the way
 *    the shop that sells it writes it, and the way a reader has seen that
 *    currency written everywhere else;
 *  - a currency without a home in that table keeps the site's language.
 * The site's language is get_locale(), not the editing user's
 * (determine_locale() in wp-admin): the picker must show the price the way
 * the visitor will read it on the page. Multilingual plugins filter
 * get_locale(), so a translated page follows its own language.
 *
 * The euro has no single home. It is written "150,00 €" (de_DE): number
 * first, decimal comma, symbol after a space - the form Germany, Spain,
 * Italy, Finland, Slovakia, the Baltics and (but for the thousands space)
 * France share, the form every euro card of this plugin has printed since
 * its first release, and the one most of its euro merchants use. An Irish
 * or Dutch site writes its own euros its own way all the same (rule one);
 * a site that wants "€150.00" for foreign euros says so through the
 * myfeeds_price_currency_locales filter.
 *
 * The symbol is the READER's: the international table below plus the site
 * language's own symbol, so a Swedish site writes its crowns "kr" while a
 * German one writes "150,00 SEK" (not "kr", which is also Norway's and
 * Denmark's), and a Canadian dollar on a German site is "C$150.00".
 *
 * Two filters:
 *  - myfeeds_price_locale ($locale, $currency, $site_locale) - the locale
 *    one price is written in, after the rule. Return one locale whatever the
 *    currency and every price on the site is written that way.
 *  - myfeeds_price_currency_locales (array code => locale) - the home table.
 *    The editor learns every currency in it, so add a currency here, not
 *    only in the first filter, for the picker to agree with the page.
 * And myfeeds_price_format ($spec, $locale) overrides separators or symbols
 * of one locale.
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

if (!function_exists('myfeeds_price_currency_code')) {
    /**
     * "gbp " -> "GBP". A stored symbol ("€") or anything that is not a
     * three-letter code passes through trimmed.
     *
     * @param mixed $currency
     * @return string
     */
    function myfeeds_price_currency_code($currency) {
        $code = trim((string) $currency);
        return preg_match('/^[A-Za-z]{3}$/', $code) ? strtoupper($code) : $code;
    }
}

if (!function_exists('myfeeds_price_currency_locales')) {
    /**
     * Currency code => the locale that currency is written in at home.
     * Filter: myfeeds_price_currency_locales.
     *
     * @return array<string,string>
     */
    function myfeeds_price_currency_locales() {
        $map = array(
            'GBP' => 'en_GB',
            'USD' => 'en_US',
            'EUR' => 'de_DE', // No single home; why this one: see the top of this file.
            'CHF' => 'de_CH',
            'SEK' => 'sv_SE',
            'NOK' => 'nb_NO',
            'DKK' => 'da_DK',
            'PLN' => 'pl_PL',
            'CAD' => 'en_CA',
            'AUD' => 'en_AU',
            'NZD' => 'en_NZ',
            'JPY' => 'ja_JP',
        );
        if (function_exists('apply_filters')) {
            $map = apply_filters('myfeeds_price_currency_locales', $map);
        }
        $out = array();
        foreach ((array) $map as $code => $locale) {
            $code = myfeeds_price_currency_code($code);
            if (is_string($locale) && $locale !== '' && preg_match('/^[A-Z]{3}$/', $code)) {
                $out[$code] = $locale;
            }
        }
        return $out;
    }
}

if (!function_exists('myfeeds_price_locale')) {
    /**
     * The locale one price is written in.
     *
     * With no currency: the site's language - what a price in a currency
     * without a home is written in, and whose symbols every price uses.
     *
     * @param string      $currency ISO code; '' = none.
     * @param string|null $site     The site's language; null = get_locale().
     * @return string
     */
    function myfeeds_price_locale($currency = '', $site = null) {
        if ($site === null || $site === '') {
            $site = function_exists('get_locale') ? (string) get_locale() : '';
        }
        $site = (string) $site !== '' ? (string) $site : 'en_US';
        $code = myfeeds_price_currency_code($currency);

        $locale = $site;
        if ($code !== '') {
            $own = myfeeds_price_format_spec($site);
            if (!isset($own['currency']) || $own['currency'] !== $code) {
                $homes = myfeeds_price_currency_locales();
                if (isset($homes[$code])) {
                    $locale = $homes[$code];
                }
            }
        }

        if (function_exists('apply_filters')) {
            $locale = (string) apply_filters('myfeeds_price_locale', $locale, $code, $site);
        }
        return $locale !== '' ? $locale : $site;
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
                'ja_JP' => array('JPY', "\xEF\xBF\xA5"),
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
     * "currency" is the locale's own currency code ('' when it has none):
     * the one a site in that language writes its own way.
     *
     * @param string|null $locale Null = the site's.
     * @return array{locale:string,decimal:string,group:string,symbol_first:bool,space:bool,currency:string,symbols:array<string,string>}
     */
    function myfeeds_price_format_spec($locale = null) {
        static $cache = array();
        $locale = ($locale === null || $locale === '') ? myfeeds_price_locale() : (string) $locale;

        if (!isset($cache[$locale])) {
            $spec = myfeeds_price_spec_from_intl($locale);
            if ($spec === null) {
                $spec = myfeeds_price_spec_from_table($locale);
            }
            $spec['currency'] = $spec['own'] ? (string) key($spec['own']) : '';
            $spec['symbols']  = array_merge(myfeeds_price_symbols(), $spec['own']);
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
        $code = myfeeds_price_currency_code($currency);
        if ($code === '') {
            return '';
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

if (!function_exists('myfeeds_price_spec_for_currency')) {
    /**
     * The spec one currency is written with on a site: separators and the
     * symbol's side from the currency's locale (myfeeds_price_locale()),
     * the symbols from the site's language.
     *
     * @param string      $currency ISO code.
     * @param string|null $site     The site's language; null = get_locale().
     * @return array
     */
    function myfeeds_price_spec_for_currency($currency, $site = null) {
        $home   = myfeeds_price_format_spec(myfeeds_price_locale('', $site));
        $locale = myfeeds_price_locale($currency, $site);
        if ($locale === $home['locale']) {
            return $home;
        }
        $spec = myfeeds_price_format_spec($locale);
        $spec['symbols'] = $home['symbols'];
        return $spec;
    }
}

if (!function_exists('myfeeds_format_price')) {
    /**
     * A price the way its currency is written (see the top of this file).
     * Raw - escape at the output site.
     *
     * @param float|int|string $amount
     * @param string           $currency ISO code, e.g. "GBP".
     * @param string|null      $site     The site's language; null = get_locale().
     * @return string
     */
    function myfeeds_format_price($amount, $currency, $site = null) {
        return myfeeds_format_price_with_spec($amount, $currency, myfeeds_price_spec_for_currency($currency, $site));
    }
}

if (!function_exists('myfeeds_price_format_payload')) {
    /**
     * What assets/price-format.js gets as window.myfeedsPriceFormat: the
     * site language's spec (for a currency without a home, and the symbols
     * of all), the layout of every currency in the home table and of the
     * site's own currency, and the shipping pattern. The editor applies it
     * exactly as myfeeds_format_price() does.
     *
     * @param string|null $site The site's language; null = get_locale().
     * @return array
     */
    function myfeeds_price_format_payload($site = null) {
        $payload = myfeeds_price_format_spec(myfeeds_price_locale('', $site));
        $codes = array_keys(myfeeds_price_currency_locales());
        if ($payload['currency'] !== '') {
            $codes[] = $payload['currency'];
        }
        $payload['currencies'] = array();
        foreach (array_unique($codes) as $code) {
            $spec = myfeeds_price_spec_for_currency($code, $site);
            $payload['currencies'][$code] = array(
                'locale'       => $spec['locale'],
                'decimal'      => $spec['decimal'],
                'group'        => $spec['group'],
                'symbol_first' => $spec['symbol_first'],
                'space'        => $spec['space'],
            );
        }
        $payload['shipping_pattern'] = myfeeds_shipping_pattern();
        return $payload;
    }
}

if (!function_exists('myfeeds_shipping_pattern')) {
    /**
     * A shipping value that IS a cost: "0", "4.95", "4,95", "4.95 EUR",
     * "£3.95", or Google's "DE::Ground:3.49". Anything else - "3-4 days",
     * "normal", "Shipping costs may apply" - is not a number, and reading a
     * number into it is how the picker came to say "Free Shipping" about a
     * feed that never named a cost, and the card "Shipping: 3.00 EUR" about
     * a delivery time of "3-4 days".
     *
     * Written so PCRE and JavaScript read it the same way: the editor gets
     * this very string (assets/price-format.js) instead of a copy of it.
     *
     * @return string Pattern without delimiters.
     */
    function myfeeds_shipping_pattern() {
        return '^[ \t]*(?:[A-Za-z]{2}:[^:]*:[^:]*:)?[ \t]*(?:[A-Za-z]{3}|[€$£¥])?[ \t]*([0-9]+(?:[.,][0-9]{1,2})?)[ \t]*(?:[A-Za-z]{3}|[€$£¥])?[ \t]*$';
    }
}

if (!function_exists('myfeeds_shipping_amount')) {
    /**
     * The shipping cost a feed value names, or null when it names none.
     * Only a real 0 is free shipping.
     *
     * @param mixed $raw The product's "shipping" field as the feed gave it.
     * @return float|null
     */
    function myfeeds_shipping_amount($raw) {
        if (is_int($raw) || is_float($raw)) {
            return (is_finite((float) $raw) && $raw >= 0) ? (float) $raw : null;
        }
        // D: "$" is the very end, as in JavaScript - not "before a final newline".
        if (!is_string($raw) || !preg_match('/' . myfeeds_shipping_pattern() . '/uD', $raw, $m)) {
            return null;
        }
        return (float) str_replace(',', '.', $m[1]);
    }
}

if (!function_exists('myfeeds_price_format_script')) {
    /**
     * Registers assets/price-format.js with the site's payload in front of it
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
        wp_add_inline_script($handle, 'window.myfeedsPriceFormat = ' . wp_json_encode(myfeeds_price_format_payload()) . ';', 'before');
        return $handle;
    }
}
