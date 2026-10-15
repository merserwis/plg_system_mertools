/**
 * MerTools for Gridbox — the "lowest price of the last 30 days" line of an Omnibus plugin, shown
 * only while the product (or its chosen version) is on sale.
 *
 * The plugin prints a hidden line (.omnibus_info) per product and per version: data-omnibus-id is
 * the product id or the version key, the product's own line comes first. The line of the product,
 * or of the chosen version when the product has versions, is shown while Gridbox shows a sale price
 * (.ba-add-to-cart-sale-price-wrapper in the price box); a line of 0,00 never. Gridbox redraws the
 * price box at every change of version, and each redraw is followed — no checking on a timer.
 *
 * Kept from the plugin's own script, which MerTools takes off the page: a category with
 * subcategories in Gridbox's Categories element opens and closes with a click on its whole row
 * (its link is taken off, the row gets Gridbox's collapse class) instead of opening its page.
 */
(function () {
  'use strict';

  if (!document.querySelector || !Element.prototype.closest) return;

  /** Subcategories: the whole row opens and closes, as the plugin's script did. */
  function subcategories() {
    var links = document.querySelectorAll('.ba-app-sub-category a');
    for (var i = 0; i < links.length; i++) {
      var link = links[i], icon = link.nextElementSibling;
      if (icon && icon.tagName === 'I') {
        link.removeAttribute('href');
        icon.classList.remove('collapse-categories-list');
        link.parentNode.classList.add('collapse-categories-list');
      }
    }
  }

  /** Gridbox's working copy of an element (it holds the chosen version), else its settings. */
  function data(item) {
    var gb = window.app, all = window.gridboxItems;
    if (gb && gb.items && gb.items[item.id]) return gb.items[item.id];
    for (var group in all || {}) {
      if (all[group] && all[group][item.id]) return all[group][item.id];
    }
    return null;
  }

  /** The key of the line to show: the chosen version, the product itself, or none (no version chosen yet). */
  function current(item, lines) {
    var obj = data(item), product = obj && obj.productData;
    var versions = product && product.variations && Object.keys(product.variations).length;
    if (!versions && !item.querySelector('.ba-add-to-cart-variation')) return lines[0].getAttribute('data-omnibus-id');
    if (obj && obj.product && obj.product.variation) return String(obj.product.variation);
    var chosen = item.querySelector('.ba-add-to-cart-row-value .ba-custom-select input[type="hidden"]');
    return chosen && chosen.value ? chosen.value : null;
  }

  function zero(line) {
    var m = line.textContent.match(/(\d[\d\s .,]*)\D*$/);
    return !m || !/[1-9]/.test(m[1]);
  }

  function update(item) {
    var lines = item.querySelectorAll('.omnibus_info');
    if (!lines.length) return;
    var onSale = !!item.querySelector('.ba-add-to-cart-price .ba-add-to-cart-sale-price-wrapper');
    var key = onSale ? current(item, lines) : null;
    for (var i = 0; i < lines.length; i++) {
      var show = key !== null && lines[i].getAttribute('data-omnibus-id') === key && !zero(lines[i]);
      lines[i].classList.toggle('mt-omnibus-on', show);
    }
  }

  function start() {
    subcategories();
    var boxes = document.querySelectorAll('.ba-add-to-cart-price');
    for (var i = 0; i < boxes.length; i++) {
      var item = boxes[i].closest('.ba-item[id]');
      if (!item || !item.querySelector('.omnibus_info')) continue;
      update(item);
      if (window.MutationObserver) {
        new MutationObserver(update.bind(null, item)).observe(boxes[i], { childList: true });
      }
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
