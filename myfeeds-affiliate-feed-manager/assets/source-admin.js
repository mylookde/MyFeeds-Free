/**
 * API source dialog on the feeds screen: connection, advertiser list,
 * scope with a live count, and the first fetch.
 */
;(function () {
    'use strict';

    var cfg = window.myfeedsSourceAdmin;
    if (!cfg || !window.wp || !window.wp.apiFetch) {
        return;
    }

    var apiFetch = window.wp.apiFetch;
    apiFetch.use(apiFetch.createNonceMiddleware(cfg.nonce));

    var modal = document.getElementById('myfeeds-source-modal');
    if (!modal) {
        return;
    }

    var el = {
        close:      document.getElementById('myfeeds-source-modal-close'),
        clientId:   document.getElementById('mf-src-client-id'),
        secret:     document.getElementById('mf-src-secret'),
        sid:        document.getElementById('mf-src-sid'),
        reveal:     document.getElementById('mf-src-reveal'),
        save:       document.getElementById('mf-src-save'),
        test:       document.getElementById('mf-src-test'),
        disconnect: document.getElementById('mf-src-disconnect'),
        feedback:   document.getElementById('mf-src-feedback'),
        feed:       document.getElementById('mf-src-feed'),
        filter:     document.getElementById('mf-src-filter'),
        list:       document.getElementById('mf-src-advertiser'),
        listNote:   document.getElementById('mf-src-list-note'),
        reload:     document.getElementById('mf-src-reload'),
        terms:      document.getElementById('mf-src-terms'),
        termsHelp:  document.getElementById('mf-src-terms-help'),
        language:   document.getElementById('mf-src-language'),
        name:       document.getElementById('mf-src-name'),
        count:      document.getElementById('mf-src-count'),
        create:     document.getElementById('mf-src-create'),
        feedFeedback: document.getElementById('mf-src-feed-feedback')
    };

    var advertisers = [];
    var listLoaded = false;
    var countTimer = null;
    var countSerial = 0;
    var lastCount = null;

    // ------------------------------------------------------------------
    // Modal
    // ------------------------------------------------------------------

    function closeModal() {
        modal.style.display = 'none';
    }

    // Delegated: the button sits in the table header, which the feeds
    // screen replaces whenever a feed is added or deleted in place. A
    // listener bound to the old node would leave the new one dead.
    document.addEventListener('click', function (e) {
        var open = e.target && e.target.closest ? e.target.closest('#myfeeds-add-source-btn') : null;
        if (!open) {
            return;
        }
        e.preventDefault();
        // flex, not block: the overlay centres its child with
        // flexbox, and an inline display:block would drop the dialog
        // into the top left corner.
        modal.style.display = 'flex';
        if (modal.getAttribute('data-ready') === '1') {
            loadAdvertisers(false);
            if (el.filter) { el.filter.focus(); }
        } else if (el.clientId) {
            el.clientId.focus();
        }
    });
    if (el.close) {
        el.close.addEventListener('click', closeModal);
    }
    modal.addEventListener('click', function (e) {
        if (e.target === modal) { closeModal(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.style.display !== 'none') { closeModal(); }
    });

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    function say(target, message, kind) {
        if (!target) {
            return;
        }
        target.textContent = message || '';
        target.className = 'myfeeds-src-feedback' + (kind ? ' is-' + kind : '');
    }

    function busy(state) {
        [el.save, el.test, el.disconnect].forEach(function (button) {
            if (button) { button.disabled = state; }
        });
    }

    function messageFrom(error) {
        if (error && error.message) {
            return error.message;
        }
        return cfg.i18n.failed;
    }

    function format(template) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return template.replace(/%(\d+\$)?s/g, function (match, pos) {
            var index = pos ? parseInt(pos, 10) - 1 : i++;
            return args[index] !== undefined ? args[index] : match;
        });
    }

    function number(n) {
        try {
            return new Intl.NumberFormat().format(n);
        } catch (e) {
            return String(n);
        }
    }

    // ------------------------------------------------------------------
    // Secret reveal (on demand, never in the page source)
    // ------------------------------------------------------------------

    if (el.reveal && el.secret) {
        var revealed = false;
        var cachedSecret = null;

        var show = function (value) {
            el.secret.value = value;
            el.secret.type = 'text';
            el.reveal.classList.add('is-on');
            el.reveal.setAttribute('aria-pressed', 'true');
            revealed = true;
        };

        el.reveal.addEventListener('click', function () {
            if (revealed) {
                el.secret.type = 'password';
                el.secret.value = '';
                el.reveal.classList.remove('is-on');
                el.reveal.setAttribute('aria-pressed', 'false');
                revealed = false;
                return;
            }
            if (el.secret.value.trim() !== '') {
                show(el.secret.value);
                return;
            }
            if (el.reveal.getAttribute('data-has-secret') !== '1') {
                el.secret.focus();
                return;
            }
            if (cachedSecret !== null) {
                show(cachedSecret);
                return;
            }
            el.reveal.disabled = true;
            apiFetch({ path: '/' + cfg.root + '/sources/rakuten/secret', method: 'POST' })
                .then(function (data) {
                    cachedSecret = (data && data.secret) ? data.secret : '';
                    show(cachedSecret);
                    el.reveal.disabled = false;
                })
                .catch(function (error) {
                    say(el.feedback, messageFrom(error), 'error');
                    el.reveal.disabled = false;
                });
        });
    }

    // ------------------------------------------------------------------
    // Connection
    // ------------------------------------------------------------------

    function save() {
        say(el.feedback, cfg.i18n.saving);
        busy(true);

        return apiFetch({
            path: '/' + cfg.root + '/sources/rakuten/connection',
            method: 'POST',
            data: {
                client_id: el.clientId ? el.clientId.value.trim() : '',
                client_secret: el.secret ? el.secret.value.trim() : '',
                sid: el.sid ? el.sid.value.trim() : ''
            }
        }).then(function () {
            if (el.secret) { el.secret.value = ''; }
            say(el.feedback, cfg.i18n.saved, 'ok');
            busy(false);
            return true;
        }).catch(function (error) {
            say(el.feedback, messageFrom(error), 'error');
            busy(false);
            return false;
        });
    }

    if (el.save) {
        el.save.addEventListener('click', save);
    }

    // Saving first means the test always checks what the form shows,
    // not a half-updated stored connection.
    if (el.test) {
        el.test.addEventListener('click', function () {
            save().then(function (ok) {
                if (!ok) { return; }
                say(el.feedback, cfg.i18n.testing);
                busy(true);

                apiFetch({ path: '/' + cfg.root + '/sources/rakuten/test', method: 'POST' })
                    .then(function (result) {
                        say(el.feedback, result.message, result.ok ? 'ok' : 'error');
                        busy(false);
                        var banner = document.getElementById('mf-src-status');
                        if (banner && result.banner) {
                            banner.outerHTML = result.banner;
                        }
                        if (result.ok) {
                            modal.setAttribute('data-ready', '1');
                            if (el.feed) { el.feed.hidden = false; }
                            listLoaded = false;
                            loadAdvertisers(true);
                        }
                    })
                    .catch(function (error) {
                        say(el.feedback, messageFrom(error), 'error');
                        busy(false);
                    });
            });
        });
    }

    if (el.disconnect) {
        el.disconnect.addEventListener('click', function () {
            if (!window.confirm(cfg.i18n.confirm)) { return; }
            busy(true);
            apiFetch({ path: '/' + cfg.root + '/sources/rakuten/connection', method: 'DELETE' })
                .then(function () { window.location.reload(); })
                .catch(function (error) {
                    say(el.feedback, messageFrom(error), 'error');
                    busy(false);
                });
        });
    }

    // ------------------------------------------------------------------
    // Advertisers
    // ------------------------------------------------------------------

    function loadAdvertisers(refresh) {
        if (!el.list || (listLoaded && !refresh)) {
            return;
        }
        if (el.listNote) { el.listNote.textContent = cfg.i18n.loading; }
        el.list.innerHTML = '';

        apiFetch({ path: '/' + cfg.root + '/sources/rakuten/advertisers' + (refresh ? '?refresh=1' : '') })
            .then(function (data) {
                advertisers = (data && data.advertisers) ? data.advertisers : [];
                listLoaded = true;
                renderList();
            })
            .catch(function (error) {
                if (el.listNote) { el.listNote.textContent = messageFrom(error); }
            });
    }

    function renderList() {
        if (!el.list) { return; }
        var needle = el.filter ? el.filter.value.trim().toLowerCase() : '';
        var selected = el.list.value;
        el.list.innerHTML = '';

        var shown = 0;
        advertisers.forEach(function (a) {
            if (needle && a.name.toLowerCase().indexOf(needle) === -1) {
                return;
            }
            var option = document.createElement('option');
            option.value = String(a.id);
            option.textContent = a.name + (a.market ? ' (' + a.market + ')' : '') + ' · ' + a.id;
            option.setAttribute('data-name', a.name);
            option.setAttribute('data-currency', a.currency || '');
            option.setAttribute('data-network', String(a.network || 0));
            if (String(a.id) === selected) { option.selected = true; }
            el.list.appendChild(option);
            shown++;
        });

        if (el.listNote) {
            if (advertisers.length === 0) {
                el.listNote.textContent = cfg.i18n.no_partners;
            } else if (shown === 0) {
                el.listNote.textContent = cfg.i18n.filter_empty;
            } else {
                el.listNote.textContent = shown + ' / ' + advertisers.length;
            }
        }
    }

    if (el.filter) {
        el.filter.addEventListener('input', renderList);
    }
    if (el.reload) {
        el.reload.addEventListener('click', function (e) {
            e.preventDefault();
            listLoaded = false;
            loadAdvertisers(true);
        });
    }

    var editing = null; // { sid, mid, name, currency, network } while editing a feed

    function selectedAdvertiser() {
        if (editing) {
            return { id: editing.mid, name: editing.advertiser, currency: editing.currency, network: editing.network };
        }
        if (!el.list || !el.list.value) {
            return null;
        }
        var option = el.list.options[el.list.selectedIndex];
        return {
            id: parseInt(el.list.value, 10),
            name: option.getAttribute('data-name') || '',
            currency: option.getAttribute('data-currency') || '',
            network: parseInt(option.getAttribute('data-network') || '0', 10)
        };
    }

    if (el.list) {
        el.list.addEventListener('change', function () {
            var a = selectedAdvertiser();
            if (a && el.name && (el.name.value.trim() === '' || el.name.getAttribute('data-auto') === '1')) {
                el.name.value = a.name;
                el.name.setAttribute('data-auto', '1');
            }
            if (mode() === 'categories') {
                if (el.terms) { el.terms.value = ''; }
                loadCategories(false);
            }
            scheduleCount();
        });
    }
    if (el.name) {
        el.name.addEventListener('input', function () {
            el.name.setAttribute('data-auto', '0');
        });
    }

    // ------------------------------------------------------------------
    // Scope and count
    // ------------------------------------------------------------------

    function mode() {
        var checked = document.querySelector('input[name="mf-src-mode"]:checked');
        return checked ? checked.value : 'all';
    }

    function scope() {
        return {
            mode: mode(),
            terms: el.terms ? el.terms.value : '',
            language: el.language ? el.language.value : ''
        };
    }

    // ------------------------------------------------------------------
    // Categories: what the advertiser actually uses, as a pick list
    // ------------------------------------------------------------------

    var catsEl = {
        wrap:   document.getElementById('mf-src-cats'),
        filter: document.getElementById('mf-src-cat-filter'),
        list:   document.getElementById('mf-src-cat-list'),
        note:   document.getElementById('mf-src-cat-note')
    };
    var categories = [];       // [{name, rows, sampled}]
    var categoriesFor = 0;     // mid the list belongs to
    var catsSource = '';       // 'feed' | 'sample' | 'both'
    var catsSampled = 0;       // products in the sample
    var catSerial = 0;

    function selectedTerms() {
        return el.terms ? el.terms.value.split(',').map(function (t) { return t.trim(); }).filter(Boolean) : [];
    }

    function renderCategories() {
        if (!catsEl.list) { return; }
        var needle = catsEl.filter ? catsEl.filter.value.trim().toLowerCase() : '';
        var chosen = selectedTerms().map(function (t) { return t.toLowerCase(); });
        catsEl.list.innerHTML = '';
        var shown = 0;
        categories.forEach(function (c) {
            if (needle && c.name.toLowerCase().indexOf(needle) === -1) { return; }
            var label = document.createElement('label');
            var box = document.createElement('input');
            box.type = 'checkbox';
            box.value = c.name;
            box.checked = chosen.indexOf(c.name.toLowerCase()) !== -1;
            box.addEventListener('change', function () {
                var terms = selectedTerms().filter(function (t) { return t.toLowerCase() !== c.name.toLowerCase(); });
                if (box.checked) { terms.push(c.name); }
                if (el.terms) { el.terms.value = terms.join(', '); }
                scheduleCount();
            });
            label.appendChild(box);
            label.appendChild(document.createTextNode(' ' + c.name + ' '));
            var count = document.createElement('span');
            count.textContent = '(' + categoryCount(c) + ')';
            label.appendChild(count);
            catsEl.list.appendChild(label);
            shown++;
        });
        if (!shown) {
            var empty = document.createElement('div');
            empty.className = 'is-empty';
            empty.textContent = categories.length ? cfg.i18n.filter_empty : cfg.i18n.cats_none;
            catsEl.list.appendChild(empty);
        }
    }

    // Rows come from the feed, sampled from Rakuten. A name with rows is
    // in the feed already; one with only a sample is there to add.
    function categoryCount(c) {
        var rows = c.rows || 0;
        var sampled = c.sampled || 0;
        if (c.rows === undefined && c.count !== undefined) {
            // A list cached before the merge existed: one number, from
            // whichever side the note names.
            if (catsSource === 'feed') { rows = c.count; } else { sampled = c.count; }
        }
        if (rows > 0) {
            return format(cfg.i18n.cat_rows, number(rows));
        }
        var text = format(cfg.i18n.cat_sampled, number(sampled), number(catsSampled));
        if (catsSource === 'both') {
            text += cfg.i18n.cat_new;
        }
        return text;
    }

    function loadCategories(refresh) {
        var a = selectedAdvertiser();
        if (!catsEl.list) { return; }
        if (!a) {
            categories = [];
            if (catsEl.note) { catsEl.note.textContent = cfg.i18n.cats_pick; }
            renderCategories();
            return;
        }
        if (a.id === categoriesFor && !refresh) {
            renderCategories();
            return;
        }
        var serial = ++catSerial;
        if (catsEl.note) { catsEl.note.textContent = cfg.i18n.cats_loading; }
        catsEl.list.innerHTML = '';

        apiFetch({ path: '/' + cfg.root + '/sources/rakuten/categories?mid=' + encodeURIComponent(a.id) + (refresh ? '&refresh=1' : '') })
            .then(function (data) {
                if (serial !== catSerial) { return; }
                categories = (data && data.categories) || [];
                categoriesFor = a.id;
                catsSource = (data && data.source) || 'sample';
                catsSampled = (data && data.sampled_rows) || 0;
                if (catsEl.note) {
                    catsEl.note.textContent = catsSource === 'feed'
                        ? cfg.i18n.cats_from_feed
                        : format(catsSource === 'both' ? cfg.i18n.cats_from_both : cfg.i18n.cats_from_sample, number(catsSampled));
                }
                renderCategories();
            })
            .catch(function (error) {
                if (serial !== catSerial) { return; }
                categories = [];
                if (catsEl.note) { catsEl.note.textContent = messageFrom(error); }
                renderCategories();
            });
    }

    if (catsEl.filter) {
        catsEl.filter.addEventListener('input', renderCategories);
    }

    function syncModeUi() {
        var m = mode();
        var needsTerms = m !== 'all';
        var isCats = m === 'categories';
        if (el.terms) { el.terms.hidden = !needsTerms || isCats; }
        if (el.termsHelp) { el.termsHelp.hidden = !needsTerms || isCats; }
        if (catsEl.wrap) { catsEl.wrap.hidden = !isCats; }
        if (isCats) { loadCategories(false); }
    }

    Array.prototype.forEach.call(document.querySelectorAll('input[name="mf-src-mode"]'), function (radio) {
        radio.addEventListener('change', function () {
            syncModeUi();
            scheduleCount();
        });
    });
    if (el.terms) {
        el.terms.addEventListener('input', scheduleCount);
    }
    if (el.language) {
        el.language.addEventListener('change', scheduleCount);
    }

    function scheduleCount() {
        if (countTimer) { window.clearTimeout(countTimer); }
        countTimer = window.setTimeout(runCount, 500);
    }

    function runCount() {
        var a = selectedAdvertiser();
        lastCount = null;
        if (el.create) { el.create.disabled = true; }

        if (!a) {
            if (el.count) { el.count.textContent = cfg.i18n.pick; el.count.className = 'myfeeds-src-count'; }
            return;
        }

        var s = scope();
        if (s.mode !== 'all' && s.terms.trim() === '') {
            if (el.count) { el.count.textContent = ''; }
            return;
        }

        var serial = ++countSerial;
        if (el.count) { el.count.textContent = cfg.i18n.counting; el.count.className = 'myfeeds-src-count'; }

        apiFetch({
            path: '/' + cfg.root + '/sources/rakuten/count',
            method: 'POST',
            data: { mid: a.id, scope: s }
        }).then(function (result) {
            if (serial !== countSerial) { return; }
            lastCount = result;
            if (!el.count) { return; }

            if (result.total === 0) {
                el.count.textContent = cfg.i18n.count_none;
                el.count.className = 'myfeeds-src-count is-warn';
                return;
            }
            if (result.over_cap) {
                el.count.textContent = format(cfg.i18n.count_over, number(result.total), number(result.cap));
                el.count.className = 'myfeeds-src-count is-warn';
            } else {
                el.count.textContent = format(cfg.i18n.count_ok, number(result.total));
                el.count.className = 'myfeeds-src-count is-ok';
            }
            if (el.create) { el.create.disabled = false; }
        }).catch(function (error) {
            if (serial !== countSerial) { return; }
            if (el.count) {
                el.count.textContent = messageFrom(error);
                el.count.className = 'myfeeds-src-count is-error';
            }
        });
    }

    // ------------------------------------------------------------------
    // Create / save
    // ------------------------------------------------------------------

    function setMode(radio) {
        Array.prototype.forEach.call(document.querySelectorAll('input[name="mf-src-mode"]'), function (r) {
            r.checked = (r.value === radio);
        });
        syncModeUi();
    }

    function leaveEditMode() {
        editing = null;
        var row = document.getElementById('mf-src-advertiser-row');
        var note = document.getElementById('mf-src-editing-note');
        var input = document.getElementById('mf-src-editing');
        if (row) { row.hidden = false; }
        if (note) { note.hidden = true; note.textContent = ''; }
        if (input) { input.value = ''; }
        if (el.create) { el.create.textContent = cfg.i18n.create_feed; }
        if (el.name) { el.name.value = ''; el.name.setAttribute('data-auto', '1'); }
        if (el.terms) { el.terms.value = ''; }
        if (el.language) { el.language.value = ''; }
        var discoveredRow = document.getElementById('mf-src-discovered-row');
        if (discoveredRow) { discoveredRow.hidden = true; }
        setMode('all');
        if (el.count) { el.count.textContent = ''; }
        say(el.feedFeedback, '');
    }

    function enterEditMode(feed) {
        editing = {
            sid: feed.stable_id,
            mid: feed.api.mid,
            advertiser: feed.api.advertiser_name || '',
            currency: feed.api.currency || '',
            network: feed.api.network_id || 0
        };
        var row = document.getElementById('mf-src-advertiser-row');
        var note = document.getElementById('mf-src-editing-note');
        var input = document.getElementById('mf-src-editing');
        if (row) { row.hidden = true; }
        if (note) {
            note.hidden = false;
            note.textContent = cfg.i18n.edit_title + ': ' + (feed.api.advertiser_name || feed.name) + ' (mid ' + feed.api.mid + ')';
        }
        if (input) { input.value = String(feed.stable_id); }
        if (el.create) { el.create.textContent = cfg.i18n.save_changes; el.create.disabled = true; }
        if (el.name) { el.name.value = feed.name || ''; el.name.setAttribute('data-auto', '0'); }
        var scope = feed.api.scope || {};
        if (el.terms) { el.terms.value = (scope.terms || []).join(', '); }
        categoriesFor = 0;
        setMode(scope.mode || 'all');
        if (el.language) { el.language.value = scope.language || ''; }
        var discoveredRow = document.getElementById('mf-src-discovered-row');
        var discovered = document.getElementById('mf-src-discovered');
        if (discoveredRow) { discoveredRow.hidden = false; }
        if (discovered) { discovered.value = (feed.discovered || []).join(', '); }
        if (el.feed) { el.feed.hidden = false; }
        modal.style.display = 'flex';
        scheduleCount();
        if (el.feed && el.feed.scrollIntoView) { el.feed.scrollIntoView({ block: 'start' }); }
    }

    if (el.create) {
        el.create.addEventListener('click', function () {
            var a = selectedAdvertiser();
            if (!a) { return; }

            el.create.disabled = true;

            if (editing) {
                say(el.feedFeedback, cfg.i18n.saving);
                var discoveredInput = document.getElementById('mf-src-discovered');
                apiFetch({
                    path: '/' + cfg.root + '/sources/feeds/' + editing.sid,
                    method: 'POST',
                    data: {
                        name: el.name ? el.name.value.trim() : '',
                        scope: scope(),
                        discovered: discoveredInput ? discoveredInput.value : ''
                    }
                }).then(function () {
                    say(el.feedFeedback, cfg.i18n.saved_feed, 'ok');
                    window.setTimeout(function () { window.location.reload(); }, 1200);
                }).catch(function (error) {
                    say(el.feedFeedback, messageFrom(error), 'error');
                    el.create.disabled = false;
                });
                return;
            }

            say(el.feedFeedback, cfg.i18n.creating);

            apiFetch({
                path: '/' + cfg.root + '/sources/feeds',
                method: 'POST',
                data: {
                    network: 'rakuten',
                    mid: a.id,
                    advertiser_name: a.name,
                    currency: a.currency,
                    network_id: a.network,
                    name: el.name ? el.name.value.trim() : a.name,
                    scope: scope()
                }
            }).then(function () {
                say(el.feedFeedback, cfg.i18n.created, 'ok');
                startPolling();
                window.setTimeout(closeModal, 1500);
            }).catch(function (error) {
                say(el.feedFeedback, messageFrom(error), 'error');
                el.create.disabled = false;
            });
        });
    }

    if (el.close) {
        el.close.addEventListener('click', function () {
            if (editing) { leaveEditMode(); }
        });
    }

    // ------------------------------------------------------------------
    // Edit an API feed: the URL dialog would clobber it
    // ------------------------------------------------------------------

    document.addEventListener('click', function (e) {
        var button = e.target && e.target.closest ? e.target.closest('.myfeeds-edit-feed-btn[data-feed-source="api"]') : null;
        if (!button) { return; }
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        var row = button.closest('tr');
        var state = row ? row.querySelector('.myfeeds-feed-api-state') : null;
        var sid = state ? parseInt(state.getAttribute('data-sid') || '0', 10) : 0;
        if (!sid) { return; }

        apiFetch({ path: '/' + cfg.root + '/sources/feeds/' + sid })
            .then(enterEditMode)
            .catch(function (error) { window.alert(messageFrom(error)); });
    }, true);

    // ------------------------------------------------------------------
    // Refresh now
    // ------------------------------------------------------------------

    document.addEventListener('click', function (e) {
        var button = e.target && e.target.closest ? e.target.closest('.myfeeds-source-refresh-btn') : null;
        if (!button) { return; }
        e.preventDefault();

        var sid = parseInt(button.getAttribute('data-sid') || '0', 10);
        if (!sid) { return; }

        button.disabled = true;
        apiFetch({ path: '/' + cfg.root + '/sources/feeds/' + sid + '/refresh', method: 'POST' })
            .then(function () { startPolling(); })
            .catch(function (error) {
                window.alert(messageFrom(error));
                button.disabled = false;
            });
    });

    // ------------------------------------------------------------------
    // Progress
    // ------------------------------------------------------------------

    var panel = document.getElementById('myfeeds-source-progress');
    var pollTimer = null;
    var pendingSeen = {};

    // The panel is rendered in the page footer; it belongs above the table.
    if (panel) {
        var table = document.querySelector('.myfeeds-feeds-table');
        if (table && table.parentNode) {
            table.parentNode.insertBefore(panel, table);
        }
    }

    function lineFor(name, state) {
        if (!state) {
            return { text: name + ': …', kind: '' };
        }
        if (state.fetching) {
            if (state.wait > 0) {
                return { text: format(cfg.i18n.progress_waiting, name, String(state.wait)), kind: '' };
            }
            return { text: format(cfg.i18n.progress_fetching, name, number(state.fetched), String(state.query), String(state.queries)), kind: '' };
        }
        if (state.status === 'failed') {
            return { text: format(cfg.i18n.progress_failed, name, state.error || ''), kind: 'is-error' };
        }
        return { text: format(cfg.i18n.progress_done, name, number(state.products || state.seen)), kind: 'is-ok' };
    }

    function renderProgress(overview) {
        if (!panel) { return false; }
        var list = panel.querySelector('ul');
        list.innerHTML = '';
        var active = false;
        var finishedPending = false;

        (overview.pending || []).forEach(function (p) {
            pendingSeen[p.token] = true;
            var line = lineFor(p.name, p.state);
            var li = document.createElement('li');
            li.textContent = line.text;
            li.className = line.kind;
            list.appendChild(li);
            if (p.state && p.state.fetching) { active = true; }
        });

        Object.keys(overview.runs || {}).forEach(function (key) {
            var run = overview.runs[key];
            if (!run.fetching && run.status !== 'failed') {
                return; // a finished refresh is told by the table row
            }
            var line = lineFor(run.name, run);
            var li = document.createElement('li');
            li.textContent = line.text;
            li.className = line.kind;
            list.appendChild(li);
            if (run.fetching) { active = true; }
        });

        // A pending source that vanished from the list has become a feed.
        Object.keys(pendingSeen).forEach(function (token) {
            var still = (overview.pending || []).some(function (p) { return p.token === token; });
            if (!still) { finishedPending = true; }
        });

        panel.hidden = list.children.length === 0;
        if (finishedPending) {
            window.location.reload();
        }
        return active;
    }

    function poll() {
        apiFetch({ path: '/' + cfg.root + '/sources/overview' })
            .then(function (overview) {
                var active = renderProgress(overview);
                var keys = [];
                (overview.pending || []).forEach(function (p) { if (p.state && p.state.fetching) { keys.push(p.token); } });
                Object.keys(overview.runs || {}).forEach(function (k) { if (overview.runs[k].fetching) { keys.push(k); } });
                // Asking a run for its state also advances it when Action
                // Scheduler is not moving on its own.
                keys.forEach(function (key) {
                    apiFetch({ path: '/' + cfg.root + '/sources/runs/' + encodeURIComponent(key) }).catch(function () {});
                });
                if (active) {
                    pollTimer = window.setTimeout(poll, 4000);
                } else {
                    pollTimer = null;
                    if (keys.length === 0 && Object.keys(pendingSeen).length === 0) {
                        // Nothing was running; a completed refresh needs the table redrawn.
                        var rows = document.querySelectorAll('.myfeeds-source-refresh-btn[disabled]');
                        if (rows.length) { window.location.reload(); }
                    }
                }
            })
            .catch(function () {
                pollTimer = window.setTimeout(poll, 8000);
            });
    }

    function startPolling() {
        if (pollTimer) { return; }
        poll();
    }

    if (panel) {
        startPolling();
    }
})();
