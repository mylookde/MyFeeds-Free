/**
 * Keeps Amazon prices current on cached pages.
 *
 * Server-rendered prices freeze inside WP Rocket, LiteSpeed and friends:
 * those serve stored HTML without running PHP, so the price and its
 * timestamp would drift apart. The Associates licence caps how stale a
 * displayed price may be, so the value is re-read here on every real view.
 */
;(function () {
    'use strict';

    var config = window.myfeedsAmazonLive;
    if (!config || !config.endpoint || !config.ids || !config.ids.length) {
        return;
    }

    var CHUNK = 50;
    var answered = {};

    function stampsFor(id) {
        return document.querySelectorAll('.myfeeds-amazon-stamp[data-myfeeds-amazon-id="' + cssEscape(id) + '"]');
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/["\\]/g, '\\$&');
    }

    // What holds the price next to a stamp: a product card, or a Look's
    // open point and list row (class-look-block.php). Each names its
    // price parts its own way; the stamp is the same everywhere.
    var HOLDERS = '.myfeeds-product-card, .myfeeds-look__card, .myfeeds-look__row';
    var PRICE_BOX = '.myfeeds-product-price, .myfeeds-look__price';
    var CURRENT = '.myfeeds-current-price, .myfeeds-look__now';
    var OLD = '.myfeeds-old-price, .myfeeds-look__was';

    function cardOf(stamp) {
        return stamp.closest ? stamp.closest(HOLDERS) : null;
    }

    // The theme's stylesheets set these with !important, so `hidden` loses.
    function hide(element) {
        element.style.setProperty('display', 'none', 'important');
    }

    function applyItem(item) {
        var stamps = stampsFor(item.id);

        answered[item.id] = true;

        Array.prototype.forEach.call(stamps, function (stamp) {
            var card = cardOf(stamp);

            if (card) {
                var priceBox = card.querySelector(PRICE_BOX);

                // Amazon no longer names a price. The stored one would now
                // stand beside a fresh stamp and read as today's.
                if (priceBox && !item.price_display) {
                    hide(priceBox);
                }

                if (priceBox && item.price_display) {
                    var current = priceBox.querySelector(CURRENT);
                    var old = priceBox.querySelector(OLD);

                    if (current) {
                        current.textContent = item.price_display;
                    }
                    if (old) {
                        if (item.old_price_display) {
                            old.textContent = item.old_price_display;
                            old.hidden = false;
                        } else {
                            old.hidden = true;
                        }
                    }
                }

                // An out-of-stock Amazon listing still links to a valid
                // page, so the card stays clickable and only says so.
                card.classList.toggle('myfeeds-out-of-stock', !item.in_stock);
            }

            var time = stamp.querySelector('.myfeeds-amazon-stamp__time');
            if (time && item.stamp) {
                time.textContent = item.stamp;
            }

            stamp.classList.remove('is-updating');
        });
    }

    function markUpdating() {
        config.ids.forEach(function (id) {
            Array.prototype.forEach.call(stampsFor(id), function (stamp) {
                stamp.classList.add('is-updating');
            });
        });
    }

    function clearUpdating() {
        Array.prototype.forEach.call(
            document.querySelectorAll('.myfeeds-amazon-stamp.is-updating'),
            function (stamp) { stamp.classList.remove('is-updating'); }
        );
    }

    // Past Amazon's ceiling a stored price may not be shown, and a page
    // cache can hold one far longer. Nothing answered for it, so it goes:
    // the card stays, and still links to Amazon.
    function dropExpired() {
        var now = Date.now() / 1000;

        Array.prototype.forEach.call(
            document.querySelectorAll('.myfeeds-amazon-stamp[data-myfeeds-amazon-at]'),
            function (stamp) {
                var at = parseInt(stamp.getAttribute('data-myfeeds-amazon-at'), 10);

                if (answered[stamp.getAttribute('data-myfeeds-amazon-id')] || !at || now - at < config.maxAge) {
                    return;
                }

                var card = cardOf(stamp);
                var priceBox = card ? card.querySelector(PRICE_BOX) : null;

                if (priceBox) {
                    hide(priceBox);
                }
                hide(stamp);
            }
        );
    }

    function fetchChunk(ids) {
        var url = config.endpoint + (config.endpoint.indexOf('?') === -1 ? '?' : '&') +
            'ids=' + encodeURIComponent(ids.join(','));

        return fetch(url, { credentials: 'omit' })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (data && data.items) {
                    data.items.forEach(applyItem);
                }
            })
            .catch(function () {
                // A failed refresh leaves the server-rendered price and its
                // own honest timestamp in place, until dropExpired() says no.
            });
    }

    function run() {
        markUpdating();

        var chunks = [];
        for (var i = 0; i < config.ids.length; i += CHUNK) {
            chunks.push(config.ids.slice(i, i + CHUNK));
        }

        Promise.all(chunks.map(fetchChunk)).then(function () {
            clearUpdating();
            dropExpired();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
