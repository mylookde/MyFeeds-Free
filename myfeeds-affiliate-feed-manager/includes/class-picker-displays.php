<?php
/**
 * The recommendation card and the comparison table: two more ways for
 * the product picker to show the products a block already holds. Their
 * styles come from one register (styles()): the recommendation card (B1)
 * and the comparison table with its pick on top (C3). Another style joins
 * through the filter myfeeds_picker_display_styles; a block that asks for
 * a style the register does not hold renders as C3.
 *
 * They are displays, not a second kind of card. Every product still goes
 * through the grid's render_product_card() (or the Amazon text card), so
 * the tracked link, rel, image way back, Amazon's stamp and disclaimer,
 * the live price script and the structured data come along unchanged.
 * Label, sentence and button are added at the card's foot, and the rest
 * is CSS.
 *
 * Nobody has to type anything (Marlon, 30.10.: "bei myfeeds geht es um
 * geschwindigkeit"). Every text is worked out on render and never stored,
 * so it follows the next import by itself; what the block stores is only
 * what its author overwrote, by the id the block holds.
 *
 * Three things the rules below keep out, each for a reason:
 *   - no label from Amazon's prices. Without written approval the
 *     Associates licence forbids using the API for "aggregating,
 *     analyzing, extracting, or repurposing" (Participation 2e), and a
 *     cached "Budget pick" could contradict the live price beside it.
 *     With an Amazon card in the block the labels are "Our pick" and
 *     "Alternative", and one click turns them into the author's own word.
 *   - no sentence from Amazon's features. They load in the browser, may
 *     not be altered (6a), and would shift the layout.
 *   - no Amazon price beside another shop's price. Participation 2 asks
 *     for the lowest new price in any such comparison, and the API does
 *     not give it out: it has the buy box offer, sometimes a used one, and
 *     refuses offers.summaries.lowestPrice (measured 30.10.2026). A block
 *     with both shows the Amazon card without its price, since the rule
 *     applies to a site that chooses to show one.
 *   - none of the words that a feed uses to sell: "best", "lowest",
 *     "tested", "sustainable", "we love". A block would be saying them in
 *     its author's voice, on the author's page.
 *
 * Shared by both plugins, apart from the text domain (FREE-SYNC.md).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Picker_Displays {

    const OPTION_WORDS = 'myfeeds_picker_words';
    const LABEL_MAX    = 32;
    const REASON_MAX   = 140;
    const WORD_MAX     = 160;

    /** A cheaper product is a budget pick from here on, a dearer one premium. */
    const BUDGET_BELOW  = 0.9;
    const PREMIUM_ABOVE = 1.25;

    /** A size line says nothing for one or two sizes, or for a shoe wall. */
    const SIZES_MIN = 3;
    const SIZES_MAX = 30;

    private static $css_done = array();
    private static $capture  = null;
    private static $css_file = null;

    public static function init() {
        add_filter('myfeeds_product_card_details_extra', array(__CLASS__, 'card_extra'), 20, 2);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    // =========================================================================
    // WHICH DISPLAY
    // =========================================================================

    /**
     * Every style these displays can draw, by key:
     *   mode        'recommendation' or 'comparison' (the block's displayMode)
     *   comparison  the comparisonStyle a comparison table answers to
     *   click       what the click row calls it
     *   css         a stylesheet of its own, relative to the plugin folder;
     *               the two here live in assets/picker-displays.css
     * The two here are always there, whatever a filter does.
     *
     * @return array<string,array<string,string>>
     */
    public static function styles() {
        $base = array(
            'b1' => array('mode' => 'recommendation', 'click' => 'picker-recommendation'),
            'c3' => array('mode' => 'comparison', 'comparison' => 'featured', 'click' => 'picker-comparison-featured'),
        );
        $styles = apply_filters('myfeeds_picker_display_styles', $base);
        $styles = is_array($styles) ? $styles : array();
        foreach ($styles as $key => $style) {
            if (!is_array($style) || !preg_match('/^[a-z][a-z0-9]{0,11}$/', (string) $key)) {
                unset($styles[$key]);
            }
        }
        return array_merge($styles, $base);
    }

    /**
     * The display a block asks for, or '' for the grid and the carousel.
     * A block that fills itself from a category (there is nobody to pick
     * a favourite) is a grid, which is what those values have always
     * rendered as. A comparison style the register does not hold - one a
     * block was saved with somewhere else - is the standard comparison
     * table; the block keeps the attribute.
     *
     * @return string A key of styles(), or ''.
     */
    public static function style_for(array $attrs, $is_dynamic) {
        if ($is_dynamic) {
            return '';
        }
        $mode = isset($attrs['displayMode']) ? (string) $attrs['displayMode'] : '';
        if ($mode === 'recommendation') {
            return 'b1';
        }
        if ($mode !== 'comparison') {
            return '';
        }
        $wanted = isset($attrs['comparisonStyle']) && is_string($attrs['comparisonStyle']) ? $attrs['comparisonStyle'] : '';
        foreach (self::styles() as $key => $style) {
            if (($style['mode'] ?? '') === 'comparison' && ($style['comparison'] ?? '') === $wanted) {
                return (string) $key;
            }
        }
        return 'c3';
    }

    /** What the click row calls this display. */
    public static function click_block($style) {
        $styles = self::styles();
        return isset($styles[$style]['click']) ? (string) $styles[$style]['click'] : '';
    }

    // =========================================================================
    // RENDER
    // =========================================================================

    /**
     * @param string $key     The id the block holds: what an override and the
     *                        favourite are stored by, whatever the card
     *                        resolved to since.
     * @param string $source  'feed' a live catalogue row, 'copy' the block's
     *                        saved copy, 'amazon' Amazon's data, 'text' the
     *                        author's card without it.
     */
    public static function item($key, array $product, $source, array $manual = array()) {
        return array('key' => (string) $key, 'product' => $product, 'source' => $source, 'manual' => $manual);
    }

    /**
     * @param array    $items      From item(), in block order.
     * @param array    $attrs      The block's attributes.
     * @param string   $style      A key of styles(), from style_for().
     * @param string   $first_key  The id of the block's first product.
     * @param callable $feed_card  array $product => html
     * @param callable $text_card  (array $item, array $pick) => html
     * @param string   $wrapper    The wrapper's attribute string.
     * @return string
     */
    public static function render(array $items, array $attrs, $style, $first_key, $feed_card, $text_card, $wrapper) {
        $items = self::with_texts($items, $attrs, $first_key);
        if (self::$capture !== null) {
            self::$capture = $items;
            return '';
        }
        if (empty($items)) {
            return '';
        }

        $has_favourite = false;
        foreach ($items as $item) {
            $has_favourite = $has_favourite || $item['favourite'];
        }
        // The pick leads the table. Moving the favourite is a click, and
        // nobody would expect it to leave "Our pick" in the middle.
        if ($style !== 'b1') {
            usort($items, function ($a, $b) {
                return ((int) $b['favourite'] - (int) $a['favourite']) ?: ($a['position'] - $b['position']);
            });
        }

        $words = self::words();
        $html  = '';
        $hide  = self::hides_amazon_price($items);
        foreach ($items as $position => $item) {
            $pick = array(
                'label'  => $item['label'],
                'reason' => $item['reason'],
                'amazon' => $item['source'] === 'amazon',
                'hide_price' => $hide && $item['source'] === 'amazon',
                'cta'    => self::cta_text($item, $words),
                // The big card holds its place when the favourite is gone:
                // the layout needs a lead, the label says who it is.
                'favourite' => $style !== 'b1' && ($item['favourite'] || ($style === 'c3' && !$has_favourite && $position === 0)),
            );
            if ($item['source'] === 'text') {
                $html .= call_user_func($text_card, $item, $pick);
                continue;
            }
            $product          = $item['product'];
            $product['title'] = self::card_title($item);
            $product['pick']  = $pick;
            $html .= call_user_func($feed_card, $product);
        }

        $list_class = 'myfeeds-display__list';
        if (count($items) === 1) {
            $list_class .= ' has-one';
        }
        if (!$has_favourite) {
            $list_class .= ' has-no-favourite';
        }

        $out  = self::css_once($style);
        $out .= '<div' . $wrapper . '>';
        $out .= '<div class="' . esc_attr($list_class) . '">' . $html . '</div>';
        if (!empty($words['show_note']) && $words['note'] !== '') {
            $out .= '<div class="myfeeds-pick-note">' . esc_html($words['note']) . '</div>';
        }
        $out .= '</div>';
        return $out;
    }

    /**
     * Does this block set Amazon's data beside another shop? Decided on
     * what is really on the page: a feed product that sold out and left
     * the block compares with nothing.
     */
    public static function hides_amazon_price(array $items) {
        $amazon = false;
        $other  = false;
        foreach ($items as $item) {
            $amazon = $amazon || $item['source'] === 'amazon';
            $other  = $other || in_array($item['source'], array('feed', 'copy'), true);
        }
        return $amazon && $other;
    }

    /**
     * The wrapper's classes. A filter may add to them (the card design's
     * fonts, whose variables only it puts on the page).
     */
    public static function wrapper_classes($style) {
        $classes = 'myfeeds-product-grid myfeeds-display myfeeds-display--' . $style;
        $filtered = apply_filters('myfeeds_picker_display_classes', $classes, $style);
        return is_string($filtered) && $filtered !== '' ? $filtered : $classes;
    }

    /**
     * Label, sentence and button at the foot of a card. Hangs on the
     * grid's own insertion point, after Amazon's stamp, and adds nothing
     * to a card without a 'pick': the grid stays what it was, byte for byte.
     */
    public static function card_extra($html, $product) {
        if (!is_array($product) || empty($product['pick']) || !is_array($product['pick'])) {
            return $html;
        }
        return $html . self::pick_html($product['pick']);
    }

    /**
     * @param array  $pick  From render().
     * @param string $badge Amazon's own badge, when the site uploaded one:
     *                      it may stand where the button does, unaltered.
     */
    public static function pick_html(array $pick, $badge = '') {
        $out = '';
        // Empty until the page script says out of stock: the words come
        // from the stylesheet, so a feed reader that drops the styles does
        // not call every Amazon product unavailable.
        if (!empty($pick['amazon'])) {
            $words = self::words();
            $out  .= '<div class="myfeeds-pick-oos" data-text="' . esc_attr($words['oos']) . '"></div>';
        }
        if ((string) $pick['label'] !== '') {
            $out .= '<div class="myfeeds-pick-label">' . esc_html($pick['label']) . '</div>';
        }
        if ((string) $pick['reason'] !== '') {
            $out .= '<div class="myfeeds-pick-reason">' . esc_html($pick['reason']) . '</div>';
        }
        if ($badge !== '') {
            return $out . '<div class="myfeeds-pick-cta myfeeds-pick-cta--badge">' . $badge . '</div>';
        }
        return $out . '<span class="myfeeds-pick-cta">' . esc_html($pick['cta']) . '</span>';
    }

    private static function cta_text(array $item, array $words) {
        if (in_array($item['source'], array('amazon', 'text'), true)) {
            return $words['view_amazon'];
        }
        $merchant = isset($item['product']['merchant']) ? trim((string) $item['product']['merchant']) : '';
        // str_replace, not sprintf: these are words a site can type, and
        // "20% off at %s" is a fatal error in sprintf on every page.
        return $merchant !== '' ? str_replace('%s', $merchant, $words['view_at']) : $words['view_product'];
    }

    /**
     * A feed's name without its size, and with the brand the card no
     * longer prints above it. An Amazon title stays as Amazon wrote it:
     * the licence allows no changes to it (Participation 6a).
     */
    public static function card_title(array $item) {
        $title = isset($item['product']['title']) ? (string) $item['product']['title'] : '';
        if ($item['source'] === 'amazon') {
            return $title;
        }
        if (class_exists('MyFeeds_Variants')) {
            $title = MyFeeds_Variants::base_name($title);
        }
        $brand = isset($item['product']['brand']) ? trim((string) $item['product']['brand']) : '';
        if ($brand !== '' && stripos($title, $brand) === false) {
            $title = $brand . ' ' . $title;
        }
        return $title;
    }

    // =========================================================================
    // TEXTS
    // =========================================================================

    /**
     * Fill in label, sentence and favourite for every item, and what each
     * would be untouched (the editor shows that as the field's placeholder).
     */
    private static function with_texts(array $items, array $attrs, $first_key) {
        $items     = array_values($items);
        $overrides = isset($attrs['pickTexts']) && is_array($attrs['pickTexts']) ? $attrs['pickTexts'] : array();
        $favourite = isset($attrs['pickFavourite']) ? (string) $attrs['pickFavourite'] : '';
        if ($favourite === '') {
            $favourite = (string) $first_key;
        }

        // Not inherited: if the favourite is out of stock, nothing else in
        // the block was ever called "Our pick" by its author.
        $fav_index = -1;
        foreach ($items as $i => $item) {
            if ($item['key'] === $favourite) {
                $fav_index = $i;
                break;
            }
        }

        $labels  = self::auto_labels($items, $fav_index);
        $reasons = self::auto_reasons($items);

        foreach ($items as $i => &$item) {
            $own = isset($overrides[$item['key']]) && is_array($overrides[$item['key']]) ? $overrides[$item['key']] : array();
            $item['position']    = $i;
            $item['favourite']   = ($i === $fav_index);
            $item['auto_label']  = $labels[$i];
            $item['auto_reason'] = $reasons[$i];
            $item['label']       = self::own_text($own, 'label', self::LABEL_MAX) ?: $labels[$i];
            $item['reason']      = self::own_text($own, 'reason', self::REASON_MAX) ?: $reasons[$i];
        }
        unset($item);
        return $items;
    }

    private static function own_text(array $own, $field, $max) {
        if (!isset($own[$field]) || !is_scalar($own[$field])) {
            return '';
        }
        $text = trim(sanitize_text_field((string) $own[$field]));
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    /**
     * "Our pick" for the favourite, "Alternative" for the rest. Only in a
     * block of nothing but live feed rows in one currency do prices speak:
     * the unique cheapest product 10% below the pick is the budget pick,
     * the unique dearest 25% above it the premium pick. A saved copy has
     * no live price to compare, and for Amazon see the top of this file.
     * Nor while the page shows no prices at all (myfeeds_hide_prices):
     * a label would name a difference the cards do not show.
     */
    private static function auto_labels(array $items, $fav_index) {
        $words  = self::words();
        $labels = array();
        foreach ($items as $i => $item) {
            $labels[$i] = $i === $fav_index ? $words['our'] : $words['alternative'];
        }
        if ($fav_index < 0 || count($items) < 2) {
            return $labels;
        }
        if (apply_filters('myfeeds_hide_prices', false)) {
            return $labels;
        }

        $cents = array();
        $currency = null;
        foreach ($items as $i => $item) {
            if ($item['source'] !== 'feed') {
                return $labels;
            }
            $price = MyFeeds_Product_Picker::effective_prices($item['product']);
            $code  = isset($item['product']['currency']) ? (string) $item['product']['currency'] : '';
            if ($price[0] <= 0 || ($currency !== null && $code !== $currency)) {
                return $labels;
            }
            $currency  = $code;
            $cents[$i] = (int) round($price[0] * 100);
        }

        $base   = $cents[$fav_index];
        $others = $cents;
        unset($others[$fav_index]);
        $low  = min($others);
        $high = max($others);
        if (count(array_keys($others, $low, true)) === 1 && $low <= $base * self::BUDGET_BELOW) {
            $labels[array_search($low, $others, true)] = $words['budget'];
        }
        if (count(array_keys($others, $high, true)) === 1 && $high >= $base * self::PREMIUM_ABOVE) {
            $labels[array_search($high, $others, true)] = $words['premium'];
        }
        return $labels;
    }

    /**
     * Amazon says nothing, the author's own note stands. A feed row walks
     * down its list: a sentence, its facts, its sizes. Two cards with the
     * same line say nothing about the difference between them, so both
     * move on to the next one.
     */
    private static function auto_reasons(array $items) {
        $chains = array();
        foreach ($items as $i => $item) {
            if ($item['source'] === 'feed') {
                $chains[$i] = self::reason_chain($item['product']);
            } else {
                $note = isset($item['manual']['note']) ? trim((string) $item['manual']['note']) : '';
                $chains[$i] = $note !== '' ? array($note) : array();
            }
        }

        for ($round = 0; $round < 3; $round++) {
            $count = array();
            foreach ($chains as $chain) {
                if (!empty($chain)) {
                    $seen = strtolower($chain[0]);
                    $count[$seen] = (isset($count[$seen]) ? $count[$seen] : 0) + 1;
                }
            }
            $moved = false;
            foreach ($chains as $i => $chain) {
                if ($items[$i]['source'] === 'feed' && !empty($chain) && $count[strtolower($chain[0])] > 1) {
                    array_shift($chains[$i]);
                    $moved = true;
                }
            }
            if (!$moved) {
                break;
            }
        }

        $out = array();
        foreach ($chains as $i => $chain) {
            $out[$i] = empty($chain) ? '' : (string) $chain[0];
        }
        return $out;
    }

    private static function reason_chain(array $product) {
        $chain = array();
        if (self::site_reads_english()) {
            $raw  = isset($product['description']) ? (string) $product['description'] : '';
            $text = self::clean_description($raw);
            $sentence = self::sentence_from($text, isset($product['title']) ? (string) $product['title'] : '');
            if ($sentence !== '') {
                $chain[] = $sentence;
            }
            $facts = self::facts_from($text, self::description_was_cut($raw));
            if ($facts !== '') {
                $chain[] = $facts;
            }
        }
        // One query, and only where the feed gave nothing else to say.
        if (count($chain) < 2) {
            $sizes = self::sizes_line($product);
            if ($sizes !== '') {
                $chain[] = $sizes;
            }
        }
        return $chain;
    }

    /**
     * Feed texts are English on every feed measured so far. A German
     * sentence is not, and we have not seen enough of them to promise
     * the filters below hold there.
     */
    private static function site_reads_english() {
        return strpos((string) (function_exists('get_locale') ? get_locale() : 'en_US'), 'en') === 0;
    }

    /** The limit on Account -> Storage cuts a description mid-list. */
    private static function description_was_cut($raw) {
        $max = (int) apply_filters('myfeeds_raw_description_max', 0);
        if ($max <= 0) {
            return false;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($raw) : strlen($raw);
        return $length >= $max - 3;
    }

    private static function clean_description($text) {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/<[^>]+>/', ' ', $text);
        $text = str_replace(array('\\n', "\n", "\r"), ' ', $text);
        $text = (string) preg_replace('/^[\s"\'\x{201C}\x{201D}]+|[\s"\'\x{201C}\x{201D}]+$/u', '', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The first short, whole, plain sentence of a description: measured on
     * 3,506 products of eight feeds and read by two independent checks.
     * Everything refused here was found in a real feed: claims nobody on
     * this site made ("recycled", "tested"), a sentence that only makes
     * sense after the one before it ("It features ..."), commands
     * ("Step into ..."), two words glued together by a feed's export, and
     * a product name repeated as a sentence.
     */
    public static function sentence_from($text, $name = '') {
        if (count(preg_split('/(?:^|\s)[-\x{2022}*]\s+/u', $text)) >= 3) {
            return '';
        }
        foreach (array_slice((array) preg_split('/(?<=[.!?])\s+/', $text), 0, 3) as $sentence) {
            $sentence = trim((string) $sentence);
            $length   = strlen($sentence);
            if ($length < 35 || $length > 125 || substr($sentence, -1) !== '.' || substr_count($sentence, ' ') < 5) {
                continue;
            }
            if (self::sells_too_hard($sentence)
                || preg_match('/^(it|this|that|these|those|they|its|their|the result|here|there|and|but|so|with|for|from|to|in|on|at|by)\b/i', $sentence)
                || preg_match('/^(step|get|lace|shift|make|let|keep|pull|slip|add|grab|take|style|create|wear|go|try|enjoy|choose|bring|turn|elevate|upgrade|discover|meet|feel)\b/i', $sentence)
                || preg_match('/[a-z][A-Z]|[a-z]\.[A-Z]/', $sentence)
                || preg_match('/^(?:[A-Z][\w&\'-]*\s+){4,}/', $sentence)
                || self::repeats_the_name($sentence, $name)
                || !self::reads_english($sentence)) {
                continue;
            }
            return $sentence;
        }
        return '';
    }

    /**
     * "Fast feels even better in the Men's Saucony Kinvara 16 Running
     * Shoes." says nothing the title above it has not said. Three of the
     * name's longer words in one sentence is the name again.
     */
    private static function repeats_the_name($sentence, $name) {
        $sentence = strtolower($sentence);
        $hits     = 0;
        foreach (array_unique((array) preg_split('/[^\p{L}\p{N}]+/u', strtolower((string) $name), -1, PREG_SPLIT_NO_EMPTY)) as $word) {
            if (strlen($word) >= 4 && strpos($sentence, $word) !== false) {
                $hits++;
            }
        }
        return $hits >= 3;
    }

    /** Two or three short bullet points of a description, joined. */
    public static function facts_from($text, $was_cut = false) {
        $parts = array();
        foreach ((array) preg_split('/(?:^|\s)[-\x{2022}*]\s+/u', $text) as $part) {
            $part = trim((string) $part, " .;,\t");
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        if (count($parts) < 3) {
            return '';
        }
        if ($was_cut) {
            array_pop($parts);
        }

        $keep = array();
        foreach ($parts as $part) {
            if (preg_match('/^(?:neck|sleeve|fit)\s*:\s*(.+)$/i', $part, $m)) {
                $part = $m[1];
            } elseif (strpos($part, ':') !== false || preg_match('/\d|\bsize\b/i', $part)) {
                continue;
            }
            $length = strlen($part);
            if ($length >= 4 && $length <= 38 && !self::sells_too_hard($part)
                && !preg_match('/\b(und|der|die|das|mit|aus)\b/i', $part)
                && !in_array(strtolower($part), array_map('strtolower', $keep), true)) {
                $keep[] = ucfirst($part);
            }
            if (count($keep) === 3) {
                break;
            }
        }
        return count($keep) >= 2 ? implode(' · ', $keep) : '';
    }

    private static function sells_too_hard($text) {
        return (bool) preg_match('/\b(you|your|yours|our|we|us|best|bestsell\w*|lowest|top[- ]rated|perfect|ultimate|unmatched|unparalleled|most|finest|greatest|must[- ]have|love|recycl\w*|sustainab\w*|eco\w*|green|organic|climate|carbon|renewable|vegan|healthy|health|tested|award\w*|winner|sale|deal\w*|save|discount\w*|cheap\w*|limited|only|left|stock|free|shipping|delivery|return\w*|guarantee\w*|warranty|size\w*|model|measure\w*|gift card\w*|wash\w*|fade|bleed)\b/i', $text);
    }

    private static function reads_english($sentence) {
        return preg_match('/\b(the|a|an|and|of|with|in|for|to)\b/i', $sentence)
            && !preg_match('/\b(und|der|die|das|mit|aus|ist|ein|eine|fur|f\x{00FC}r)\b/iu', $sentence);
    }

    private static function sizes_line(array $product) {
        if (empty($product['id']) || !class_exists('MyFeeds_DB_Manager')) {
            return '';
        }
        $sizes = 0;
        foreach (MyFeeds_DB_Manager::get_size_variants((string) $product['id']) as $variant) {
            if (!empty($variant['in_stock'])) {
                $sizes++;
            }
        }
        if ($sizes < self::SIZES_MIN || $sizes > self::SIZES_MAX) {
            return '';
        }
        $words = self::words();
        return str_replace('%d', (string) $sizes, $words['sizes']);
    }

    // =========================================================================
    // WORDS
    // =========================================================================

    /**
     * The fixed words of these displays. They come in English and German,
     * follow the site's language, and a site can change them once for all
     * its blocks. A field left empty goes back to the default.
     */
    public static function defaults() {
        if (strpos((string) get_locale(), 'de') === 0) {
            return array(
                'our'          => 'Unsere Wahl',
                'budget'       => 'Preis-Tipp',
                'premium'      => 'Premium-Wahl',
                'alternative'  => 'Alternative',
                'gift'         => 'Großes Geschenk',
                'beginners'    => 'Für Einsteiger',
                'note'         => 'Bezahlte Links: Wir erhalten möglicherweise eine Provision.',
                'sizes'        => 'In %d Größen erhältlich.',
                'view_at'      => 'Zu %s',
                'view_amazon'  => 'Bei Amazon ansehen',
                'view_product' => 'Zum Produkt',
                'oos'          => 'Derzeit bei Amazon nicht verfügbar',
            );
        }
        return array(
            'our'          => __('Our pick', 'myfeeds-affiliate-feed-manager'),
            'budget'       => __('Budget pick', 'myfeeds-affiliate-feed-manager'),
            'premium'      => __('Premium pick', 'myfeeds-affiliate-feed-manager'),
            'alternative'  => __('Alternative', 'myfeeds-affiliate-feed-manager'),
            'gift'         => __('Big gift', 'myfeeds-affiliate-feed-manager'),
            'beginners'    => __('For beginners', 'myfeeds-affiliate-feed-manager'),
            'note'         => __('Paid links: we may earn a commission.', 'myfeeds-affiliate-feed-manager'),
            /* translators: %d: number of sizes in stock */
            'sizes'        => __('Available in %d sizes.', 'myfeeds-affiliate-feed-manager'),
            /* translators: %s: shop name */
            'view_at'      => __('View at %s', 'myfeeds-affiliate-feed-manager'),
            'view_amazon'  => __('View on Amazon', 'myfeeds-affiliate-feed-manager'),
            'view_product' => __('View product', 'myfeeds-affiliate-feed-manager'),
            'oos'          => __('Currently unavailable on Amazon', 'myfeeds-affiliate-feed-manager'),
        );
    }

    public static function words() {
        $words = self::defaults();
        $saved = get_option(self::OPTION_WORDS, array());
        $saved = is_array($saved) ? $saved : array();
        foreach ($words as $key => $default) {
            if (isset($saved[$key]) && is_string($saved[$key]) && trim($saved[$key]) !== '') {
                $words[$key] = $saved[$key];
            }
        }
        // On unless switched off: most sites these blocks are for have to
        // say so (FTC, UWG), and the note is one small line.
        $words['show_note'] = !isset($saved['show_note']) || !empty($saved['show_note']);
        return $words;
    }

    // =========================================================================
    // EDITOR
    // =========================================================================

    public static function register_routes() {
        register_rest_route('myfeeds/v1', '/picker/pick-texts', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'rest_pick_texts'),
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
        ));
        register_rest_route('myfeeds/v1', '/picker/words', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'rest_save_words'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));
    }

    /**
     * What the block will say. The answer comes from a real render of the
     * block with the attributes the editor holds now, so the editor cannot
     * promise a text the page would not show, and a product left out on
     * the page is left out here too.
     */
    public static function rest_pick_texts($request) {
        $attrs = $request->get_json_params();
        $attrs = is_array($attrs) ? $attrs : array();
        if (self::style_for($attrs, false) === '') {
            return rest_ensure_response(array('items' => array()));
        }

        self::$capture = array();
        (new MyFeeds_Product_Picker())->render_callback($attrs);
        $items = self::$capture;
        self::$capture = null;

        $out = array();
        foreach ((array) $items as $item) {
            $product = $item['product'];
            $name    = isset($product['title']) ? (string) $product['title'] : '';
            if ($item['source'] === 'text') {
                $name = trim((isset($item['manual']['brand']) ? $item['manual']['brand'] . ' ' : '') . (isset($item['manual']['name']) ? $item['manual']['name'] : ''));
            }
            $out[] = array(
                'key'         => $item['key'],
                'name'        => html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'image'       => isset($product['image_url']) ? (string) $product['image_url'] : '',
                'source'      => $item['source'],
                'favourite'   => $item['favourite'],
                'auto_label'  => $item['auto_label'],
                'auto_reason' => $item['auto_reason'],
            );
        }
        return rest_ensure_response(array(
            'items'               => $out,
            'amazon_price_hidden' => self::hides_amazon_price((array) $items),
        ));
    }

    public static function rest_save_words($request) {
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $saved  = array('show_note' => !empty($params['show_note']));
        foreach (array_keys(self::defaults()) as $key) {
            if (isset($params[$key]) && is_scalar($params[$key])) {
                $text = sanitize_text_field((string) $params[$key]);
                // By characters: half an umlaut is invalid UTF-8, which the
                // database refuses, and the note would be gone without a word.
                $saved[$key] = function_exists('mb_substr') ? mb_substr($text, 0, self::WORD_MAX) : wp_html_excerpt($text, self::WORD_MAX);
            }
        }
        update_option(self::OPTION_WORDS, $saved, false);
        return rest_ensure_response(array('words' => self::words(), 'defaults' => self::defaults()));
    }

    /** What the editor needs to know about these displays. */
    public static function editor_config() {
        return array(
            'canWords'  => current_user_can('manage_options'),
            'words'     => self::words(),
            'defaults'  => self::defaults(),
            'labelMax'  => self::LABEL_MAX,
            'reasonMax' => self::REASON_MAX,
            'wordMax'   => self::WORD_MAX,
        );
    }

    // =========================================================================
    // CSS
    // =========================================================================

    /**
     * The shared rules once, then the rules of each display a page uses:
     * its part of assets/picker-displays.css, or the stylesheet its entry
     * in styles() names. In the page and not a stylesheet: a stylesheet
     * asked for from inside a block arrives in the footer, after the block
     * has drawn itself without it. A static string, so a cached page keeps
     * working.
     */
    private static function css_once($style) {
        if (self::$css_file === null) {
            self::$css_file = self::read_css('assets/picker-displays.css');
        }
        $parts = preg_split('~/\*variant:([a-z][a-z0-9]*)\*/~', self::$css_file, -1, PREG_SPLIT_DELIM_CAPTURE);
        $base  = (string) array_shift($parts);
        $css   = '';
        if (empty(self::$css_done['base'])) {
            self::$css_done['base'] = true;
            $css .= $base;
        }
        if (empty(self::$css_done[$style])) {
            self::$css_done[$style] = true;
            for ($i = 0; $i + 1 < count($parts); $i += 2) {
                if ($parts[$i] === $style) {
                    $css .= $parts[$i + 1];
                }
            }
            $styles = self::styles();
            if (!empty($styles[$style]['css']) && is_string($styles[$style]['css'])) {
                $css .= self::read_css($styles[$style]['css']);
            }
        }
        if ($css === '') {
            return '';
        }
        return '<style id="myfeeds-displays-' . esc_attr($style) . '">' . self::compile_css($css) . '</style>';
    }

    private static function read_css($rel) {
        $rel = ltrim((string) $rel, '/');
        if (strpos($rel, '..') !== false || substr($rel, -4) !== '.css') {
            return '';
        }
        $path = MYFEEDS_PLUGIN_DIR . $rel;
        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /** @P is the wrapper of every display, @B1, @C3 ... the wrapper of one. */
    private static function compile_css($css) {
        $wrapper = 'html body .myfeeds-product-grid.myfeeds-display';
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $css = (string) preg_replace_callback('/@([A-Z][0-9]+)\b/', function ($m) use ($wrapper) {
            return $wrapper . '.myfeeds-display--' . strtolower($m[1]);
        }, $css);
        $css = str_replace('@P', $wrapper, $css);
        return trim((string) preg_replace('/\s+/', ' ', $css));
    }
}
