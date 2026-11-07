/**
 * The Amazon window on the Feeds screen: open, close, the badge, Save.
 *
 * The window holds what an Amazon link needs (class-amazon-admin.php):
 * the Amazon site, the partner tag, visitors abroad, the badge. Save sends
 * those and nothing else - a field that is not on the page is not sent.
 * Whatever else the window holds brings its own script and hangs on
 * window.myfeedsAmazonLinks:
 *   onOpen(fn)    fn() each time the window opens (now, if it is open)
 *   addData(fn)   fn(data) before Save sends, to add its own fields
 *   onSaved(fn)   fn() after the server accepted a Save
 *   addButton(el) a button to disable while a request runs
 *   save()        Promise of true once saved, false if refused
 *   say(text, kind), busy(state)
 *
 * Shared byte for byte by both plugins.
 */
;(function () {
    'use strict';

    var config = window.myfeedsAmazonAdmin;
    if (!config || !window.wp || !window.wp.apiFetch) {
        return;
    }

    var apiFetch = window.wp.apiFetch;
    apiFetch.use(apiFetch.createNonceMiddleware(config.nonce));

    var openers = [];
    var extras = [];
    var saved = [];

    var modal = document.getElementById('myfeeds-amazon-modal');
    var isOpen = function () { return !!modal && modal.style.display !== 'none'; };

    var el = {
        marketplace: document.getElementById('mf-amz-marketplace'),
        tag:         document.getElementById('mf-amz-tag'),
        save:        document.getElementById('mf-amz-save'),
        feedback:    document.getElementById('mf-amz-feedback'),
        badge:       document.getElementById('mf-amz-badge'),
        keepMarket:  document.getElementById('mf-amz-keep-market'),
        badgePick:   document.getElementById('mf-amz-badge-pick'),
        badgeClear:  document.getElementById('mf-amz-badge-clear'),
        badgePreview: document.getElementById('mf-amz-badge-preview')
    };
    var buttons = [el.save];

    if (modal) {
        var closeBtn = document.getElementById('myfeeds-amazon-modal-close');

        var closeModal = function () { modal.style.display = 'none'; };

        var openModal = function () {
            // flex, not block: the overlay centres its child with
            // flexbox, and an inline display:block would beat the
            // stylesheet and drop the dialog into the top left corner.
            modal.style.display = 'flex';
            openers.forEach(function (fn) { fn(); });
            if (el.tag) { el.tag.focus(); }
        };

        // Delegated: the button sits in the table header, which the feeds
        // screen replaces whenever a feed is added or deleted in place. A
        // listener bound to the old node would leave the new one dead.
        document.addEventListener('click', function (e) {
            var openBtn = e.target && e.target.closest ? e.target.closest('#myfeeds-add-amazon-btn') : null;
            if (!openBtn) { return; }
            e.preventDefault();
            openModal();
        });

        // "Open Amazon settings" in the product picker and the Look
        // editor leads here with this anchor. Nothing on the page has
        // that id, so the link used to end on the feed list and left the
        // author to find the button - for a new site the first thing
        // anyone is told to do ("Save your Amazon partner tag first").
        if (config.anchor && window.location.hash === '#' + config.anchor) {
            openModal();
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }
        modal.addEventListener('click', function (e) {
            if (e.target === modal) { closeModal(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.style.display !== 'none') { closeModal(); }
        });
    }

    // The badge is an attachment id; Save sends it with the rest.
    if (el.badgePick && window.wp && wp.media) {
        var frame = null;
        el.badgePick.addEventListener('click', function () {
            if (!frame) {
                frame = wp.media({ title: config.i18n.badge || '', library: { type: 'image' }, multiple: false });
                frame.on('select', function () {
                    var a = frame.state().get('selection').first().toJSON();
                    var src = (a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url);
                    el.badge.value = String(a.id);
                    el.badgePreview.innerHTML = '';
                    var img = document.createElement('img');
                    img.src = src;
                    img.alt = '';
                    img.style.width = '120px';
                    img.style.height = 'auto';
                    el.badgePreview.appendChild(img);
                    if (el.badgeClear) el.badgeClear.style.display = '';
                });
            }
            frame.open();
        });
    }
    if (el.badgeClear) {
        el.badgeClear.addEventListener('click', function () {
            el.badge.value = '0';
            el.badgePreview.innerHTML = '';
            el.badgeClear.style.display = 'none';
        });
    }

    function say(message, kind) {
        if (!el.feedback) {
            return;
        }
        el.feedback.textContent = message || '';
        el.feedback.className = 'myfeeds-amz-feedback' + (kind ? ' is-' + kind : '');
    }

    function busy(state) {
        buttons.forEach(function (button) {
            if (button) {
                button.disabled = state;
            }
        });
    }

    function messageFrom(error) {
        if (error && error.message) {
            return error.message;
        }
        return config.i18n.failed;
    }

    // Each Amazon site has its own tag. The field shows the one saved for
    // the site it is switched to, or nothing: left as it was, a Save after
    // a look at another site stored the first site's tag under that one,
    // and every link there carried a tag Amazon does not credit there.
    var tags = {};
    Object.keys(config.tags || {}).forEach(function (key) {
        tags[key] = String(config.tags[key]);
    });
    if (el.marketplace && el.tag) {
        el.marketplace.addEventListener('change', function () {
            el.tag.value = Object.prototype.hasOwnProperty.call(tags, el.marketplace.value) ? tags[el.marketplace.value] : '';
        });
    }

    function save() {
        say(config.i18n.saving);
        busy(true);

        var data = {
            marketplace: el.marketplace ? el.marketplace.value : '',
            partner_tag: el.tag ? el.tag.value.trim() : '',
            badge_id: el.badge ? parseInt(el.badge.value, 10) || 0 : 0,
            keep_market: el.keepMarket ? (el.keepMarket.checked ? 1 : 0) : 1
        };
        extras.forEach(function (fn) { fn(data); });

        return apiFetch({
            path: '/' + config.root + '/amazon/connection',
            method: 'POST',
            data: data
        }).then(function () {
            if (data.partner_tag) {
                tags[data.marketplace] = data.partner_tag;
            } else {
                delete tags[data.marketplace];
            }
            saved.forEach(function (fn) { fn(); });
            say(config.i18n.saved, 'ok');
            busy(false);
            return true;
        }).catch(function (error) {
            say(messageFrom(error), 'error');
            busy(false);
            return false;
        });
    }

    if (el.save) {
        el.save.addEventListener('click', save);
    }

    window.myfeedsAmazonLinks = {
        onOpen: function (fn) {
            openers.push(fn);
            if (isOpen()) { fn(); }
        },
        addData: function (fn) { extras.push(fn); },
        onSaved: function (fn) { saved.push(fn); },
        addButton: function (button) { if (button) { buttons.push(button); } },
        save: save,
        say: say,
        busy: busy,
        messageFrom: messageFrom
    };
})();
