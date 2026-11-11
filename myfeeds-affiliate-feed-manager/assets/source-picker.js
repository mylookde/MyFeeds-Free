/*
 * Rakuten in the product picker, where Rakuten is this site's product
 * source (MyFeeds_Source_Modules::editor_handles() registers this file
 * there and nowhere else): the line under the picker's search, which puts
 * every search to Rakuten as well, its progress in the results window,
 * and the Look block's fetch of a shop address no feed holds yet.
 *
 * Hangs on the shared sidebar's myfeeds.picker.extras
 * (assets/picker-inspector.js) and on the Look block's
 * myfeeds.look.notInFeed (assets/look-block.js); draws no block of its
 * own. Loaded after picker-inspector.js. Shared byte for byte by both
 * plugins.
 */
;(function () {
  "use strict";

  if (!window.wp || !wp.hooks || !wp.element) {
    return;
  }

  var addFilter = wp.hooks.addFilter;
  var Fragment = wp.element.Fragment;
  var createElement = wp.element.createElement;
  var createPortal = wp.element.createPortal;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var useRef = wp.element.useRef;
  var apiFetch = wp.apiFetch;

  // Before the first addFilter: wp.hooks drops a filter whose namespace
  // is not a string yet, and says nothing.
  var NS = 'myfeeds/source-picker';

  // ---------------------------------------------------------------------------
  // Rakuten inside the picker search.
  //
  // An API feed is a snapshot of what Rakuten's Product Search returned,
  // and for a big advertiser that is a slice of the catalogue (the API
  // stops at 5,000 per query). The picker search only sees the slice.
  // So every search the author runs is also put to Rakuten: the matching
  // products are appended to the feed's snapshot, imported, and the term
  // is remembered so the nightly refresh keeps them current. When that
  // is done the picker search is run again and the new products are in
  // the results like any other.
  //
  // The editor bundle owns the search box and the request; this code only
  // listens to the request and renders one line under the search actions
  // through a portal.
  // ---------------------------------------------------------------------------
  function getSourcesConfig() {
    return (window.myfeedsData && window.myfeedsData.sources) || { allowed: false };
  }

  var searchListeners = [];
  var lastSearch = null; // { query, total, at }

  function isPickerSearch(options) {
    var path = options && typeof options.path === 'string' ? options.path : '';
    return isPickerSearchUrl(path);
  }

  // Matches the path the block's search builds, whether it arrives as a
  // REST path ("/myfeeds/v1/products?...") or as a full URL - the editor
  // bundle sends the second kind.
  function isPickerSearchUrl(url) {
    url = typeof url === 'string' ? url : '';
    return url.indexOf('myfeeds/v1/products?') !== -1 && url.indexOf('with_meta') !== -1;
  }

  function announceSearch(query, total) {
    if (!query) {
      return;
    }
    lastSearch = { query: query, total: typeof total === 'number' ? total : null, at: Date.now() };
    searchListeners.forEach(function (fn) { fn(lastSearch); });
  }

  function queryFromPath(path) {
    var qs = path.split('?')[1] || '';
    var q = '';
    qs.split('&').forEach(function (pair) {
      var kv = pair.split('=');
      if (decodeURIComponent(kv[0]) === 'q') {
        q = decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
      }
    });
    return q.trim();
  }

  if (getSourcesConfig().allowed && apiFetch && typeof apiFetch.use === 'function') {
    apiFetch.use(function (options, next) {
      var result = next(options);
      if (isPickerSearch(options) && result && typeof result.then === 'function') {
        result.then(function (data) {
          announceSearch(queryFromPath(options.path), data && data.total);
          return data;
        }, function () {});
      }
      return result;
    });
  }

  // The block's search does not go through apiFetch. It calls
  // window.fetch directly - measured 2026-09-09, the string "apiFetch"
  // appears zero times in the editor bundle - so the middleware above
  // never saw a single search: no pull was ever started on its own, and
  // the progress bar in the results window had nothing to show. That is
  // why the bar "was still missing" after three rounds of moving it.
  //
  // The wrapper never touches the response. The only thing read is the
  // URL, so a search behaves exactly as before whether or not anything
  // here works.
  if (getSourcesConfig().allowed && typeof window.fetch === 'function' && !window.myfeedsRktFetchHooked) {
    window.myfeedsRktFetchHooked = true;
    var nativeFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
      var url = '';
      try {
        url = typeof input === 'string' ? input : ((input && input.url) || '');
      } catch (e) {
        url = '';
      }
      var out = nativeFetch(input, init);
      if (isPickerSearchUrl(url) && out && typeof out.then === 'function') {
        var query = queryFromPath(url);
        if (query) {
          out.then(function (response) { announceSearch(query); return response; }, function () {});
        }
      }
      return out;
    };
  }

  function rakutenAutoEnabled() {
    try {
      return window.localStorage.getItem('myfeedsRakutenAuto') !== '0';
    } catch (e) {
      return true;
    }
  }

  function setRakutenAuto(on) {
    try {
      window.localStorage.setItem('myfeedsRakutenAuto', on ? '1' : '0');
    } catch (e) {
      // no storage, no memory - the default applies
    }
  }

  // Keep one anchor element right after the picker's search actions so
  // the line renders inside the search section. The editor bundle may
  // re-render that section; the observer puts the anchor back.
  function useSearchAnchor(wrapperRef) {
    var anchorState = useState(null);
    var anchor = anchorState[0];
    var setAnchor = anchorState[1];

    useEffect(function () {
      var container = wrapperRef.current;
      if (!container) return;

      var raf = null;
      function sync() {
        raf = null;
        var section = container.querySelector('.myfeeds-search-section');
        if (!section) {
          setAnchor(null);
          return;
        }
        var el = section.querySelector(':scope > .myfeeds-rkt-anchor');
        if (!el) {
          el = document.createElement('div');
          el.className = 'myfeeds-rkt-anchor';
          var actions = section.querySelector('.myfeeds-search-actions');
          if (actions && actions.parentNode === section) {
            actions.insertAdjacentElement('afterend', el);
          } else {
            section.appendChild(el);
          }
        }
        setAnchor(function (current) { return current === el ? current : el; });
      }

      sync();
      var obs = new MutationObserver(function () {
        if (raf) return;
        raf = (window.requestAnimationFrame || function (fn) { return setTimeout(fn, 16); })(sync);
      });
      obs.observe(container, { childList: true, subtree: true });

      return function () {
        obs.disconnect();
        if (raf) (window.cancelAnimationFrame || clearTimeout)(raf);
      };
    }, []);

    return anchor;
  }

  // One post can hold several picker blocks, and each one mounts this
  // line. Every mounted line heard the same search and started its own
  // pull: two blocks and two Rakuten feeds meant FOUR discover calls for
  // one search, against an API budgeted at a hundred calls a minute -
  // and two progress bars side by side in the modal, which is what made
  // it visible. One instance owns the run; the others show their switch
  // and stay out of it.
  var rktOwnerToken = null;
  var rktWaiting = [];

  function useOwnsRakutenRun() {
    var tokenRef = useRef(null);
    if (tokenRef.current === null) tokenRef.current = {};
    var ownerState = useState(false);
    var isOwner = ownerState[0];
    var setIsOwner = ownerState[1];

    useEffect(function () {
      var token = tokenRef.current;
      function claim() {
        if (rktOwnerToken === null) {
          rktOwnerToken = token;
          setIsOwner(true);
          return true;
        }
        return rktOwnerToken === token;
      }
      var waiter = null;
      if (!claim()) {
        waiter = function () { claim(); };
        rktWaiting.push(waiter);
      }
      return function () {
        if (rktOwnerToken === token) {
          rktOwnerToken = null;
          setIsOwner(false);
          // Hand it on, so closing one block does not leave the feature
          // dead for the blocks that are still there.
          var next = rktWaiting.shift();
          if (next) next();
        } else if (waiter) {
          rktWaiting = rktWaiting.filter(function (fn) { return fn !== waiter; });
        }
      };
    }, []);

    return isOwner;
  }

  // The results modal is the author's workbench: a selection in it is
  // work, and a search wipes it. So the re-run only happens while the
  // modal is open AND nothing is ticked - Marlon closed it, had seven
  // products chosen, and got an empty modal back.
  function pickerModalActions() {
    return document.querySelector('.myfeeds-spacious-modal .myfeeds-modal-actions');
  }

  function pickerModalIsOpen() {
    return !!pickerModalActions();
  }

  function pickerHasSelection() {
    var bar = pickerModalActions();
    if (!bar) return false;
    var buttons = bar.querySelectorAll('button, .components-button');
    for (var i = 0; i < buttons.length; i++) {
      var m = /(\d+)\s+products?/i.exec(buttons[i].textContent || '');
      if (m) return parseInt(m[1], 10) > 0;
    }
    return false;
  }

  function rerunPickerSearch(wrapper, query) {
    if (!wrapper) return false;
    var input = wrapper.querySelector('.myfeeds-search-section input[type="text"]');
    if (!input || input.value.trim() !== query) return false;
    var button = wrapper.querySelector('.myfeeds-search-actions button');
    if (!button) return false;
    button.click();
    return true;
  }

  // Whether the open search window shows another source than the site's
  // feeds - Amazon, in the picker's Amazon tab or after a Look's switch.
  // The window's action bar says which (data-myfeeds-source, the editor
  // bundle's ProductSearchModal). Rakuten belongs to the feeds: no pull and no bar
  // while Amazon is searched (Marlon, 28.10.).
  function windowShowsOtherSource(bar) {
    bar = bar || document.querySelector('.myfeeds-spacious-modal .myfeeds-modal-actions');
    return !!bar && (bar.getAttribute('data-myfeeds-source') || 'feeds') !== 'feeds';
  }

  // The results modal is a WordPress Modal, portaled to <body>. Its action
  // bar ("Use N Products", "Cancel") gets an anchor for the Rakuten
  // progress; the observer follows the modal opening and closing, and its
  // switch to another source, where the anchor goes.
  function useModalActionsAnchor() {
    var anchorState = useState(null);
    var anchor = anchorState[0];
    var setAnchor = anchorState[1];

    useEffect(function () {
      var raf = null;
      function sync() {
        raf = null;
        var bar = document.querySelector('.myfeeds-spacious-modal .myfeeds-modal-actions');
        if (!bar || windowShowsOtherSource(bar)) {
          setAnchor(function (current) { return current === null ? current : null; });
          var stale = bar && bar.querySelector(':scope > .myfeeds-rkt-modal-anchor');
          if (stale && stale.parentNode) stale.parentNode.removeChild(stale);
          return;
        }
        var el = bar.querySelector(':scope > .myfeeds-rkt-modal-anchor');
        if (!el) {
          el = document.createElement('div');
          el.className = 'myfeeds-rkt-modal-anchor';
          bar.appendChild(el);
        }
        placeAnchor(bar, el);
        setAnchor(function (current) { return current === el ? current : el; });
      }

      // The bar keeps its buttons centred; the anchor floats over the
      // right-hand side, centred between the last button and the edge.
      function placeAnchor(bar, el) {
        var buttons = bar.querySelectorAll(':scope > button, :scope > .components-button');
        var barRect = bar.getBoundingClientRect();
        var rightEdge = barRect.left;
        for (var i = 0; i < buttons.length; i++) {
          var r = buttons[i].getBoundingClientRect();
          if (r.right > rightEdge) rightEdge = r.right;
        }
        var padding = 20;
        var mid = (rightEdge + (barRect.right - padding)) / 2 - barRect.left;
        el.style.left = Math.round(mid) + 'px';
      }

      sync();
      var obs = new MutationObserver(function () {
        if (raf) return;
        raf = (window.requestAnimationFrame || function (fn) { return setTimeout(fn, 16); })(sync);
      });
      obs.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-myfeeds-source'] });
      window.addEventListener('resize', sync);

      return function () {
        obs.disconnect();
        window.removeEventListener('resize', sync);
        if (raf) (window.cancelAnimationFrame || clearTimeout)(raf);
      };
    }, []);

    return anchor;
  }

  function jobProgress(job) {
    if (!job) return null;
    var entries = Object.keys(job.feeds).map(function (sid) { return job.feeds[sid]; });
    var active = entries.filter(function (e) { return e.status === 'pulling' || e.status === 'done' || e.status === 'failed'; });
    if (!active.length) return { percent: 100, pending: false, added: 0, failed: 0, rows: 0, total: 0 };

    var total = 0, rows = 0, unknown = false, pending = false, added = 0, failed = 0;
    active.forEach(function (e) {
      if (e.status === 'pulling') pending = true;
      if (e.status === 'done') { added += e.products || 0; }
      if (e.status === 'failed') { failed++; }
      if (e.status === 'done' || e.status === 'failed') {
        total += e.total || e.fetched || 0;
        rows += e.total || e.fetched || 0;
        return;
      }
      if (!e.total) { unknown = true; }
      total += e.total || 0;
      rows += Math.min(e.fetched || 0, e.total || Infinity);
    });

    var percent = !pending ? 100 : (unknown || total === 0 ? 0 : Math.min(99, Math.round(rows / total * 100)));
    return { percent: percent, pending: pending, added: added, failed: failed, rows: rows, total: total };
  }

  function RakutenSearchLine(props) {
    var wrapperRef = props.wrapperRef;
    var config = getSourcesConfig();
    var anchor = useSearchAnchor(wrapperRef);
    var modalAnchor = useModalActionsAnchor();
    // Every picker block in the post mounts one of these. Exactly one of
    // them runs the pull and draws the bar; the rest keep their switch.
    var ownsRun = useOwnsRakutenRun();

    var targetsState = useState(null);
    var targets = targetsState[0];
    var setTargets = targetsState[1];

    var autoState = useState(rakutenAutoEnabled());
    var auto = autoState[0];
    var setAuto = autoState[1];

    var searchState = useState(lastSearch);
    var search = searchState[0];
    var setSearch = searchState[1];

    // { query, feeds: { sid: { name, status: 'pulling'|'queued'|'known'|'done'|'failed', key, fetched, total, products, error } }, rerun, added }
    var jobState = useState(null);
    var job = jobState[0];
    var setJob = jobState[1];

    function loadTargets() {
      return apiFetch({ path: '/myfeeds/v1/sources/targets' })
        .then(function (data) {
          var feeds = (data && data.feeds) || [];
          setTargets(feeds);
          return feeds;
        })
        .catch(function () { setTargets([]); return []; });
    }

    useEffect(function () {
      if (config.connected) loadTargets();
    }, []);

    useEffect(function () {
      var listener = function (s) { setSearch(s); };
      searchListeners.push(listener);
      return function () {
        searchListeners = searchListeners.filter(function (fn) { return fn !== listener; });
      };
    }, []);

    // A new search: put it to Rakuten for every feed that does not know
    // the term yet.
    useEffect(function () {
      if (!ownsRun) return;
      if (!search || !auto || !config.connected || !targets || !targets.length) return;
      if (windowShowsOtherSource()) return;
      if (job && job.query === search.query) return;
      if (search.query.length < 3) return;
      startPull(search.query, targets);
    }, [search, targets, auto, ownsRun]);

    function patchFeed(query, sid, patch) {
      setJob(function (current) {
        if (!current || current.query !== query) return current;
        var copy = Object.assign({}, current, { feeds: Object.assign({}, current.feeds) });
        copy.feeds[sid] = Object.assign({}, copy.feeds[sid], patch);
        return copy;
      });
    }

    function startPull(query, feeds) {
      var next = { query: query, feeds: {} };
      feeds.forEach(function (f) {
        var known = (f.discovered || []).some(function (t) { return t.toLowerCase() === query.toLowerCase(); });
        next.feeds[f.sid] = { name: f.name, status: known ? 'known' : 'pulling', key: null, fetched: 0, total: 0, products: 0, error: '' };
      });
      setJob(next);

      feeds.forEach(function (f) {
        if (next.feeds[f.sid].status !== 'pulling') return;
        apiFetch({
          path: '/myfeeds/v1/sources/feeds/' + f.sid + '/discover',
          method: 'POST',
          data: { term: query }
        }).then(function (result) {
          patchFeed(query, f.sid, result.queued
            ? { status: 'queued' }
            : { status: 'pulling', key: result.state ? result.state.key : null });
        }).catch(function (err) {
          patchFeed(query, f.sid, { status: 'failed', error: (err && err.message) || 'Rakuten did not answer.' });
        });
      });
    }

    // Poll every running pull; the poll also drives it on hosts where
    // Action Scheduler is not moving on its own.
    useEffect(function () {
      if (!job) return undefined;
      var running = Object.keys(job.feeds).filter(function (sid) {
        return job.feeds[sid].status === 'pulling' && job.feeds[sid].key;
      });
      if (!running.length) return undefined;

      var timer = setTimeout(function () {
        running.forEach(function (sid) {
          var key = job.feeds[sid].key;
          apiFetch({ path: '/myfeeds/v1/sources/runs/' + encodeURIComponent(key) })
            .then(function (state) {
              var patch = { fetched: state.fetched || 0, total: state.total || 0 };
              if (!state.fetching) {
                patch.status = state.status === 'done' ? 'done' : 'failed';
                patch.products = state.products || 0;
                patch.error = state.error || '';
              }
              patchFeed(job.query, sid, patch);
            })
            .catch(function (err) {
              patchFeed(job.query, sid, { status: 'failed', error: (err && err.message) || 'Lost contact with the site.' });
            });
        });
      }, 2500);
      return function () { clearTimeout(timer); };
    }, [job]);

    // Everything finished: refresh the feed list and, when something was
    // added, run the picker search again so the products show up.
    useEffect(function () {
      if (!job || job.rerun) return;
      var entries = Object.keys(job.feeds).map(function (sid) { return job.feeds[sid]; });
      var pending = entries.some(function (e) { return e.status === 'pulling'; });
      if (pending) return;
      var added = entries.reduce(function (sum, e) { return sum + (e.status === 'done' ? e.products : 0); }, 0);
      var held_back = added > 0 && (!pickerModalIsOpen() || pickerHasSelection());
      setJob(Object.assign({}, job, { rerun: true, added: added, heldBack: held_back }));
      if (added > 0) {
        loadTargets();
        if (!held_back) {
          setTimeout(function () { rerunPickerSearch(wrapperRef.current, job.query); }, 800);
        }
      }
    }, [job]);

    if (!createPortal || !config.allowed) return null;

    var tooltip = createElement('span', { className: 'myfeeds-rkt-tip myfeeds-rkt-line__help', tabIndex: 0 },
      createElement('span', { className: 'myfeeds-rkt-tip__trigger', 'aria-hidden': 'true' }, '?'),
      createElement('span', { className: 'myfeeds-rkt-tip__content', role: 'tooltip' },
        'A Rakuten feed holds what Rakuten returned for its scope, and Rakuten answers at most 5,000 products per query. ' +
        'Switched on, every search you run here is also sent to Rakuten: matching products from all your Rakuten feeds are added to those feeds, ' +
        'imported, and the search runs again. The bar in the results window shows when everything for this search has arrived.')
    );

    var line;
    if (!config.connected) {
      line = createElement('span', { className: 'myfeeds-rkt-line__text' },
        'Rakuten is not connected. ',
        config.settingsUrl ? createElement('a', { href: config.settingsUrl, target: '_blank', rel: 'noopener' }, 'Connect it on the Feeds screen') : null
      );
    } else if (targets !== null && !targets.length) {
      line = createElement('span', { className: 'myfeeds-rkt-line__text' },
        'No Rakuten feed yet. Create one on the Feeds screen and every search here will grow it.');
    } else {
      var progress = jobProgress(job);
      var status = '';
      if (job && progress) {
        if (progress.pending) {
          status = 'pulling "' + job.query + '"… ' + progress.percent + '%';
        } else if (job.rerun && job.added > 0) {
          status = '"' + job.query + '": ' + job.added.toLocaleString() + ' products added, searched again';
        } else if (progress.failed) {
          status = '"' + job.query + '": Rakuten did not answer for ' + progress.failed + ' feed' + (progress.failed > 1 ? 's' : '');
        } else if (job.rerun) {
          status = '"' + job.query + '": nothing new on Rakuten';
        }
      }
      line = createElement(Fragment, null,
        createElement('label', { className: 'myfeeds-rkt-switch' + (auto ? ' is-on' : '') },
          createElement('span', { className: 'myfeeds-rkt-switch__label' }, 'Rakuten'),
          createElement('input', {
            type: 'checkbox',
            role: 'switch',
            className: 'myfeeds-rkt-switch__input',
            checked: auto,
            'aria-checked': auto ? 'true' : 'false',
            onChange: function (e) { setAuto(!!e.target.checked); setRakutenAuto(!!e.target.checked); }
          }),
          createElement('span', { className: 'myfeeds-rkt-switch__track', 'aria-hidden': 'true' },
            createElement('span', { className: 'myfeeds-rkt-switch__thumb' })
          )
        ),
        tooltip,
        status ? createElement('span', { className: 'myfeeds-rkt-line__status' }, status) : null
      );
    }

    var bar = null;
    if (ownsRun && modalAnchor && job && config.connected) {
      var p = jobProgress(job);
      if (p) {
        var label;
        var kind = '';
        if (p.pending) {
          label = 'Rakuten ' + p.percent + '%';
        } else if (job.added > 0 || p.added > 0) {
          var n = (job.added || p.added).toLocaleString();
          // Held back because the author was in the middle of something:
          // say what is waiting instead of taking the screen back.
          label = job.heldBack
            ? 'Rakuten: ' + n + ' added - search again to see them'
            : 'Rakuten: ' + n + ' added';
          kind = 'is-done';
        } else if (p.failed) {
          label = 'Rakuten: no answer';
          kind = 'is-failed';
        } else {
          label = 'Rakuten: nothing new';
          kind = 'is-done';
        }
        bar = createPortal(
          createElement('div', { className: 'myfeeds-rkt-progress ' + kind, title: 'Products for "' + job.query + '" from your Rakuten feeds' },
            createElement('span', { className: 'myfeeds-rkt-progress__label' }, label),
            createElement('span', { className: 'myfeeds-rkt-progress__track' },
              createElement('span', { className: 'myfeeds-rkt-progress__fill', style: { width: (p.pending ? p.percent : 100) + '%' } })
            )
          ),
          modalAnchor
        );
      }
    }

    return createElement(Fragment, null,
      anchor ? createPortal(createElement('div', { className: 'myfeeds-rkt-line' }, line), anchor) : null,
      bar
    );
  }

  // Under the hand-picked products' search. A block that fills itself
  // from a category has no search to widen.
  addFilter('myfeeds.picker.extras', NS, function (elements, ctx) {
    var out = Array.isArray(elements) ? elements.slice() : [];
    if (!ctx || ctx.dynamicActive) {
      return out;
    }
    // Rakuten feeds are ordinary feeds, so nothing here picks products;
    // the line under the search widens what the normal search finds.
    if (ctx.tab === 'manual' && getSourcesConfig().allowed) {
      out.push(createElement(RakutenSearchLine, { key: 'rakuten', wrapperRef: ctx.wrapperRef }));
    }
    return out;
  });

  // ---------------------------------------------------------------------------
  // The Look block (look-block.js): a pasted address one of the Rakuten
  // feeds' shops sells but its snapshot does not hold yet.
  // ---------------------------------------------------------------------------
  function lookWait(ms) {
    return new Promise(function (r) { setTimeout(r, ms); });
  }

  // Ask Rakuten for the URL's own words (the picker's discovery), wait for
  // the import, then look the URL up again (class-source-look.php).
  function lookFetchFromRakuten(url, answer, ui) {
    var target = answer && answer.rakuten;
    if (!target) {
      return false;
    }
    if (!target.term) {
      ui.setMsg({ error: true, text: 'Not in your ' + target.name + ' feed, and this address does not name the product. Paste the product page address.' });
      return true;
    }
    ui.setBusy(true);
    ui.setMsg({ text: 'Not in your ' + target.name + ' feed yet. Fetching "' + target.term + '" from Rakuten...' });
    apiFetch({ path: '/myfeeds/v1/look/fetch', method: 'POST', data: { url: url } }).then(function (job) {
      if (job.queued) {
        ui.setBusy(false);
        ui.setMsg({ text: 'The ' + target.name + ' feed is refreshing right now and will bring "' + target.term + '" along. Paste the address again in a few minutes.' });
        return null;
      }
      function poll(n) {
        return apiFetch({ path: '/myfeeds/v1/sources/runs/' + encodeURIComponent(job.key) }).then(function (st) {
          if (st && st.fetching && n < 60) return lookWait(2500).then(function () { return poll(n + 1); });
          if (!st || st.status !== 'done') throw new Error((st && st.error) || 'Rakuten did not answer.');
          ui.setMsg({ text: 'Fetched ' + (st.products || 0) + ' products from Rakuten. Importing...' });
          return findAgain(0);
        });
      }
      // The import runs right after the fetch; give it a little while.
      function findAgain(n) {
        return apiFetch({ path: '/myfeeds/v1/look/resolve?url=' + encodeURIComponent(url) }).then(function (res) {
          if (res && res.found && res.product) { ui.setBusy(false); ui.setMsg(null); ui.choose(res.product); return null; }
          if (n < 12) return lookWait(3000).then(function () { return findAgain(n + 1); });
          ui.setBusy(false);
          ui.setMsg({ error: true, text: 'Rakuten has nothing for "' + target.term + '" at ' + target.name + ' that matches this page. The shop may not offer it through Rakuten.' });
          return null;
        });
      }
      return poll(0);
    }).catch(function (err) {
      ui.setBusy(false);
      ui.setMsg({ error: true, text: (err && err.message) || 'Rakuten did not answer.' });
    });
    return true;
  }

  addFilter('myfeeds.look.notInFeed', NS, function (take) {
    return typeof take === 'function' ? take : lookFetchFromRakuten;
  });
})();
