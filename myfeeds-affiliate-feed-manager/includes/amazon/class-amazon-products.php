<?php
/**
 * Read layer between the Creators API and the rest of the plugin.
 *
 * Callers ask for namespaced ids (amzn:de:B0...) and get the same product
 * array shape the feed-backed products use. Whether that came from the
 * cache or from a live call is this class's business.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Amazon_Products {

    /**
     * Resolve namespaced Amazon ids to product arrays.
     *
     * @param string[] $ids
     * @return array<string,array> keyed by the namespaced id
     */
    /**
     * Refresh modes:
     *   stale   - fetch anything whose offers are over an hour old. Right
     *             for the editor and the live-price route, where the caller
     *             is waiting for current data anyway.
     *   missing - fetch only what is not cached at all. Right for a page
     *             render: a cold card has nothing to show, but a card with
     *             an hour-old price already renders with an honest stamp
     *             and the browser corrects it a moment later.
     *   known   - refresh stale offers, but never fetch an ASIN we do not
     *             already hold. This is what the public live-price route
     *             uses: without it, anyone could post made-up ASINs at an
     *             unauthenticated endpoint and drain the day's request
     *             budget. A card that reached a browser was already warmed
     *             during the page render, so nothing legitimate is lost.
     *   never   - cache only.
     */
    public static function get_by_ids(array $ids, $refresh = 'stale') {
        $by_marketplace = array();

        foreach ($ids as $id) {
            $parsed = MyFeeds_Amazon_Compliance::parse_id($id);
            if (!$parsed) {
                continue;
            }
            $by_marketplace[$parsed['marketplace']][] = $parsed['asin'];
        }

        $out = array();

        foreach ($by_marketplace as $marketplace => $asins) {
            foreach (self::get_by_asins($asins, $marketplace, $refresh) as $product) {
                $out[$product['id']] = $product;
            }
        }

        return $out;
    }

    /**
     * @return array[] list of product arrays
     */
    public static function get_by_asins(array $asins, $marketplace, $refresh = 'stale') {
        $asins = array_values(array_unique(array_map('strtoupper', $asins)));
        if (empty($asins)) {
            return array();
        }

        // Everything this class hands out is Amazon's data, the cached
        // rows included. Where Amazon's API does not run there is none of
        // it: no call, and no row an earlier time with the API left in the
        // cache for its last hours. Every caller then does what it does
        // when Amazon has no data - the card and the Look point fall back
        // to the author's own words and the link built from the ASIN.
        if (!class_exists('MyFeeds_Amazon_Api') || !MyFeeds_Amazon_Api::active()) {
            return array();
        }

        // A page render (missing) and the public price route (known) do
        // not knock on a door Amazon just shut. See api_refused_recently().
        if (in_array($refresh, array('missing', 'known'), true) && MyFeeds_Amazon_Credentials::api_refused_recently()) {
            $refresh = 'never';
        }

        if ($refresh !== 'never' && MyFeeds_Amazon_Credentials::is_configured($marketplace)) {
            $wanted = MyFeeds_Amazon_Cache::stale_asins($asins, $marketplace, $refresh !== 'missing');

            if ($refresh === 'known') {
                $held   = MyFeeds_Amazon_Cache::get_many($asins, $marketplace);
                $wanted = array_values(array_intersect($wanted, array_keys($held)));
            }

            if (!empty($wanted)) {
                // Only a front-end render is latency-critical; the editor
                // and the live route can take the time they need.
                $cap = ($refresh === 'missing') ? self::MAX_RENDER_CHUNKS : 0;
                self::refresh($wanted, $marketplace, $cap);
            }
        }

        $rows     = MyFeeds_Amazon_Cache::get_many($asins, $marketplace);
        $products = array();

        // Preserve the caller's order: the picker renders tiles in the
        // order the block stored them.
        foreach ($asins as $asin) {
            if (isset($rows[$asin])) {
                $products[] = MyFeeds_Amazon_Cache::row_to_product($rows[$asin]);
            }
        }

        return $products;
    }

    /**
     * Pull fresh data for the given ASINs. GetItems takes ten at a time and
     * every call costs a transaction, so the list is chunked, never looped
     * one by one.
     *
     * @return int number of items written
     */
    /**
     * Calls are spaced a second apart, so an unbounded refresh could stall
     * a page render for as long as it likes. Front-end renders cap out;
     * anything past the cap shows as a missing card until the next view
     * picks it up. Nobody puts this many Amazon products in one block, and
     * a slow page is worse than a late one.
     */
    const MAX_RENDER_CHUNKS = 4;

    public static function refresh(array $asins, $marketplace, $max_chunks = 0) {
        $asins   = array_values(array_unique(array_map('strtoupper', $asins)));
        $written = 0;
        $chunks  = array_chunk($asins, MyFeeds_Amazon_Client::MAX_ITEM_IDS);

        if ($max_chunks > 0 && count($chunks) > $max_chunks) {
            myfeeds_log(
                'AMAZON_refresh_capped: ' . count($chunks) . ' chunks requested, ' . $max_chunks . ' allowed',
                'warning'
            );
            $chunks = array_slice($chunks, 0, $max_chunks);
        }

        foreach ($chunks as $chunk) {
            $response = MyFeeds_Amazon_Client::get_items($chunk, $marketplace);

            if (is_wp_error($response)) {
                myfeeds_log(
                    'AMAZON_refresh_failed: ' . $response->get_error_code() . ' ' . $response->get_error_message(),
                    'warning'
                );
                // A throttle or an eligibility loss will hit every following
                // chunk too. Stop instead of burning the daily budget.
                break;
            }

            $items = isset($response['itemsResult']['items']) && is_array($response['itemsResult']['items'])
                ? $response['itemsResult']['items']
                : array();

            $returned = array();
            foreach ($items as $item) {
                $shaped = MyFeeds_Amazon_Cache::shape_api_item($item);
                if ($shaped) {
                    $returned[] = $shaped['asin'];
                }
                if ($shaped && MyFeeds_Amazon_Cache::store($shaped, $marketplace)) {
                    $written++;
                }
            }

            $answers = self::answers_from_errors($response, $chunk, $returned);
            if (!empty($answers)) {
                MyFeeds_Amazon_Cache::forget_rows(array_keys($answers), $marketplace);
            }
            if (!empty($answers) && MyFeeds_Amazon_Cache::remember_answers($answers, $marketplace)
                && in_array(MyFeeds_Amazon_Cache::ANSWER_GONE, $answers, true)) {
                // A product just turned out to be gone: a report of dead
                // products should not wait out its cache to say so.
                do_action('myfeeds_amazon_gone_found');
            }
        }

        return $written;
    }

    /**
     * What Amazon said about the ASINs of one GetItems call it did not
     * return, from the response's errors list:
     *
     *   InvalidParameterValue  "The ItemIds B0... provided in the request
     *                          is invalid." - no such product any more;
     *                          its page answers 404. -> gone
     *   ItemNotAccessible      "The ItemId B0... is not accessible through
     *                          the ... API." - the product page is live,
     *                          the API only does not describe it. -> no_data
     *
     * The ASIN is read out of the message, and only one this call asked
     * for and did not get back counts: an invalid partner tag is an
     * InvalidParameterValue too, and it must not condemn a product.
     *
     * A call whose every ASIN is declined still answers 200 with this
     * list and an empty itemsResult - measured with one ASIN and with ten.
     *
     * @param array    $response GetItems response body.
     * @param string[] $asked    ASINs the call asked for.
     * @param string[] $returned ASINs it returned.
     * @return array<string,string> ASIN => gone|no_data
     */
    public static function answers_from_errors(array $response, array $asked, array $returned) {
        $errors = isset($response['errors']) && is_array($response['errors']) ? $response['errors'] : array();
        if (empty($errors)) {
            return array();
        }

        $open = array_diff(array_map('strtoupper', $asked), array_map('strtoupper', $returned));
        $open = array_fill_keys(array_values($open), true);

        $out = array();
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $code = isset($error['code']) ? (string) $error['code'] : '';
            if ($code === 'InvalidParameterValue') {
                $state = MyFeeds_Amazon_Cache::ANSWER_GONE;
            } elseif ($code === 'ItemNotAccessible') {
                $state = MyFeeds_Amazon_Cache::ANSWER_NO_DATA;
            } else {
                continue;
            }

            $message = isset($error['message']) ? strtoupper((string) $error['message']) : '';
            if (!preg_match_all('/\b[A-Z0-9]{10}\b/', $message, $m)) {
                continue;
            }
            foreach ($m[0] as $asin) {
                if (isset($open[$asin])) {
                    $out[$asin] = $state;
                }
            }
        }

        return $out;
    }

    /**
     * What Amazon last said about products placed in posts, for the
     * readers that judge them - the dead-products card, Clear all, the
     * editor's marks - without asking Amazon again:
     *
     *   dead          Amazon calls the ASIN invalid: the link ends on a 404.
     *   out_of_stock  Amazon has no offer a shopper can order right now.
     *                 The post keeps the card (dimmed): the link still
     *                 reaches a live Amazon page.
     *   active        Amazon describes it and it can be ordered.
     *   unknown       Nothing known within the last day, the API does not
     *                 describe it, or Amazon's API does not run here. Never
     *                 a reason to mark or remove anything.
     *
     * Where Amazon's API does not run every answer is unknown: what the
     * cache and the answers hold is Amazon's data, and that is not read
     * without it.
     *
     * @param string[] $ids Namespaced ids (amzn:<market>:<ASIN>).
     * @return array<string,string> id => verdict
     */
    public static function verdicts(array $ids) {
        $out = array();
        $by_marketplace = array();

        foreach ($ids as $id) {
            $id = (string) $id;
            $out[$id] = 'unknown';
            $parsed = MyFeeds_Amazon_Compliance::parse_id($id);
            if ($parsed) {
                $by_marketplace[$parsed['marketplace']][$parsed['asin']][] = $id;
            }
        }

        if (empty($by_marketplace) || !class_exists('MyFeeds_Amazon_Api') || !MyFeeds_Amazon_Api::active()) {
            return $out;
        }

        foreach ($by_marketplace as $marketplace => $by_asin) {
            $asins   = array_keys($by_asin);
            $answers = MyFeeds_Amazon_Cache::answers($asins, $marketplace);
            $rows    = MyFeeds_Amazon_Cache::get_many($asins, $marketplace);

            foreach ($by_asin as $asin => $id_list) {
                if (isset($rows[$asin])) {
                    $verdict = (int) $rows[$asin]['in_stock'] === 1 ? 'active' : 'out_of_stock';
                } elseif (($answers[$asin] ?? '') === MyFeeds_Amazon_Cache::ANSWER_GONE) {
                    $verdict = 'dead';
                } else {
                    continue;
                }
                foreach ($id_list as $id) {
                    $out[$id] = $verdict;
                }
            }
        }

        return $out;
    }

    /**
     * Has Amazon said this product no longer exists? The page and the
     * editor leave it out, as they leave out a product gone from a feed
     * (rule 17): its link would send a reader to a 404.
     */
    public static function is_gone($id) {
        $parsed = MyFeeds_Amazon_Compliance::parse_id((string) $id);
        if (!$parsed || !class_exists('MyFeeds_Amazon_Api') || !MyFeeds_Amazon_Api::active()) {
            return false;
        }
        // The answers alone: a product Amazon describes has no answer
        // (store() clears it), so no cache query is needed per card.
        $answers = MyFeeds_Amazon_Cache::answers(array($parsed['asin']), $parsed['marketplace']);
        return ($answers[$parsed['asin']] ?? '') === MyFeeds_Amazon_Cache::ANSWER_GONE;
    }

    /**
     * Live catalogue search.
     *
     * SearchItems returns at most ten items and accepts at most ten pages,
     * so a query can never reach past a hundred products. That ceiling is
     * Amazon's, not ours, and callers are told about it through
     * total_available so the UI can be honest.
     *
     * @return array|WP_Error
     */
    public static function search(array $args, $marketplace = null) {
        $marketplace = $marketplace ?: MyFeeds_Amazon_Credentials::marketplace();

        if (!MyFeeds_Amazon_Credentials::is_configured($marketplace)) {
            return new WP_Error('myfeeds_amazon_not_configured', __('Amazon is not connected yet.', 'myfeeds-affiliate-feed-manager'));
        }

        $response = MyFeeds_Amazon_Client::search_items($args, $marketplace);
        if (is_wp_error($response)) {
            return $response;
        }

        $result = isset($response['searchResult']) && is_array($response['searchResult'])
            ? $response['searchResult']
            : array();

        $items    = isset($result['items']) && is_array($result['items']) ? $result['items'] : array();
        $products = array();

        foreach ($items as $item) {
            $shaped = MyFeeds_Amazon_Cache::shape_api_item($item);
            if (!$shaped) {
                continue;
            }

            // Search already paid for this data; caching it here means
            // adding the product to a post does not cost a second call.
            MyFeeds_Amazon_Cache::store($shaped, $marketplace);

            $product = MyFeeds_Amazon_Cache::api_item_to_product($item, $marketplace);
            if ($product) {
                $products[] = $product;
            }
        }

        $total = isset($result['totalResultCount']) ? (int) $result['totalResultCount'] : count($products);
        $page  = isset($args['item_page']) ? max(1, (int) $args['item_page']) : 1;
        $per   = isset($args['item_count']) ? max(1, (int) $args['item_count']) : MyFeeds_Amazon_Client::MAX_ITEM_COUNT;

        return array(
            'products'        => $products,
            'total_matches'   => $total,
            'reachable_total' => min($total, MyFeeds_Amazon_Client::MAX_ITEM_COUNT * MyFeeds_Amazon_Client::MAX_ITEM_PAGE),
            'page'            => $page,
            'per_page'        => $per,
            'max_page'        => min(MyFeeds_Amazon_Client::MAX_ITEM_PAGE, (int) ceil($total / max(1, $per))),
            'search_url'      => isset($result['searchURL']) ? (string) $result['searchURL'] : '',
            'marketplace'     => $marketplace,
        );
    }

    /**
     * One level of Amazon's category tree, for click-through browsing.
     *
     * @return array|WP_Error
     */
    public static function browse_node($node_id, $marketplace = null) {
        $marketplace = $marketplace ?: MyFeeds_Amazon_Credentials::marketplace();

        $response = MyFeeds_Amazon_Client::get_browse_nodes(array($node_id), $marketplace);
        if (is_wp_error($response)) {
            return $response;
        }

        $nodes = isset($response['browseNodesResult']['browseNodes']) && is_array($response['browseNodesResult']['browseNodes'])
            ? $response['browseNodesResult']['browseNodes']
            : array();

        if (empty($nodes)) {
            return new WP_Error('myfeeds_amazon_not_found', __('Amazon does not know this category.', 'myfeeds-affiliate-feed-manager'));
        }

        return self::shape_node($nodes[0]);
    }

    private static function shape_node(array $node) {
        $children = array();
        if (!empty($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                $children[] = array(
                    'id'    => isset($child['id']) ? (string) $child['id'] : '',
                    'name'  => isset($child['displayName']) ? (string) $child['displayName'] : '',
                    'label' => isset($child['contextFreeName']) ? (string) $child['contextFreeName'] : '',
                );
            }
        }

        $ancestors = array();
        $walk      = isset($node['ancestor']) ? $node['ancestor'] : null;
        while (is_array($walk)) {
            $ancestors[] = array(
                'id'   => isset($walk['id']) ? (string) $walk['id'] : '',
                'name' => isset($walk['displayName']) ? (string) $walk['displayName'] : '',
            );
            $walk = isset($walk['ancestor']) ? $walk['ancestor'] : null;
        }

        return array(
            'id'        => isset($node['id']) ? (string) $node['id'] : '',
            'name'      => isset($node['displayName']) ? (string) $node['displayName'] : '',
            'label'     => isset($node['contextFreeName']) ? (string) $node['contextFreeName'] : '',
            'is_root'   => !empty($node['isRoot']),
            'children'  => $children,
            'ancestors' => array_reverse($ancestors),
        );
    }
}
