// MyFeeds carousel bootstrap.
// Moved out of class-product-picker.php, which printed this same block
// once per carousel on the page. Each container now carries its own
// options in data-myfeeds-carousel and this file mounts them all.
//
// Two decisions are made here rather than in Splide, because both depend
// on how wide the carousel really is and only the browser knows that:
//
// - How many slides a page shows. Splide's breakpoints are screen widths,
//   but Wide and Full only widen the block where the theme says so. In a
//   theme that does not, a carousel set to Full stays as narrow as the
//   text, and a screen-based count squeezed six slides into it. Wide and
//   Full bring 'myfeedsFit' (see splide_options() in the picker class):
//   as many slides as fit the measured track, never fewer than the
//   screen-based count every carousel has.
//
// - Whether it moves at all. A loop carousel with fewer products than
//   places fills the gap with clones, so the same card stood twice side
//   by side. When everything fits, Splide stays out and the slides stand
//   as one centred row (.myfeeds-carousel--still in carousel.css).

;(function () {
    var STILL = 'myfeeds-carousel--still';

    // What Splide's own max-width breakpoints would give at this screen
    // width: the narrowest one that matches wins, as in Splide.
    function screenPerPage(perPage, breakpoints) {
        var best = Infinity;
        for (var key in breakpoints) {
            if (!Object.prototype.hasOwnProperty.call(breakpoints, key)) {
                continue;
            }
            var width = parseInt(key, 10);
            var entry = breakpoints[key];
            if (!entry || !entry.perPage || !(width < best)) {
                continue;
            }
            if (window.matchMedia && window.matchMedia('(max-width: ' + width + 'px)').matches) {
                best = width;
                perPage = entry.perPage;
            }
        }
        return perPage;
    }

    // The width the slides share: the track minus the room it keeps for
    // the arrows. Zero while the carousel is hidden (a closed tab).
    function trackWidth(track) {
        var style = window.getComputedStyle(track);
        return track.clientWidth
            - (parseFloat(style.paddingLeft) || 0)
            - (parseFloat(style.paddingRight) || 0);
    }

    function perPageFor(track, options, breakpoints, fit) {
        var perPage = screenPerPage(options.perPage || 1, breakpoints);
        if (fit && fit.slide > 0) {
            var gap = parseFloat(options.gap) || 0;
            var width = trackWidth(track);
            if (width > 0) {
                var fits = Math.floor((width + gap) / (fit.slide + gap));
                perPage = Math.max(perPage, Math.min(fit.max || perPage, fits));
            }
        }
        return perPage;
    }

    function setup(el) {
        if (typeof Splide === 'undefined' || el.getAttribute('data-myfeeds-mounted')) {
            return;
        }

        var options;
        try {
            options = JSON.parse(el.getAttribute('data-myfeeds-carousel') || '{}');
        } catch (e) {
            return;
        }

        var track = el.querySelector('.splide__track');
        if (!track) {
            return;
        }

        el.setAttribute('data-myfeeds-mounted', '1');

        var breakpoints = options.breakpoints || {};
        var fit = options.myfeedsFit || null;
        delete options.breakpoints;
        delete options.myfeedsFit;

        // Counted before Splide adds its clones.
        var count = el.querySelectorAll('.splide__slide').length;
        // destroy() strips the container's style attribute, which carries
        // the arrow colour; it goes back on after every remount.
        var style = el.getAttribute('style');
        el.style.setProperty('--myfeeds-slide-gap', options.gap || '0px');

        var splide = null;
        var state = '';

        // Smart autoplay resume: after any user interaction, pause briefly
        // then resume. Uses interval + 3 seconds bonus time.
        var bonusMs = (options.interval || 3000) + 3000;
        var timer = null;

        function onUserInteraction() {
            if (!splide || !splide.Components.Autoplay) {
                return;
            }
            if (timer) clearTimeout(timer);
            splide.Components.Autoplay.pause();
            timer = setTimeout(function () {
                if (splide && splide.Components.Autoplay) {
                    splide.Components.Autoplay.play();
                }
            }, bonusMs);
        }

        function mount(perPage) {
            var settings = {};
            for (var key in options) {
                if (Object.prototype.hasOwnProperty.call(options, key)) {
                    settings[key] = options[key];
                }
            }
            settings.perPage = perPage;

            splide = new Splide(el, settings);
            splide.mount();

            if (!options.autoplay) {
                return;
            }
            // Direct DOM listeners on arrow buttons (most reliable). Splide
            // builds new buttons on every mount.
            var arrows = el.querySelectorAll('.splide__arrow');
            for (var i = 0; i < arrows.length; i++) {
                arrows[i].addEventListener('click', onUserInteraction);
            }
        }

        function apply() {
            var perPage = perPageFor(track, options, breakpoints, fit);
            var moves = count > perPage;
            var next = (moves ? 'moves:' : 'still:') + perPage;
            if (next === state) {
                return;
            }

            if (moves && splide) {
                splide.options = { perPage: perPage };
                state = next;
                return;
            }

            if (splide) {
                splide.destroy(true);
                splide = null;
                // Splide 4.1 leaves these two behind, and its stylesheet
                // turns an "initialized" carousel that is not active into
                // a vertical stack.
                el.classList.remove('is-initialized', 'is-overflow');
                if (style === null) {
                    el.removeAttribute('style');
                } else {
                    el.setAttribute('style', style);
                }
                el.style.setProperty('--myfeeds-slide-gap', options.gap || '0px');
            }

            el.style.setProperty('--myfeeds-per-page', String(perPage));
            if (moves) {
                el.classList.remove(STILL);
                mount(perPage);
            } else {
                el.classList.add(STILL);
            }
            state = next;
        }

        apply();

        if (options.autoplay) {
            // Touch/drag interaction on the track. The track survives a
            // remount, so these are bound once.
            track.addEventListener('pointerdown', onUserInteraction);
            track.addEventListener('touchstart', onUserInteraction, { passive: true });
        }

        // The track's own width, so a stylesheet that arrives late, a
        // sidebar that opens or a tab that is shown counts as well as a
        // turned phone. The window listener catches a breakpoint crossed
        // while the track keeps its width.
        var pending = null;
        function later() {
            if (pending) clearTimeout(pending);
            pending = setTimeout(function () {
                pending = null;
                apply();
            }, 120);
        }
        if (typeof ResizeObserver === 'function') {
            new ResizeObserver(later).observe(track);
        }
        window.addEventListener('resize', later);
    }

    function mountAll() {
        var els = document.querySelectorAll('[data-myfeeds-carousel]');
        for (var i = 0; i < els.length; i++) {
            setup(els[i]);
        }
    }

    // The script sits in the footer, so on a normal page load DOMContentLoaded
    // is still ahead of us. It is not when a page cache replays the document,
    // and a listener registered after the event never fires.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountAll);
    } else {
        mountAll();
    }
})();
