/*
 * The product picker's sidebar and the parts under the block: the display
 * (grid, carousel, recommendation card, comparison table), the texts of a
 * recommendation card or comparison table, and the words every block uses.
 * The block itself is the editor bundle's (build/index.js); this file adds
 * to it through one editor.BlockEdit filter, and it is the only one that
 * draws the block.
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
 *                                                        'manual' shows
 *   myfeeds.picker.extras          ([], ctx)             elements under the block
 *
 * ctx: { attributes, setAttributes, displayMode, dynamicActive, clientId,
 * isSelected }; extras also get { tab, setTab, wrapperRef } - wrapperRef is
 * the element around the bundle's block.
 *
 * Shared byte for byte by both plugins (FREE-SYNC.md).
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
    var priceHiddenState = useState(false);

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
          priceHiddenState[1](!!(res && res.amazon_price_hidden));
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
      priceHiddenState[0]
        ? createElement('p', { className: 'myfeeds-pick-panel__empty' },
            'Amazon\'s price is left out in this block. Next to other shops Amazon asks for its lowest new price, and its API does not give that out. Readers see the price on Amazon.')
        : null,
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

      var tabs = list(applyFilters('myfeeds.picker.tabs', [{ key: 'manual', label: 'Manual picker' }], ctx))
        .filter(function (t) { return t.key; });
      var tab = activeTab(tabs, tabState[0], attributes, dynamicActive);
      var extras = list(applyFilters('myfeeds.picker.extras', [], Object.assign({}, ctx, {
        tab: tab,
        setTab: setTab,
        wrapperRef: wrapperRef
      })));

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
        body = applyFilters('myfeeds.picker.tabBody', null, tab, ctx) || null;
      } else {
        // The bundle's block stays mounted behind another tab, so its own
        // state - and what hangs on its tiles - survives the switch.
        body = createElement(Fragment, null,
          createElement('div', { ref: wrapperRef, style: tab !== 'manual' ? { display: 'none' } : undefined },
            createElement(BlockEdit, props)
          ),
          tab !== 'manual' ? (applyFilters('myfeeds.picker.tabBody', null, tab, ctx) || null) : null
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
