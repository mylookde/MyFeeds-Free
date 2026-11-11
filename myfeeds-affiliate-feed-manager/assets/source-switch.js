// MyFeeds "Change source": the window that says what changing the site's
// product source takes out of its posts, and does it; and "Remove them" on
// connection details no source uses. Every sentence comes from the server
// (MyFeeds_Source_Switch); this file knows none. The buttons sit in parts
// of the screen that are swapped in place, so the clicks are caught on the
// document.

;(function () {
    var cfg = window.myfeedsSourceSwitch || {};
    var i18n = cfg.i18n || {};
    var target = '';
    var busy = false;

    function el(id) {
        return document.getElementById(id);
    }

    function post(action, data) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', cfg.nonce || '');
        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });
        return window.fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        });
    }

    function say(text) {
        var box = el('myfeeds-change-source-text');
        if (!box) {
            return;
        }
        box.textContent = '';
        var p = document.createElement('p');
        p.textContent = text;
        box.appendChild(p);
    }

    function failed(response) {
        say(response && response.data && response.data.message ? response.data.message : (i18n.serverError || ''));
    }

    function open(value) {
        var modal = el('myfeeds-change-source-modal');
        var confirm = el('myfeeds-change-source-confirm');
        if (!modal || !confirm) {
            return;
        }
        target = value || '';
        confirm.disabled = true;
        say(i18n.counting || '');
        modal.style.display = 'flex';
        post('myfeeds_source_preview', { target: target }).then(function (response) {
            if (!response || !response.success) {
                failed(response);
                return;
            }
            el('myfeeds-change-source-text').innerHTML = response.data.html;
            confirm.textContent = response.data.confirm;
            confirm.disabled = false;
        }, function () {
            failed(null);
        });
    }

    function close() {
        var modal = el('myfeeds-change-source-modal');
        if (modal && !busy) {
            modal.style.display = 'none';
        }
    }

    function change() {
        var confirm = el('myfeeds-change-source-confirm');
        if (busy || !confirm || confirm.disabled) {
            return;
        }
        busy = true;
        confirm.disabled = true;
        post('myfeeds_source_change', { target: target }).then(function (response) {
            if (response && response.success && response.data.reload) {
                window.location.href = response.data.redirect || cfg.pageUrl;
                return;
            }
            busy = false;
            failed(response);
        }, function () {
            busy = false;
            failed(null);
        });
    }

    function forget(button) {
        if (busy || button.disabled) {
            return;
        }
        var question = button.getAttribute('data-confirm') || '';
        if (question !== '' && !window.confirm(question)) {
            return;
        }
        busy = true;
        button.disabled = true;
        post('myfeeds_forget_connection', { connection: button.getAttribute('data-connection') || '' }).then(function (response) {
            if (response && response.success && response.data.reload) {
                window.location.href = response.data.redirect || cfg.pageUrl;
                return;
            }
            busy = false;
            button.disabled = false;
            window.alert(response && response.data && response.data.message ? response.data.message : (i18n.serverError || ''));
        }, function () {
            busy = false;
            button.disabled = false;
            window.alert(i18n.serverError || '');
        });
    }

    document.addEventListener('click', function (event) {
        var hit = event.target && event.target.closest ? event.target : null;
        if (!hit) {
            return;
        }
        var use = hit.closest('.myfeeds-use-source');
        var unused = hit.closest('.myfeeds-forget-connection');
        if (unused) {
            event.preventDefault();
            forget(unused);
        } else if (hit.closest('#myfeeds-change-source-btn') || use) {
            event.preventDefault();
            open(use ? use.getAttribute('data-target') : '');
        } else if (hit.closest('#myfeeds-change-source-confirm')) {
            event.preventDefault();
            change();
        } else if (hit.closest('[data-myfeeds-source-cancel]') || hit.id === 'myfeeds-change-source-modal') {
            event.preventDefault();
            close();
        }
    });
})();
