<?php
/**
 * The product picker as a carousel: the frame around the cards, the
 * options Splide reads off it, and the files a page with one needs.
 *
 * Everything a carousel needs travels on its container: the arrow colour
 * as a CSS variable that carousel.css reads, the Splide options as JSON
 * that carousel.js reads. Both used to be printed per block as a <style>
 * and a <script>, the same rules and the same mount code again for every
 * carousel on the page. Drop either attribute and nothing errors - the
 * arrows turn the wrong colour, or the cards stay a still stack.
 *
 * The standard carousel moves by its arrows, in #333333. Two filters let
 * another part of a site change that for one block:
 *   myfeeds_carousel_options     ($options, $attrs)  the Splide options
 *   myfeeds_carousel_arrow_color ('#333333', $attrs) a hex colour
 *
 * Shared byte for byte by both plugins.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Picker_Carousel {

    const ARROW_COLOR    = '#333333';
    const SPLIDE_VERSION = '4.1.4';

    /** The opening of one carousel, up to where the first slide goes. */
    public static function open(array $attrs) {
        $id = 'myfeeds-carousel-' . wp_unique_id();
        return '<div id="' . esc_attr($id) . '"'
            . MyFeeds_Product_Picker::wrapper_attributes($attrs, 'myfeeds-product-carousel splide') . ' '
            . self::container_attrs($attrs) . '>'
            . '<div class="splide__track">'
            . '<div class="splide__list">';
    }

    public static function slide($html) {
        return '<div class="splide__slide">' . $html . '</div>';
    }

    /** splide__list, splide__track, the container. */
    public static function close() {
        return '</div></div></div>';
    }

    /**
     * The two attributes the container carries. Kept apart from the
     * render so they can be checked without a database.
     */
    public static function container_attrs(array $attrs) {
        $color = apply_filters('myfeeds_carousel_arrow_color', self::ARROW_COLOR, $attrs);
        $color = sanitize_hex_color(is_string($color) ? $color : '') ?: self::ARROW_COLOR;
        return 'style="--myfeeds-arrow-color: ' . esc_attr($color) . ';" '
            . 'data-myfeeds-carousel="' . esc_attr(wp_json_encode(self::splide_options($attrs))) . '"';
    }

    /**
     * Splide options for one carousel block. carousel.js reads them
     * straight off the container, so this is the only place the layout
     * numbers live. What carousel.js does with them on top - fewer
     * products than places shows them as one still row instead of
     * clones - is written up there.
     *
     * perPage and the breakpoints are what every carousel shows: three,
     * two on a tablet or phone. Wide and Full add 'myfeedsFit', which
     * carousel.js takes out before Splide sees the options: as many slides
     * as fit at 260px or more, up to six, never fewer than the three. A
     * wider carousel shows more slides, not bigger ones - three across
     * Twenty Twenty-Five's 1340px Wide would be 400px cards.
     *
     * The count has to come from the carousel's measured width, not from
     * the screen. Splide's breakpoints are screen widths, and Wide and
     * Full only widen the block where the theme says so: on mylook.com.de
     * (no .alignwide rule under its post template) or in GeneratePress
     * with a sidebar the carousel stays 813px, and six per page there
     * were 103px slides.
     */
    public static function splide_options(array $attrs) {
        $options = array(
            'type'       => 'loop',
            'perPage'    => 3,
            'perMove'    => 1,
            'gap'        => '16px',
            'pagination' => false,
            'arrows'     => true,
            'autoHeight' => true,
            'breakpoints' => array(
                768 => array('perPage' => 2),
                480 => array('perPage' => 2),
            ),
        );

        $align = MyFeeds_Product_Picker::align_class($attrs);
        if ($align === 'alignwide' || $align === 'alignfull') {
            $options['myfeedsFit'] = array('slide' => 260, 'max' => 6);
        }

        $filtered = apply_filters('myfeeds_carousel_options', $options, $attrs);
        return is_array($filtered) ? $filtered : $options;
    }

    /** Does any picker block in this tree show a carousel? */
    public static function blocks_need(array $blocks) {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['blockName'] ?? '') === 'myfeeds/product-picker'
                && (($block['attrs']['displayMode'] ?? 'grid') === 'carousel')) {
                return true;
            }
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks']) && self::blocks_need($block['innerBlocks'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Splide and the two carousel files, on a page whose post holds a
     * carousel. Splide is bundled (MIT, assets/vendor/splide-LICENSE.txt)
     * and versioned by its own release; our two files by their file time,
     * because MYFEEDS_VERSION only moves on a release and a carousel fix
     * deployed in between would stay behind the browser cache.
     */
    public static function enqueue_for_post() {
        global $post;
        if (!$post || !has_blocks($post->post_content)) {
            return;
        }
        if (!self::blocks_need(parse_blocks($post->post_content))) {
            return;
        }

        wp_enqueue_style('splide-core', MYFEEDS_PLUGIN_URL . 'assets/vendor/splide-core.min.css', array(), self::SPLIDE_VERSION);
        wp_enqueue_script('splide', MYFEEDS_PLUGIN_URL . 'assets/vendor/splide.min.js', array(), self::SPLIDE_VERSION, true);

        $css = MYFEEDS_PLUGIN_DIR . 'assets/carousel.css';
        wp_enqueue_style(
            'myfeeds-carousel',
            MYFEEDS_PLUGIN_URL . 'assets/carousel.css',
            array('splide-core'),
            file_exists($css) ? (string) filemtime($css) : MYFEEDS_VERSION
        );
        $js = MYFEEDS_PLUGIN_DIR . 'assets/carousel.js';
        wp_enqueue_script(
            'myfeeds-carousel',
            MYFEEDS_PLUGIN_URL . 'assets/carousel.js',
            array('splide'),
            file_exists($js) ? (string) filemtime($js) : MYFEEDS_VERSION,
            true
        );
    }
}
