/**
 * MerTools for Gridbox — phone numbers written as plain text become links that dial them (tel:).
 *
 * Polish numbers in the usual groupings ("22 531 00 94", "(22) 531-00-94", "533 394 222", with or
 * without +48) and international numbers starting with "+" are found in the text of the page; links,
 * buttons, form fields, code and the areas excluded in the settings are left alone, and so are
 * numbers that are not phones (NIP, REGON, KRS, bank accounts, serial numbers, prices, fax numbers).
 * The look of the text stays: the link takes the colour of the text around it.
 *
 * Light on the page: the text is scanned once, when the browser is idle after loading, only text nodes
 * with enough digits are looked at, and content added later is scanned in the same way.
 */
(function () {
  'use strict';

  var opts = (window.Joomla && Joomla.getOptions && Joomla.getOptions('plg_system_mertools')) || {};
  var cfg = opts.tel;
  if (!cfg || !document.createTreeWalker) return;
  if (cfg.touch && window.matchMedia && !matchMedia('(pointer: coarse)').matches) return;

  var S = '[\\s\\u00a0.\\-]';
  var PREFIX = '(?:\\+|00)\\d{2,3}' + S + '?';
  var RE = new RegExp('(^|[^\\w+\\-./:,])('
    // Polish: 2-3-2-2 (landline, area code optionally in brackets) or 3-3-3 (mobile), optional prefix
    + '(?:' + PREFIX + ')?(?:\\(?[1-9]\\d\\)?' + S + '?\\d{3}' + S + '\\d{2}' + S + '\\d{2}|[1-9]\\d{2}' + S + '\\d{3}' + S + '\\d{3})'
    // with a prefix, also nine digits in one block
    + '|' + PREFIX + '[1-9]\\d{8}'
    // other countries: "+" and groups of digits
    + '|\\+[1-9]\\d{0,2}(?:' + S + '?\\(?\\d{1,4}\\)?){2,6}'
    + ')(?![\\w]|' + S + '?\\d)', 'g');
  // numbers that are not phones: what stands before or after them says so
  var BEFORE = /(nip|regon|krs|pesel|iban|bdo|konta|konto|rachunku|rachunek|s\/n|sn|seryjny|fabryczny|nr\s+katalogowy|kod|ean|indeks)\s*(nr\.?|no\.?)?\s*[:.#-]?\s*$/i;
  var AFTER = /^\s?(zł|zl|pln|eur|€|\$|usd|gbp|%|kg|mm|cm|km|szt|godz|h\b|min\b)/i;
  // a fax is not called (but "tel./fax" is a phone)
  var FAX = /fa(x|ks)\s*(nr\.?)?\s*[:.-]?\s*$/i, TELFAX = /tel\.?\s*\/\s*fa(x|ks)\s*(nr\.?)?\s*[:.-]?\s*$/i;
  var NOT_IN = 'a,button,script,style,noscript,textarea,input,select,option,code,pre,kbd,samp,svg,math,iframe,template,title,[contenteditable=""],[contenteditable="true"],.mertools-dt-box';
  var exclude = cfg.exclude ? NOT_IN + ',' + cfg.exclude : NOT_IN;

  function href(number) {
    var plus = /^\s*(\+|00)/.test(number), digits = number.replace(/\D/g, '');
    if (plus) return 'tel:+' + digits.replace(/^00/, '');
    return 'tel:' + (cfg.cc ? '+' + cfg.cc : '') + digits;
  }

  function count(digits) {
    var n = digits.replace(/\D/g, '').length;
    return n >= 8 && n <= 15;
  }

  function inside(node) {
    var el = node.parentElement;
    if (!el) return true;
    try { return !!el.closest(exclude); } catch (e) { return !!el.closest(NOT_IN); }
  }

  /** Links for the numbers in one text node; true when something was replaced. */
  function linkNode(node) {
    var text = node.nodeValue, m, last = 0, frag = null;
    RE.lastIndex = 0;
    while ((m = RE.exec(text))) {
      var start = m.index + m[1].length, number = m[2], end = start + number.length;
      var before = text.slice(Math.max(0, start - 24), start);
      if (!count(number) || BEFORE.test(before) || (FAX.test(before) && !TELFAX.test(before)) || AFTER.test(text.slice(end, end + 6))) continue;
      frag = frag || document.createDocumentFragment();
      if (start > last) frag.appendChild(document.createTextNode(text.slice(last, start)));
      var a = document.createElement('a');
      a.className = 'mertools-tel';
      a.href = href(number);
      a.textContent = number;
      frag.appendChild(a);
      last = end;
    }
    if (!frag) return false;
    if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
    node.parentNode.replaceChild(frag, node);
    return true;
  }

  // a text node is worth a look only with at least eight digits in it
  var DIGITS = /(?:\d\D{0,3}){8}/;

  function scan(root) {
    if (!root || !root.isConnected) return;
    var list = [], w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null), n;
    while ((n = w.nextNode())) {
      if (DIGITS.test(n.nodeValue) && !inside(n)) list.push(n);
    }
    // the reads first, then the changes
    for (var i = 0; i < list.length; i++) linkNode(list[i]);
  }

  var idle = window.requestIdleCallback || function (fn) { return setTimeout(fn, 200); };

  function start() {
    idle(function () {
      scan(document.body);
      if (!window.MutationObserver) return;
      // content added later (tabs loaded on demand, search results, pop-ups): only the new parts
      var added = [], queued = false;
      new MutationObserver(function (records) {
        records.forEach(function (r) {
          r.addedNodes.forEach(function (x) {
            if ((x.nodeType === 1 || x.nodeType === 3) && !(x.nodeType === 1 && x.classList.contains('mertools-tel'))) added.push(x);
          });
        });
        if (!added.length || queued) return;
        queued = true;
        idle(function () {
          var nodes = added; added = []; queued = false;
          nodes.forEach(function (x) {
            if (!x.isConnected) return;
            if (x.nodeType === 3) { if (DIGITS.test(x.nodeValue) && !inside(x)) linkNode(x); } else scan(x);
          });
        }, { timeout: 1000 });
      }).observe(document.body, { childList: true, subtree: true });
    }, { timeout: 1500 });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
