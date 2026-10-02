/**
 * Prices in the editor, written the way the page will write them.
 *
 * The rule lives in PHP (includes/myfeeds-price-format.php): it decides the
 * separators, the symbol's side and the symbols for the site's locale and
 * hands the result over as window.myfeedsPriceFormat. This file only applies
 * it - the same steps as myfeeds_format_price_with_spec() - so a tile in the
 * picker reads exactly like the card in the post. Byte-identical in Free and
 * Pro.
 */
;(function () {
  'use strict';

  var spec = window.myfeedsPriceFormat || {};
  var decimal = typeof spec.decimal === 'string' ? spec.decimal : '.';
  var group = typeof spec.group === 'string' ? spec.group : ',';
  var symbolFirst = spec.symbol_first === true;
  var space = spec.space === true;
  var symbols = spec.symbols && typeof spec.symbols === 'object' ? spec.symbols : {};

  var letterAtEnd = /[A-Za-z]$/;
  var letterAtStart = /^[A-Za-z]/;
  try {
    letterAtEnd = new RegExp('\\p{L}$', 'u');
    letterAtStart = new RegExp('^\\p{L}', 'u');
  } catch (e) { /* a browser without Unicode property escapes keeps the ASCII test */ }

  function symbolFor(currency) {
    var code = String(currency == null ? '' : currency).trim();
    if (code === '') return '';
    if (/^[A-Za-z]{3}$/.test(code)) code = code.toUpperCase();
    return Object.prototype.hasOwnProperty.call(symbols, code) ? String(symbols[code]) : code;
  }

  function format(amount, currency) {
    var n = Number(amount);
    if (!isFinite(n)) n = 0;
    // "1.005e2" parses to 100.5 exactly, where 1.005 * 100 is 100.4999...;
    // this is how PHP's round() lands on 1.01 too.
    var cents = Math.round(Number(Math.abs(n).toFixed(8) + 'e2'));
    var whole = String(Math.floor(cents / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, function () { return group; });
    var frac = String(cents % 100);
    var number = whole + decimal + (frac.length < 2 ? '0' + frac : frac);

    var out = number;
    var symbol = symbolFor(currency);
    if (symbol !== '') {
      var touchingLetter = (symbolFirst ? letterAtEnd : letterAtStart).test(symbol);
      var gap = (space || touchingLetter) ? ' ' : '';
      out = symbolFirst ? symbol + gap + number : number + gap + symbol;
    }
    return (n < 0 && cents > 0) ? '-' + out : out;
  }

  window.myfeedsFormatPrice = format;
})();
