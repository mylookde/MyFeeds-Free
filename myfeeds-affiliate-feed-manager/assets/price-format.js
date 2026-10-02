/**
 * Prices in the editor, written the way the page will write them.
 *
 * The rule lives in PHP (includes/myfeeds-price-format.php): it decides,
 * per currency, the separators and the symbol's side (the site's language
 * for its own currency, the currency's home convention for the others), and
 * the symbols, and hands the result over as window.myfeedsPriceFormat
 * (myfeeds_price_format_payload()). This file only applies it - the same
 * steps as myfeeds_format_price_with_spec() - so a tile in the picker reads
 * exactly like the card in the post. Byte-identical in Free and Pro.
 */
;(function () {
  'use strict';

  var spec = window.myfeedsPriceFormat || {};
  var decimal = typeof spec.decimal === 'string' ? spec.decimal : '.';
  var group = typeof spec.group === 'string' ? spec.group : ',';
  var symbolFirst = spec.symbol_first === true;
  var space = spec.space === true;
  var symbols = spec.symbols && typeof spec.symbols === 'object' ? spec.symbols : {};
  // Currency code => the layout that currency is written in on this site.
  // A currency missing here keeps the site's layout above, as in PHP.
  var currencies = spec.currencies && typeof spec.currencies === 'object' ? spec.currencies : {};
  var has = Object.prototype.hasOwnProperty;

  var letterAtEnd = /[A-Za-z]$/;
  var letterAtStart = /^[A-Za-z]/;
  try {
    letterAtEnd = new RegExp('\\p{L}$', 'u');
    letterAtStart = new RegExp('^\\p{L}', 'u');
  } catch (e) { /* a browser without Unicode property escapes keeps the ASCII test */ }

  function codeOf(currency) {
    var code = String(currency == null ? '' : currency).trim();
    return /^[A-Za-z]{3}$/.test(code) ? code.toUpperCase() : code;
  }

  function symbolFor(code) {
    if (code === '') return '';
    return has.call(symbols, code) ? String(symbols[code]) : code;
  }

  function format(amount, currency) {
    var code = codeOf(currency);
    var own = has.call(currencies, code) && currencies[code] && typeof currencies[code] === 'object' ? currencies[code] : null;
    var dec = own && typeof own.decimal === 'string' ? own.decimal : decimal;
    var grp = own && typeof own.group === 'string' ? own.group : group;
    var first = own ? own.symbol_first === true : symbolFirst;
    var spaced = own ? own.space === true : space;

    var n = Number(amount);
    if (!isFinite(n)) n = 0;
    // "1.005e2" parses to 100.5 exactly, where 1.005 * 100 is 100.4999...;
    // this is how PHP's round() lands on 1.01 too.
    var cents = Math.round(Number(Math.abs(n).toFixed(8) + 'e2'));
    var whole = String(Math.floor(cents / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, function () { return grp; });
    var frac = String(cents % 100);
    var number = whole + dec + (frac.length < 2 ? '0' + frac : frac);

    var out = number;
    var symbol = symbolFor(code);
    if (symbol !== '') {
      var touchingLetter = (first ? letterAtEnd : letterAtStart).test(symbol);
      var gap = (spaced || touchingLetter) ? ' ' : '';
      out = first ? symbol + gap + number : number + gap + symbol;
    }
    return (n < 0 && cents > 0) ? '-' + out : out;
  }

  // The shipping cost a feed value names, or null when it names none - the
  // same pattern string myfeeds_shipping_amount() matches with, handed over
  // with the spec. Only a real 0 is free shipping.
  var shippingPattern = null;
  try {
    shippingPattern = typeof spec.shipping_pattern === 'string' ? new RegExp(spec.shipping_pattern) : null;
  } catch (e) { shippingPattern = null; }

  function shippingAmount(raw) {
    if (typeof raw === 'number') {
      return (isFinite(raw) && raw >= 0) ? raw : null;
    }
    if (typeof raw !== 'string' || !shippingPattern) return null;
    var m = shippingPattern.exec(raw);
    return m ? parseFloat(m[1].replace(',', '.')) : null;
  }

  window.myfeedsFormatPrice = format;
  window.myfeedsShippingAmount = shippingAmount;
})();
