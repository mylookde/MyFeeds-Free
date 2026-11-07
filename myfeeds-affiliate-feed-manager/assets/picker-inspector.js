/*
 * The product picker's sidebar and the parts under the block: the display
 * (grid, carousel, recommendation card, comparison table), the texts of a
 * recommendation card or comparison table, the words every block uses, and
 * the Amazon tab (products by link or ASIN). The block itself is the editor
 * bundle's (build/index.js); this file adds to it through one
 * editor.BlockEdit filter, and it is the only one that draws the block.
 *
 * What else a site may offer in the picker hangs on wp.hooks filters, each
 * called on every render with what the block has:
 *
 *   myfeeds.picker.sourceIsDynamic (false, attributes)   the block fills itself
 *   myfeeds.picker.displayModes    (options, ctx)        [{ label, value }]
 *   myfeeds.picker.displayStyles   (styles, ctx)         the comparison table's
 *                                                        styles; a second dropdown
 *                                                        only with more than one
 *   myfeeds.picker.inspector       ([], ctx)             more elements for the
 *                                                        Display Settings panel
 *   myfeeds.picker.tabs            (tabs, ctx)           [{ key, label, amazon?, sourceMode? }]
 *   myfeeds.picker.tabBody         (null, key, ctx)      what a tab other than
 *                                                        'manual' and 'amazon' shows
 *   myfeeds.picker.extras          ([], ctx)             elements under the block
 *   myfeeds.picker.amazonPanel     (AmazonPanel, ctx)    the component the Amazon
 *                                                        tab shows; it gets
 *                                                        { attributes, setAttributes }
 *
 * The Amazon tab's reading of "ASIN or Amazon link" and its parts are
 * window.myfeedsAmazonAsin, for every block that takes one.
 *
 * ctx: { attributes, setAttributes, displayMode, dynamicActive, clientId,
 * isSelected }; extras also get { tab, setTab, wrapperRef } - wrapperRef is
 * the element around the bundle's block.
 *
 * Shared byte for byte by both plugins.
 */
;(function () {
  "use strict";

  if (!window.wp || !wp.hooks || !wp.compose || !wp.element || !wp.components) {
    return;
  }

  var BLOCK = 'myfeeds/product-picker';
  var addFilter = wp.hooks.addFilter;
  var applyFilters = wp.hooks.applyFilters;
  var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
  var Fragment = wp.element.Fragment;
  var createElement = wp.element.createElement;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var useRef = wp.element.useRef;
  var InspectorControls = (wp.blockEditor || wp.editor || {}).InspectorControls;
  var PanelBody = wp.components.PanelBody;
  var SelectControl = wp.components.SelectControl;
  var ToggleControl = wp.components.ToggleControl;
  var TextControl = wp.components.TextControl;
  var Button = wp.components.Button;
  var Spinner = wp.components.Spinner;
  var apiFetch = wp.apiFetch;

  if (!InspectorControls || !PanelBody || !SelectControl) {
    return;
  }

  // The editor bundle registers the block with selectedProducts only, and
  // the editor drops from a saved block every attribute its own list does
  // not name. The same list as myfeeds_picker_block_attributes(), which
  // also prints as an inline script before the bundle; this copy is for a
  // page whose inline scripts an optimiser took out.
  var ATTRIBUTES = {
    amazonProducts:   { type: 'array',   default: [] },
    displayMode:      { type: 'string',  default: 'grid' },
    comparisonStyle:  { type: 'string',  default: 'featured' },
    pickTexts:        { type: 'object',  default: {} },
    pickFavourite:    { type: 'string',  default: '' },
    autoplay:         { type: 'boolean', default: false },
    autoplayInterval: { type: 'number',  default: 3 },
    arrowColor:       { type: 'string',  default: '#333333' },
    sourceMode:       { type: 'string',  default: 'manual' },
    categoryId:       { type: 'number',  default: 0 },
    categorySlugPath: { type: 'string',  default: '' },
    categoryLabel:    { type: 'string',  default: '' },
    categoryCount:    { type: 'number',  default: 10 },
    dynamicSort:      { type: 'string',  default: 'popular' },
    showMoreButton:   { type: 'boolean', default: true },
    moreButtonText:   { type: 'string',  default: 'More products' },
    moreButtonColor:  { type: 'string',  default: '' },
    filterBrands:     { type: 'array',   default: [], items: { type: 'string' } },
    filterColours:    { type: 'array',   default: [], items: { type: 'string' } },
    filterMerchants:  { type: 'array',   default: [], items: { type: 'string' } },
    filterStyles:     { type: 'array',   default: [], items: { type: 'string' } },
    filterSearch:     { type: 'string',  default: '' },
    filterMinPrice:   { type: 'number',  default: 0 },
    filterMaxPrice:   { type: 'number',  default: 0 },
    filterOnSale:     { type: 'boolean', default: false },
    filterInStock:    { type: 'boolean', default: false }
  };

  addFilter(
    'blocks.registerBlockType',
    'myfeeds/picker-attributes',
    function (settings, name) {
      if (name !== BLOCK) {
        return settings;
      }
      settings.attributes = Object.assign({}, settings.attributes || {}, ATTRIBUTES);
      return settings;
    }
  );

  var DISPLAY_MODES = [
    { label: 'Grid', value: 'grid' },
    { label: 'Carousel', value: 'carousel' },
    { label: 'Recommendation card', value: 'recommendation' },
    { label: 'Comparison table', value: 'comparison' }
  ];
  var DISPLAY_STYLES = [
    { label: 'Big pick on top', value: 'featured' }
  ];

  // A filter's answer as a list, whatever a filter made of it.
  function list(value) {
    return Array.isArray(value) ? value.filter(Boolean) : [];
  }

  function hasValue(options, value) {
    return options.some(function (o) { return o && o.value === value; });
  }

  function children(elements) {
    return createElement.apply(null, [Fragment, null].concat(elements));
  }

  // ---------------------------------------------------------------------------
  // The tabs above the block, when there is more than one way to fill it
  // (myfeeds.picker.tabs). A tab with amazon: true is drawn in Amazon's
  // orange; only a tab with a sourceMode changes what the block renders.
  // ---------------------------------------------------------------------------
  function SourceModeToggle(props) {
    var value = props.value;
    var onChange = props.onChange;
    var tabs = props.tabs || [];
    var btn = function (tab) {
      var active = value === tab.key;
      var amazon = !!tab.amazon;
      return createElement('button', {
        key: 'tab-' + tab.key,
        type: 'button',
        className: 'myfeeds-picker-tab' + (amazon ? ' is-amazon' : '') + (active ? ' is-active' : ''),
        'aria-pressed': active,
        onClick: function () { onChange(tab.key); },
        style: {
          flex: 1, padding: '10px 18px', border: 'none',
          background: active
            ? (amazon ? 'linear-gradient(135deg,#ff9900 0%,#f97316 100%)' : 'linear-gradient(135deg,#667eea 0%,#764ba2 100%)')
            : 'transparent',
          color: active ? '#fff' : (amazon ? '#c45500' : '#5b5673'),
          fontSize: '13px', fontWeight: 600, cursor: 'pointer',
          borderRadius: '999px',
          boxShadow: active ? (amazon ? '0 2px 10px rgba(249,115,22,.3)' : '0 2px 10px rgba(102,126,234,.25)') : 'none',
          transition: 'all .18s ease',
          letterSpacing: '.01em',
          whiteSpace: 'nowrap'
        }
      }, tab.label);
    };
    return createElement('div', {
      'data-myfeeds-tour': 'source-toggle',
      style: {
        display: 'flex', gap: '4px', padding: '4px',
        background: 'rgba(102,126,234,.06)',
        borderRadius: '999px',
        marginBottom: '16px',
        maxWidth: tabs.length > 2 ? '600px' : '440px'
      }
    }, tabs.map(btn));
  }


  // ---------------------------------------------------------------------------
  // PickPanel - the texts of a recommendation card or comparison table.
  // Nothing here has to be filled in: the grey text in each field is what
  // the page shows anyway, worked out by the server from a real render of
  // this block. Typing overwrites it for this block, an emptied field goes
  // back to automatic.
  // ---------------------------------------------------------------------------
  function getDisplaysConfig() {
    return (window.myfeedsData && window.myfeedsData.displays) || { words: {}, defaults: {} };
  }

  function pickBlockIds(attributes) {
    var ids = [];
    (attributes.selectedProducts || []).concat(attributes.amazonProducts || []).forEach(function (p) {
      if (p && p.id != null) ids.push(String(p.id));
    });
    return ids;
  }

  function PickPanel(props) {
    var attributes = props.attributes;
    var setAttributes = props.setAttributes;
    var config = getDisplaysConfig();
    var words = config.words || {};
    var chips = [words.budget, words.premium, words.gift, words.beginners].filter(Boolean);

    var itemsState = useState(null);
    var items = itemsState[0];
    var setItems = itemsState[1];
    var failedState = useState(false);
    var failed = failedState[0];
    var setFailed = failedState[1];
    var wordsSeenState = useState(0);
    // Lines the server adds about this block (myfeeds_pick_texts_notes).
    var notesState = useState([]);

    var isComparison = attributes.displayMode === 'comparison';
    var texts = (attributes.pickTexts && typeof attributes.pickTexts === 'object') ? attributes.pickTexts : {};
    var ids = pickBlockIds(attributes);
    // From the block, not the server's answer: a click shows at once.
    var favourite = attributes.pickFavourite ? String(attributes.pickFavourite) : (ids[0] || '');
    var signature = JSON.stringify([
      attributes.displayMode, attributes.comparisonStyle, attributes.pickFavourite, ids,
      (attributes.amazonProducts || []).map(function (p) { return p ? [p.brand, p.name, p.note] : null; }),
      wordsSeenState[0]
    ]);

    // New words for all blocks change what the grey texts say.
    useEffect(function () {
      if (!window.addEventListener) {
        return undefined;
      }
      function reload() {
        wordsSeenState[1](function (n) { return n + 1; });
      }
      window.addEventListener('myfeeds-pick-words', reload);
      return function () { window.removeEventListener('myfeeds-pick-words', reload); };
    }, []);

    useEffect(function () {
      var cancelled = false;
      var timer = window.setTimeout(function () {
        apiFetch({
          path: '/myfeeds/v1/picker/pick-texts',
          method: 'POST',
          data: {
            displayMode: attributes.displayMode,
            comparisonStyle: attributes.comparisonStyle,
            pickFavourite: attributes.pickFavourite,
            selectedProducts: attributes.selectedProducts || [],
            amazonProducts: attributes.amazonProducts || []
          }
        }).then(function (res) {
          if (cancelled) return;
          setItems((res && res.items) || []);
          notesState[1](res && Array.isArray(res.notes) ? res.notes.filter(function (n) { return typeof n === 'string' && n; }) : []);
          setFailed(false);
        }).catch(function () {
          if (cancelled) return;
          setFailed(true);
        });
      }, 300);
      return function () {
        cancelled = true;
        window.clearTimeout(timer);
      };
    }, [signature]);

    // A product the author took out of the block takes its words with it.
    // One that is only out of stock keeps them: it comes back.
    var idKey = ids.join('\n');
    useEffect(function () {
      var patch = {};
      var stale = Object.keys(texts).filter(function (key) { return ids.indexOf(key) === -1; });
      if (stale.length) {
        var kept = {};
        Object.keys(texts).forEach(function (key) {
          if (ids.indexOf(key) !== -1) kept[key] = texts[key];
        });
        patch.pickTexts = kept;
      }
      if (attributes.pickFavourite && ids.indexOf(String(attributes.pickFavourite)) === -1) {
        patch.pickFavourite = '';
      }
      if (Object.keys(patch).length) {
        setAttributes(patch);
      }
    }, [idKey]);

    function write(key, field, value) {
      var next = Object.assign({}, texts);
      var own = Object.assign({}, next[key] || {});
      if (value) {
        own[field] = value;
      } else {
        delete own[field];
      }
      if (Object.keys(own).length) {
        next[key] = own;
      } else {
        delete next[key];
      }
      setAttributes({ pickTexts: next });
    }

    var head = createElement('div', { className: 'myfeeds-pick-panel__head' },
      createElement('strong', null, isComparison ? 'Comparison table' : 'Recommendation card'),
      createElement('span', null, 'The grey texts appear on the page by themselves. Type or click only to change one.')
    );

    if (!ids.length) {
      return null;
    }
    if (failed && !items) {
      return createElement('div', { className: 'myfeeds-pick-panel' }, head,
        createElement('p', { className: 'myfeeds-pick-panel__empty' }, 'The texts could not be loaded. The page still shows them.'));
    }
    if (!items) {
      return createElement('div', { className: 'myfeeds-pick-panel' }, head, createElement(Spinner));
    }

    var hidden = ids.length - items.length;
    return createElement('div', { className: 'myfeeds-pick-panel' }, head,
      items.map(function (item) {
        var own = texts[item.key] || {};
        return createElement('div', { className: 'myfeeds-pick-row', key: item.key },
          createElement('div', { className: 'myfeeds-pick-row__thumb' },
            item.image ? createElement('img', { src: item.image, alt: '', loading: 'lazy' }) : createElement('span', null, 'No image')
          ),
          createElement('div', { className: 'myfeeds-pick-row__body' },
            createElement('div', { className: 'myfeeds-pick-row__name' }, item.name),
            createElement('div', { className: 'myfeeds-pick-row__chips' },
              chips.map(function (chip) {
                var on = own.label === chip;
                return createElement('button', {
                  key: chip,
                  type: 'button',
                  className: 'myfeeds-pick-chip' + (on ? ' is-on' : ''),
                  'aria-pressed': on,
                  onClick: function () { write(item.key, 'label', on ? '' : chip); }
                }, chip);
              })
            ),
            createElement('input', {
              type: 'text',
              className: 'myfeeds-pick-row__field',
              'aria-label': 'Label',
              maxLength: config.labelMax || 32,
              placeholder: item.auto_label,
              value: own.label || '',
              onChange: function (e) { write(item.key, 'label', e.target.value); }
            }),
            createElement('input', {
              type: 'text',
              className: 'myfeeds-pick-row__field',
              'aria-label': 'Sentence',
              maxLength: config.reasonMax || 140,
              placeholder: item.auto_reason || 'No sentence. Add one if you like.',
              value: own.reason || '',
              onChange: function (e) { write(item.key, 'reason', e.target.value); }
            })
          ),
          isComparison
            ? createElement('label', { className: 'myfeeds-pick-row__fav' },
                createElement('input', {
                  type: 'radio',
                  name: 'myfeeds-pick-fav-' + (props.clientId || ''),
                  checked: item.key === favourite,
                  onChange: function () { setAttributes({ pickFavourite: item.key }); }
                }),
                ' Favourite'
              )
            : null
        );
      }),
      notesState[0].map(function (line, i) {
        return createElement('p', { key: 'note-' + i, className: 'myfeeds-pick-panel__empty' }, line);
      }),
      hidden > 0
        ? createElement('p', { className: 'myfeeds-pick-panel__empty' },
            hidden === 1
              ? '1 product is not shown on the page right now: sold out or gone, or an Amazon product without a name.'
              : hidden + ' products are not shown on the page right now: sold out or gone, or Amazon products without a name.')
        : null
    );
  }

  // The words every block on the site uses. Empty goes back to the default,
  // which follows the site's language.
  function PickWordsPanel() {
    var config = getDisplaysConfig();
    var wordsState = useState(config.words || {});
    var words = wordsState[0];
    var setWords = wordsState[1];
    var statusState = useState('');
    var status = statusState[0];
    var setStatus = statusState[1];
    var defaults = config.defaults || {};

    if (!config.canWords) {
      return null;
    }

    var fields = [
      ['our', 'Favourite'], ['budget', 'Cheaper'], ['premium', 'Dearer'], ['alternative', 'Other products'],
      ['note', 'Paid-links note'], ['sizes', 'Sizes line (%d is the number)'], ['view_at', 'Button (%s is the shop)'],
      ['view_amazon', 'Button for Amazon'], ['view_product', 'Button without a shop name']
    ];

    function set(key, value) {
      var next = Object.assign({}, words);
      next[key] = value;
      setWords(next);
      setStatus('');
    }

    function save() {
      var data = { show_note: words.show_note !== false };
      fields.forEach(function (f) {
        data[f[0]] = words[f[0]] === defaults[f[0]] ? '' : (words[f[0]] || '');
      });
      setStatus('saving');
      apiFetch({ path: '/myfeeds/v1/picker/words', method: 'POST', data: data }).then(function (res) {
        if (res && res.words) {
          config.words = res.words;
        }
        setStatus('saved');
        if (window.dispatchEvent && window.CustomEvent) {
          window.dispatchEvent(new window.CustomEvent('myfeeds-pick-words'));
        }
      }).catch(function () {
        setStatus('failed');
      });
    }

    return createElement(PanelBody, { title: 'Words on all blocks', initialOpen: false },
      createElement(ToggleControl, {
        label: 'Show the paid-links note',
        help: 'Most sites have to say that a link earns them money.',
        checked: words.show_note !== false,
        onChange: function (val) { set('show_note', val); }
      }),
      fields.map(function (f) {
        return createElement(TextControl, {
          key: f[0],
          label: f[1],
          value: words[f[0]] || '',
          maxLength: config.wordMax || 160,
          placeholder: defaults[f[0]] || '',
          onChange: function (val) { set(f[0], val); }
        });
      }),
      createElement(Button, { variant: 'secondary', isBusy: status === 'saving', onClick: save }, 'Save for all blocks'),
      status === 'saved' ? createElement('p', null, 'Saved.') : null,
      status === 'failed' ? createElement('p', null, 'Could not save. Try again.') : null
    );
  }

  // ---------------------------------------------------------------------------
  // Amazon by link or ASIN (myfeedsData.amazon): the author pastes an ASIN or
  // an Amazon product link and names the product, and the card links to
  // Amazon with the site's partner tag. Amazon products live in their own
  // attribute, never in selectedProducts: a post may keep an Amazon
  // product's ASIN and nothing else of Amazon's, so there is no snapshot
  // for the bundle's tiles to render. The panel asks the site nothing.
  // ---------------------------------------------------------------------------
  function getAmazonConfig() {
    return (window.myfeedsData && window.myfeedsData.amazon) || { allowed: false };
  }

  function amazonCountOf(attributes) {
    return attributes && Array.isArray(attributes.amazonProducts)
      ? attributes.amazonProducts.filter(function (p) { return p && p.id; }).length
      : 0;
  }

  // "B0D1XD1ZV3", "https://www.amazon.de/dp/B0D1XD1ZV3?th=1",
  // "amazon.co.uk/gp/product/0141036141" -> { asin, marketplace }, or
  // { error }. The marketplace comes from the link when there is one, so a
  // product copied from amazon.co.uk is not looked up on amazon.de.
  function parseAsinInput(raw, fallbackMarketplace, marketplaces) {
    var text = String(raw || '').trim();
    if (!text) {
      return { error: '' };
    }
    if (/^[A-Za-z0-9]{10}$/.test(text)) {
      return { asin: text.toUpperCase(), marketplace: fallbackMarketplace };
    }

    var u;
    try {
      u = new URL(/^[a-z]+:\/\//i.test(text) ? text : 'https://' + text);
    } catch (e) {
      return { error: 'That is neither an ASIN (10 letters and digits) nor an Amazon product link.' };
    }

    var host = u.hostname.toLowerCase();
    if (/^(amzn\.to|amzn\.eu|a\.co)$/.test(host)) {
      return { error: 'Short links do not show the product. Open the link and copy the address from the browser bar instead.' };
    }
    if (!/(^|\.)amazon\.[a-z.]+$/.test(host)) {
      return { error: 'That is not an Amazon link.' };
    }

    var m = u.pathname.match(/\/(?:dp|gp\/product|gp\/aw\/d|exec\/obidos\/ASIN|o\/ASIN)\/([A-Za-z0-9]{10})(?:[\/?#]|$)/);
    if (!m) {
      return { error: 'This Amazon link does not point to one product. Open the product page and copy its address.' };
    }

    var marketplace = 'www.' + host.replace(/^(www|smile|m)\./, '');
    var name = nameFromAmazonPath(u.pathname);
    // A link from an Amazon site the owner has no partner tag for would
    // earn nothing there. The same ASIN goes to the owner's own site
    // instead, and the caller says so (most products exist on both).
    if (marketplaces && !Object.prototype.hasOwnProperty.call(marketplaces, marketplace)) {
      return { asin: m[1].toUpperCase(), marketplace: fallbackMarketplace, moved: marketplace, name: name };
    }

    return { asin: m[1].toUpperCase(), marketplace: marketplace, name: name };
  }

  // "/Brooks-Revel-Running-Shoes/dp/B0..." carries the product's words, so
  // the author need not type them. The field stays open to change. Short
  // links and bare /dp/ links have none.
  function nameFromAmazonPath(pathname) {
    var m = String(pathname || '').match(/^\/(?:-\/[a-z]{2}\/)?([^\/]+)\/(?:dp|gp\/product)\//);
    if (!m || m[1].indexOf('=') !== -1) {
      return '';
    }
    var words;
    try {
      words = decodeURIComponent(m[1]);
    } catch (e) {
      return '';
    }
    words = words.replace(/[-_]+/g, ' ').replace(/\s+/g, ' ').trim();
    return /[a-zÀ-ɏ]{3}/i.test(words) ? words : '';
  }

  function movedNote(from, to) {
    return 'That link is from ' + from.replace(/^www\./, '') + '. Your partner tag is for ' + to.replace(/^www\./, '')
      + ', so the product links there - clicks only count on your own Amazon site. Check that it is sold there.';
  }

  // Same id the server builds in MyFeeds_Amazon_Compliance::make_id().
  function amazonId(marketplace, asin) {
    var code = String(marketplace || '').replace('www.amazon.', '').replace(/\./g, '').toLowerCase();
    return 'amzn:' + code + ':' + asin;
  }

  var AMAZON_TEXTS = {
    needTag: 'Save your Amazon partner tag first. With it you add Amazon products by link or ASIN, and each card links to Amazon with your tag.',
    byHand: 'Added. Check brand and name below. The card links with your partner tag.',
    note: 'Add Amazon products by link or ASIN and name them yourself: the card shows your words and links to Amazon with your partner tag.',
    manualTitle: 'In this block'
  };

  // One product the author names: its ASIN, brand, name and note, and the
  // way out. Brand and name are what the reader sees on the card; the
  // note is optional. A plain function, not a component, so a panel can
  // draw it in its own render.
  function amazonManualPick(pick, onChange, onRemove) {
    var id = String(pick.id);
    var asin = id.split(':')[2] || id;
    return createElement('div', { key: 'man-' + id, className: 'myfeeds-amz-manual' + (pick.name ? '' : ' is-incomplete') },
      createElement('div', { className: 'myfeeds-amz-manual__head' },
        createElement('code', null, asin),
        createElement(Button, { variant: 'link', isLink: true, isDestructive: true, onClick: onRemove }, 'Remove')
      ),
      createElement(TextControl, { label: 'Brand', value: pick.brand || '', onChange: function (v) { onChange('brand', v); } }),
      createElement(TextControl, { label: 'Product name', value: pick.name || '', onChange: function (v) { onChange('name', v); },
        help: pick.name ? null : 'Needed - the card stays hidden on the page until it has a name.' }),
      createElement(TextControl, { label: 'Your note (optional)', value: pick.note || '', onChange: function (v) { onChange('note', v); },
        placeholder: 'e.g. runs half a size small' })
    );
  }

  // One reading of "ASIN or Amazon link" for every block that takes one
  // (the Look block asks here instead of keeping a second copy), and the
  // panel's parts for a panel that takes its place (myfeeds.picker.amazonPanel).
  window.myfeedsAmazonAsin = {
    parse: parseAsinInput,
    id: amazonId,
    movedNote: movedNote,
    manualPick: amazonManualPick,
    texts: AMAZON_TEXTS
  };

  function AmazonPanel(props) {
    var attributes = props.attributes;
    var setAttributes = props.setAttributes;
    var config = getAmazonConfig();
    var texts = AMAZON_TEXTS;

    var asinState = useState('');
    var asinInput = asinState[0];
    var setAsinInput = asinState[1];

    var asinErrorState = useState('');
    var asinError = asinErrorState[0];
    var setAsinError = asinErrorState[1];

    var asinNoteState = useState('');
    var asinNote = asinNoteState[0];
    var setAsinNote = asinNoteState[1];

    var picks = Array.isArray(attributes.amazonProducts) ? attributes.amazonProducts : [];
    var pickIds = picks.map(function (p) { return p && p.id ? String(p.id) : ''; }).filter(Boolean);

    // The ASIN goes in as it is; the author names it.
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
      setAttributes({ amazonProducts: picks.concat([{ id: id, brand: '', name: parsed.name || '', note: '' }]) });
      setAsinInput('');
      setAsinNote(parsed.moved ? movedNote(parsed.moved, parsed.marketplace) + ' ' + texts.byHand : texts.byHand);
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

    if (!config.allowed) {
      return null;
    }

    if (!config.hasTag) {
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
    var field = createElement('div', { className: 'myfeeds-amz__controls myfeeds-amz__asin' },
      createElement(TextControl, {
        label: 'Add one product',
        placeholder: 'ASIN or Amazon product link',
        value: asinInput,
        onChange: function (v) { setAsinInput(v); if (asinError) setAsinError(''); },
        onKeyDown: function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            addByAsin();
          }
        }
      }),
      createElement(Button, {
        variant: 'secondary',
        isSecondary: true,
        onClick: addByAsin,
        disabled: !typed
      }, 'Add'),
      asinError ? createElement('div', { className: 'myfeeds-amz__error' }, asinError) : null,
      asinNote ? createElement('div', { className: 'myfeeds-amz__note' }, asinNote) : null
    );

    var named = picks.filter(function (p) { return p && p.id; });
    var manualList = named.length
      ? createElement('div', { className: 'myfeeds-amz__manual' },
          createElement('div', { className: 'myfeeds-amz__chosen-title' }, texts.manualTitle + ' (' + named.length + ')'),
          named.map(function (p) {
            var id = String(p.id);
            return amazonManualPick(p, function (key, value) { updatePick(id, key, value); }, function () { removePick(id); });
          })
        )
      : null;

    return createElement('div', { className: 'myfeeds-amz is-embedded' },
      createElement('div', { className: 'myfeeds-amz__body' },
        field,
        manualList,
        createElement('p', { className: 'myfeeds-amz__note' }, texts.note)
      )
    );
  }

  // The tab the block shows. A block that fills itself shows the tab of
  // its sourceMode; otherwise the one last chosen, while it is there.
  function activeTab(tabs, chosen, attributes, dynamicActive) {
    var i;
    if (dynamicActive) {
      for (i = 0; i < tabs.length; i++) {
        if (tabs[i].sourceMode && tabs[i].sourceMode === attributes.sourceMode) {
          return tabs[i].key;
        }
      }
      return 'manual';
    }
    for (i = 0; i < tabs.length; i++) {
      if (tabs[i].key === chosen && !tabs[i].sourceMode) {
        return chosen;
      }
    }
    return 'manual';
  }

  var withPickerInspector = createHigherOrderComponent(function (BlockEdit) {
    return function (props) {
      if (props.name !== BLOCK) {
        return createElement(BlockEdit, props);
      }

      var attributes = props.attributes || {};
      var setAttributes = props.setAttributes;
      var displayMode = attributes.displayMode || 'grid';
      var wrapperRef = useRef ? useRef(null) : { current: null };
      // Which tab the block shows. The choice is the editor's, not saved;
      // only a tab with a sourceMode writes one.
      var tabState = useState('manual');
      var setTab = tabState[1];

      var dynamicActive = !!applyFilters('myfeeds.picker.sourceIsDynamic', false, attributes);
      var ctx = {
        attributes: attributes,
        setAttributes: setAttributes,
        displayMode: displayMode,
        dynamicActive: dynamicActive,
        clientId: props.clientId,
        isSelected: !!props.isSelected
      };

      var modes = list(applyFilters('myfeeds.picker.displayModes', DISPLAY_MODES.slice(), ctx));
      if (!modes.length) {
        modes = DISPLAY_MODES.slice(0, 1);
      }
      // A mode this block may not show here (a recommendation card on a
      // block that fills itself) reads as the first, as it renders.
      var shownMode = hasValue(modes, displayMode) ? displayMode : modes[0].value;
      var isPickDisplay = (shownMode === 'recommendation' || shownMode === 'comparison') && !dynamicActive;

      var styles = list(applyFilters('myfeeds.picker.displayStyles', DISPLAY_STYLES.slice(), ctx));
      var shownStyle = hasValue(styles, attributes.comparisonStyle) ? attributes.comparisonStyle : (styles[0] ? styles[0].value : 'featured');

      var inspector = createElement(InspectorControls, null,
        createElement(PanelBody, { title: 'Display Settings', initialOpen: true, className: 'myfeeds-picker-display-settings' },
          createElement(SelectControl, {
            label: 'Display Mode',
            value: shownMode,
            options: modes,
            onChange: function (val) { setAttributes({ displayMode: val }); }
          }),
          shownMode === 'comparison' && !dynamicActive && styles.length > 1
            ? createElement(SelectControl, {
                label: 'Comparison style',
                value: shownStyle,
                options: styles,
                onChange: function (val) { setAttributes({ comparisonStyle: val }); }
              })
            : null,
          children(list(applyFilters('myfeeds.picker.inspector', [], ctx)))
        ),
        isPickDisplay ? createElement(PickWordsPanel) : null
      );

      var pickPanel = isPickDisplay
        ? createElement(PickPanel, { attributes: attributes, setAttributes: setAttributes, clientId: props.clientId })
        : null;

      // Manual and Amazon fill the same block - Amazon products show after
      // the hand-picked ones - so only a tab with a sourceMode changes what
      // the block renders.
      var amazonAllowed = !!getAmazonConfig().allowed;
      var amazonCount = amazonCountOf(attributes);
      var ownTabs = [{ key: 'manual', label: 'Manual picker' }];
      if (amazonAllowed) {
        ownTabs.push({ key: 'amazon', label: amazonCount ? 'Amazon (' + amazonCount + ')' : 'Amazon', amazon: true });
      }
      var tabs = list(applyFilters('myfeeds.picker.tabs', ownTabs, ctx))
        .filter(function (t) { return t.key; });
      var tab = activeTab(tabs, tabState[0], attributes, dynamicActive);
      var extras = list(applyFilters('myfeeds.picker.extras', [], Object.assign({}, ctx, {
        tab: tab,
        setTab: setTab,
        wrapperRef: wrapperRef
      })));
      // The Amazon products are part of this block too; say so where the
      // hand-picked ones are, with the way to them.
      if (amazonAllowed && amazonCount && tab === 'manual' && !dynamicActive) {
        extras.push(createElement('p', { key: 'amazon-hint', className: 'myfeeds-amz-hint' },
          (amazonCount === 1 ? '1 Amazon product' : amazonCount + ' Amazon products') + ' in this block, after the products above. ',
          createElement('button', { type: 'button', className: 'myfeeds-amz-hint__go', onClick: function () { setTab('amazon'); } }, 'Open the Amazon tab')));
      }
      var tabBody = function () {
        if (tab === 'amazon' && amazonAllowed && !dynamicActive) {
          var Panel = applyFilters('myfeeds.picker.amazonPanel', AmazonPanel, ctx);
          return createElement(typeof Panel === 'function' ? Panel : AmazonPanel, { attributes: attributes, setAttributes: setAttributes });
        }
        return applyFilters('myfeeds.picker.tabBody', null, tab, ctx) || null;
      };

      // One way to fill the block: the bundle's block as it always was,
      // with the sidebar beside it.
      if (tabs.length < 2) {
        return createElement(Fragment, null,
          createElement('div', { ref: wrapperRef },
            createElement(BlockEdit, props)
          ),
          children(extras),
          pickPanel,
          inspector
        );
      }

      var body;
      if (dynamicActive) {
        body = tabBody();
      } else {
        // The bundle's block stays mounted behind another tab, so its own
        // state - and what hangs on its tiles - survives the switch.
        body = createElement(Fragment, null,
          createElement('div', { ref: wrapperRef, style: tab !== 'manual' ? { display: 'none' } : undefined },
            createElement(BlockEdit, props)
          ),
          tab !== 'manual' ? tabBody() : null
        );
      }

      return createElement(Fragment, null,
        createElement('div', { className: 'myfeeds-picker-source-wrapper' },
          createElement(SourceModeToggle, {
            value: tab,
            tabs: tabs,
            onChange: function (key) {
              var chosen = null;
              tabs.forEach(function (t) { if (t.key === key) chosen = t; });
              if (chosen && chosen.sourceMode) {
                setAttributes({ sourceMode: chosen.sourceMode });
                setTab('manual');
                return;
              }
              if (attributes.sourceMode && attributes.sourceMode !== 'manual') {
                setAttributes({ sourceMode: 'manual' });
              }
              setTab(key);
            }
          }),
          body,
          children(extras),
          pickPanel
        ),
        inspector
      );
    };
  }, 'withMyFeedsPickerInspector');

  addFilter(
    'editor.BlockEdit',
    'myfeeds/picker-inspector',
    withPickerInspector
  );
})();
