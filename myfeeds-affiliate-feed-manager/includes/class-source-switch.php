<?php
/**
 * "Change source" on the Feeds screen: the way from one product source to
 * another.
 *
 * Changing the source removes the old one as deleting a feed does - what
 * a published post shows becomes unavailable, everything else of the
 * source is deleted - after a window that says what that takes out of the
 * site's posts. The same window brings a source that is set aside back
 * ("Use this source instead"): the current one goes, the one set aside
 * returns with its products.
 *
 * The page loads again afterwards. What a source offers on the screen is
 * wired when the page loads, so swapping parts of it would leave the
 * screen of the source that was there before.
 *
 * Amazon's API has no entry in the list. Changing away from it releases
 * it (MyFeeds_Source_Rules::release()): Amazon's data leaves the site, as
 * Amazon's licence asks, and the cards fall back to the names their
 * authors gave them.
 *
 * A network's connection details stay stored when its source goes. The
 * screen names them while no source of theirs is in use, with the one
 * way to remove them: without that line there is no window left to do it
 * in.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Source_Switch {

    public static function init() {
        add_action('wp_ajax_myfeeds_source_preview', array(__CLASS__, 'ajax_preview'));
        add_action('wp_ajax_myfeeds_source_change', array(__CLASS__, 'ajax_change'));
        add_action('wp_ajax_myfeeds_forget_connection', array(__CLASS__, 'ajax_forget_connection'));
        // Before the sample products' own handler (priority 10): it reads the
        // feed list only, and the site's source is more than the list.
        add_action('admin_post_myfeeds_load_demo', array(__CLASS__, 'refuse_samples'), 1);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }

    public static function enqueue($hook) {
        if ($hook !== 'toplevel_page_myfeeds-feeds') {
            return;
        }
        $rel = 'assets/source-switch.js';
        wp_enqueue_script(
            'myfeeds-source-switch',
            MYFEEDS_PLUGIN_URL . $rel,
            array(),
            function_exists('myfeeds_asset_ver') ? myfeeds_asset_ver($rel) : (string) filemtime(MYFEEDS_PLUGIN_DIR . $rel),
            true
        );
        wp_localize_script('myfeeds-source-switch', 'myfeedsSourceSwitch', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('myfeeds_nonce'),
            'pageUrl' => admin_url('admin.php?page=myfeeds-feeds'),
            'i18n'    => array(
                'counting'    => __('Counting what this changes ...', 'myfeeds-affiliate-feed-manager'),
                'serverError' => __('Server error. Please try again.', 'myfeeds-affiliate-feed-manager'),
            ),
        ));
    }

    /**
     * The window, rendered once with the other dialogs of the screen. Its
     * text comes from preview(), counted when it opens.
     */
    public static function render_modal() {
        ?>
        <div id="myfeeds-change-source-modal" class="myfeeds-modal-overlay" style="display:none;" data-testid="change-source-modal">
            <div class="myfeeds-modal-content" role="dialog" aria-modal="true" aria-labelledby="myfeeds-change-source-title">
                <div class="myfeeds-modal-header">
                    <h3 id="myfeeds-change-source-title"><?php esc_html_e('Change the product source', 'myfeeds-affiliate-feed-manager'); ?></h3>
                    <button type="button" class="myfeeds-modal-close" data-myfeeds-source-cancel>&times;</button>
                </div>
                <div class="myfeeds-modal-body">
                    <div id="myfeeds-change-source-text" aria-live="polite"></div>
                    <p class="myfeeds-change-source-actions">
                        <button type="button" class="button" data-myfeeds-source-cancel><?php esc_html_e('Cancel', 'myfeeds-affiliate-feed-manager'); ?></button>
                        <button type="button" class="button button-primary" id="myfeeds-change-source-confirm" disabled><?php esc_html_e('Remove this source', 'myfeeds-affiliate-feed-manager'); ?></button>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * The value of 'target' in a request: '' (no source comes back), a
     * group of myfeeds_feeds_set_aside, or 'amazon_api' while Amazon's API
     * is set aside with its credentials stored - as the note on the Feeds
     * screen names them. Null for anything else.
     *
     * @return array{0:string,1:array[]}|null [target, the entries it names; none for Amazon's API]
     */
    private static function requested_target() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- both callers check the nonce first.
        $target = isset($_POST['target']) ? sanitize_text_field(wp_unslash($_POST['target'])) : '';
        if ($target === '') {
            return array('', array());
        }
        if ($target === 'amazon_api') {
            return self::amazon_set_aside() ? array($target, array()) : null;
        }
        $group = self::set_aside_group($target);
        return $group === array() ? null : array($target, $group);
    }

    /** Is Amazon's API set aside, with something of its connection still stored to come back with? */
    private static function amazon_set_aside() {
        return get_option(MyFeeds_Source_Rules::AMAZON_CHOICE) === 'set_aside' && self::connection_stored('amazon');
    }

    /**
     * The entries of myfeeds_feeds_set_aside a target names: 'feed:<id>'
     * one feed by its stable_id, 'api:rakuten' every advertiser of
     * Rakuten's API, which are one source.
     *
     * @param string $target
     * @return array[] Empty when it names nothing set aside.
     */
    private static function set_aside_group($target) {
        $aside = get_option(MyFeeds_Feed_Manager::SET_ASIDE_OPTION, array());
        if (!is_array($aside)) {
            return array();
        }
        $feed = preg_match('/^feed:([1-9][0-9]*)$/', (string) $target, $m) ? (int) $m[1] : 0;
        if ($feed === 0 && $target !== 'api:rakuten') {
            return array();
        }
        $group = array();
        foreach ($aside as $entry) {
            if (!self::is_source_entry($entry)) {
                continue;
            }
            $network = self::network_of($entry);
            if ($feed > 0 ? ($network === '' && (int) ($entry['stable_id'] ?? 0) === $feed) : $network === 'rakuten') {
                $group[] = $entry;
            }
        }
        return $feed > 0 ? array_slice($group, 0, 1) : $group;
    }

    /** An entry that is part of a product source: named, with a URL, no sample. */
    private static function is_source_entry($entry) {
        return is_array($entry)
            && trim((string) ($entry['name'] ?? '')) !== ''
            && trim((string) ($entry['url'] ?? '')) !== ''
            && !(class_exists('MyFeeds_Demo_Content') && MyFeeds_Demo_Content::is_demo_feed($entry));
    }

    /** '' for a feed, the network for an entry a network's API wrote. */
    private static function network_of(array $entry) {
        if (empty($entry['api']) || !is_array($entry['api'])) {
            return '';
        }
        $network = sanitize_key((string) ($entry['api']['network'] ?? ''));
        return $network !== '' ? $network : 'other';
    }

    /** The entries of the site's product source: every real entry of the list. */
    private static function source_entries() {
        $list = get_option(MyFeeds_Feed_Manager::OPTION_KEY, array());
        $out = array();
        foreach (is_array($list) ? $list : array() as $entry) {
            if (self::is_source_entry($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** The key of this entry in the list now: by stable_id, by URL and name where it has none. */
    private static function key_of(array $entry) {
        $list = get_option(MyFeeds_Feed_Manager::OPTION_KEY, array());
        $sid = (int) ($entry['stable_id'] ?? 0);
        foreach (is_array($list) ? $list : array() as $key => $have) {
            if (!is_array($have)) {
                continue;
            }
            if ($sid > 0 ? (int) ($have['stable_id'] ?? 0) === $sid
                : ((string) ($have['url'] ?? '') === (string) ($entry['url'] ?? '') && (string) ($have['name'] ?? '') === (string) ($entry['name'] ?? ''))) {
                return $key;
            }
        }
        return null;
    }

    /** The same entry by its stable_id, or by its URL - as the other plugin brings feeds back. */
    private static function holds(array $list, array $entry) {
        $sid = (int) ($entry['stable_id'] ?? 0);
        $url = (string) ($entry['url'] ?? '');
        foreach ($list as $have) {
            if (!is_array($have)) {
                continue;
            }
            if (($sid > 0 && (int) ($have['stable_id'] ?? 0) === $sid) || ($url !== '' && (string) ($have['url'] ?? '') === $url)) {
                return true;
            }
        }
        return false;
    }

    /**
     * What the window says before anything happens: which source goes,
     * how many of its products leave how many published posts (counted as
     * the delete path will take them, MyFeeds_Feed_Manager::leaving()),
     * and which source comes back. Built here, so every sentence stays
     * translatable and the script knows none.
     *
     * Amazon's API is counted from the same read: the Amazon products in
     * published places, the places, and the ones without a name, which
     * render nowhere without Amazon's data.
     *
     * @param array[] $back        The entries that come back, or none.
     * @param bool    $amazon_back Amazon's API comes back instead.
     * @return array{kind:string,label:string,products:int,posts:int,unnamed:int,html:string,confirm:string}
     */
    public static function preview(array $back = array(), $amazon_back = false) {
        $old = self::source_entries();
        $current = MyFeeds_Source_Rules::current();
        $amazon = ($current === 'amazon_api' || $amazon_back) ? self::amazon_placed() : array('products' => 0, 'posts' => 0, 'unnamed' => 0);
        if ($current === 'amazon_api') {
            $leaving = $amazon;
        } else {
            $leaving = !empty($old) ? MyFeeds_Feed_Manager::leaving($old) : array('products' => 0, 'posts' => 0);
        }
        $products = (int) $leaving['products'];
        $posts = (int) $leaving['posts'];
        $unnamed = $current === 'amazon_api' ? (int) $amazon['unnamed'] : 0;

        $lines = array();
        if (!empty($back)) {
            if (self::network_of($back[0]) === '') {
                /* translators: %s: the name of the feed that comes back */
                $lines[] = sprintf(__('“%s” comes back with its products.', 'myfeeds-affiliate-feed-manager'), (string) $back[0]['name']);
            } else {
                $lines[] = __('Rakuten Advertising comes back with its advertisers and their products.', 'myfeeds-affiliate-feed-manager');
            }
        } elseif ($amazon_back) {
            $line = __('Amazon\'s API comes back as this site\'s product source.', 'myfeeds-affiliate-feed-manager');
            if ((int) $amazon['products'] > 0) {
                $line .= ' ' . sprintf(
                    /* translators: 1: "3 Amazon products", 2: "2 published posts" */
                    _n('%1$s in %2$s shows Amazon\'s picture and price again.', '%1$s in %2$s show Amazon\'s picture and price again.', (int) $amazon['products'], 'myfeeds-affiliate-feed-manager'),
                    /* translators: %s: a number of Amazon products */
                    sprintf(_n('%s Amazon product', '%s Amazon products', (int) $amazon['products'], 'myfeeds-affiliate-feed-manager'), number_format_i18n((int) $amazon['products'])),
                    /* translators: %s: a number of published posts */
                    sprintf(_n('%s published post', '%s published posts', (int) $amazon['posts'], 'myfeeds-affiliate-feed-manager'), number_format_i18n((int) $amazon['posts']))
                );
            }
            $lines[] = $line;
        }
        if ($current === 'amazon_api') {
            $lines[] = __('Amazon\'s API stops being this site\'s product source.', 'myfeeds-affiliate-feed-manager');
            $lines[] = self::amazon_leaving_text($products, $posts, $unnamed);
        } elseif (!empty($old)) {
            if ($current === 'feed') {
                $name = '';
                foreach ($old as $entry) {
                    if (self::network_of($entry) === '') {
                        $name = (string) $entry['name'];
                        break;
                    }
                }
                /* translators: %s: the name of the feed */
                $lines[] = sprintf(__('“%s” stops being this site\'s product source.', 'myfeeds-affiliate-feed-manager'), $name);
            } elseif ($current === 'api:rakuten') {
                $lines[] = __('Rakuten Advertising stops being this site\'s product source, with all its advertisers.', 'myfeeds-affiliate-feed-manager');
            } else {
                $lines[] = __('These feeds stop being this site\'s product source.', 'myfeeds-affiliate-feed-manager');
            }
            if ($products > 0) {
                $lines[] = sprintf(
                    /* translators: 1: "3 products", 2: "2 published posts" */
                    __('It has %1$s in %2$s. They leave those posts, as when a feed is deleted: readers no longer see them, and the Content health card on this screen lists the posts so you can replace them. Every other product of this source is deleted, including products in drafts and scheduled posts. This cannot be undone.', 'myfeeds-affiliate-feed-manager'),
                    /* translators: %s: a number of products */
                    sprintf(_n('%s product', '%s products', $products, 'myfeeds-affiliate-feed-manager'), number_format_i18n($products)),
                    /* translators: %s: a number of published posts */
                    sprintf(_n('%s published post', '%s published posts', $posts, 'myfeeds-affiliate-feed-manager'), number_format_i18n($posts))
                );
            } else {
                $lines[] = __('None of its products is placed in a published post. Its products are deleted, including any in drafts and scheduled posts. This cannot be undone.', 'myfeeds-affiliate-feed-manager');
            }
            if ($current === 'api:rakuten' && self::connection_stored('rakuten')) {
                $lines[] = __('Your Rakuten connection details stay stored, unused, until you remove them.', 'myfeeds-affiliate-feed-manager');
            }
        }

        $html = '';
        foreach ($lines as $line) {
            $html .= '<p>' . esc_html($line) . '</p>';
        }
        return array(
            'kind'     => $current,
            'label'    => MyFeeds_Source_Rules::label($current),
            'products' => $products,
            'posts'    => $posts,
            'unnamed'  => $unnamed,
            'html'     => $html,
            'confirm'  => (!empty($back) || $amazon_back)
                ? __('Use this source instead', 'myfeeds-affiliate-feed-manager')
                : __('Remove this source', 'myfeeds-affiliate-feed-manager'),
        );
    }

    /**
     * What Amazon's API leaving does to the posts, in one paragraph. A card
     * without Amazon's data renders only with the name the author gave it
     * (MyFeeds_Amazon_Card), so the ones without a name leave the posts.
     * Products picked through the API's search are saved without one.
     *
     * @param int $products Amazon products in published places.
     * @param int $posts    Those places.
     * @param int $unnamed  Of the products, the ones without a name somewhere.
     * @return string
     */
    private static function amazon_leaving_text($products, $posts, $unnamed) {
        $parts = array();
        if ($products > 0) {
            $parts[] = sprintf(
                /* translators: 1: "3 products", 2: "2 published posts" */
                __('It has %1$s in %2$s. They lose Amazon\'s picture and price now.', 'myfeeds-affiliate-feed-manager'),
                /* translators: %s: a number of products */
                sprintf(_n('%s product', '%s products', $products, 'myfeeds-affiliate-feed-manager'), number_format_i18n($products)),
                /* translators: %s: a number of published posts */
                sprintf(_n('%s published post', '%s published posts', $posts, 'myfeeds-affiliate-feed-manager'), number_format_i18n($posts))
            );
            if ($unnamed > 0) {
                $parts[] = sprintf(
                    /* translators: %s: "2 products" */
                    _n(
                        'The ones you named yourself stay as text cards that link to Amazon with your partner tag; %s without a name leaves those posts.',
                        'The ones you named yourself stay as text cards that link to Amazon with your partner tag; %s without a name leave those posts.',
                        $unnamed,
                        'myfeeds-affiliate-feed-manager'
                    ),
                    /* translators: %s: a number of products */
                    sprintf(_n('%s product', '%s products', $unnamed, 'myfeeds-affiliate-feed-manager'), number_format_i18n($unnamed))
                );
            } else {
                $parts[] = __('The ones you named yourself stay as text cards that link to Amazon with your partner tag.', 'myfeeds-affiliate-feed-manager');
            }
        } else {
            $parts[] = __('None of its products is placed in a published post.', 'myfeeds-affiliate-feed-manager');
        }
        $parts[] = __('Amazon\'s data is deleted from this site, as Amazon\'s licence requires.', 'myfeeds-affiliate-feed-manager');
        if (self::connection_stored('amazon')) {
            $parts[] = __('Your Amazon API details stay stored, unused, until you remove them, and your partner tag stays as it is.', 'myfeeds-affiliate-feed-manager');
        }
        return implode(' ', $parts);
    }

    /**
     * The Amazon products of published places, from the one read of the
     * site's content (MyFeeds_Batch_Importer::placements()): how many, in
     * how many places, and how many stand somewhere without a name.
     *
     * @return array{products:int,posts:int,unnamed:int}
     */
    private static function amazon_placed() {
        $out = array('products' => 0, 'posts' => 0, 'unnamed' => 0);
        if (!class_exists('MyFeeds_Batch_Importer')) {
            return $out;
        }
        $ids = array();
        foreach ((array) (MyFeeds_Batch_Importer::placements()['places'] ?? array()) as $place) {
            $here = (array) ($place['amazon'] ?? array());
            if (empty($here)) {
                continue;
            }
            $out['posts']++;
            foreach ($here as $id => $name) {
                $id = (string) $id;
                $ids[$id] = ($ids[$id] ?? false) || trim((string) $name) === '';
            }
        }
        $out['products'] = count($ids);
        $out['unnamed'] = count(array_filter($ids));
        return $out;
    }

    /**
     * Removes the site's product source the way deleting a feed does, and
     * brings $back in its place if given.
     *
     * One read of the placed products for every entry, one sweep after
     * the last: each read walks every published post. The entries are
     * found by stable_id each time, because every delete shifts the keys
     * after it.
     *
     * Rakuten's fetches end first, before anything is deleted: a fetch
     * that finished in between would write its advertiser back into the
     * list, or bring in a source beside the one that comes back. One that
     * is still under way is waited for, as an import is.
     *
     * Amazon's API leaves no entry to delete: it is released, which takes
     * Amazon's data off the site, and the cached copies of the posts are
     * cleared once. Its credentials and the partner tag stay. Brought back,
     * it is chosen again - unless another source was connected while the
     * old one went.
     *
     * @param array[] $back        The entries that come back, or none.
     * @param bool    $amazon_back Amazon's API comes back instead.
     * @return array{message:string,reload:bool,redirect:string}|WP_Error
     */
    public static function change(array $back = array(), $amazon_back = false) {
        if (MyFeeds_Feed_Manager::import_running()
            || (class_exists('MyFeeds_Source_Fetcher') && MyFeeds_Source_Fetcher::is_busy())) {
            return new WP_Error('myfeeds_import_running', __('An import is running. Change the source when it has finished.', 'myfeeds-affiliate-feed-manager'));
        }
        $old = self::source_entries();
        $amazon_goes = MyFeeds_Source_Rules::current() === 'amazon_api';
        if (empty($old) && empty($back) && !$amazon_back && !$amazon_goes) {
            return new WP_Error('myfeeds_no_source', __('This site has no product source to remove.', 'myfeeds-affiliate-feed-manager'));
        }

        if (class_exists('MyFeeds_Source_Fetcher')) {
            MyFeeds_Source_Fetcher::stop_all();
        }

        // Amazon's API chosen beside the list's source (a feed saved in
        // another tab while its door chose it): not the source while the
        // list holds one, it would become the source once the list is
        // empty, without a click. Released before the list empties, as
        // stored.
        if (!$amazon_goes) {
            MyFeeds_Source_Rules::read_as_stored();
            if (get_option(MyFeeds_Source_Rules::AMAZON_CHOICE) === 'chosen' && MyFeeds_Source_Rules::current() !== 'amazon_api') {
                MyFeeds_Source_Rules::release('amazon_api', 'not_chosen');
            }
        }

        if (!empty($old)) {
            $placed = class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::is_db_mode()
                ? MyFeeds_DB_Manager::placed_product_ids()
                : null;
            foreach ($old as $entry) {
                $key = self::key_of($entry);
                if ($key !== null) {
                    MyFeeds_Feed_Manager::delete_entry_at($key, $placed, false);
                }
            }
            MyFeeds_Feed_Manager::after_deletion($placed);
        }
        if ($amazon_goes) {
            MyFeeds_Source_Rules::release('amazon_api', 'not_chosen');
            if (class_exists('MyFeeds_Page_Cache_Purge')) {
                MyFeeds_Page_Cache_Purge::purge_product_posts();
            }
            if (class_exists('MyFeeds_Dead_Products')) {
                MyFeeds_Dead_Products::bust_cache();
            }
        }

        if ($amazon_back) {
            // The old source took its time to go. Chosen only where no
            // other source came in meanwhile, read as stored.
            MyFeeds_Source_Rules::read_as_stored();
            if (MyFeeds_Source_Rules::current() !== '') {
                $message = __('The product source was removed. Another source was connected meanwhile, so Amazon\'s API stays set aside.', 'myfeeds-affiliate-feed-manager');
            } else {
                if (class_exists('MyFeeds_Amazon_Cache')) {
                    MyFeeds_Amazon_Cache::maybe_create_table();
                }
                MyFeeds_Source_Rules::choose('amazon_api');
                if (class_exists('MyFeeds_Dead_Products')) {
                    MyFeeds_Dead_Products::bust_cache();
                }
                $message = __('Amazon\'s API is this site\'s product source again.', 'myfeeds-affiliate-feed-manager');
            }
        } elseif (empty($back)) {
            $message = __('The product source was removed. Choose a new one below.', 'myfeeds-affiliate-feed-manager');
        } else {
            $updating = self::bring_back($back);
            if (self::network_of($back[0]) === '') {
                /* translators: %s: the name of the feed */
                $message = sprintf(__('“%s” is this site\'s product source again.', 'myfeeds-affiliate-feed-manager'), (string) $back[0]['name']);
            } else {
                $message = __('Rakuten Advertising is this site\'s product source again.', 'myfeeds-affiliate-feed-manager');
            }
            if ($updating) {
                $message .= ' ' . __('Its products are being updated.', 'myfeeds-affiliate-feed-manager');
            }
        }

        return array(
            'message'  => $message,
            'reload'   => true,
            'redirect' => add_query_arg('myfeeds_success', rawurlencode($message), admin_url('admin.php?page=myfeeds-feeds')),
        );
    }

    /**
     * The entries come back from myfeeds_feeds_set_aside: into the list
     * (none twice, matched as the other plugin matches them), then out of
     * the option - in that order, so a request that ends in between leaves
     * an entry in both, never in neither. The samples make room (rule 21),
     * the rows archived when the entries were set aside are live again,
     * and each entry is brought up to date for what changed meanwhile: a
     * feed is imported, a Rakuten advertiser fetched again from its
     * snapshot on, where the connection details are there to fetch with.
     *
     * @param array[] $back
     * @return bool Whether an import or a fetch was started.
     */
    private static function bring_back(array $back) {
        if (class_exists('MyFeeds_Demo_Content')) {
            MyFeeds_Demo_Content::remove_if_active();
        }
        $list = get_option(MyFeeds_Feed_Manager::OPTION_KEY, array());
        $list = is_array($list) ? array_values($list) : array();
        $returned = array();
        foreach ($back as $entry) {
            if (!self::holds($list, $entry)) {
                $list[] = $entry;
                $returned[] = $entry;
            }
        }
        update_option(MyFeeds_Feed_Manager::OPTION_KEY, $list);

        $aside = get_option(MyFeeds_Feed_Manager::SET_ASIDE_OPTION, array());
        $rest = array();
        foreach (is_array($aside) ? $aside : array() as $entry) {
            if (!is_array($entry) || !self::holds($back, $entry)) {
                $rest[] = $entry;
            }
        }
        if (empty($rest)) {
            delete_option(MyFeeds_Feed_Manager::SET_ASIDE_OPTION);
        } else {
            update_option(MyFeeds_Feed_Manager::SET_ASIDE_OPTION, $rest, false);
        }

        MyFeeds_Feed_Manager::bring_rows_back($returned);
        if (class_exists('MyFeeds_Dead_Products')) {
            MyFeeds_Dead_Products::bust_cache();
        }

        $scheduled = false;
        foreach ($returned as $entry) {
            if (self::network_of($entry) === 'rakuten') {
                if (class_exists('MyFeeds_Source_Fetcher') && class_exists('MyFeeds_Rakuten_Credentials')
                    && MyFeeds_Rakuten_Credentials::is_configured()
                    && !is_wp_error(MyFeeds_Source_Fetcher::refresh_feed((int) ($entry['stable_id'] ?? 0)))) {
                    $scheduled = true;
                }
                continue;
            }
            if (self::network_of($entry) !== '' || !class_exists('MyFeeds_Batch_Importer')) {
                continue;
            }
            $key = self::key_of($entry);
            if ($key !== null && (new MyFeeds_Batch_Importer())->schedule_new_feed_import($key, true)) {
                $scheduled = true;
            }
        }
        return $scheduled;
    }

    public static function ajax_preview() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'myfeeds-affiliate-feed-manager')), 403);
            return;
        }
        $target = self::requested_target();
        if ($target === null) {
            wp_send_json_error(array('message' => __('There is no such source set aside on this site.', 'myfeeds-affiliate-feed-manager')), 400);
            return;
        }
        if ($target[0] === '' && MyFeeds_Source_Rules::current() === '') {
            wp_send_json_error(array('message' => __('This site has no product source to remove.', 'myfeeds-affiliate-feed-manager')));
            return;
        }
        wp_send_json_success(self::preview($target[1], $target[0] === 'amazon_api'));
    }

    public static function ajax_change() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'myfeeds-affiliate-feed-manager')), 403);
            return;
        }
        $target = self::requested_target();
        if ($target === null) {
            wp_send_json_error(array('message' => __('There is no such source set aside on this site.', 'myfeeds-affiliate-feed-manager')), 400);
            return;
        }
        $done = self::change($target[1], $target[0] === 'amazon_api');
        if (is_wp_error($done)) {
            wp_send_json_error(array('message' => $done->get_error_message()));
            return;
        }
        wp_send_json_success($done);
    }

    /**
     * Connection details stored here and not in use. Rakuten's while
     * another kind of source is the site's product source
     * (forget_refusal()); Amazon's API's whenever it is not the source -
     * with no source too: its window removes them only while the door may
     * still connect it, and set aside it has no window of its own.
     *
     * @return array<string,string> network => its name
     */
    private static function unused_connections() {
        $out = array();
        if (self::forget_refusal() === '' && self::connection_stored('rakuten')) {
            $out['rakuten'] = __('Rakuten Advertising', 'myfeeds-affiliate-feed-manager');
        }
        if (self::amazon_forget_refusal() === '' && self::connection_stored('amazon')) {
            $out['amazon'] = __('Amazon\'s API', 'myfeeds-affiliate-feed-manager');
        }
        return $out;
    }

    /**
     * Why the credentials of Amazon's API may not be removed from here now,
     * or '' when they may: never while it is the site's product source, set
     * up or not - the way off it is "Change source", which says first what
     * leaves the posts.
     *
     * @return string
     */
    private static function amazon_forget_refusal() {
        if (MyFeeds_Source_Rules::current() === 'amazon_api') {
            return __('These connection details are in use by this site\'s product source. Change the source first.', 'myfeeds-affiliate-feed-manager');
        }
        return '';
    }

    /**
     * Why Rakuten's connection details may not be removed from here now,
     * or '' when they may. Not while Rakuten is the source, and not while
     * the site has none: then the window behind "+ Add API source" has
     * Disconnect of its own, and the site's first Rakuten fetch may be
     * running on these details - it has no source until it ends. Nor
     * while a fetch is pending or under way.
     *
     * @return string
     */
    private static function forget_refusal() {
        $current = MyFeeds_Source_Rules::current();
        if ($current === 'api:rakuten') {
            return __('These connection details are in use by this site\'s product source. Change the source first.', 'myfeeds-affiliate-feed-manager');
        }
        if ($current === '') {
            return __('This site has no product source yet. To remove the connection details, choose Disconnect in the window of “+ Add API source”.', 'myfeeds-affiliate-feed-manager');
        }
        if (class_exists('MyFeeds_Source_Fetcher')
            && (!empty(MyFeeds_Source_Fetcher::pending()) || MyFeeds_Source_Fetcher::is_busy())) {
            return __('Rakuten is fetching for this site. Remove the connection details when it has finished.', 'myfeeds-affiliate-feed-manager');
        }
        return '';
    }

    /**
     * Are any of this network's connection details stored? Not
     * is_configured(): a client id alone is stored too, and so is a secret
     * the site can no longer open.
     *
     * @param string $network 'rakuten' or 'amazon'.
     * @return bool
     */
    private static function connection_stored($network) {
        if ($network === 'amazon') {
            return class_exists('MyFeeds_Amazon_Credentials') && MyFeeds_Amazon_Credentials::is_stored();
        }
        return $network === 'rakuten' && class_exists('MyFeeds_Rakuten_Credentials')
            && MyFeeds_Rakuten_Credentials::is_stored();
    }

    /**
     * The line under the source's heading, one per network with details
     * stored and not in use, and its button.
     */
    public static function render_unused_connections() {
        foreach (self::unused_connections() as $network => $name) {
            $question = $network === 'amazon'
                ? __('Remove the stored connection details? You would have to enter them again to connect. Your Amazon site and partner tag stay as they are.', 'myfeeds-affiliate-feed-manager')
                : __('Remove the stored connection details? You would have to enter them again to connect.', 'myfeeds-affiliate-feed-manager');
            ?>
            <p class="description myfeeds-unused-connection" data-testid="unused-connection-<?php echo esc_attr($network); ?>">
                <?php
                /* translators: %s: where the details are for, "Rakuten Advertising" or "Amazon's API" */
                echo esc_html(sprintf(__('Connection details stored on this site and not in use: %s.', 'myfeeds-affiliate-feed-manager'), $name));
                ?>
                <button type="button" class="button-link myfeeds-forget-connection"
                    data-connection="<?php echo esc_attr($network); ?>"
                    data-confirm="<?php echo esc_attr($question); ?>">
                    <?php esc_html_e('Remove them', 'myfeeds-affiliate-feed-manager'); ?>
                </button>
            </p>
            <?php
        }
    }

    /**
     * "Remove them": the connection details of a network that is not the
     * site's product source are deleted, while another kind of source is
     * (forget_refusal(), asked here and not only by the screen). Nothing
     * of that network's can be fetched without them, so a run left behind
     * ends with them.
     *
     * Amazon's API: what only the API needs goes (forget_api()), and with
     * it Amazon's data and the choice of it, which then is 'not_chosen'.
     * The Amazon site, the partner tags and the badge stay: every Amazon
     * link is built from them. Asked as stored, not as this request loaded
     * it: its door may have made it the source from another tab meanwhile.
     */
    public static function ajax_forget_connection() {
        check_ajax_referer('myfeeds_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'myfeeds-affiliate-feed-manager')), 403);
            return;
        }
        $network = isset($_POST['connection']) ? sanitize_text_field(wp_unslash($_POST['connection'])) : '';
        $known = ($network === 'rakuten' && class_exists('MyFeeds_Rakuten_Credentials'))
            || ($network === 'amazon' && self::connection_stored('amazon'));
        if (!$known) {
            wp_send_json_error(array('message' => __('There are no such connection details on this site.', 'myfeeds-affiliate-feed-manager')), 400);
            return;
        }
        if ($network === 'amazon') {
            MyFeeds_Source_Rules::read_as_stored();
        }
        $refusal = $network === 'amazon' ? self::amazon_forget_refusal() : self::forget_refusal();
        if ($refusal !== '') {
            wp_send_json_error(array('message' => $refusal));
            return;
        }
        if ($network === 'amazon') {
            MyFeeds_Amazon_Credentials::forget_api();
            MyFeeds_Source_Rules::release('amazon_api', 'not_chosen');
        } else {
            if (class_exists('MyFeeds_Source_Fetcher')) {
                MyFeeds_Source_Fetcher::stop_all();
            }
            MyFeeds_Rakuten_Credentials::disconnect();
        }
        $message = __('The stored connection details were removed.', 'myfeeds-affiliate-feed-manager');
        wp_send_json_success(array(
            'message'  => $message,
            'reload'   => true,
            'redirect' => add_query_arg('myfeeds_success', rawurlencode($message), admin_url('admin.php?page=myfeeds-feeds')),
        ));
    }

    /**
     * The sample products are offered while the site has no product
     * source; a request to load them beside one is turned down.
     */
    public static function refuse_samples() {
        if (MyFeeds_Source_Rules::current() === '') {
            return;
        }
        wp_safe_redirect(add_query_arg('myfeeds_error', rawurlencode(MyFeeds_Source_Rules::refusal()), admin_url('admin.php?page=myfeeds-feeds')));
        exit;
    }
}
