;(function () {
  'use strict';

  // MyFeeds Look: the author's own photo with numbered shop points.
  // Click the photo to place a point, drag it to move it. Each point is one
  // row with one field: an Amazon link or ASIN, a shop's product URL, or a
  // few words to search the feeds - it works out which by itself. The page
  // is rendered on the server (class-look-block.php); this file only edits
  // the attributes. Styles: look-block-editor.css (the block's
  // editor_style, the only way into the iframed canvas).
  //
  // Products from beyond the site's own feeds come in through wp.hooks,
  // each a filter on null that may hand back a function:
  //   myfeeds.look.amazonDetails  fn(id) -> Promise of { title, brand,
  //                               image_url } for a set Amazon point, shown
  //                               in its row and never kept in the post
  //   myfeeds.look.notInFeed      fn(url, answer, ui) -> true when it
  //                               takes over a pasted address no feed sells
  //   myfeeds.look.searchSources  ([]) more sources for the search window
  // and the words the editor says about Amazon come with myfeedsLook.amazon
  // (texts), so a site that knows more about Amazon products says so.

  if (!window.wp || !wp.blocks || !wp.element || !wp.blockEditor) {
    return;
  }

  var el = wp.element.createElement;
  var useState = wp.element.useState;
  var useRef = wp.element.useRef;
  var useEffect = wp.element.useEffect;
  var be = wp.blockEditor;
  var c = wp.components;
  var apiFetch = wp.apiFetch;
  var cfg = window.myfeedsLook || {};
  var amazon = cfg.amazon || { allowed: false };

  function clamp(v) {
    return Math.max(0, Math.min(100, Math.round(v * 100) / 100));
  }

  function newUid() {
    return Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
  }

  // An ASIN is ten letters and digits and always has a digit in it
  // (B0..., or a book's ISBN). Without that check a ten-letter word typed
  // to search the feeds - "sweatshirt", "trenchcoat" - was read as one.
  function looksAmazon(text) {
    return (/^[A-Za-z0-9]{10}$/.test(text) && /\d/.test(text))
      || /(^|[\/.])(amazon\.[a-z.]+|amzn\.(to|eu)|a\.co)(\/|$)/i.test(text);
  }

  // Amazon products by link or ASIN (amazon.links, class-look-block.php)
  // are named by their author. What the editor says about them comes from
  // the server (amazon.texts), each in place of the line here.
  var amazonTexts = Object.assign({
    namePlaceholder: 'Product name (needed)',
    nameNeeded: 'Give it a name; the point stays hidden on the page until it has one.',
    prices: '',
    pictures: ''
  }, amazon.texts || {});
  function settingsLink() {
    return amazon.settingsUrl ? { url: amazon.settingsUrl, label: 'Open Amazon settings' } : null;
  }

  // The second half of a switch's help, where the site says one: what an
  // Amazon point shows of it.
  function amazonHelp(what) {
    return amazon.links && amazonTexts[what] ? ' ' + amazonTexts[what] : '';
  }

  // One line under a field: plain, as an error, or with a link behind it.
  function note(msg) {
    return el('p', { className: 'myfeeds-look-ed__msg' + (msg.error ? ' is-error' : '') },
      msg.text,
      msg.link ? ' ' : null,
      msg.link ? el('a', { href: msg.link.url, target: '_blank', rel: 'noopener' }, msg.link.label) : null);
  }

  // The site's price format (assets/price-format.js), so the row reads like
  // the price under the photo on the page.
  function money(amount, currency) {
    if (typeof window.myfeedsFormatPrice === 'function') {
      return window.myfeedsFormatPrice(amount, currency || '');
    }
    return currency ? amount + ' ' + currency : String(amount);
  }

  function looksUrl(text) {
    return /^https?:\/\//i.test(text) || /^[a-z0-9-]+(\.[a-z0-9-]+)+\//i.test(text);
  }

  // The picker's reading of "ASIN or Amazon link" (window.myfeedsAmazonAsin).
  function readAsin(text) {
    var shared = window.myfeedsAmazonAsin;
    if (shared && typeof shared.parse === 'function') {
      var parsed = shared.parse(text, amazon.marketplace, amazon.marketplaces);
      return parsed.asin
        ? { id: shared.id(parsed.marketplace, parsed.asin), asin: parsed.asin, moved: parsed.moved && shared.movedNote ? shared.movedNote(parsed.moved, parsed.marketplace) : '' }
        : { error: parsed.error || '' };
    }
    return { error: 'The Amazon reader did not load. Reload the editor and try again.' };
  }

  // amazon.com/Adidas-Samba-OG-Sneaker/dp/B0... carries a readable name in
  // the link the author pasted. That is their text, not something fetched
  // from Amazon, so it fills the title (amazon.nameFromLink false: the site
  // names the product itself).
  function nameFromAmazonLink(text) {
    var m = String(text).match(/\/([^\/?#]+)\/(?:dp|gp\/product)\//i);
    if (!m || m[1].indexOf('-') === -1) return '';
    try {
      return decodeURIComponent(m[1]).replace(/-/g, ' ').replace(/\s+/g, ' ').trim();
    } catch (e) {
      return '';
    }
  }

  // amzn:com:B0... -> "Amazon.com". The market decides which shop a reader
  // lands in, so the row names it next to the ASIN.
  function amazonSite(id) {
    var code = String(id || '').split(':')[1] || '';
    var keys = Object.keys(amazon.marketplaces || {});
    for (var i = 0; i < keys.length; i++) {
      if (keys[i].replace('www.amazon.', '').replace(/\./g, '') === code) {
        return 'Amazon.' + keys[i].replace('www.amazon.', '');
      }
    }
    return 'Amazon';
  }

  function provided(name, value) {
    if (!wp.hooks || typeof wp.hooks.applyFilters !== 'function') return value;
    return wp.hooks.applyFilters(name, value);
  }

  // ---------------------------------------------------------------------
  // One row = one point
  // ---------------------------------------------------------------------
  function Row(props) {
    var p = props.point;
    var update = props.update;
    var isSet = !!p.id;
    var isAmazon = p.kind === 'amazon';

    var textState = useState('');
    var text = textState[0], setText = textState[1];
    var msgState = useState(null);
    var msg = msgState[0], setMsg = msgState[1];
    var busyState = useState(false);
    var busy = busyState[0], setBusy = busyState[1];
    // The picker's search window, open with these words; null when shut.
    var winState = useState(null);
    var win = winState[0], setWin = winState[1];
    var pickState = useState([]);
    var pick = pickState[0], setPick = pickState[1];
    var knownState = useState(null);
    var known = knownState[0], setKnown = knownState[1];
    var timer = useRef(null);
    var inputRef = useRef(null);

    useEffect(function () {
      if (props.focus && inputRef.current) inputRef.current.focus();
    }, [props.focus]);

    // A set Amazon point asks once what the site knows about it, where a
    // filter answers, for the row only: the post keeps the ASIN and the
    // author's words, nothing else.
    useEffect(function () {
      var details = isAmazon && p.id ? provided('myfeeds.look.amazonDetails', null) : null;
      if (typeof details !== 'function') { setKnown(null); return; }
      var gone = false;
      details(p.id).then(function (hit) {
        if (!gone) setKnown(hit || null);
      }).catch(function () {});
      return function () { gone = true; };
    }, [p.id]);

    function resolve(value) {
      var v = String(value || '').trim();
      setMsg(null);
      if (!v) return;

      if (looksAmazon(v)) {
        // Before anything is written to the point: it stays as it was.
        // A site without Amazon links is told what a point takes instead,
        // as a hint: pasting a link is no mistake of the author's.
        if (!amazon.links) {
          setMsg({ text: 'A point here links to a product from your shops\' feeds: paste its shop link or search for it.' });
          return;
        }
        if (!amazon.allowed) {
          setMsg({ error: true, text: 'Amazon is not set up. Add your partner tag in the Amazon settings first.', link: settingsLink() });
          return;
        }
        var r = readAsin(v);
        if (!r.id) { setMsg({ error: true, text: r.error }); return; }
        update({ kind: 'amazon', id: r.id, brand: '', name: amazon.nameFromLink === false ? '' : nameFromAmazonLink(v), img: '' });
        setText('');
        if (r.moved) setMsg({ text: r.moved });
        return;
      }

      if (looksUrl(v)) {
        setBusy(true);
        apiFetch({ path: '/myfeeds/v1/look/resolve?url=' + encodeURIComponent(v) }).then(function (res) {
          setBusy(false);
          if (res && res.found && res.product) {
            choose(res.product);
            return;
          }
          var notInFeed = provided('myfeeds.look.notInFeed', null);
          if (typeof notInFeed === 'function' && notInFeed(v, res || {}, { setBusy: setBusy, setMsg: setMsg, choose: choose })) {
            return;
          }
          setMsg({ error: true, text: 'None of your feeds sells this page. Type the product name instead to search them.' });
        }).catch(function () {
          setBusy(false);
          setMsg({ error: true, text: 'The lookup failed. Try again.' });
        });
        return;
      }

      openSearch(v);
    }

    // Words search the feeds in the picker's own window
    // (window.myfeedsProductSearch, from the picker bundle): the same
    // results, filters and detail view, choosing one product.
    function openSearch(term) {
      var search = window.myfeedsProductSearch;
      if (!search || typeof search.Modal !== 'function') {
        setMsg({ error: true, text: 'The product search did not load. Reload the editor and try again.' });
        return;
      }
      setMsg(null);
      setPick([]);
      setWin({ term: term || '' });
    }

    function closeSearch() {
      setWin(null);
      setPick([]);
    }

    // A product as the picker keeps it -> what a point keeps.
    function takeFromSearch(list) {
      var prod = list && list[0];
      closeSearch();
      if (!prod) return;
      // An Amazon product from another source of the window: the ASIN
      // only, as when one is pasted.
      if (String(prod.id).indexOf('amzn:') === 0) {
        setMsg(null);
        update({ kind: 'amazon', id: String(prod.id), brand: '', name: '', img: '', shop: '', price: '' });
        setText('');
        return;
      }
      choose({
        id: prod.id,
        title: prod.title,
        brand: prod.brand,
        image_url: prod.image_url,
        merchant: prod.merchant,
        price_text: prod.price > 0 ? money(prod.price, prod.currency) : ''
      });
    }

    function choose(prod) {
      update({
        kind: 'feed',
        id: String(prod.id),
        brand: prod.brand || '',
        name: prod.title || '',
        img: prod.image_url || '',
        shop: prod.merchant || '',
        price: prod.price_text || ''
      });
      setText('');
    }

    function onType(v) {
      setText(v);
      clearTimeout(timer.current);
      var t = v.trim();
      setMsg(null);
      // A pasted link or ASIN resolves at once. Words wait for Enter or
      // the Search button: a window that opened by itself mid-word would
      // take the field away from the author while they type.
      if (t && (looksAmazon(t) || looksUrl(t))) {
        timer.current = setTimeout(function () { resolve(v); }, 150);
      }
    }

    var body;
    if (!isSet) {
      var typed = text.trim();
      var Search = win && window.myfeedsProductSearch ? window.myfeedsProductSearch.Modal : null;
      var sources = win ? provided('myfeeds.look.searchSources', []) : [];
      body = el('div', { className: 'myfeeds-look-ed__body' },
        el('div', { className: 'myfeeds-look-ed__find' },
          el('input', {
            ref: inputRef,
            className: 'myfeeds-look-ed__input',
            type: 'text',
            value: text,
            placeholder: amazon.allowed ? 'Paste an ASIN or a product URL, or search your products' : 'Paste a product URL or search your products',
            onChange: function (e) { onType(e.target.value); },
            onKeyDown: function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer.current); resolve(text); } },
            onFocus: props.select
          }),
          // A pasted link or ASIN is not a search: the button then opens
          // the window empty, for the author to type into.
          el('button', { type: 'button', className: 'myfeeds-look-ed__pick is-secondary', disabled: busy, onClick: function () {
            clearTimeout(timer.current);
            openSearch(typed && !looksAmazon(typed) && !looksUrl(typed) ? typed : '');
          } }, 'Search')),
        busy && !msg ? el('p', { className: 'myfeeds-look-ed__msg' }, 'Looking it up...') : null,
        msg ? note(msg) : null,
        Search ? el(Search, {
          term: win.term,
          onTermChange: function (t) { setWin({ term: t }); },
          selected: pick,
          onSelectedChange: setPick,
          single: true,
          // Other sources beside the site's own products, where a filter
          // brings one; the switch keeps the words.
          sources: Array.isArray(sources) ? sources.filter(Boolean) : [],
          feedsLabel: 'Your products',
          onConfirm: takeFromSearch,
          onCancel: closeSearch,
          confirmLabel: function () { return 'Use this product'; }
        }) : null
      );
    } else {
      var shownBrand = p.brand || (known && known.brand) || '';
      var placeholder = isAmazon
        ? (known && known.title ? known.title : amazonTexts.namePlaceholder)
        : 'Product name';
      var needed = isAmazon && !p.name && !(known && known.title);
      // A picture only where there is one: an Amazon point by link has
      // none, and an empty box only pushed the title out of line. One the
      // site knows (myfeeds.look.amazonDetails) is shown for the editor,
      // never stored in the post.
      var thumbSrc = isAmazon ? (known && known.image_url ? known.image_url : '') : (p.img || '');
      var thumb = thumbSrc
        ? el('span', { className: 'myfeeds-look-ed__thumb' }, el('img', { src: thumbSrc, alt: '' }))
        : null;
      body = el('div', { className: 'myfeeds-look-ed__body' },
        el('div', { className: 'myfeeds-look-ed__product' + (thumb ? '' : ' no-thumb') },
          thumb,
          el('div', null,
            shownBrand ? el('p', { className: 'myfeeds-look-ed__brand' }, shownBrand) : null,
            el('input', {
              className: 'myfeeds-look-ed__title' + (needed ? ' is-needed' : ''),
              type: 'text',
              value: p.name || '',
              placeholder: placeholder,
              title: 'Shown on the page. Edit it as you like.',
              onChange: function (e) { update({ name: e.target.value }); },
              onFocus: props.select
            }),
            el('div', { className: 'myfeeds-look-ed__meta' },
              el('span', { className: 'myfeeds-look-ed__chip' + (isAmazon ? ' is-amazon' : '') },
                isAmazon ? amazonSite(p.id) + ' · ' + (p.id.split(':')[2] || '') : (p.shop || 'Your feeds')),
              !isAmazon && p.price ? el('span', null, p.price) : null,
              el('button', { type: 'button', className: 'myfeeds-look-ed__change', onClick: function () {
                setMsg(null);
                update({ kind: '', id: '', brand: '', name: '', img: '', shop: '', price: '' });
              } }, 'Change')
            )
          )
        ),
        el('input', {
          className: 'myfeeds-look-ed__note',
          type: 'text',
          value: p.note || '',
          placeholder: 'Your note (optional), e.g. runs half a size small',
          onChange: function (e) { update({ note: e.target.value }); },
          onFocus: props.select
        }),
        msg ? note(msg) : null,
        needed ? el('p', { className: 'myfeeds-look-ed__msg' }, amazonTexts.nameNeeded) : null,
        isAmazon && amazon.links ? el('p', { className: 'myfeeds-look-ed__msg' }, 'Colour or size wrong on Amazon? Paste the link of the exact variant you mean - an ASIN for "all colours" opens the default one.') : null
      );
    }

    return el('li', {
        className: 'myfeeds-look-ed__row' + (props.selected ? ' is-selected' : '') + (props.over ? ' is-over' : '')
          + (props.overBelow ? ' is-below' : '') + (props.dragging ? ' is-dragging' : ''),
        // Which point this row shows. The editor bridge marks a point the
        // post leaves out (editor-replace-bridge.js) by this number.
        'data-myfeeds-point': props.number - 1
      },
      el('span', { className: 'myfeeds-look-ed__lead' },
        el('span', {
          className: 'myfeeds-look-ed__grip',
          role: 'button',
          tabIndex: 0,
          title: 'Drag to change the order',
          'aria-label': 'Move point ' + props.number + ' (arrow keys)',
          onPointerDown: props.gripDown,
          onPointerMove: props.gripMove,
          onPointerUp: props.gripUp,
          onPointerCancel: props.gripCancel,
          onKeyDown: props.gripKey
        }, '\u2807\u2807'),
        el('span', { className: 'myfeeds-look-ed__num', onClick: props.select }, String(props.number))),
      body,
      el('button', { type: 'button', className: 'myfeeds-look-ed__remove', title: 'Remove this point', 'aria-label': 'Remove point ' + props.number, onClick: props.remove }, '×')
    );
  }

  // ---------------------------------------------------------------------
  // A photo from a URL: shown from its source, or copied in
  // ---------------------------------------------------------------------
  function UrlImage(props) {
    var urlState = useState('');
    var url = urlState[0], setUrl = urlState[1];
    var probeState = useState(null);
    var probe = probeState[0], setProbe = probeState[1];
    var busyState = useState(false);
    var busy = busyState[0], setBusy = busyState[1];
    var errState = useState('');
    var err = errState[0], setErr = errState[1];
    var modeState = useState('url');
    var mode = modeState[0], setMode = modeState[1];
    var okState = useState(false);
    var ok = okState[0], setOk = okState[1];
    var creditState = useState('');
    var credit = creditState[0], setCredit = creditState[1];
    var sourceState = useState('');
    var source = sourceState[0], setSource = sourceState[1];
    var altState = useState('');
    var alt = altState[0], setAlt = altState[1];

    function check() {
      var v = url.trim();
      if (!v) return;
      setBusy(true); setErr(''); setProbe(null);
      apiFetch({ path: '/myfeeds/v1/look/probe?url=' + encodeURIComponent(v) }).then(function (r) {
        setBusy(false);
        setProbe(r);
        setCredit(r.credit || '');
        setSource(r.page_url || v);
        setAlt(r.alt || '');
        if (!r.https) setMode('media');
      }).catch(function (e) { setBusy(false); setErr((e && e.message) || 'This address could not be checked.'); });
    }

    function use() {
      if (!probe || !ok) return;
      var stamp = new Date().toISOString().slice(0, 16).replace('T', ' ');
      var common = { creditText: credit, creditUrl: source, rightsConfirmed: stamp + ' (' + (mode === 'url' ? 'linked' : 'copied') + ')' };
      if (mode === 'url') {
        // mylook shows pins at 564px, centred; a smaller image keeps its own size.
        props.done(Object.assign({ imageSource: 'url', imageId: 0, imageUrl: probe.image_url, imageWidth: probe.width, imageHeight: probe.height, imageAlt: alt,
          width: Math.min(probe.width || 564, 564) }, common));
        return;
      }
      setBusy(true); setErr('');
      apiFetch({ path: '/myfeeds/v1/look/import', method: 'POST', data: {
        image_url: probe.image_url, confirm: true, source_url: source, credit: credit, alt: alt,
        post_id: (wp.data && wp.data.select('core/editor')) ? wp.data.select('core/editor').getCurrentPostId() : 0
      } }).then(function (r) {
        setBusy(false);
        props.done(Object.assign({ imageSource: 'media', imageId: r.id, imageUrl: r.url, imageWidth: 0, imageHeight: 0, imageAlt: '' }, common));
      }).catch(function (e) { setBusy(false); setErr((e && e.message) || 'The import failed.'); });
    }

    return el('div', { className: 'myfeeds-look-ed__url' },
      el('div', { className: 'myfeeds-look-ed__formhead' },
        el('strong', null, 'Image from a URL'),
        props.cancel ? el('button', { type: 'button', className: 'myfeeds-look-ed__change', onClick: props.cancel }, 'Cancel') : null),
      el('div', { className: 'myfeeds-look-ed__urlrow' },
        el('input', { className: 'myfeeds-look-ed__input', type: 'url', value: url, autoFocus: true,
          placeholder: 'Image address, or a page with the photo (a Pinterest pin, a product page)',
          onChange: function (e) { setUrl(e.target.value); setProbe(null); },
          onKeyDown: function (e) { if (e.key === 'Enter') { e.preventDefault(); check(); } } }),
        el('button', { type: 'button', className: 'myfeeds-look-ed__pick', onClick: check, disabled: busy || !url.trim() }, busy && !probe ? 'Checking...' : 'Check')),
      err ? el('p', { className: 'myfeeds-look-ed__msg is-error' }, err) : null,
      probe ? el('div', { className: 'myfeeds-look-ed__found' },
        el('img', { src: probe.image_url, alt: '', referrerPolicy: 'no-referrer' }),
        el('div', null,
          el('p', { className: 'myfeeds-look-ed__msg' }, probe.width + ' x ' + probe.height + ' px, from ' + probe.host),
          el('label', { className: 'myfeeds-look-ed__lbl' }, 'Credit, shown as "by ...", linked to the source (both optional)'),
          el('div', { className: 'myfeeds-look-ed__pair' },
            el('input', { className: 'myfeeds-look-ed__input', value: credit, placeholder: 'e.g. Pinterest', onChange: function (e) { setCredit(e.target.value); } }),
            el('input', { className: 'myfeeds-look-ed__input', value: source, placeholder: 'Source page', onChange: function (e) { setSource(e.target.value); } })),
          el('label', { className: 'myfeeds-look-ed__lbl' }, 'Alt text (what the photo shows, for Google and screen readers)'),
          el('input', { className: 'myfeeds-look-ed__input', value: alt, onChange: function (e) { setAlt(e.target.value); } })
        )) : null,
      probe ? el('div', { className: 'myfeeds-look-ed__modes' },
        el('label', { className: 'myfeeds-look-ed__mode' + (mode === 'url' ? ' is-on' : '') + (probe.https ? '' : ' is-off') },
          el('input', { type: 'radio', name: 'mflook-mode', checked: mode === 'url', disabled: !probe.https, onChange: function () { setMode('url'); } }),
          el('span', null, el('strong', null, 'Link the image'), ' - shown from ' + probe.host + ', nothing copied.',
            el('small', null, probe.https
              ? 'Each visit loads it from that server, which sees your readers\' IP addresses: name it in your privacy policy. If the source removes the image, the photo disappears and the product list stays.'
              : 'Not possible: the image is not served over https.'))),
        el('label', { className: 'myfeeds-look-ed__mode' + (mode === 'media' ? ' is-on' : '') },
          el('input', { type: 'radio', name: 'mflook-mode', checked: mode === 'media', onChange: function () { setMode('media'); } }),
          el('span', null, el('strong', null, 'Copy into my media library'), ' - faster, no outside connection, stays even if the source changes.',
            el('small', null, 'Copying needs a licence for the image itself.')))
      ) : null,
      probe ? el('label', { className: 'myfeeds-look-ed__rights' },
        el('input', { type: 'checkbox', checked: ok, onChange: function (e) { setOk(e.target.checked); } }),
        el('span', null, mode === 'url'
          ? 'The image is published at its source by its rights holder or with their permission, and I am responsible for showing it on my site.'
          : 'I have the right to copy and use this image on my site (my own photo, a press image the brand allows, or a free licence such as Unsplash), and I am responsible for this.')) : null,
      probe ? el('button', { type: 'button', className: 'myfeeds-look-ed__pick', onClick: use, disabled: !ok || busy }, busy ? 'Importing...' : 'Use this image') : null
    );
  }

  // ---------------------------------------------------------------------
  // The block
  // ---------------------------------------------------------------------
  function Edit(props) {
    var a = props.attributes;
    var set = props.setAttributes;
    var points = Array.isArray(a.points) ? a.points : [];
    var selState = useState(-1);
    var sel = selState[0], setSel = selState[1];
    var focusState = useState(null);
    var focusUid = focusState[0], setFocusUid = focusState[1];
    var dragState = useState(null);
    var drag = dragState[0], setDrag = dragState[1];
    var stageRef = useRef(null);
    var movedRef = useRef(false);
    // A row on the move: { from, over, list }, or null.
    var rowDragState = useState(null);
    var rowDrag = rowDragState[0], setRowDrag = rowDragState[1];
    var urlFormState = useState(false);
    var urlForm = urlFormState[0], setUrlForm = urlFormState[1];
    var blockProps = be.useBlockProps ? be.useBlockProps({ className: 'myfeeds-look-ed' }) : { className: 'myfeeds-look-ed' };
    var hasImage = a.imageSource === 'url' ? !!a.imageUrl : !!a.imageId;

    function key(p, i) { return p.uid || 'i' + i; }

    function pick(media) {
      if (!media || !media.id) return;
      var url = media.sizes && media.sizes.large ? media.sizes.large.url : media.url;
      set({ imageSource: 'media', imageId: media.id, imageUrl: url, imageWidth: 0, imageHeight: 0, imageAlt: '', rightsConfirmed: '' });
    }

    // Rows reorder by drag; the number on the photo is the position in
    // the list, so moving a row renumbers its point.
    function move(a1, b1) {
      if (a1 < 0 || b1 < 0 || a1 === b1) return;
      var next = points.slice();
      var item = next.splice(a1, 1)[0];
      next.splice(b1, 0, item);
      set({ points: next });
      setSel(b1);
    }

    // Rows reorder by dragging their grip. Pointer events, not the
    // browser's drag and drop: in the editor's canvas the block editor
    // takes drag events for moving whole blocks, and a row never started
    // one (Marlon, 28.10.: the rows did not move). Mouse, pen and touch
    // alike; the arrow keys do the same on a focused grip.
    //
    // Where the row lands: above the first row whose middle the pointer
    // is above - one less on the way down, because the row leaves its
    // own place first.
    function rowTarget(list, from, y) {
      var rows = list ? list.querySelectorAll('.myfeeds-look-ed__row') : [];
      if (!rows.length) return from;
      var k = rows.length;
      for (var j = 0; j < rows.length; j++) {
        var r = rows[j].getBoundingClientRect();
        if (y < r.top + r.height / 2) { k = j; break; }
      }
      var to = k > from ? k - 1 : k;
      return Math.max(0, Math.min(rows.length - 1, to));
    }

    function gripDown(i, e) {
      if (e.button !== undefined && e.button !== 0) return;
      e.preventDefault();
      e.stopPropagation();
      var grip = e.currentTarget;
      if (grip && grip.setPointerCapture && e.pointerId !== undefined) {
        try { grip.setPointerCapture(e.pointerId); } catch (err) { /* the move still works without it */ }
      }
      setSel(i);
      setRowDrag({ from: i, over: i, list: grip && grip.closest ? grip.closest('.myfeeds-look-ed__rows') : null });
    }

    function gripMove(e) {
      if (!rowDrag) return;
      var over = rowTarget(rowDrag.list, rowDrag.from, e.clientY);
      if (over !== rowDrag.over) setRowDrag({ from: rowDrag.from, over: over, list: rowDrag.list });
    }

    function gripUp() {
      if (!rowDrag) return;
      var d = rowDrag;
      setRowDrag(null);
      if (d.over !== d.from) move(d.from, d.over);
    }

    function gripKey(i, e) {
      var to = e.key === 'ArrowUp' ? i - 1 : (e.key === 'ArrowDown' ? i + 1 : -1);
      if (to < 0 || to >= points.length) return;
      e.preventDefault();
      move(i, to);
    }

    function fromUrl(patch) {
      set(patch);
      setUrlForm(false);
    }

    function at(e) {
      var r = stageRef.current.getBoundingClientRect();
      return { x: clamp((e.clientX - r.left) / r.width * 100), y: clamp((e.clientY - r.top) / r.height * 100) };
    }

    function updatePoint(i, patch) {
      set({ points: points.map(function (p, j) { return j === i ? Object.assign({}, p, patch) : p; }) });
    }

    function addPoint(e) {
      if (e.target !== e.currentTarget && e.target.tagName !== 'IMG') return;
      var pos = at(e);
      var uid = newUid();
      var next = points.concat([{ uid: uid, x: pos.x, y: pos.y, kind: '', id: '', brand: '', name: '', note: '' }]);
      set({ points: next });
      setSel(next.length - 1);
      setFocusUid(uid);
    }

    // Dragging keeps its position locally and writes once on release: one
    // undo step per move, not one per pixel.
    function startDrag(i, e) {
      e.preventDefault();
      e.stopPropagation();
      movedRef.current = false;
      if (e.currentTarget.setPointerCapture) e.currentTarget.setPointerCapture(e.pointerId);
      setDrag({ i: i, x: points[i].x, y: points[i].y });
    }
    function moveDrag(e) {
      if (!drag) return;
      movedRef.current = true;
      var pos = at(e);
      setDrag({ i: drag.i, x: pos.x, y: pos.y });
    }
    function endDrag() {
      if (!drag) return;
      if (movedRef.current) updatePoint(drag.i, { x: drag.x, y: drag.y });
      setSel(drag.i);
      if (!movedRef.current) setFocusUid(key(points[drag.i], drag.i));
      setDrag(null);
    }

    var inspector = el(be.InspectorControls, null,
      el(c.PanelBody, { title: 'Look', className: 'myfeeds-look-panel' },
        el(c.ToggleControl, {
          label: 'List the products under the photo',
          help: 'Recommended: the list is how Google reads the products, and on a phone it is easier to tap than small points.',
          checked: a.showList !== false,
          onChange: function (v) { set({ showList: v }); }
        }),
        el(c.ToggleControl, {
          label: 'Show prices',
          help: 'On the points and in the list.' + amazonHelp('prices'),
          checked: a.showPrices !== false,
          onChange: function (v) { set({ showPrices: v }); }
        }),
        el(c.ToggleControl, {
          label: 'Show product images',
          help: 'A small picture beside each product.' + amazonHelp('pictures'),
          checked: a.showImages !== false,
          onChange: function (v) { set({ showImages: v }); }
        }),
        // Two short buttons side by side; the sidebar is narrow.
        hasImage ? el('div', { style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px', margin: '4px 0 16px' } },
          el(be.MediaUploadCheck, null,
            el(be.MediaUpload, {
              onSelect: pick,
              allowedTypes: ['image'],
              value: a.imageId,
              render: function (o) { return el(c.Button, { variant: 'secondary', isSecondary: true, size: 'compact', style: { justifyContent: 'center', width: '100%' }, onClick: o.open }, 'Media library'); }
            })),
          el(c.Button, { variant: 'secondary', isSecondary: true, size: 'compact', style: { justifyContent: 'center', width: '100%' }, onClick: function () { setUrlForm(true); } }, 'From a URL')) : null,
        hasImage ? el(c.RangeControl, {
          label: 'Photo width (px)',
          help: 'Empty = as wide as the text column. Phones always show it full width.',
          value: a.width || undefined,
          min: 200,
          max: 1200,
          step: 10,
          allowReset: true,
          onChange: function (v) { set({ width: v ? Math.round(v) : 0 }); }
        }) : null,
        a.imageSource === 'url'
          ? el(c.TextControl, { label: 'Alt text', value: a.imageAlt || '', onChange: function (v) { set({ imageAlt: v }); },
              help: 'Shown from ' + ((a.imageUrl.match(/^https?:\/\/([^\/]+)/) || [])[1] || 'its source') + '. Name that server in your privacy policy.' })
          : el('p', { style: { color: '#757575', fontSize: '12px', marginTop: '12px' } }, 'Alt text and caption come from the photo in the media library.')
      ),
      el(c.PanelBody, { title: 'Image credit', initialOpen: !!a.creditText },
        el(c.TextControl, { label: 'Shown as "by ..."', value: a.creditText || '', placeholder: 'e.g. Pinterest', onChange: function (v) { set({ creditText: v }); } }),
        el(c.TextControl, { label: 'Links to (optional)', value: a.creditUrl || '', placeholder: 'https://', onChange: function (v) { set({ creditUrl: v }); },
          help: 'An outgoing nofollow link. Credit the creator where a licence asks for it (Creative Commons "BY").' }),
        a.rightsConfirmed ? el('p', { style: { color: '#757575', fontSize: '12px' } }, 'Rights confirmed ' + a.rightsConfirmed + '.') : null
      )
    );

    if (urlForm) {
      return el('div', blockProps, inspector, el(UrlImage, { done: fromUrl, cancel: function () { setUrlForm(false); } }));
    }

    if (!hasImage) {
      return el('div', blockProps, inspector,
        el('div', { className: 'myfeeds-look-ed__empty' },
          el('h4', null, 'MyFeeds Look'),
          el('p', null, amazon.links
            ? 'Choose a photo, then click on it wherever a product is. Each point links to Amazon or to a product from your feeds.'
            : 'Choose a photo, then click on it wherever a product is. Each point links to a product from your feeds.'),
          el('div', { className: 'myfeeds-look-ed__choices' },
            el(be.MediaUploadCheck, null,
              el(be.MediaUpload, {
                onSelect: pick,
                allowedTypes: ['image'],
                render: function (o) { return el('button', { type: 'button', className: 'myfeeds-look-ed__pick', onClick: o.open }, 'Choose photo'); }
              })),
            el('button', { type: 'button', className: 'myfeeds-look-ed__pick is-secondary', onClick: function () { setUrlForm(true); } }, 'Image from a URL'))));
    }

    var align = a.imageAlign || 'center';
    var toolbar = be.BlockControls && be.BlockAlignmentToolbar
      ? el(be.BlockControls, { group: 'block' },
          el(be.BlockAlignmentToolbar, {
            value: align,
            controls: ['left', 'center', 'right'],
            onChange: function (v) { set({ imageAlign: v || 'center' }); }
          }))
      : null;

    var stage = el('div', {
        className: 'myfeeds-look-ed__stage is-' + align,
        style: a.width ? { maxWidth: a.width + 'px' } : undefined,
        ref: stageRef,
        onClick: addPoint,
        onPointerMove: moveDrag,
        onPointerUp: endDrag
      },
      el('img', { src: a.imageUrl, alt: '', draggable: false, referrerPolicy: 'no-referrer' }),
      points.map(function (p, i) {
        var x = drag && drag.i === i ? drag.x : p.x;
        var y = drag && drag.i === i ? drag.y : p.y;
        return el('button', {
          key: key(p, i),
          type: 'button',
          className: 'myfeeds-look-ed__dot' + (i === sel ? ' is-selected' : '') + (p.id ? '' : ' is-empty'),
          'data-myfeeds-point': i,
          style: { left: x + '%', top: y + '%' },
          title: p.name || 'Not set yet',
          onPointerDown: function (e) { startDrag(i, e); },
          onClick: function (e) { e.stopPropagation(); }
        }, String(i + 1));
      })
    );

    return el('div', blockProps, inspector, toolbar,
      stage,
      el('p', { className: 'myfeeds-look-ed__hint' },
        points.length
          ? 'Click the photo to add a point, drag a point to move it.'
          : 'Click on the photo wherever a product is.'),
      points.length ? el('ol', { className: 'myfeeds-look-ed__rows' },
        points.map(function (p, i) {
          return el(Row, {
            key: key(p, i),
            point: p,
            number: i + 1,
            selected: i === sel,
            focus: focusUid === key(p, i),
            select: function () { setSel(i); },
            dragging: !!rowDrag && rowDrag.from === i,
            over: !!rowDrag && rowDrag.over === i && rowDrag.from !== i,
            overBelow: !!rowDrag && rowDrag.over === i && rowDrag.over > rowDrag.from,
            gripDown: function (e) { gripDown(i, e); },
            gripMove: gripMove,
            gripUp: gripUp,
            gripCancel: function () { setRowDrag(null); },
            gripKey: function (e) { gripKey(i, e); },
            update: function (patch) { updatePoint(i, patch); },
            remove: function () { set({ points: points.filter(function (_, j) { return j !== i; }) }); setSel(-1); }
          });
        })) : null
    );
  }

  wp.blocks.registerBlockType('myfeeds/look', {
    apiVersion: 2,
    title: 'MyFeeds Look',
    description: 'Your own photo with numbered points that link to the products in it.',
    icon: 'location',
    category: 'widgets',
    keywords: ['shop the look', 'hotspot', 'amazon', 'outfit'],
    attributes: {
      imageId: { type: 'number', default: 0 },
      imageUrl: { type: 'string', default: '' },
      points: { type: 'array', default: [] },
      showList: { type: 'boolean', default: true },
      showPrices: { type: 'boolean', default: true },
      showImages: { type: 'boolean', default: true },
      imageSource: { type: 'string', default: 'media' },
      imageWidth: { type: 'number', default: 0 },
      imageHeight: { type: 'number', default: 0 },
      imageAlt: { type: 'string', default: '' },
      creditText: { type: 'string', default: '' },
      creditUrl: { type: 'string', default: '' },
      rightsConfirmed: { type: 'string', default: '' },
      width: { type: 'number', default: 0 },
      imageAlign: { type: 'string', default: 'center' }
    },
    edit: Edit,
    save: function () { return null; }
  });
})();
