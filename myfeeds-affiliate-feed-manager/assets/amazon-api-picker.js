/*
 * Amazon's API in the editor, where it runs on this site
 * (MyFeeds_Amazon_Api::editor_handles() registers this file there and
 * nowhere else): the product picker's Amazon tab with Amazon's data for
 * the block's products, a product looked up by ASIN or link, and Amazon's
 * catalogue in the picker's search window; for a Look, what Amazon calls
 * a set point, and Amazon in its search window.
 *
 * Hangs on the shared sidebar's myfeeds.picker.amazonPanel
 * (assets/picker-inspector.js), built from the parts that file hands out
 * (window.myfeedsAmazonAsin), and on the Look block's
 * myfeeds.look.amazonDetails and myfeeds.look.searchSources
 * (assets/look-block.js); draws no block of its own. A product Amazon
 * gives no data for is listed to be named by hand, each row through
 * myfeeds.picker.amazonManualItem (row, id, the products listed, remove),
 * drawn as it is where nothing answers. Loaded after picker-inspector.js.
 * Shared byte for byte by both plugins.
 */
;(function () {
  "use strict";

  if (!window.wp || !wp.hooks || !wp.element || !wp.components) {
    return;
  }

  var addFilter = wp.hooks.addFilter;
  var applyFilters = wp.hooks.applyFilters;
  var createElement = wp.element.createElement;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var TextControl = wp.components.TextControl;
  var Button = wp.components.Button;
  var apiFetch = wp.apiFetch;

  // Before the first addFilter: wp.hooks drops a filter whose namespace
  // is not a string yet, and says nothing.
  var NS = 'myfeeds/amazon-api';

  // ---------------------------------------------------------------------------
  // The picker's Amazon tab. The tab and its panel for products by link or
  // ASIN, named by hand, are the shared picker-inspector.js; where Amazon's
  // API runs this panel takes its place, built from the shared parts and
  // what the API adds, from the payload MyFeeds_Amazon_Api::picker_config()
  // puts beside the links' (connected, apiState, searchIndices) -
  // amazonApiFor():
  //   hydrate   Amazon's data for the block's products, once connected
  //   lookup    one product by ASIN, once Amazon answers
  //   searchSource  Amazon's catalogue in the search window
  //   texts     what the tab says about the API, for the state it is in
  // ---------------------------------------------------------------------------
  function getAmazonConfig() {
    return (window.myfeedsData && window.myfeedsData.amazon) || { allowed: false };
  }

  function amazonApiUsable(config) {
    return !!config.connected && config.apiState !== 'not_eligible' && config.apiState !== 'invalid';
  }

  // The sorts Amazon's search takes, best rated first. Amazon gives no
  // stars or review counts to show - its API has no such data - but it
  // sorts by them, and filters by them: the "Rating" option below sends
  // Amazon's minReviewsRating (Marlon, 28.10.: "if Amazon's API has a
  // minimum-stars filter, it belongs in"). In six searches on mylook that
  // day the filter changed no result; whatever Amazon applies, the
  // option says only what is asked of it.
  var AMAZON_WINDOW_SORTS = [
    { label: 'Best rated', value: 'AvgCustomerReviews' },
    { label: 'Relevance', value: '' },
    { label: 'Featured', value: 'Featured' },
    { label: 'Newest', value: 'NewestArrivals' },
    { label: 'Price (low \u2192 high)', value: 'Price:LowToHigh' },
    { label: 'Price (high \u2192 low)', value: 'Price:HighToLow' }
  ];

  // Amazon as a source of the picker's search window
  // (window.myfeedsProductSearch, from the picker bundle): the picker's
  // Amazon tab opens the window on Amazon alone, a Look point offers it
  // beside the site's own products. Only where Amazon's API answers -
  // without it there is nothing to search. Amazon hands out ten products
  // a call and ten pages at most; the window fetches the first three
  // pages by itself and the rest on "Load More Results".
  // Well-rated only unless the author asks for more (Marlon, 28.10.).
  var AMAZON_DEFAULT_MIN_RATING = '4';

  function amazonSearchSource() {
    var config = getAmazonConfig();
    if (!config.allowed || !amazonApiUsable(config)) {
      return null;
    }
    var indices = config.searchIndices || { All: 'All categories' };
    var markets = config.marketplaces || {};
    var marketKeys = Object.keys(markets);
    var options = [{
      key: 'min_rating',
      label: 'Rating',
      value: AMAZON_DEFAULT_MIN_RATING,
      values: [
        { value: '4', label: '4 stars & up' },
        { value: '3', label: '3 stars & up' },
        { value: '2', label: '2 stars & up' },
        { value: '1', label: '1 star & up' },
        { value: '', label: 'Any rating' }
      ]
    }, {
      key: 'search_index',
      label: 'Category',
      value: 'All',
      values: Object.keys(indices).map(function (key) { return { value: key, label: indices[key] }; })
    }];
    if (marketKeys.length > 1) {
      options.push({
        key: 'marketplace',
        label: 'Amazon site',
        value: config.marketplace || marketKeys[0],
        values: marketKeys.map(function (key) { return { value: key, label: markets[key] }; })
      });
    }
    return {
      id: 'amazon',
      label: 'Amazon',
      accent: '#c45500',
      gradient: 'linear-gradient(135deg, #ff9900 0%, #f97316 100%)',
      sorts: AMAZON_WINDOW_SORTS,
      defaultSort: 'AvgCustomerReviews',
      options: options,
      firstPages: 3,
      // Amazon's detail and shipping are Amazon's to show; the window's
      // detail view reads the site's feeds.
      detail: false,
      shipping: false,
      note: 'Amazon returns at most 100 products for one search.',
      emptyText: 'Type what you are looking for on Amazon.',
      search: function (q) {
        var o = q.options || {};
        var params = [
          'q=' + encodeURIComponent(q.term),
          'page=' + (q.page || 1),
          'sort_by=' + encodeURIComponent(q.sort || ''),
          'min_rating=' + encodeURIComponent(o.min_rating !== undefined ? o.min_rating : AMAZON_DEFAULT_MIN_RATING),
          'search_index=' + encodeURIComponent(o.search_index || 'All'),
          'marketplace=' + encodeURIComponent(o.marketplace || config.marketplace || '')
        ].join('&');
        return apiFetch({ path: '/myfeeds/v1/amazon/search?' + params }).then(function (data) {
          var page = (data && data.page) || 1;
          return {
            products: (data && Array.isArray(data.products)) ? data.products : [],
            total: (data && data.reachable_total) || 0,
            more: !!data && page < (data.max_page || 0)
          };
        });
      }
    };
  }
  window.myfeedsAmazonSearchSource = amazonSearchSource;

  function amazonItems(ids) {
    return apiFetch({
      path: '/myfeeds/v1/amazon/items',
      method: 'POST',
      data: { ids: ids }
    }).then(function (items) {
      return Array.isArray(items) ? items : [];
    });
  }

  function amazonApiFor(config) {
    if (!config || !config.allowed) {
      return {};
    }

    var usable = amazonApiUsable(config);
    var byHand = 'Added. Amazon has not unlocked its API for you yet, so check brand and name below. The card links with your partner tag and switches to Amazon\'s photo and price on its own once Amazon unlocks.';
    var out = {
      texts: {
        needTag: 'Save your Amazon partner tag first. With it you can add products by ASIN now; Amazon\'s photos, prices and search follow once Amazon unlocks its API (10 qualifying sales in 30 days).',
        byHand: byHand,
        manualTitle: 'Without Amazon data',
        note: !config.connected
          ? 'Amazon search, photos and prices arrive once Amazon unlocks its API for you (10 qualifying sales in 30 days). Until then, add products by ASIN and name them yourself.'
          : (!usable
            ? 'Amazon has not unlocked its API for you yet, so there is no search. Add products by ASIN and name them yourself; photos and prices follow once Amazon unlocks.'
            : 'Prices and images come from Amazon each time the page is shown. Only the ASIN is saved in this post.')
      }
    };
    if (!config.connected) {
      return out;
    }

    out.hydrate = amazonItems;
    if (!usable) {
      return out;
    }

    out.searchSource = amazonSearchSource;

    // One product by ASIN or link. Asked through the same route the chosen
    // tiles use, so it is looked up once at Amazon, lands in the cache, and
    // only an ASIN Amazon actually knows reaches the post.
    out.lookup = function (id, parsed) {
      var notFound = 'Amazon returned no product for ' + parsed.asin + ' on ' + parsed.marketplace.replace(/^www\./, '') + '. Check the ASIN.';
      return amazonItems([id]).then(function (items) {
        if (items.some(function (it) { return it && String(it.id) === id; })) {
          return { found: true };
        }
        // The items route answers an empty list for an unknown ASIN and
        // for a refused connection alike. Ask why before blaming the
        // ASIN: below Amazon's ten sales every lookup comes back empty.
        return apiFetch({ path: '/myfeeds/v1/amazon/status' }).then(function (st) {
          if (st && (st.state === 'not_eligible' || st.state === 'invalid')) {
            return { byHand: true };
          }
          var broken = st && st.state && st.state !== 'ok' && st.state !== 'untested' && st.message;
          return { error: broken ? 'Amazon refused the lookup: ' + st.message : notFound };
        }, function () {
          return { error: notFound };
        });
      }, function (err) {
        return { error: (err && err.message) || 'Amazon did not answer.' };
      });
    };

    return out;
  }

  // ASIN or Amazon link (added at once) - or words (searched).
  function looksLikeAmazonProduct(text) {
    var t = String(text || '').trim();
    return (/^[A-Za-z0-9]{10}$/.test(t) && /\d/.test(t))
      || /(^|[\/.])(amazon\.[a-z.]+|amzn\.(to|eu)|a\.co)(\/|$)/i.test(t);
  }

  function amazonMoney(product) {
    if (!product || !product.price) {
      return '';
    }
    if (typeof window.myfeedsFormatPrice === 'function') {
      return window.myfeedsFormatPrice(product.price, product.currency || 'EUR');
    }
    return (Math.round(product.price * 100) / 100).toFixed(2) + ' ' + (product.currency || 'EUR');
  }

  function AmazonTile(props) {
    var product = props.product;
    var chosen = props.chosen;

    return createElement('div', {
      className: 'myfeeds-amz-tile' + (chosen ? ' is-chosen' : ''),
      onClick: props.onToggle,
      title: product.title
    },
      createElement('div', { className: 'myfeeds-amz-tile__img' },
        product.image_url
          ? createElement('img', { src: product.image_url, alt: '', loading: 'lazy', decoding: 'async' })
          : null
      ),
      createElement('div', { className: 'myfeeds-amz-tile__body' },
        product.brand
          ? createElement('div', { className: 'myfeeds-amz-tile__brand' }, product.brand)
          : null,
        createElement('div', { className: 'myfeeds-amz-tile__title' }, product.title),
        createElement('div', { className: 'myfeeds-amz-tile__price' },
          amazonMoney(product) || 'Price on request',
          !product.in_stock
            ? createElement('span', { className: 'myfeeds-amz-tile__oos' }, ' out of stock')
            : null
        )
      ),
      createElement('div', { className: 'myfeeds-amz-tile__mark' }, chosen ? 'Added' : 'Add')
    );
  }

  // The Amazon tab's panel with Amazon's API: the shared panel's field and
  // its list of products named by hand, plus what the API adds for the
  // state the connection is in (amazonApiFor()). Named like the shared
  // one, whose place it takes.
  function AmazonPanel(props) {
    var attributes = props.attributes;
    var setAttributes = props.setAttributes;
    var shared = window.myfeedsAmazonAsin || {};
    var parseAsinInput = shared.parse;
    var amazonId = shared.id;
    var movedNote = shared.movedNote;
    var config = getAmazonConfig();
    var api = amazonApiFor(config);
    var texts = Object.assign({}, shared.texts || {}, api.texts || {});
    var hydrate = typeof api.hydrate === 'function' ? api.hydrate : null;
    var lookup = typeof api.lookup === 'function' ? api.lookup : null;

    var chosenState = useState([]);
    var chosenDetails = chosenState[0];
    var setChosenDetails = chosenState[1];

    // The one field: an ASIN or Amazon link is added at once, words open
    // the search window where there is one.
    var asinState = useState('');
    var asinInput = asinState[0];
    var setAsinInput = asinState[1];

    var asinBusyState = useState(false);
    var asinBusy = asinBusyState[0];
    var setAsinBusy = asinBusyState[1];

    var asinErrorState = useState('');
    var asinError = asinErrorState[0];
    var setAsinError = asinErrorState[1];

    var asinNoteState = useState('');
    var asinNote = asinNoteState[0];
    var setAsinNote = asinNoteState[1];

    // Which ids Amazon has answered for. An id it has nothing for is
    // named by hand below instead of waiting for a tile that will not come.
    var loadedState = useState('');
    var loadedKey = loadedState[0];
    var setLoadedKey = loadedState[1];

    // The picker's search window on Amazon alone: open with these words,
    // or null; and its working selection until "Use ...".
    var winState = useState(null);
    var win = winState[0];
    var setWin = winState[1];
    var winSelState = useState([]);
    var winSel = winSelState[0];
    var setWinSel = winSelState[1];

    var source = typeof api.searchSource === 'function' ? api.searchSource() : null;
    var SearchWindow = window.myfeedsProductSearch && window.myfeedsProductSearch.Modal;

    var picks = Array.isArray(attributes.amazonProducts) ? attributes.amazonProducts : [];
    var pickIds = picks.map(function (p) { return p && p.id ? String(p.id) : ''; }).filter(Boolean);
    var pickKey = pickIds.join(',');

    // Selected tiles carry no stored data, so the panel asks what they
    // currently are every time the id list changes.
    useEffect(function () {
      if (!pickIds.length) {
        setChosenDetails([]);
        return;
      }

      var cancelled = false;
      if (!hydrate) {
        setChosenDetails([]);
        setLoadedKey(pickKey);
        return;
      }
      hydrate(pickIds).then(function (items) {
        if (!cancelled) {
          setChosenDetails(Array.isArray(items) ? items : []);
          setLoadedKey(pickKey);
        }
      }).catch(function () {
        if (!cancelled) {
          setChosenDetails([]);
          setLoadedKey(pickKey);
        }
      });

      return function () { cancelled = true; };
    }, [pickKey]);

    // Products Amazon does not describe: the author's own card. Brand and
    // name are what the reader sees in place of the photo; the note is optional.
    var detailIds = chosenDetails.map(function (d) { return String(d.id); });
    var manualPicks = loadedKey === pickKey
      ? picks.filter(function (p) { return p && p.id && detailIds.indexOf(String(p.id)) === -1; })
      : [];

    function addByAsin() {
      var parsed = parseAsinInput(asinInput, config.marketplace, config.marketplaces);
      if (!parsed.asin) {
        setAsinError(parsed.error || '');
        return;
      }

      var id = amazonId(parsed.marketplace, parsed.asin);
      if (pickIds.indexOf(id) !== -1) {
        setAsinError('This product is already in the block.');
        return;
      }

      setAsinError('');
      setAsinNote(parsed.moved ? movedNote(parsed.moved, parsed.marketplace) : '');

      // The ASIN goes in as it is; the author names it.
      function addByHand() {
        setAttributes({ amazonProducts: picks.concat([{ id: id, brand: '', name: parsed.name || '', note: '' }]) });
        setAsinInput('');
        setAsinNote(parsed.moved ? movedNote(parsed.moved, parsed.marketplace) + ' ' + texts.byHand : texts.byHand);
      }

      if (!lookup) {
        addByHand();
        return;
      }

      setAsinBusy(true);
      lookup(id, parsed).then(function (answer) {
        setAsinBusy(false);
        if (answer && answer.found) {
          setAttributes({ amazonProducts: picks.concat([{ id: id }]) });
          setAsinInput('');
          return;
        }
        if (answer && answer.byHand) {
          addByHand();
          return;
        }
        setAsinError((answer && answer.error) || '');
      }).catch(function (err) {
        setAsinBusy(false);
        setAsinError((err && err.message) || '');
      });
    }

    function openSearch(term) {
      if (!source || typeof SearchWindow !== 'function') {
        setAsinError('The product search did not load. Reload the editor and try again.');
        return;
      }
      setAsinError('');
      setWinSel(chosenDetails.slice());
      setWin({ term: term || '' });
    }

    // Enter in the field: a product is added, words are searched.
    function submitField() {
      var text = asinInput.trim();
      if (source && !looksLikeAmazonProduct(text)) {
        openSearch(text);
        return;
      }
      addByAsin();
    }

    // "Use ..." in the window: what is ticked there is the block's Amazon
    // list, in the order it had, new ones after. Products named by hand
    // below were never in the window, so they stay as they are. Ids only:
    // anything else would be product data the post may not keep.
    function useFromWindow(list) {
      var chosenIds = [];
      (list || []).forEach(function (p) {
        var id = p && p.id ? String(p.id) : '';
        if (id.indexOf('amzn:') === 0 && chosenIds.indexOf(id) === -1) chosenIds.push(id);
      });
      var next = picks.filter(function (p) {
        var id = p && p.id ? String(p.id) : '';
        return id && (detailIds.indexOf(id) === -1 || chosenIds.indexOf(id) !== -1);
      });
      chosenIds.forEach(function (id) {
        if (pickIds.indexOf(id) === -1) next.push({ id: id });
      });
      setAttributes({ amazonProducts: next });
      setWin(null);
      setAsinInput('');
    }

    function updatePick(id, field, value) {
      setAttributes({
        amazonProducts: picks.map(function (p) {
          if (!p || String(p.id) !== id) return p;
          var copy = Object.assign({}, p);
          copy[field] = value;
          return copy;
        })
      });
    }

    function removePick(id) {
      setAttributes({ amazonProducts: picks.filter(function (p) { return !p || String(p.id) !== id; }) });
    }

    if (!config.allowed || typeof parseAsinInput !== 'function' || typeof shared.manualPick !== 'function') {
      return null;
    }

    if (!hydrate && !config.hasTag) {
      return createElement('div', { className: 'myfeeds-amz is-embedded' },
        createElement('div', { className: 'myfeeds-amz__body' },
          createElement('p', null, texts.needTag),
          createElement('a', {
            className: 'components-button is-secondary',
            href: config.settingsUrl,
            target: '_blank',
            rel: 'noopener'
          }, 'Open Amazon settings')
        )
      );
    }

    var typed = asinInput.trim();
    var field = createElement('div', { className: 'myfeeds-amz__controls myfeeds-amz__asin' + (source ? ' has-search' : '') },
      createElement(TextControl, {
        label: source ? 'Add a product or search Amazon' : 'Add one product',
        placeholder: source ? 'ASIN, Amazon product link, or what you are looking for' : 'ASIN or Amazon product link',
        value: asinInput,
        onChange: function (v) { setAsinInput(v); if (asinError) setAsinError(''); },
        onKeyDown: function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            submitField();
          }
        }
      }),
      createElement(Button, {
        variant: 'secondary',
        isSecondary: true,
        onClick: addByAsin,
        disabled: asinBusy || !typed || (source ? !looksLikeAmazonProduct(typed) : false)
      }, asinBusy ? 'Adding…' : 'Add'),
      source
        ? createElement(Button, {
            className: 'myfeeds-amz__search-btn',
            onClick: function () { openSearch(typed && !looksLikeAmazonProduct(typed) ? typed : ''); }
          }, 'Search Amazon')
        : null,
      asinError ? createElement('div', { className: 'myfeeds-amz__error' }, asinError) : null,
      asinNote ? createElement('div', { className: 'myfeeds-amz__note' }, asinNote) : null
    );

    var manualList = manualPicks.length
      ? createElement('div', { className: 'myfeeds-amz__manual' },
          createElement('div', { className: 'myfeeds-amz__chosen-title' }, texts.manualTitle + ' (' + manualPicks.length + ')'),
          manualPicks.map(function (p) {
            var id = String(p.id);
            var remove = function () { removePick(id); };
            var row = shared.manualPick(p, function (key, value) { updatePick(id, key, value); }, remove);
            // Each row as the panel draws it, unless something else has
            // more to say about that product (its row, its id, the
            // products listed here, and the way to take it out).
            var told = applyFilters('myfeeds.picker.amazonManualItem', row, id, manualPicks, remove);
            return told || row;
          })
        )
      : null;

    var chosenList = null;
    if (chosenDetails.length) {
      chosenList = createElement('div', { className: 'myfeeds-amz__chosen' },
        createElement('div', { className: 'myfeeds-amz__chosen-title' }, 'In this block'),
        createElement('div', { className: 'myfeeds-amz__grid' },
          chosenDetails.map(function (product) {
            return createElement(AmazonTile, {
              key: 'sel-' + product.id,
              product: product,
              chosen: true,
              onToggle: function () { removePick(String(product.id)); }
            });
          })
        )
      );
    } else if (pickIds.length && loadedKey !== pickKey) {
      chosenList = createElement('p', { className: 'myfeeds-amz__empty' },
        'Loading the ' + pickIds.length + ' selected products from Amazon…');
    }

    var windowEl = (win && source && typeof SearchWindow === 'function')
      ? createElement(SearchWindow, {
          term: win.term,
          onTermChange: function (t) { setWin({ term: t }); },
          selected: winSel,
          onSelectedChange: setWinSel,
          sources: [source],
          source: 'amazon',
          lockSource: true,
          onConfirm: useFromWindow,
          onCancel: function () { setWin(null); },
          confirmLabel: function (n) { return n === 1 ? 'Use 1 Amazon product' : 'Use ' + n + ' Amazon products'; }
        })
      : null;

    var link = texts.noteLink && texts.noteLink.url ? texts.noteLink : null;
    var note = createElement('p', { className: 'myfeeds-amz__note' },
      texts.note,
      link ? ' ' : null,
      link ? createElement('a', { href: link.url, target: '_blank', rel: 'noopener' }, link.label) : null);

    return createElement('div', { className: 'myfeeds-amz is-embedded' },
      createElement('div', { className: 'myfeeds-amz__body' },
        field,
        manualList,
        chosenList,
        note,
        windowEl
      )
    );
  }

  // The Amazon tab shows this panel (see AmazonPanel).
  addFilter('myfeeds.picker.amazonPanel', NS, function () {
    return AmazonPanel;
  });

  // ---------------------------------------------------------------------------
  // The Look block's filters (look-block.js): what Amazon calls a set point,
  // and Amazon in the search window.
  // ---------------------------------------------------------------------------
  // A set Amazon point's row shows what Amazon calls it, where Amazon
  // answers: looked up live for the editor, never stored in the post (the
  // licence keeps only the ASIN).
  addFilter('myfeeds.look.amazonDetails', NS, function (details) {
    if (typeof details === 'function') {
      return details;
    }
    var look = window.myfeedsLook && window.myfeedsLook.amazon;
    if (!look || !look.apiUsable) {
      return details;
    }
    return function (id) {
      return apiFetch({ path: '/myfeeds/v1/amazon/items', method: 'POST', data: { ids: [id] } }).then(function (items) {
        return (Array.isArray(items) && items.filter(function (it) { return it && it.id === id; })[0]) || null;
      });
    };
  });

  addFilter('myfeeds.look.searchSources', NS, function (sources) {
    var look = window.myfeedsLook && window.myfeedsLook.amazon;
    if (!look || !look.apiUsable) {
      return sources;
    }
    var amazon = amazonSearchSource();
    return amazon ? (Array.isArray(sources) ? sources : []).concat([amazon]) : sources;
  });
})();
