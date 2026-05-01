(() => {
  "use strict";

  const React = window.React;
  const { registerBlockType } = window.wp.blocks;
  const { TextControl, Button, Modal } = window.wp.components;
  const { useState, useEffect, Fragment } = window.wp.element;

  const data = window.myfeedsData || {};
  const apiUrl = data.apiUrl || '';
  const pluginUrl = data.pluginUrl || '';
  const nonce = data.nonce || '';
  const PLACEHOLDER_IMG = pluginUrl + "assets/placeholder.png";

  // Utility: robust price + images + shop resolution
  function toNumber(val) {
    if (val === null || val === undefined) return 0;
    if (typeof val === 'number') return isFinite(val) ? val : 0;
    const s = String(val).replace(/[^0-9.,-]/g, '').replace(',', '.');
    const n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  }
  function resolveCurrentPrice(p) {
    return (
      toNumber(p.sale_price) ||
      toNumber(p.discounted_price) ||
      toNumber(p.current_price) ||
      toNumber(p.search_price) ||
      toNumber(p.price) ||
      toNumber(p.store_price)
    );
  }
  function fromKeys(obj, keys) {
    for (let i = 0; i < keys.length; i++) {
      const v = obj && obj[keys[i]];
      const n = toNumber(v);
      if (n > 0) return n;
    }
    return 0;
  }
  function resolveOriginalPrice(p) {
    // Try top-level synonyms
    let n = fromKeys(p, [
      'original_price','old_price','was_price','list_price','regular_price','rrp','rrp_price','msrp','full_price','price_old','strike_price','previous_price','product_price_old','base_price_amount'
    ]);
    if (n > 0) return n;
    // Try nested attributes
    n = fromKeys(p && p.attributes || {}, [
      'original_price','old_price','was_price','list_price','regular_price','rrp','rrp_price','msrp','full_price','price_old','strike_price','previous_price','product_price_old','base_price_amount'
    ]);
    return n > 0 ? n : 0;
  }
  function getCurrency(p) { return p.currency || (p.attributes && p.attributes.currency) || 'EUR'; }
  function looksLikeSku(str) {
    if (!str || typeof str !== 'string') return false;
    const noSpaces = !/\s/.test(str);
    const alnum = (str.match(/[A-Za-z0-9]/g) || []).length;
    const digits = (str.match(/\d/g) || []).length;
    const ratio = alnum ? (digits / alnum) : 0;
    const longish = alnum >= 8;
    const hasDot = /\./.test(str);
    return noSpaces && longish && ratio >= 0.7 && !hasDot;
  }
  function hostFromUrl(u) {
    try { return new URL(u).hostname.replace('www.', ''); } catch(_) { return ''; }
  }
  function extractDestFromTracking(u) {
    try {
      const url = new URL(u);
      const qp = url.searchParams;
      const keys = ['p','ued','u','url','destination','dest','rd','redirect','murl'];
      for (let i = 0; i < keys.length; i++) {
        const raw = qp.get(keys[i]);
        if (raw) {
          const decoded = decodeURIComponent(raw);
          const host = hostFromUrl(decoded);
          if (host) return host;
        }
      }
      return '';
    } catch(_) { return ''; }
  }
  function resolveShopName(p) {
    const cands = [
      p.merchant_name,
      p.merchantName,
      p.shopname,
      p.shop,
      p.retailer,
      p.store,
      p.seller,
      p.advertiserName,
      p.advertiser_name,
      p.retailer_name,
      p.store_name,
      p.brand_store,
      p.merchant // LAST, because some feeds misuse this field
    ];
    for (let i = 0; i < cands.length; i++) {
      const v = cands[i];
      if (v && typeof v === 'string' && !looksLikeSku(v)) return v;
    }
    const trackingHosts = ['awin','tradedoubler','affili','effiliation','zanox','partnerize','admitad','impactradius','rakuten','webgains'];
    const link = p.affiliate_link || p.aw_deep_link || p.product_url || p.link || p.url || '';
    const linkHost = hostFromUrl(link);
    if (linkHost && trackingHosts.some(t => linkHost.includes(t))) {
      const dest = extractDestFromTracking(link);
      if (dest) return dest;
    }
    if (linkHost && !trackingHosts.some(t => linkHost.includes(t))) return linkHost;
    const domain = (p.domain || p.merchant_domain || '').replace('www.','');
    if (domain && !trackingHosts.some(t => domain.includes(t))) return domain;
    return '';
  }
  function normalizeArray(val) {
    if (!val) return [];
    if (Array.isArray(val)) return val;
    if (typeof val === 'string') return val.split(/[,|\s]+/).filter(Boolean);
    return [];
  }
  function resolveImages(p) {
    const list = [];
    const pushUrl = (u) => { if (typeof u === 'string' && /^https?:\/\//.test(u)) list.push(u); };
    const candidates = [
      p.additional_images,
      p.images,
      p.image_urls,
      p.gallery,
      p.all_images,
      p.image_list,
      p.more_images,
      p.pictures,
      p.extra_images,
      p.large_image,
      p.aw_image_url,
      p.aw_thumb_url,
      p.alternate_image,
      p.alternate_image_two,
      p.alternate_image_three,
      p.alternate_image_four,
      p.merchant_thumb_url,
      p.attributes && (p.attributes.additional_images || p.attributes.images || p.attributes.gallery)
    ];
    candidates.forEach(c => { normalizeArray(c).forEach(pushUrl); });
    Object.keys(p || {}).forEach(k => {
      const v = p[k];
      if (typeof v === 'string' && /image|\.jpg|\.png|\.jpeg/i.test(k) && /^https?:\/\//.test(v)) pushUrl(v);
      if (Array.isArray(v)) v.forEach(x => { if (typeof x === 'string' && /^https?:\/\//.test(x) && /image|\.jpg|\.png|\.jpeg/i.test(x)) pushUrl(x); });
    });
    const main = p.image_url || p.merchant_image_url || p.large_image || p.aw_image_url || p.image || p.picture || '';
    const uniq = Array.from(new Set(list.filter(Boolean)));
    const result = main ? [main, ...uniq.filter(u => u !== main)] : uniq;
    return result;
  }
  function isLikelyShippingString(s, currency) {
    if (!s) return false;
    const str = String(s).toLowerCase();
    if (str.includes('free')) return true;
    if (str.includes('ship')) return true;
    if (currency && str.includes(String(currency).toLowerCase())) return true;
    const n = toNumber(s);
    if (!isNaN(n) && (n >= 0)) return true;
    // guard: strings that look like product ids
    if (looksLikeSku(String(s))) return false;
    return false;
  }

  registerBlockType("myfeeds/product-picker", {
    title: "MyFeeds \u2013 Product Picker",
    icon: "cart",
    category: "widgets",
    description: "Display affiliate products from your configured feeds with smart search",

    attributes: {
      selectedProducts: { type: "array", default: [] },
    },

    edit({ attributes, setAttributes }) {
      const [searchTerm, setSearchTerm] = useState("");
      const [results, setResults] = useState([]);
      const [isLoading, setIsLoading] = useState(false);
      const [showModal, setShowModal] = useState(false);
      const [selected, setSelected] = useState(attributes.selectedProducts || []);
      const [error, setError] = useState(null);
      const [showProductDetail, setShowProductDetail] = useState(null);
      const [searchOffset, setSearchOffset] = useState(0);
      const [hasMoreResults, setHasMoreResults] = useState(false);
      const [isLoadingMore, setIsLoadingMore] = useState(false);
      const [currentVariant, setCurrentVariant] = useState({ color: '', size: '' });
      const [detailSizes, setDetailSizes] = useState([]);

      // On block mount: refresh selected products with current data from DB
      useEffect(function() {
        if (!selected || selected.length === 0 || !apiUrl) return;
        
        var ids = selected.map(function(p) { return String(p.id); });
        
        fetch(apiUrl + 'products-by-ids', {
          method: 'POST',
          credentials: 'include',
          headers: { 
            'Content-Type': 'application/json',
            'X-WP-Nonce': nonce 
          },
          body: JSON.stringify({ ids: ids })
        })
        .then(function(r) { return r.json(); })
        .then(function(freshProducts) {
          if (!Array.isArray(freshProducts) || freshProducts.length === 0) return;
          
          var freshMap = {};
          freshProducts.forEach(function(p) {
            var pid = String(p.id || p.aw_product_id || '');
            if (pid) freshMap[pid] = p;
          });
          
          var updated = selected.map(function(existing) {
            var fresh = freshMap[String(existing.id)];
            if (fresh) {
              return Object.assign({}, existing, {
                title: fresh.title || fresh.product_name || existing.title,
                price: fresh.price || existing.price,
                original_price: fresh.original_price || existing.original_price,
                image_url: fresh.image_url || existing.image_url,
                brand: fresh.brand || existing.brand,
                currency: fresh.currency || existing.currency,
                affiliate_link: fresh.affiliate_link || existing.affiliate_link,
              });
            }
            return existing;
          });
          
          var changed = JSON.stringify(updated) !== JSON.stringify(selected);
          if (changed) {
            setSelected(updated);
            setAttributes({ selectedProducts: updated });
          }
        })
        .catch(function() { /* Silently fail */ });
      }, []);

      // Body class while modal open (for scoped CSS)
      useEffect(function() {
        if (showModal || !!showProductDetail) { document.body.classList.add('myfeeds-modal-open'); }
        else { document.body.classList.remove('myfeeds-modal-open'); }
        return function cleanup(){ document.body.classList.remove('myfeeds-modal-open'); };
      }, [showModal, showProductDetail]);

      // Fetch available sizes when detail view opens (for deduplicated results)
      useEffect(function() {
        if (showProductDetail) {
          var productName = showProductDetail.title || showProductDetail.product_name || '';
          var colourVal = showProductDetail.colour || showProductDetail.color || '';
          if (!colourVal && showProductDetail.attributes) {
            var attrC = showProductDetail.attributes.colour || showProductDetail.attributes.color;
            colourVal = Array.isArray(attrC) ? (attrC[0] || '') : (typeof attrC === 'string' ? attrC : '');
          }
          if (productName && apiUrl) {
            fetch(apiUrl + 'product-sizes?name=' + encodeURIComponent(productName) + '&colour=' + encodeURIComponent(colourVal), {
              credentials: 'include',
              headers: { 'X-WP-Nonce': nonce }
            })
            .then(function(r) { return r.json(); })
            .then(function(sizes) { if (Array.isArray(sizes)) setDetailSizes(sizes); })
            .catch(function() { setDetailSizes([]); });
          }
        } else {
          setDetailSizes([]);
        }
      }, [showProductDetail]);

      const openModalAndSearch = async function(){
        setShowModal(true);
        if (!searchTerm || searchTerm.length < 2) {
          // Empty search: show selected products or prompt message
          if (selected.length > 0) {
            setResults(selected);
            setError(null);
          } else {
            setResults([]);
            setError("Enter a search term to find products in your feeds.");
          }
        } else {
          await fetchProducts(false);
        }
      };

      const fetchProducts = async function (loadMore) {
        if (!searchTerm || searchTerm.length < 2) {
          setError("Please enter at least 2 characters for search");
          return;
        }
        if (loadMore) {
          setIsLoadingMore(true);
        } else {
          setIsLoading(true);
          setSearchOffset(0);
        }
        setError(null);
        try {
          var offset = loadMore ? searchOffset + 50 : 0;
          const response = await fetch(apiUrl + 'products?q=' + encodeURIComponent(searchTerm) + '&offset=' + offset, {
            credentials: 'include',
            headers: { 'X-WP-Nonce': nonce }
          });
          if (response.ok) {
            const products = await response.json();
            var newProducts = Array.isArray(products) ? products : [];
            if (loadMore) {
              setResults(function(prev) {
                var existingIds = {};
                prev.forEach(function(p) { existingIds[String(p.id || p.aw_product_id)] = true; });
                var unique = newProducts.filter(function(p) { return !existingIds[String(p.id || p.aw_product_id)]; });
                return prev.concat(unique);
              });
            } else {
              setResults(newProducts);
              if (newProducts.length === 0) {
                setError('No products found for "' + searchTerm + '". Try other keywords.');
              }
            }
            setSearchOffset(offset);
            setHasMoreResults(newProducts.length >= 50);
          } else {
            setError('Search failed.');
            if (!loadMore) setResults([]);
          }
        } catch (e) {
          setError('Search failed. Please check your feeds configuration.');
          if (!loadMore) setResults([]);
        } finally {
          setIsLoading(false);
          setIsLoadingMore(false);
        }
      };

      const toggleProduct = function (product, useCurrentVariant) {
        if (!product || !product.id && !product.aw_product_id) {
          alert("Product missing ID - cannot be saved.");
          return;
        }
        const awId = product.aw_product_id || product.id;
        
        const title = product.title || product.product_name || product.name || '';
        const mainImage = product.image_url || product.merchant_image_url || product.large_image || product.aw_image_url || product.image || product.picture || '';
        const deepLink = product.affiliate_link || product.aw_deep_link || product.link || product.url || '';
        const currency = getCurrency(product);
        
        // ENHANCED: Use selected variant color/size if available
        let selectedColor = '';
        let selectedSize = '';
        
        if (useCurrentVariant && currentVariant) {
          selectedColor = currentVariant.color || '';
          selectedSize = currentVariant.size || '';
          console.log("✅ MYFEEDS: Saving product with selected variant - Color:", selectedColor, "Size:", selectedSize);
        } else {
          // Fallback to product's default color
          const colorAttr = (product.attributes && (product.attributes.colour || product.attributes.color || product.attributes['Fashion:colour'])) || product.colour;
          selectedColor = (Array.isArray(colorAttr) && colorAttr.length > 0) ? colorAttr[0] : (typeof colorAttr === 'string' ? colorAttr : '');
        }

        let priceNow = resolveCurrentPrice(product);
        let priceOld = resolveOriginalPrice(product);
        const discountPct = toNumber(product.savings_percent || product.discount || product.saving);
        if (priceOld === 0 && discountPct > 0 && priceNow > 0 && discountPct < 100) {
          priceOld = priceNow / (1 - discountPct / 100);
        }

        const enriched = {
          id: String(awId),
          title: title,
          image_url: mainImage,
          affiliate_link: deepLink,
          brand: product.brand || product.brand_name || product.manufacturer || '',
          merchant: resolveShopName(product) || product.merchant_name || product.merchantName || product.shopname || '',
          price: priceNow,
          original_price: priceOld,
          currency: currency,
          color: selectedColor || product.color || '',
          size: selectedSize || ''
        };
        
        console.log("💾 MYFEEDS: Enriched product data:", enriched);
        
        setSelected(function(prev){
          const exists = prev.some(function(p){ return String(p.id) === enriched.id; });
          if (exists) { return prev.filter(function(p){ return String(p.id) !== enriched.id; }); }
          return prev.concat([enriched]);
        });
      };

      const removeSelectedProduct = function (productId) {
        const newSelected = (selected || []).filter(function(p){ return String(p.id) !== String(productId); });
        setSelected(newSelected);
        setAttributes({ selectedProducts: newSelected });
      };

      const saveSelection = function () {
        setAttributes({ selectedProducts: selected });
        setShowModal(false);
      };

      const formatShipping = function (product) {
        const s = product.shipping || product.shipping_text || product.shipping_cost || product.delivery_cost || '';
        const currency = getCurrency(product);
        if (!s || !isLikelyShippingString(s, currency)) return '';
        const n = toNumber(s);
        if (n > 0) return 'Shipping: ' + n.toFixed(2) + ' ' + currency;
        if (n === 0) return 'Free Shipping';
        if (typeof s === 'string' && s.indexOf(':') !== -1) {
          const parts = s.split(':');
          const val = toNumber(parts[parts.length - 1]);
          if (val > 0) return 'Shipping: ' + val.toFixed(2) + ' ' + currency;
          if (val === 0) return 'Free Shipping';
        }
        if (/(free)/i.test(String(s))) return 'Free Shipping';
        return 'Shipping costs may apply';
      };

      const priceBlock = function(product){
        let currentPrice = resolveCurrentPrice(product);
        let originalPrice = resolveOriginalPrice(product);
        const pct = toNumber(product.savings_percent || product.discount || product.saving);
        if (originalPrice === 0 && pct > 0 && currentPrice > 0 && pct < 100) {
          originalPrice = currentPrice / (1 - pct/100);
        }
        const hasDiscount = originalPrice > currentPrice && originalPrice > 0 && currentPrice > 0;
        const currency = getCurrency(product);
        return React.createElement("div", { style: { display: "flex", alignItems: "center", gap: "8px", margin: "2px 0 6px" } },
          hasDiscount ? [
            React.createElement("span", { key: "original", style: { fontSize: "13px", color: "#888", textDecoration: "line-through" } }, originalPrice.toFixed(2) + ' ' + currency),
            React.createElement("span", { key: "current", style: { fontSize: "14px", fontWeight: 700, color: "#c0392b" } }, currentPrice.toFixed(2) + ' ' + currency)
          ] : [
            React.createElement("span", { key: "normal", style: { fontSize: "14px", fontWeight: 700, color: "#111" } }, currentPrice.toFixed(2) + ' ' + currency)
          ]
        );
      };

      const discountBadge = function(product){
        let currentPrice = resolveCurrentPrice(product);
        let originalPrice = resolveOriginalPrice(product);
        let percent = toNumber(product.savings_percent || product.discount || product.saving);
        if ((!percent || percent <= 0) && originalPrice > currentPrice && currentPrice > 0) {
          percent = Math.round(((originalPrice - currentPrice) / originalPrice) * 100);
        }
        if (percent > 0) {
          return React.createElement("div", { style: { position: "absolute", left: "8px", bottom: "8px", background: "#e53935", color: "#fff", borderRadius: "12px", padding: "2px 8px", fontSize: "12px", fontWeight: 700 } }, '-' + percent + '%');
        }
        return null;
      };

      const infoButton = function(onClick){
        return React.createElement("button", { onClick, title: "Show details", style: { position: "absolute", top: "8px", right: "8px", width: "22px", height: "22px", borderRadius: "50%", border: "1px solid #667eea", background: "#fff", color: "#667eea", fontSize: "12px", fontWeight: 700, cursor: "pointer" } }, 'i');
      };

      const productCard = function(product, index){
        const isSelected = (selected || []).some(function(p){ return String(p.id) === String(product.id || product.aw_product_id); });
        const shopName = resolveShopName(product);
        const brandName = product.brand || product.brand_name || product.manufacturer || product.make || product.vendor || "";
        return React.createElement("div", { key: 'result-' + String(product.id || product.aw_product_id) + '-' + index, style: { border: isSelected ? "2px solid #667eea" : "1px solid #e5e7eb", borderRadius: "8px", background: "#fff", cursor: "pointer", overflow: "hidden", transition: "box-shadow .2s", boxShadow: isSelected ? "0 0 0 2px rgba(102,126,234,0.15)" : "0 1px 2px rgba(0,0,0,0.06)" }, onClick: function(){ toggleProduct(product); } },
          React.createElement("div", { style: { position: "relative", height: "160px", background: "#fff", overflow: "hidden" } },
            React.createElement("img", { src: product.image_url || product.merchant_image_url || product.large_image || product.aw_image_url || product.image || product.picture || PLACEHOLDER_IMG, alt: product.title || product.product_name || 'Product', style: { width: "100%", height: "100%", objectFit: "contain" } }),
            infoButton(function(e){ 
              e.stopPropagation(); 
              
              // Initialize variant state when opening detail view
              const variants = getAllProductVariants(product);
              setCurrentVariant({
                color: variants.current_color,
                size: variants.current_size
              });
              
              setShowProductDetail(product); 
            }),
            discountBadge(product),
            colorIndicators(product)
          ),
          React.createElement("div", { style: { padding: "10px 12px" } },
            brandName && React.createElement("div", { style: { fontSize: "11px", color: "#666", textTransform: "uppercase", letterSpacing: "0.3px", marginBottom: "4px", fontWeight: 600 } }, brandName),
            React.createElement("div", { style: { fontSize: "13px", color: "#222", fontWeight: 600, lineHeight: 1.35, marginBottom: "4px", minHeight: "36px", overflow: "hidden", display: "-webkit-box", WebkitLineClamp: 2, WebkitBoxOrient: "vertical" } }, product.title || product.product_name || product.name || ''),
            priceBlock(product),
            (function(){ const s=formatShipping(product); return s ? React.createElement("div", { style: { fontSize: "12px", color: "#555", marginBottom: "4px" } }, s) : null; })(),
            (shopName && !looksLikeSku(shopName)) && React.createElement("div", { style: { fontSize: "12px", color: "#666" } }, shopName)
          )
        );
      };

      // ENHANCED: Extract all available colors and sizes for a product
      const getAllProductVariants = function(product) {
        const variants = {
          colors: [],
          sizes: [],
          current_color: '',
          current_size: ''
        };
        
        // Extract colors from various sources with enhanced logic
        const colorSources = [];
        
        // 1. Direct color fields
        if (product.colors && Array.isArray(product.colors)) {
          colorSources.push(...product.colors);
        }
        if (product.color && product.color !== 'null' && product.color !== '') {
          colorSources.push(product.color);
        }
        if (product.colour && product.colour !== 'null' && product.colour !== '') {
          colorSources.push(product.colour);
        }
        
        // 2. Attributes object (most common source for AWIN feeds)
        if (product.attributes) {
          const attrColorFields = ['colors', 'color', 'colour', 'Fashion:colour', 'Fashion:color', 
                                    'Fashion:swatch', 'swatch', 'colorway', 'variant_color'];
          attrColorFields.forEach(field => {
            const value = product.attributes[field];
            if (value && value !== 'null' && value !== '') {
              if (Array.isArray(value)) {
                colorSources.push(...value);
              } else if (typeof value === 'string') {
                // Handle various separators: comma, pipe, semicolon
                const separators = /[,|;]/;
                if (separators.test(value)) {
                  colorSources.push(...value.split(separators).map(c => c.trim()));
                } else {
                  colorSources.push(value);
                }
              }
            }
          });
        }
        
        // 3. ENHANCED: Try to extract colors from product title
        if (product.title) {
          const titleLower = product.title.toLowerCase();
          // Comprehensive color list (English + German)
          const commonColors = [
            'black', 'white', 'red', 'blue', 'green', 'yellow', 'brown', 'gray', 'grey', 
            'pink', 'purple', 'orange', 'navy', 'beige', 'khaki', 'olive', 'cream', 'ivory',
            'maroon', 'burgundy', 'teal', 'turquoise', 'mint', 'lime', 'gold', 'silver',
            'schwarz', 'weiß', 'weiss', 'rot', 'blau', 'grün', 'gelb', 'braun', 'grau', 
            'rosa', 'lila', 'orange', 'beige', 'creme'
          ];
          
          commonColors.forEach(color => {
            // Use word boundaries to avoid false matches
            const regex = new RegExp('\\b' + color + '\\b', 'i');
            if (regex.test(titleLower)) {
              colorSources.push(color.charAt(0).toUpperCase() + color.slice(1));
            }
          });
        }
        
        // 4. Check variant fields if product has variant information
        if (product.variants && Array.isArray(product.variants)) {
          product.variants.forEach(v => {
            if (v.color) colorSources.push(v.color);
            if (v.colour) colorSources.push(v.colour);
          });
        }
        
        // 5. Check custom fields that might contain color info
        const customColorFields = ['variant', 'option1', 'option2', 'style'];
        customColorFields.forEach(field => {
          const value = product[field];
          if (value && typeof value === 'string' && value.length > 0 && value.length < 30) {
            // Check if value looks like a color (not a SKU or long description)
            const lowerValue = value.toLowerCase();
            if (/black|white|red|blue|green|yellow|brown|gray|grey|pink|purple|orange|navy|beige|schwarz|weiß|rot|blau|grün/i.test(lowerValue)) {
              colorSources.push(value);
            }
          }
        });
        
        // PROFESSIONAL COLOR NORMALIZATION AND DEDUPLICATION
        const cleanedColors = colorSources
          .map(c => c ? c.toString().trim() : '')
          .filter(c => {
            if (!c || c.length === 0) return false;
            const lower = c.toLowerCase();
            // Filter out non-color values
            if (lower === 'null' || lower === 'undefined' || lower === 'n/a' || lower === 'none') return false;
            // Filter out very long strings (likely not colors)
            if (c.length > 30) return false;
            return true;
          })
          .map(c => {
            // Normalize color names (first letter uppercase, rest lowercase)
            const normalized = c.toLowerCase();
            return normalized.charAt(0).toUpperCase() + normalized.slice(1);
          });
        
        // Advanced deduplication with case-insensitive comparison
        const uniqueColors = [];
        const seenColors = new Set();
        
        cleanedColors.forEach(color => {
          const colorKey = color.toLowerCase().replace(/[^a-z]/g, ''); // Normalize for comparison
          
          if (!seenColors.has(colorKey)) {
            seenColors.add(colorKey);
            uniqueColors.push(color);
          }
        });
        
        variants.colors = uniqueColors;
        
        // Extract sizes with enhanced logic
        const sizeSources = [];
        
        // 1. Direct size fields
        if (product.sizes && Array.isArray(product.sizes)) {
          sizeSources.push(...product.sizes);
        }
        if (product.size && product.size !== 'null' && product.size !== '') {
          sizeSources.push(product.size);
        }
        
        // 2. Attributes object
        if (product.attributes) {
          const attrSizeFields = ['sizes', 'size', 'Fashion:size', 'variant_size'];
          attrSizeFields.forEach(field => {
            const value = product.attributes[field];
            if (value && value !== 'null' && value !== '') {
              if (Array.isArray(value)) {
                sizeSources.push(...value);
              } else if (typeof value === 'string') {
                const separators = /[,|;]/;
                if (separators.test(value)) {
                  sizeSources.push(...value.split(separators).map(s => s.trim()));
                } else {
                  sizeSources.push(value);
                }
              }
            }
          });
        }
        
        // 3. Check variant fields
        if (product.variants && Array.isArray(product.variants)) {
          product.variants.forEach(v => {
            if (v.size) sizeSources.push(v.size);
          });
        }
        
        // PROFESSIONAL SIZE NORMALIZATION AND DEDUPLICATION
        const cleanedSizes = sizeSources
          .map(s => s ? s.toString().trim() : '')
          .filter(s => {
            if (!s || s.length === 0) return false;
            const lower = s.toLowerCase();
            if (lower === 'null' || lower === 'undefined' || lower === 'n/a' || lower === 'none') return false;
            if (s.length > 20) return false; // Filter out very long strings
            return true;
          });
        
        // Remove duplicates and sort sizes logically
        const uniqueSizes = Array.from(new Set(cleanedSizes));
        
        // Sort sizes in logical order (numbers first, then letters)
        uniqueSizes.sort((a, b) => {
          const aNum = parseFloat(a);
          const bNum = parseFloat(b);
          
          // If both are numbers, sort numerically
          if (!isNaN(aNum) && !isNaN(bNum)) {
            return aNum - bNum;
          }
          
          // If one is number and one is text, numbers come first
          if (!isNaN(aNum) && isNaN(bNum)) return -1;
          if (isNaN(aNum) && !isNaN(bNum)) return 1;
          
          // Both are text, sort alphabetically with special order for clothing sizes
          const sizeOrder = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];
          const aIndex = sizeOrder.indexOf(a.toUpperCase());
          const bIndex = sizeOrder.indexOf(b.toUpperCase());
          
          if (aIndex !== -1 && bIndex !== -1) {
            return aIndex - bIndex;
          }
          if (aIndex !== -1) return -1;
          if (bIndex !== -1) return 1;
          
          return a.localeCompare(b);
        });
        
        variants.sizes = uniqueSizes;
        
        // Set current values
        variants.current_color = variants.colors[0] || '';
        variants.current_size = variants.sizes[0] || '';
        
        return variants;
      };

      // DEACTIVATED: Color indicator circles - see Archiv/Product-Picker-WP-2025-01-20-COLOR-BADGE-SYSTEM
      const colorIndicators = function(product) {
        return null; // Deactivated until variant grouping is implemented
      };
      
      // ENHANCED: Helper function to convert color names to hex values
      const getColorHex = function(colorName) {
        if (!colorName) return '#cccccc';
        const colorMap = {
          // Basic colors - English
          'black': '#000000', 'white': '#ffffff', 'red': '#ff0000', 'blue': '#0000ff',
          'green': '#008000', 'yellow': '#ffff00', 'brown': '#8b4513', 'gray': '#808080',
          'grey': '#808080', 'pink': '#ffc0cb', 'purple': '#800080', 'orange': '#ffa500',
          // Basic colors - German
          'schwarz': '#000000', 'weiß': '#ffffff', 'weiss': '#ffffff', 'rot': '#ff0000',
          'blau': '#0000ff', 'grün': '#008000', 'gelb': '#ffff00', 'braun': '#8b4513',
          'grau': '#808080', 'rosa': '#ffc0cb', 'lila': '#800080',
          // Extended colors - English
          'navy': '#000080', 'beige': '#f5f5dc', 'khaki': '#c3b091', 'olive': '#808000',
          'cream': '#fffdd0', 'ivory': '#fffff0', 'maroon': '#800000', 'burgundy': '#800020',
          'teal': '#008080', 'turquoise': '#40e0d0', 'mint': '#98ff98', 'lime': '#00ff00',
          'gold': '#ffd700', 'silver': '#c0c0c0', 'bronze': '#cd7f32', 'copper': '#b87333',
          // Extended colors - German
          'creme': '#fffdd0', 'marine': '#000080', 'türkis': '#40e0d0', 'mint': '#98ff98'
        };
        const normalizedColor = colorName.toLowerCase().trim();
        return colorMap[normalizedColor] || '#cccccc';
      };

      // ENHANCED: Function to switch color variant in detail view
      // This will attempt to find a different product with the same base name but different color
      const switchColorVariant = function(newColor, newSize) {
        console.log("🔄 MYFEEDS DEBUG: Switching variant to color:", newColor, "size:", newSize);
        
        if (!showProductDetail) return;
        
        // Update current variant state
        const updatedVariant = {
          color: newColor || currentVariant.color,
          size: newSize !== undefined ? newSize : currentVariant.size
        };
        setCurrentVariant(updatedVariant);
        
        console.log("✅ MYFEEDS DEBUG: Variant state updated:", updatedVariant);
        
        // FUTURE ENHANCEMENT: Search for actual product variant in results
        // For now, we just update the display state
        // In a full implementation, you would:
        // 1. Query the backend for the same product in different color
        // 2. Update the showProductDetail with the new product data
        // 3. This would include different prices, images, affiliate links
        
        // Visual feedback that color was selected
        console.log("💡 MYFEEDS INFO: Color selected -", newColor, "- this variant will be saved when you click 'Add to Selection'");
      };

      // Build detail images upfront
      const detailImages = function(p){
        const list = resolveImages(p);
        const main = p.image_url || p.merchant_image_url || p.large_image || p.aw_image_url || p.image || p.picture || PLACEHOLDER_IMG;
        const result = [main].concat(list.filter(u => u !== main));
        return Array.from(new Set(result));
      };

      return React.createElement(
        Fragment,
        null,

        React.createElement(
        "div",
        { className: "myfeeds-editor-wrapper" },

        // Scoped CSS (modal spacing)
        React.createElement("style", null, `
          .myfeeds-editor-wrapper .myfeeds-selected-product-tile { position: relative; }
          .myfeeds-editor-wrapper .myfeeds-remove-button { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border-radius: 50%; border: 1px solid #d63638; background: #fff; color: #d63638; font-size: 14px; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; line-height: 1; font-weight: 600; z-index: 10; }
          .myfeeds-editor-wrapper .myfeeds-remove-button:hover { background: #d63638; color: #fff; }
          body.myfeeds-modal-open .components-modal__screen-overlay { inset: 0 !important; background: rgba(0,0,0,0.5) !important; }
          body.myfeeds-modal-open .components-modal__frame { position: fixed !important; top: 48px !important; left: 40px !important; right: 40px !important; bottom: 48px !important; width: calc(100vw - 80px) !important; height: calc(100vh - 96px) !important; max-width: none !important; max-height: none !important; margin: 0 !important; border-radius: 10px !important; background: #fff !important; box-shadow: 0 20px 40px rgba(0,0,0,0.25) !important; transform: none !important; }
          body.myfeeds-modal-open .components-modal__content { width: 100% !important; height: 100% !important; margin: 0 !important; padding: 0 !important; overflow: hidden !important; display: flex !important; flex-direction: column !important; }
          body.myfeeds-modal-open .components-modal__header { flex-shrink: 0 !important; padding: 8px 14px !important; border-bottom: 1px solid #e5e7eb !important; background: #f8f9fa !important; }
          .myfeeds-modal-body { flex: 1; overflow-y: auto; padding: 12px 20px 20px; }
          
          /* PROFESSIONAL DETAIL VIEW SCROLLING - COMPLETELY FIXED */
          .myfeeds-detail-content { 
            height: 100% !important; 
            overflow-y: auto !important; 
            padding: 30px 20px 100px 20px !important; /* CRITICAL: 30px top padding for title visibility */
            scroll-behavior: smooth !important;
            /* CRITICAL: Full viewport height minus modal header */
            max-height: calc(100vh - 100px) !important;
            /* CRITICAL: Ensure proper scrolling from top to bottom */
            box-sizing: border-box !important;
            position: relative !important;
          }
          .myfeeds-product-detail { 
            display: flex !important; 
            gap: 30px !important; 
            padding: 0 !important;
            /* CRITICAL: Allow content to expand beyond viewport */
            min-height: fit-content !important;
            height: auto !important;
            margin-bottom: 80px !important;
          }
          .myfeeds-product-images { 
            flex: 0 0 400px !important; 
            height: fit-content !important;
            /* CRITICAL: No sticky positioning - let it scroll naturally */
            position: static !important;
          }
          .myfeeds-main-image img {
            width: 100% !important;
            height: auto !important;
            max-height: 400px !important;
            object-fit: contain !important;
            border-radius: 8px !important;
          }
          .myfeeds-additional-images {
            display: flex !important;
            gap: 8px !important;
            margin-top: 10px !important;
            flex-wrap: wrap !important;
          }
          .myfeeds-thumb-image {
            width: 60px !important;
            height: 60px !important;
            border: 1px solid #ddd !important;
            border-radius: 4px !important;
            overflow: hidden !important;
          }
          .myfeeds-thumb-image img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
          }
          .myfeeds-product-info { 
            flex: 1 !important; 
            /* CRITICAL: Remove any overflow restrictions */
            overflow: visible !important;
            padding-right: 0 !important;
            min-height: fit-content !important;
            height: auto !important;
          }
          .myfeeds-product-brand {
            font-size: 14px !important;
            color: #666 !important;
            text-transform: uppercase !important;
            font-weight: 600 !important;
            margin-bottom: 8px !important;
          }
          .myfeeds-product-title {
            font-size: 24px !important;
            font-weight: 700 !important;
            color: #333 !important;
            margin: 0 0 15px 0 !important;
            line-height: 1.3 !important;
          }
          .myfeeds-product-price {
            margin: 15px 0 !important;
          }
          .myfeeds-current-price {
            font-size: 28px !important;
            font-weight: 700 !important;
            color: #e74c3c !important;
          }
          .myfeeds-old-price {
            font-size: 20px !important;
            color: #999 !important;
            text-decoration: line-through !important;
            margin-left: 10px !important;
          }
          .myfeeds-discount-badge {
            background: #e74c3c !important;
            color: white !important;
            padding: 4px 8px !important;
            border-radius: 12px !important;
            font-size: 14px !important;
            font-weight: 700 !important;
            margin-left: 10px !important;
          }
          .myfeeds-product-description { 
            margin: 25px 0 !important; 
            /* CRITICAL: No height restrictions whatsoever */
            max-height: none !important;
            height: auto !important;
            overflow: visible !important;
          }
          .myfeeds-product-description h4 {
            font-size: 18px !important;
            font-weight: 600 !important;
            color: #333 !important;
            margin: 0 0 10px 0 !important;
          }
          .myfeeds-description-text { 
            line-height: 1.6 !important; 
            font-size: 14px !important;
            color: #555 !important;
            /* CRITICAL: Complete freedom for text display */
            max-height: none !important;
            height: auto !important;
            overflow: visible !important;
            white-space: pre-wrap !important;
            word-wrap: break-word !important;
            /* CRITICAL: Ensure text can expand to any length */
            min-height: fit-content !important;
          }
          .myfeeds-product-attributes {
            margin: 25px 0 !important;
          }
          .myfeeds-attribute-section {
            margin: 15px 0 !important;
          }
          .myfeeds-product-actions {
            position: sticky !important;
            bottom: 0 !important;
            background: rgba(255,255,255,0.98) !important;
            backdrop-filter: blur(10px) !important;
            padding: 15px 20px !important;
            border-top: 1px solid #e5e7eb !important;
            display: flex !important;
            gap: 10px !important;
            z-index: 100 !important;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1) !important;
            margin: 0 !important;
          }
          
          /* ENHANCED COLOR VARIANT STYLING */
          .myfeeds-color-indicators {
            background: rgba(255,255,255,0.9) !important;
            border-radius: 12px !important;
            padding: 3px !important;
          }
          .myfeeds-attribute-title {
            font-weight: 600 !important;
            font-size: 14px !important;
            color: #333 !important;
            margin-bottom: 8px !important;
          }
          .myfeeds-color-option:hover {
            transform: translateY(-1px) !important;
            box-shadow: 0 2px 8px rgba(102,126,234,0.15) !important;
          }
          .myfeeds-color-option.selected {
            border-color: #667eea !important;
            background-color: #f5f3ff !important;
            font-weight: 600 !important;
          }
          .myfeeds-size-option:hover {
            transform: translateY(-1px) !important;
            box-shadow: 0 2px 8px rgba(102,126,234,0.15) !important;
          }
          .myfeeds-size-option.selected {
            border-color: #667eea !important;
            background-color: #f5f3ff !important;
            font-weight: 600 !important;
          }
          .myfeeds-back-button, .myfeeds-add-button {
            padding: 12px 24px !important;
            border-radius: 6px !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
          }
          .myfeeds-back-button {
            background: #f8f9fa !important;
            border: 1px solid #dee2e6 !important;
            color: #495057 !important;
          }
          .myfeeds-back-button:hover {
            background: #e9ecef !important;
            color: #212529 !important;
          }
          .myfeeds-add-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            border: none !important;
            color: white !important;
            box-shadow: 0 2px 4px rgba(102, 126, 234, 0.2) !important;
          }
          .myfeeds-add-button:hover {
            background: linear-gradient(135deg, #5a6fd6 0%, #6a4092 100%) !important;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3) !important;
          }
        `),

        // Header
        React.createElement("div", { className: "myfeeds-editor-header" },
          React.createElement("h3", null, "My Product Picker"),
          React.createElement("p", null, (attributes.selectedProducts || []).length + " products saved | Smart search enabled")
        ),

        // Search Section
        React.createElement("div", { className: "myfeeds-search-section" },
          React.createElement(TextControl, { label: "Search Products", value: searchTerm, onChange: setSearchTerm, onKeyDown: function (e) { if (e.key === "Enter") { e.preventDefault(); openModalAndSearch(); } } }),
          React.createElement("div", { className: "myfeeds-search-actions" },
            React.createElement(Button, { isPrimary: true, onClick: openModalAndSearch, disabled: isLoading || ((!searchTerm || searchTerm.length < 2) && selected.length === 0), style: { background: "linear-gradient(135deg, #667eea 0%, #764ba2 100%)", border: "none", borderRadius: "6px", boxShadow: "0 2px 4px rgba(102, 126, 234, 0.2)" } }, (!searchTerm || searchTerm.length < 2) && selected.length > 0 ? "View Selected" : "Search Products"),
            selected.length > 0 && React.createElement(Button, { isSecondary: true, onClick: function(){ setSelected([]); setAttributes({ selectedProducts: [] }); }, style: { marginLeft: "10px", background: "#fff", color: "#667eea", border: "1px solid #667eea", borderRadius: "6px" } }, "Clear All")
          )
        ),

        // Selected Products Preview
        selected.length > 0 && React.createElement("div", { className: "myfeeds-selected-products", style: { margin: "20px 0", padding: "12px", border: "1px solid #e5e7eb", borderRadius: "6px", backgroundColor: "#f9fafb" } },
          React.createElement("h4", { style: { margin: "0 0 10px 0", fontSize: "14px" } }, selected.length + " Products Selected"),
          React.createElement("div", { style: { display: "flex", flexWrap: "wrap", justifyContent: "flex-start", gap: "10px" } },
            selected.map(function(product, index){
              return React.createElement("div", { key: "selected-" + product.id + "-" + index, className: "myfeeds-selected-product-tile", style: { border: "1px solid #e5e7eb", borderRadius: "4px", padding: "6px", textAlign: "center", fontSize: "12px", position: "relative", backgroundColor: "#fff", width: "130px", flexShrink: 0 } },
                React.createElement("button", { className: "myfeeds-remove-button", onClick: function(e){ e.stopPropagation(); removeSelectedProduct(product.id); }, title: "Remove" }, "\u2715"),
                React.createElement("img", { src: product.image_url || PLACEHOLDER_IMG, alt: product.title || '', style: { width: "100%", height: "90px", objectFit: "contain", borderRadius: "2px" } }),
                product.brand && React.createElement("div", { style: { fontSize: "10px", color: "#888", textTransform: "uppercase", letterSpacing: "0.3px", fontWeight: 600, marginTop: "4px", lineHeight: 1.2, overflow: "hidden", whiteSpace: "nowrap", textOverflow: "ellipsis" } }, product.brand),
                React.createElement("div", { style: { marginTop: "2px", fontWeight: 600, fontSize: "11px", lineHeight: 1.3 } }, (product.title || '').substring(0, 28) + ((product.title || '').length > 28 ? '...' : '')),
                (product.price > 0) && React.createElement("div", { style: { marginTop: "2px", fontSize: "10px", lineHeight: 1.3 } },
                  (product.original_price > 0 && product.original_price > product.price)
                    ? [
                        React.createElement("span", { key: "old", style: { color: "#999", textDecoration: "line-through", marginRight: "3px" } }, toNumber(product.original_price).toFixed(2)),
                        React.createElement("span", { key: "cur", style: { color: "#c0392b", fontWeight: 600 } }, toNumber(product.price).toFixed(2) + ' ' + (product.currency || 'EUR'))
                      ]
                    : React.createElement("span", { style: { color: "#333", fontWeight: 600 } }, toNumber(product.price).toFixed(2) + ' ' + (product.currency || 'EUR'))
                )
              );
            })
          )
        ),

        // Product Search Modal - ONLY show if no product detail is shown
        showModal && !showProductDetail && React.createElement(Modal, { title: results.length > 0 ? ((!searchTerm || searchTerm.length < 2) ? "Your Selected Products (" + selected.length + ")" : "Product Search Results (" + results.length + " found)") : "Product Search Results", onRequestClose: function(){ setShowModal(false); }, className: "myfeeds-spacious-modal", shouldCloseOnClickOutside: false },
          React.createElement("div", { className: "myfeeds-modal-content" },
            // Search within modal - sticky at top
            React.createElement("div", { className: "myfeeds-search-controls" },
              React.createElement("div", { style: { display: "flex", gap: "10px", alignItems: "end" } },
                React.createElement("div", { style: { flex: 1 } }, React.createElement(TextControl, { label: "Search", value: searchTerm, onChange: setSearchTerm, onKeyDown: function (e) { if (e.key === "Enter" || e.key === "NumpadEnter") { e.preventDefault(); fetchProducts(false); } }, style: { width: "100%" } })),
                React.createElement("div", null, React.createElement(Button, { isPrimary: true, onClick: function(){ fetchProducts(false); }, disabled: isLoading || !searchTerm || searchTerm.length < 2, style: { height: "36px", background: "linear-gradient(135deg, #667eea 0%, #764ba2 100%)", border: "none", borderRadius: "6px", boxShadow: "0 2px 4px rgba(102, 126, 234, 0.2)" } }, isLoading ? "Searching..." : "Search"))
              )
            ),

            // Error in modal
            error && React.createElement("div", { style: { backgroundColor: "#fef2f2", border: "1px solid #fecaca", padding: "10px", borderRadius: "6px", margin: "12px 0", color: "#b91c1c" } }, error),

            // Product Grid (5 per row) - scrollable
            results.length > 0 && React.createElement("div", { style: { marginBottom: "20px" } },
              React.createElement("div", { style: { display: "flex", flexWrap: "wrap", justifyContent: "center", gap: "16px", padding: "12px", border: "1px solid #e5e7eb", borderRadius: "8px", background: "#fff" } },
                results.map(productCard)
              )
            ),

            // Load More Button
            hasMoreResults && results.length > 0 && React.createElement("div", { style: { textAlign: "center", padding: "20px 0 10px" } },
              React.createElement(Button, {
                isSecondary: true,
                onClick: function(){ fetchProducts(true); },
                disabled: isLoadingMore,
                style: { 
                  padding: "10px 32px", 
                  fontSize: "14px", 
                  background: "#fff", 
                  color: "#667eea", 
                  border: "1px solid #667eea", 
                  borderRadius: "6px",
                  minWidth: "200px"
                }
              }, isLoadingMore ? "Loading..." : "Load More Results")
            ),

            // Modal Actions - sticky at bottom
            React.createElement("div", { style: { position: "sticky", bottom: "-20px", marginTop: "18px", textAlign: "center", borderTop: "1px solid #e5e7eb", background: "#f8f9fa", margin: "18px -20px -20px -20px", padding: "16px 20px", zIndex: "10" } },
              React.createElement("div", { style: { display: "flex", gap: "10px", justifyContent: "center" } },
                React.createElement(Button, { isPrimary: true, onClick: saveSelection, disabled: selected.length === 0, style: { padding: "10px 18px", fontSize: "14px", background: "linear-gradient(135deg, #667eea 0%, #764ba2 100%)", border: "none", borderRadius: "6px", boxShadow: "0 2px 4px rgba(102, 126, 234, 0.2)" } }, "Use " + selected.length + " Products"),
                React.createElement(Button, { isSecondary: true, onClick: function(){ setSelected(attributes.selectedProducts || []); setShowModal(false); }, style: { padding: "10px 18px", fontSize: "14px", background: "#fff", color: "#667eea", border: "1px solid #667eea", borderRadius: "6px" } }, "Cancel")
              )
            )
          )
        ),

        // Product Detail Modal - FIXED: Always shows on top, proper navigation
        showProductDetail && React.createElement(Modal, { title: "", onRequestClose: function(){ 
          console.log("🔙 MYFEEDS DEBUG: Detail modal X button clicked - returning to search results"); 
          setShowProductDetail(null); 
          // Keep the main search modal open for proper navigation
        }, className: "myfeeds-detail-modal", shouldCloseOnClickOutside: false },
          React.createElement("div", { className: "myfeeds-detail-content" },
            React.createElement("div", { className: "myfeeds-product-detail" },
              // Product Images Section
              React.createElement("div", { className: "myfeeds-product-images" },
                React.createElement("div", { className: "myfeeds-main-image" },
                  React.createElement("img", { src: (showProductDetail && (showProductDetail.large_image || showProductDetail.merchant_image_url || showProductDetail.alternate_image || showProductDetail.image_url || showProductDetail.aw_image_url || showProductDetail.image || showProductDetail.picture)) || PLACEHOLDER_IMG, alt: showProductDetail && (showProductDetail.title || showProductDetail.product_name) || 'Product' })
                ),
                (function(){ 
                  const imgs = detailImages(showProductDetail || {}); 
                  return imgs.length > 1 ? React.createElement("div", { className: "myfeeds-additional-images" },
                    imgs.slice(1, 8).map(function(imgUrl, idx){ 
                      return React.createElement("div", { 
                        key: 'thumb-' + idx, 
                        className: "myfeeds-thumb-image"
                      }, React.createElement("img", { src: imgUrl, alt: 'Image ' + (idx+2) })); 
                    })
                  ) : null; 
                })()
              ),

              // Product Info Section
              React.createElement("div", { className: "myfeeds-product-info" },
                // Brand
                (showProductDetail && (showProductDetail.brand || showProductDetail.brand_name || showProductDetail.manufacturer)) && React.createElement("div", { className: "myfeeds-product-brand" }, showProductDetail.brand || showProductDetail.brand_name || showProductDetail.manufacturer),
                
                // Title
                React.createElement("h1", { className: "myfeeds-product-title" }, showProductDetail && (showProductDetail.title || showProductDetail.product_name || 'Product')),
                
                // Price Section
                React.createElement("div", { className: "myfeeds-product-price" },
                  (function(){ 
                    const current = resolveCurrentPrice(showProductDetail || {});
                    const original = resolveOriginalPrice(showProductDetail || {});
                    const currency = getCurrency(showProductDetail || {});
                    const discount = original > current && current > 0 ? Math.round(((original - current) / original) * 100) : 0;
                    
                    return React.createElement("div", { style: { display: "flex", alignItems: "center", gap: "15px" } },
                      current > 0 && React.createElement("span", { className: "myfeeds-current-price" }, current.toFixed(2) + " " + currency),
                      original > current && original > 0 && React.createElement("span", { className: "myfeeds-old-price" }, original.toFixed(2) + " " + currency),
                      discount > 0 && React.createElement("span", { className: "myfeeds-discount-badge" }, "-" + discount + "%")
                    );
                  })()
                ),
                
                // Shipping
                (function(){ 
                  const s = formatShipping(showProductDetail || {}); 
                  return s ? React.createElement("div", { style: { fontSize: "14px", color: "#28a745", fontWeight: "500", margin: "10px 0" } }, "📦 " + s) : null; 
                })(),
                
                // Product Description - FULL TEXT
                (showProductDetail && (showProductDetail.description || showProductDetail.product_description || showProductDetail.long_description || showProductDetail.full_description)) && React.createElement("div", { className: "myfeeds-product-description" },
                  React.createElement("h4", null, "Product Description"),
                  React.createElement("div", { className: "myfeeds-description-text" }, showProductDetail.description || showProductDetail.product_description || showProductDetail.long_description || showProductDetail.full_description || '')
                ),
                
                // Product Attributes - ENHANCED WITH CLICKABLE COLORS
                React.createElement("div", { className: "myfeeds-product-attributes" },
                  // Colors - Interactive Color Selection
                  (function(){
                    const variants = getAllProductVariants(showProductDetail || {});
                    if (variants.colors.length === 0) return null;
                    
                    return React.createElement("div", { className: "myfeeds-attribute-section" },
                      React.createElement("div", { className: "myfeeds-attribute-title" }, "Available Colors:"),
                      React.createElement("div", { className: "myfeeds-color-selection", style: { display: "flex", gap: "8px", flexWrap: "wrap", marginTop: "8px" } }, 
                        variants.colors.map(function(color, idx){
                          const isCurrentColor = currentVariant.color === color || (!currentVariant.color && idx === 0);
                          
                          return React.createElement("div", { 
                            key: 'color-option-' + idx,
                            className: "myfeeds-color-option",
                            style: {
                              display: "flex",
                              alignItems: "center",
                              gap: "6px",
                              padding: "6px 12px",
                              border: isCurrentColor ? "2px solid #667eea" : "1px solid #ddd",
                              borderRadius: "20px",
                              cursor: "pointer",
                              backgroundColor: isCurrentColor ? "#f5f3ff" : "#fff",
                              fontSize: "13px",
                              fontWeight: isCurrentColor ? "600" : "400",
                              transition: "all 0.2s ease"
                            },
                            onClick: function() {
                              console.log("🎨 MYFEEDS DEBUG: Color clicked:", color);
                              switchColorVariant(color, null);
                            },
                            onMouseEnter: function(e) {
                              if (!isCurrentColor) {
                                e.target.style.backgroundColor = "#f8f9fa";
                                e.target.style.borderColor = "#667eea";
                              }
                            },
                            onMouseLeave: function(e) {
                              if (!isCurrentColor) {
                                e.target.style.backgroundColor = "#fff";
                                e.target.style.borderColor = "#ddd";
                              }
                            }
                          }, [
                            // Color circle indicator
                            React.createElement("div", {
                              style: {
                                width: "16px",
                                height: "16px",
                                borderRadius: "50%",
                                backgroundColor: getColorHex(color),
                                border: "1px solid rgba(0,0,0,0.2)",
                                flexShrink: 0
                              }
                            }),
                            // Color name
                            React.createElement("span", null, color.toString().trim())
                          ]);
                        })
                      )
                    );
                  })(),
                  
                  // Sizes - Interactive Size Selection (uses DB sizes from dedup if available)
                  (function(){
                    var variants = getAllProductVariants(showProductDetail || {});
                    var sizesToShow = detailSizes.length > 0 ? detailSizes : variants.sizes;
                    if (sizesToShow.length === 0) return null;
                    
                    return React.createElement("div", { className: "myfeeds-attribute-section", style: { marginTop: "15px" } },
                      React.createElement("div", { className: "myfeeds-attribute-title" }, "Available Sizes" + (detailSizes.length > 0 ? " (" + detailSizes.length + " variants)" : "") + ":"),
                      React.createElement("div", { className: "myfeeds-size-selection", style: { display: "flex", gap: "6px", flexWrap: "wrap", marginTop: "8px" } }, 
                        sizesToShow.map(function(size, idx){
                          const isCurrentSize = currentVariant.size === size || (!currentVariant.size && idx === 0);
                          
                          return React.createElement("div", { 
                            key: 'size-option-' + idx,
                            className: "myfeeds-size-option",
                            style: {
                              padding: "8px 12px",
                              border: isCurrentSize ? "2px solid #667eea" : "1px solid #ddd",
                              borderRadius: "4px",
                              cursor: "pointer",
                              backgroundColor: isCurrentSize ? "#f5f3ff" : "#fff",
                              fontSize: "13px",
                              fontWeight: isCurrentSize ? "600" : "400",
                              transition: "all 0.2s ease",
                              minWidth: "35px",
                              textAlign: "center"
                            },
                            onClick: function() {
                              console.log("📏 MYFEEDS DEBUG: Size clicked:", size);
                              switchColorVariant(null, size);
                            },
                            onMouseEnter: function(e) {
                              if (!isCurrentSize) {
                                e.target.style.backgroundColor = "#f8f9fa";
                                e.target.style.borderColor = "#667eea";
                              }
                            },
                            onMouseLeave: function(e) {
                              if (!isCurrentSize) {
                                e.target.style.backgroundColor = "#fff";
                                e.target.style.borderColor = "#ddd";
                              }
                            }
                          }, size.toString().trim());
                        })
                      )
                    );
                  })()
                )
              )
            )
          ),
          
          // Action Buttons - Outside scrollable area
          React.createElement("div", { className: "myfeeds-product-actions" },
            React.createElement("button", { 
              className: "myfeeds-back-button",
              onClick: function(){ 
                console.log("🔙 MYFEEDS DEBUG: Back to Search clicked - returning to main search modal");
                setShowProductDetail(null); 
                setShowModal(true); // CRITICAL: Reopen the main search modal instead of closing everything
              }
            }, "← Back to Search"),
            React.createElement("button", {
              className: "myfeeds-add-button",
              onClick: function(){
                toggleProduct(showProductDetail, true);
                setShowProductDetail(null);
              }
            }, (selected || []).some(function(p){ return String(p.id) === String(showProductDetail && showProductDetail.id); }) ? "Remove from Selection" : "Add to Selection")
          )
        )
        ) // close myfeeds-editor-wrapper
      ); // close Fragment
    },

    save() { return null; }
  });
})();