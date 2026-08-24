<?php
/**
 * The one door every feed fetch goes through.
 *
 * A feed URL is typed by a person and then fetched by the server, which
 * is the classic shape of a server-side request forgery: the address can
 * name the machine itself, a database on the private network, or a cloud
 * provider's metadata endpoint, and the answer comes back into the feed
 * test where it is shown on screen. WordPress ships wp_safe_remote_get
 * for exactly this, but swapping it in wholesale would also refuse two
 * things we need - an uploaded feed, whose URL is this very site and on
 * shared hosting often resolves to a private address, and a genuine
 * intranet feed on a company network.
 *
 * So the policy lives here instead of at eight call sites. Every one of
 * them calls myfeeds_remote_get(); AssetContractTest-style, FeedFetchTest
 * fails the build if a raw wp_remote_get for a feed URL reappears.
 *
 * @package MyFeeds
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reject anything that is not a plain http(s) address.
 *
 * file://, ftp:// and friends would be handed straight to cURL.
 *
 * @param string $url Candidate URL.
 * @return bool
 */
function myfeeds_url_scheme_allowed($url) {
    $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
    return $scheme === 'http' || $scheme === 'https';
}

/**
 * Is this address inside a range that only exists on the local machine
 * or the private network?
 *
 * @param string $ip IPv4 or IPv6 address.
 * @return bool
 */
function myfeeds_ip_is_private($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return true; // Unparseable: treat as unsafe rather than guess.
    }

    // FILTER_FLAG_NO_RES_RANGE misses 100.64.0.0/10 and 0.0.0.0/8 on some
    // PHP builds, so the two explicit checks below stay.
    $public = filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
    if ($public === false) {
        return true;
    }

    $long = ip2long($ip);
    if ($long !== false) {
        // 169.254.0.0/16 is where AWS, GCP and Azure all park the
        // instance metadata service, and it is the single most valuable
        // target of an SSRF on a hosted site.
        if (($long & 0xFFFF0000) === 0xA9FE0000) {
            return true;
        }
        // 100.64.0.0/10 carrier-grade NAT, 0.0.0.0/8 "this network".
        if (($long & 0xFFC00000) === 0x64400000) {
            return true;
        }
        if (($long & 0xFF000000) === 0x00000000) {
            return true;
        }
    }

    return false;
}

/**
 * The host this site itself answers on.
 *
 * An uploaded feed is stored under the site's own uploads folder, so its
 * URL points here. On shared hosting that frequently resolves to a
 * private address, which is precisely what the check below refuses -
 * hence this exemption. It is narrow on purpose: only the exact host of
 * home_url(), not "anything local".
 *
 * @return string
 */
function myfeeds_own_host() {
    return strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
}

/**
 * Decide whether a feed URL may be fetched.
 *
 * @param string $url Feed URL.
 * @return true|WP_Error
 */
function myfeeds_feed_url_allowed($url) {
    $url = (string) $url;

    if ($url === '' || !myfeeds_url_scheme_allowed($url)) {
        return new WP_Error(
            'myfeeds_bad_scheme',
            __('A feed address has to start with http:// or https://.', 'myfeeds-affiliate-feed-manager')
        );
    }

    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return new WP_Error(
            'myfeeds_bad_host',
            __('That feed address has no host name.', 'myfeeds-affiliate-feed-manager')
        );
    }

    if ($host === myfeeds_own_host()) {
        return true;
    }

    /**
     * Allow a feed on the private network.
     *
     * A company running its own feed server behind the firewall has a
     * legitimate reason to point MyFeeds at 10.x. Off by default,
     * because the person who needs it knows they need it and the person
     * who does not should never be exposed by the default.
     *
     * @param bool   $allow Whether to permit private addresses.
     * @param string $host  Host being requested.
     * @param string $url   Full URL.
     */
    if (apply_filters('myfeeds_allow_private_feed_host', false, $host, $url)) {
        return true;
    }

    $ips = myfeeds_resolve_host($host);
    if (empty($ips)) {
        return new WP_Error(
            'myfeeds_unresolved_host',
            sprintf(
                /* translators: %s: host name from the feed URL */
                __('The address "%s" could not be resolved. Check the feed URL for a typo.', 'myfeeds-affiliate-feed-manager'),
                $host
            )
        );
    }

    // Every answer has to be public. One private address among them is
    // enough to make the fetch unsafe, because we do not control which
    // one cURL picks.
    foreach ($ips as $ip) {
        if (myfeeds_ip_is_private($ip)) {
            return new WP_Error(
                'myfeeds_private_host',
                sprintf(
                    /* translators: %s: host name from the feed URL */
                    __('"%s" points at an address inside this server or its private network, so MyFeeds will not fetch it. Use a public feed URL, or upload the file instead.', 'myfeeds-affiliate-feed-manager'),
                    $host
                )
            );
        }
    }

    return true;
}

/**
 * Resolve a host to every address it answers with.
 *
 * @param string $host Host name or IP literal.
 * @return array<int,string>
 */
function myfeeds_resolve_host($host) {
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return array($host);
    }

    // IPv6 literals arrive bracketed from wp_parse_url.
    $unbracketed = trim($host, '[]');
    if (filter_var($unbracketed, FILTER_VALIDATE_IP)) {
        return array($unbracketed);
    }

    $ips = array();
    if (function_exists('gethostbynamel')) {
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
    }
    if (function_exists('dns_get_record')) {
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
    }

    return array_values(array_unique($ips));
}

/**
 * Fetch a feed URL. The only function in this plugin that may do so.
 *
 * @param string $url  Feed URL.
 * @param array  $args Passed through to wp_remote_get.
 * @return array|WP_Error
 */
function myfeeds_remote_get($url, $args = array()) {
    $args = wp_parse_args($args, array());

    // Redirects are followed by hand, one hop at a time, because letting
    // cURL follow them would check only the address the operator typed.
    // A public URL answering "302 -> http://169.254.169.254/" is the
    // ordinary way this protection gets walked around, and it is not
    // hypothetical: an attacker who controls any host the site is
    // pointed at controls where it ends up.
    $max_hops = isset($args['redirection']) ? (int) $args['redirection'] : 3;
    $args['redirection'] = 0;

    $current = (string) $url;

    for ($hop = 0; $hop <= $max_hops; $hop++) {
        $allowed = myfeeds_feed_url_allowed($current);
        if (is_wp_error($allowed)) {
            if (function_exists('myfeeds_log')) {
                myfeeds_log('Blocked feed fetch: ' . $allowed->get_error_message(), 'error');
            }
            return $allowed;
        }

        $response = wp_remote_get($current, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 300 || $code > 399) {
            return $response;
        }

        $location = wp_remote_retrieve_header($response, 'location');
        if (is_array($location)) {
            $location = end($location);
        }
        if (!is_string($location) || $location === '') {
            return $response;
        }

        // A relative Location stays on a host we already cleared.
        $next = (string) $location;
        if (wp_parse_url($next, PHP_URL_HOST) === null) {
            $next = myfeeds_join_redirect_url($current, $next);
        }

        $current = $next;
    }

    return new WP_Error(
        'myfeeds_too_many_redirects',
        __('That feed address redirects too many times.', 'myfeeds-affiliate-feed-manager')
    );
}

/**
 * Resolve a relative Location header against the URL it came from.
 *
 * @param string $base     URL the redirect came from.
 * @param string $relative Location header value.
 * @return string
 */
function myfeeds_join_redirect_url($base, $relative) {
    $parts = wp_parse_url($base);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return $relative;
    }

    $root = $parts['scheme'] . '://' . $parts['host']
          . (isset($parts['port']) ? ':' . $parts['port'] : '');

    if (strpos($relative, '/') === 0) {
        return $root . $relative;
    }

    $dir = isset($parts['path']) ? rtrim(dirname($parts['path']), '/') : '';

    return $root . $dir . '/' . $relative;
}
