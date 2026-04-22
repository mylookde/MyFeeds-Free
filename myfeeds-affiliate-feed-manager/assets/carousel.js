/**
 * MyFeeds Carousel — shared Splide initializer.
 *
 * Each rendered carousel block (formerly an inline <script>) calls
 * MyFeedsCarousel.init(elementId, splideOptions, autoplayIntervalMs) once.
 * autoplayIntervalMs > 0 enables the smart autoplay-resume handler that
 * pauses on user interaction and restarts after interval + 3s.
 */
window.MyFeedsCarousel = window.MyFeedsCarousel || {
    init: function (id, options, autoplayMs) {
        var run = function () {
            if (typeof Splide === 'undefined') return;

            var el = document.getElementById(id);
            if (!el) return;

            var splide = new Splide('#' + id, options);
            splide.mount();

            if (autoplayMs > 0) {
                var bonusMs = autoplayMs + 3000;
                var timer = null;
                var autoplayComp = splide.Components.Autoplay;
                if (!autoplayComp) return;

                var onUserInteraction = function () {
                    if (timer) clearTimeout(timer);
                    autoplayComp.pause();
                    timer = setTimeout(function () { autoplayComp.play(); }, bonusMs);
                };

                var arrows = el.querySelectorAll('.splide__arrow');
                for (var i = 0; i < arrows.length; i++) {
                    arrows[i].addEventListener('click', onUserInteraction);
                }

                var track = el.querySelector('.splide__track');
                if (track) {
                    track.addEventListener('pointerdown', onUserInteraction);
                    track.addEventListener('touchstart', onUserInteraction, { passive: true });
                }
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    }
};
