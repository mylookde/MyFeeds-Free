/**
 * MyFeeds feature-preview lightbox.
 *
 * Click a screenshot to open it in a centered zoom view. Click the X
 * (outside the image, top-right), click anywhere outside the image,
 * or press ESC to close. Smooth fade + scale transition.
 *
 * Vanilla JS, no dependencies, runs only on the three feature-preview
 * admin pages where the markup exists.
 */
(function () {
    'use strict';

    var lightbox = document.querySelector('.myfeeds-preview-lightbox');
    if (!lightbox) {
        return;
    }

    var imageEl = lightbox.querySelector('.myfeeds-preview-lightbox-image');
    var captionEl = lightbox.querySelector('.myfeeds-preview-lightbox-caption');
    var closeBtn = lightbox.querySelector('.myfeeds-preview-lightbox-close');
    var lastTrigger = null;

    function open(triggerEl) {
        var src = triggerEl.getAttribute('data-myfeeds-zoom-src');
        var caption = triggerEl.getAttribute('data-myfeeds-zoom-caption') || '';
        if (!src) {
            return;
        }
        lastTrigger = triggerEl;
        imageEl.setAttribute('src', src);
        imageEl.setAttribute('alt', caption);
        captionEl.textContent = caption;
        captionEl.style.display = caption ? '' : 'none';
        document.body.classList.add('myfeeds-preview-lightbox-open');
        lightbox.setAttribute('aria-hidden', 'false');
        // Defer the .is-visible class to the next frame so the CSS
        // transition has a starting state to animate from.
        requestAnimationFrame(function () {
            lightbox.classList.add('is-visible');
        });
        closeBtn.focus();
    }

    function close() {
        lightbox.classList.remove('is-visible');
        lightbox.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('myfeeds-preview-lightbox-open');
        // Clear src after the fade-out completes so the image isn't
        // visible during the next open animation.
        window.setTimeout(function () {
            if (!lightbox.classList.contains('is-visible')) {
                imageEl.setAttribute('src', '');
            }
        }, 220);
        if (lastTrigger) {
            lastTrigger.focus();
            lastTrigger = null;
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('.myfeeds-preview-zoom-trigger');
        if (trigger) {
            event.preventDefault();
            open(trigger);
            return;
        }
        if (event.target === closeBtn || closeBtn.contains(event.target)) {
            event.preventDefault();
            close();
            return;
        }
        // Click anywhere on the backdrop (the lightbox element itself
        // or any child that isn't the image / caption / close button)
        // closes too. The image and figure stopPropagation below to
        // keep clicks on the image from closing.
        if (lightbox.classList.contains('is-visible') && lightbox.contains(event.target)) {
            close();
        }
    });

    // Stop clicks inside the figure (image + caption) from bubbling
    // up to the backdrop-close handler.
    var figure = lightbox.querySelector('.myfeeds-preview-lightbox-figure');
    if (figure) {
        figure.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && lightbox.classList.contains('is-visible')) {
            close();
        }
    });
}());
