/**
 * Amazon's API in the Amazon window: the credentials Save sends, the test,
 * disconnecting, reading the stored secret back, and the request counter.
 *
 * The window, its fields for a link and its Save are amazon-admin.js. This
 * script is loaded only where Amazon's API runs or may be set up now
 * (class-amazon-api-admin.php), after that one, and hangs on
 * window.myfeedsAmazonLinks. Where the API runs (apiOpen) its part is open
 * however the window opens. Elsewhere only a door opens it (mode 'api'),
 * and its Save makes Amazon's API the site's product source
 * (source_choice); "+ Add Amazon" then shows and sends what a link needs,
 * and nothing of the credentials the window holds.
 */
;(function () {
    'use strict';

    var config = window.myfeedsAmazonApiAdmin;
    var links = window.myfeedsAmazonLinks;
    if (!config || !links || !window.wp || !window.wp.apiFetch) {
        return;
    }

    var apiFetch = window.wp.apiFetch;
    var i18n = config.i18n || {};
    var mode = '';
    var testing = false;
    var modal = document.getElementById('myfeeds-amazon-modal');

    function apiShown() {
        return !!config.apiOpen || mode === 'api';
    }

    // A Save from the door: Amazon's API becomes the site's product source.
    function choosing() {
        return !config.apiOpen && mode === 'api';
    }

    var el = {
        marketplace: document.getElementById('mf-amz-marketplace'),
        id:          document.getElementById('mf-amz-id'),
        secret:      document.getElementById('mf-amz-secret'),
        version:     document.getElementById('mf-amz-version'),
        reveal:      document.getElementById('mf-amz-reveal'),
        test:        document.getElementById('mf-amz-test'),
        disconnect:  document.getElementById('mf-amz-disconnect')
    };
    links.addButton(el.test);
    links.addButton(el.disconnect);

    /**
     * The request counter is the number a user checks before doing
     * anything expensive, so it is re-read whenever the dialog opens and
     * after every call that could have spent from the budget.
     */
    function refreshBudget() {
        if (!document.getElementById('mf-amz-budget')) {
            return Promise.resolve();
        }
        return apiFetch({ path: '/' + config.root + '/amazon/status' })
            .then(function (status) {
                if (!status || !status.budget) {
                    return;
                }

                var value = document.getElementById('mf-amz-budget');
                var cached = document.getElementById('mf-amz-cached');
                var bar = document.querySelector('.myfeeds-amz-budget__bar > span');

                if (value) {
                    value.innerHTML = '';
                    value.appendChild(document.createTextNode(String(status.budget.used)));
                    var total = document.createElement('span');
                    total.className = 'myfeeds-amz-budget__total';
                    total.textContent = ' / ' + status.budget.total;
                    value.appendChild(total);
                }
                if (cached) {
                    cached.textContent = String(status.cached_rows);
                }
                if (bar && status.budget.total > 0) {
                    bar.style.width = Math.min(100, Math.round(status.budget.used / status.budget.total * 100)) + '%';
                }
            })
            .catch(function () {
                // A stale counter is not worth an error message.
            });
    }
    links.onOpen(function (how) {
        mode = how || '';
        if (modal && modal.classList) {
            if (apiShown()) {
                modal.classList.add('is-api');
            } else {
                modal.classList.remove('is-api');
            }
        }
        if (apiShown()) {
            refreshBudget();
        }
    });

    // The version Amazon assigns follows the store's region, so switching
    // the site prefills the value the user is about to be handed anyway.
    if (el.marketplace && el.version) {
        el.marketplace.addEventListener('change', function () {
            var suggested = config.versions ? config.versions[el.marketplace.value] : '';
            if (suggested) {
                el.version.value = suggested;
            }
            links.say('');
        });
    }

    // A field that is not on the page is not sent. Sent empty, the server
    // would read "no credential id" as "delete it". Nor is a field the
    // window does not show: it comes prefilled with what is stored.
    links.addData(function (data) {
        if (!apiShown()) {
            return;
        }
        if (choosing()) {
            data.source_choice = 'amazon_api';
        }
        if (el.id) {
            data.credential_id = el.id.value.trim();
        }
        if (el.secret) {
            data.credential_secret = el.secret.value.trim();
        }
        if (el.version) {
            data.credential_version = el.version.value.trim();
        }
    });
    links.onSaved(function (answer) {
        if (el.secret) {
            el.secret.value = '';
        }
        // Set up, so it is the source now: the Feeds screen shows it so.
        if (choosing() && !testing && answer && answer.connected) {
            window.location.reload();
        }
    });

    /**
     * Reveal pulls the secret on demand instead of the page carrying it.
     * Amazon shows a secret once, so being able to read it back here is
     * the difference between losing it and not; fetching it only when
     * asked keeps it out of the page source, screenshots and screen
     * shares in the meantime.
     */
    if (el.reveal && el.secret) {
        var revealed = false;
        var cached = null;

        var show = function (value) {
            if (value !== undefined) {
                el.secret.value = value;
            }
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

            // A secret the user has just typed is already on screen.
            if (el.secret.value.trim() !== '') {
                show();
                return;
            }

            // Nothing typed and nothing stored: there is simply nothing
            // to show, and asking the server would only produce a 404.
            if (el.reveal.getAttribute('data-has-secret') !== '1') {
                el.secret.focus();
                return;
            }

            if (cached !== null) {
                show(cached);
                return;
            }

            el.reveal.disabled = true;
            apiFetch({ path: '/' + config.root + '/amazon/secret', method: 'POST' })
                .then(function (data) {
                    cached = (data && data.secret) ? data.secret : '';
                    show(cached);
                    el.reveal.disabled = false;
                })
                .catch(function (error) {
                    links.say(links.messageFrom(error), 'error');
                    el.reveal.disabled = false;
                });
        });
    }

    // Saving first means the test always checks what the form shows,
    // not a half-updated stored connection.
    if (el.test) {
        el.test.addEventListener('click', function () {
            testing = true;
            links.save().then(function (ok) {
                if (!ok) {
                    testing = false;
                    return;
                }

                links.say(i18n.testing);
                links.busy(true);

                apiFetch({
                    path: '/' + config.root + '/amazon/test',
                    method: 'POST'
                }).then(function (result) {
                    links.say(result.message, result.ok ? 'ok' : 'error');
                    links.busy(false);
                    // Swap the banner for the one the server just rendered,
                    // otherwise it keeps showing the state from page load
                    // and contradicts the line we just wrote.
                    var banner = document.getElementById('mf-amz-status');
                    if (banner && result.banner) {
                        banner.outerHTML = result.banner;
                    }
                    // A test spends from the day's allowance either way,
                    // so the counter is re-read on failure too.
                    refreshBudget();
                    // From the door Amazon's API is the source now, whatever
                    // Amazon said: the Feeds screen shows it so.
                    if (result.ok || choosing()) {
                        window.setTimeout(function () { window.location.reload(); }, 1200);
                    }
                    testing = false;
                }).catch(function (error) {
                    links.say(links.messageFrom(error), 'error');
                    links.busy(false);
                    refreshBudget();
                    testing = false;
                });
            });
        });
    }

    if (el.disconnect) {
        el.disconnect.addEventListener('click', function () {
            if (!window.confirm(i18n.confirm)) {
                return;
            }

            links.busy(true);

            apiFetch({
                path: '/' + config.root + '/amazon/connection',
                method: 'DELETE'
            }).then(function () {
                window.location.reload();
            }).catch(function (error) {
                links.say(links.messageFrom(error), 'error');
                links.busy(false);
            });
        });
    }
})();
