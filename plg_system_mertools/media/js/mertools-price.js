/**
 * MerTools for Gridbox — price on request: a product without a price shows a text ("On request")
 * where its price would be, on the product page and in the product lists and sliders. A product
 * without a price of its own whose versions/models have prices asks for a model instead ("Choose
 * a model from the list"), until a model is chosen.
 *
 * Gridbox prints no price for a product whose price is 0 or empty: the price box stays empty, and
 * its script empties the box of the product page again when a version without a price is chosen.
 * An empty box is filled only where the Gridbox element shows prices: a list or slider with prices
 * switched off in Gridbox (window.gridboxItems: "store": {"price": false}) has an empty box for every
 * product, priced or not, and is left alone. The text takes Gridbox's price classes, so it looks like
 * a price of that element.
 *
 * Which text: on the product page from Gridbox's data of the "Add to cart" element (app.items: the
 * prices of the versions and the version chosen); in the lists from data-mt-choose, put on the
 * products with priced versions by the server. A "lowest price of the last 30 days" line of an
 * Omnibus plugin that says 0,00 is hidden.
 *
 * Content added later (filters, "load more", search results) and the product page box emptied by
 * Gridbox are filled as they come; the boxes already filled are left as they are.
 */
(function () {
  'use strict';

  var opts = (window.Joomla && Joomla.getOptions && Joomla.getOptions('plg_system_mertools')) || {};
  var cfg = opts.price;
  if (!cfg || !cfg.text || !window.MutationObserver || !Element.prototype.closest) return;
  var CHOOSE = cfg.choose || cfg.text;

  var TILE = '.ba-blog-post-add-to-cart-wrapper';   // price and button of a product in a list or slider
  var PAGE = '.ba-add-to-cart-price';               // price of the product page ("Add to cart" element)
  var OMNIBUS = '.omnibus_info';                    // "lowest price of the last 30 days" (Omnibus plugin)
  var ALL = TILE + ',' + PAGE + ',' + OMNIBUS;

  function make(tag, cls, text) {
    var el = document.createElement(tag);
    el.className = cls;
    if (text) el.textContent = text;
    return el;
  }

  /** The settings of a Gridbox element, from the theme, header, footer, library or page group. */
  function settings(item) {
    var all = window.gridboxItems;
    if (!all || !item.id) return null;
    for (var group in all) {
      if (all[group] && all[group][item.id]) return all[group][item.id];
    }
    return null;
  }

  /** Gridbox's working copy of an element (it holds the chosen version), else its settings. */
  function live(item) {
    var gb = window.app;
    return (gb && gb.items && gb.items[item.id]) || settings(item);
  }

  /**
   * Whether the element of this list shows prices. Without its settings (gridboxItems not there),
   * only when another product of the element shows a real price.
   */
  function pricesShown(box) {
    var item = box.closest('.ba-item[id]');
    if (!item) return true;
    var s = settings(item);
    if (s) return !(s.desktop && s.desktop.store && s.desktop.store.price === false);
    if (window.gridboxItems) return true; // Gridbox shows prices when an element has no settings
    return !!item.querySelector('.ba-blog-post-add-to-cart-price:not(.mt-price-request)');
  }

  /** The product page: a model to choose while none is chosen and some model has a price. */
  function pageText(box) {
    var item = box.closest('.ba-item[id]');
    var obj = item && live(item);
    var data = obj && obj.productData;
    if (!data || !data.variations || +data.data.price > 0) return cfg.text;
    if (obj.product && obj.product.variation) return cfg.text;   // the chosen model has no price
    for (var key in data.variations) {
      if (+data.variations[key].price > 0) return CHOOSE;
    }
    return cfg.text;
  }

  function fillTile(box) {
    if (box.querySelector('.ba-blog-post-add-to-cart-price') || !pricesShown(box)) return;
    var price = make('div', 'ba-blog-post-add-to-cart-price mt-price-request');
    var wrap = make('span', 'ba-blog-post-add-to-cart-price-wrapper');
    wrap.appendChild(make('span', 'ba-blog-post-add-to-cart-price-value', box.closest('[data-mt-choose]') ? CHOOSE : cfg.text));
    price.appendChild(wrap);
    box.insertBefore(price, box.firstChild);
  }

  function fillPage(box) {
    if (box.firstElementChild) return;
    var wrap = make('span', 'ba-add-to-cart-price-wrapper mt-price-request');
    wrap.appendChild(make('span', 'ba-add-to-cart-price-value', pageText(box)));
    box.appendChild(wrap);
  }

  /** An Omnibus line whose amount (the last number, after "30 days") is 0,00 is not shown. */
  function checkOmnibus(line) {
    var m = line.textContent.match(/(\d[\d\s\u00a0.,]*)\D*$/);
    if (m && !/[1-9]/.test(m[1])) line.classList.add('mt-price-zero');
  }

  function fill(box) {
    if (box.matches(TILE)) fillTile(box);
    else if (box.matches(PAGE)) fillPage(box);
    else checkOmnibus(box);
  }

  function scan(root) {
    if (root.matches && root.matches(ALL)) fill(root);
    var boxes = root.querySelectorAll(ALL);
    for (var i = 0; i < boxes.length; i++) fill(boxes[i]);
  }

  function start() {
    scan(document);
    new MutationObserver(function (records) {
      for (var i = 0; i < records.length; i++) {
        var r = records[i];
        // Gridbox empties the product page box and puts a price back only when there is one
        if (r.target.nodeType === 1 && r.target.matches(PAGE)) fillPage(r.target);
        for (var j = 0; j < r.addedNodes.length; j++) {
          var node = r.addedNodes[j];
          if (node.nodeType === 1 && !node.classList.contains('mt-price-request')) scan(node);
        }
      }
    }).observe(document.body, { childList: true, subtree: true });
  }

  // the element settings come with Gridbox's own scripts; without them yet, after the page has loaded
  if (window.gridboxItems || document.readyState === 'complete') start();
  else window.addEventListener('load', start, { once: true });
})();
