<?php
/**
 * MyFeeds - the currency a feed does not say.
 *
 * Partnerize feeds carry no currency column. Bianco, Pieces, Noisy May
 * and Selected all ship one feed per market - "Pieces - FR", "Pieces -
 * BE (NL)" - and the market is written everywhere except in a column
 * of its own: the delivery-time field is called deliverytime_gb, and
 * every destination URL starts with /en-gb/. The importer, finding no
 * currency, stored EUR, and 25,000 products priced in pounds showed a
 * euro sign (mylook.com.de, 2026-09-06).
 *
 * This reads the market out of those hints. It runs last: a currency
 * column wins, then the per-feed default from the mapping editor, and
 * only a feed that says nothing at all is asked where it ships to.
 *
 * @since 1.5.12
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ISO 3166-1 alpha-2 country -> ISO 4217 currency, for the markets an
 * affiliate feed is likely to name. Euro members are listed one by one
 * so an unknown country stays unknown instead of becoming EUR by accident.
 *
 * @return array<string,string>
 */
function myfeeds_market_currency_map() {
    return array(
        'gb' => 'GBP', 'uk' => 'GBP', 'ie' => 'EUR',
        'de' => 'EUR', 'at' => 'EUR', 'fr' => 'EUR', 'it' => 'EUR', 'es' => 'EUR',
        'nl' => 'EUR', 'be' => 'EUR', 'lu' => 'EUR', 'pt' => 'EUR', 'fi' => 'EUR',
        'gr' => 'EUR', 'sk' => 'EUR', 'si' => 'EUR', 'ee' => 'EUR', 'lv' => 'EUR',
        'lt' => 'EUR', 'mt' => 'EUR', 'cy' => 'EUR', 'hr' => 'EUR',
        'ch' => 'CHF', 'dk' => 'DKK', 'se' => 'SEK', 'no' => 'NOK',
        'pl' => 'PLN', 'cz' => 'CZK', 'hu' => 'HUF', 'ro' => 'RON', 'bg' => 'BGN',
        'us' => 'USD', 'ca' => 'CAD', 'au' => 'AUD', 'nz' => 'NZD',
        'jp' => 'JPY', 'cn' => 'CNY', 'kr' => 'KRW', 'in' => 'INR',
        'tr' => 'TRY', 'br' => 'BRL', 'mx' => 'MXN', 'za' => 'ZAR',
        'ae' => 'AED', 'sa' => 'SAR', 'sg' => 'SGD', 'hk' => 'HKD',
    );
}

/**
 * The shop page a tracking link ends at.
 *
 * Partnerize keeps it in a path segment (`/destination:https%3A%2F%2F...`),
 * AWIN's cread.php in the `ued` parameter. A link that is neither is
 * already the shop page.
 *
 * @param string $link
 * @return string
 */
function myfeeds_market_destination_url($link) {
    $link = (string) $link;
    if ($link === '') {
        return '';
    }
    if (preg_match('#/destination:([^/?\#]+)#i', $link, $m)) {
        return rawurldecode($m[1]);
    }
    if (preg_match('#[?&]ued=([^&\#]+)#i', $link, $m)) {
        return rawurldecode($m[1]);
    }
    return $link;
}

/**
 * The country a shop URL is for, as a lowercase alpha-2 code, or ''.
 *
 * Two shapes are read: a locale path segment (`/en-gb/`, `/de_at/`,
 * `/fr-FR/`) and a country top-level domain (`.co.uk`, `.de`, `.com.au`).
 * `.com` says nothing and is left alone.
 *
 * @param string $url
 * @return string
 */
function myfeeds_market_country_from_url($url) {
    $url = (string) $url;
    if ($url === '') {
        return '';
    }
    $map = myfeeds_market_currency_map();

    $path = wp_parse_url($url, PHP_URL_PATH);
    if (is_string($path) && preg_match('#/[a-z]{2}[-_]([a-z]{2})(?=/|$)#i', $path, $m)) {
        $cc = strtolower($m[1]);
        if (isset($map[$cc])) {
            return $cc;
        }
    }

    $host = wp_parse_url($url, PHP_URL_HOST);
    if (is_string($host)) {
        $host = strtolower($host);
        if (preg_match('/\.(co|com|org|net)\.([a-z]{2})$/', $host, $m)) {
            $cc = $m[2] === 'uk' ? 'gb' : $m[2];
            return isset($map[$cc]) ? $cc : '';
        }
        if (preg_match('/\.([a-z]{2})$/', $host, $m)) {
            $cc = $m[1];
            if ($cc === 'uk') {
                return 'gb';
            }
            return isset($map[$cc]) ? $cc : '';
        }
    }

    return '';
}

/**
 * The currency a feed row is priced in, read from where the feed names
 * its market, or '' when it names none.
 *
 * Column names first (`deliverytime_gb`, `shipping_de`), because they
 * were written by the network for exactly this market; the destination
 * URL second. A URL that says `/en-gb/` on a feed called "- GB" is the
 * normal case; a feed that mixes markets in one file will be judged
 * row by row, which is still right.
 *
 * @param array  $raw  The row as the feed delivered it.
 * @param string $link The row's tracked link.
 * @return string ISO 4217 code or ''.
 */
function myfeeds_currency_from_market($raw, $link = '') {
    $map = myfeeds_market_currency_map();

    if (is_array($raw)) {
        foreach ($raw as $key => $_value) {
            if (!is_string($key)) {
                continue;
            }
            if (preg_match('/^(?:deliverytime|delivery_time|deliverycost|delivery_cost|shipping|shippingcost|shipping_cost)_([a-z]{2})$/i', $key, $m)) {
                $cc = strtolower($m[1]);
                if ($cc === 'uk') {
                    $cc = 'gb';
                }
                if (isset($map[$cc])) {
                    return $map[$cc];
                }
            }
        }
    }

    $cc = myfeeds_market_country_from_url(myfeeds_market_destination_url($link));
    if ($cc !== '' && isset($map[$cc])) {
        return $map[$cc];
    }

    return '';
}
