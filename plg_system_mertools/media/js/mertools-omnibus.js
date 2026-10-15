/**
 * MerTools for Gridbox — Omnibus: the line "lowest price of the 30 days before the reduction" of the
 * product page, shown only while the product (or its chosen version) is on sale.
 *
 * The server puts a hidden line (.omnibus_info.mt-omnibus) after the price box for each item of the
 * product on sale: data-omnibus-id is the version key or the product id (its line has data-mt-own).
 * The line of the chosen version, or of the product while no version is chosen, is shown while
 * Gridbox shows a sale price (.ba-add-to-cart-sale-price-wrapper in the price box); a line of 0,00
 * never. Gridbox redraws the price box at every change of version, and each redraw is followed — no
 * checking on a timer.
 *
 * The line under a sale price in the lists is put in by the server; here it only gets the alignment
 * of the prices above it. (The click on a whole category row, the other thing the separate plugin's
 * script did, is in mertools-categories.js.)
 */
(function () {
  'use strict';

  if (!document.querySelector || !Element.prototype.closest) return;

  /** Gridbox's working copy of an element (it holds the chosen version), else its settings. */
  function data(item) {
    var gb = window.app, all = window.gridboxItems;
    if (gb && gb.items && gb.items[item.id]) return gb.items[item.id];
    for (var group in all || {}) {
      if (all[group] && all[group][item.id]) return all[group][item.id];
    }
    return null;
  }

  /** The key of the line to show: the chosen version, else the product itself (its line is marked). */
  function current(item) {
    var obj = data(item);
    if (obj && obj.product && obj.product.variation) return String(obj.product.variation);
    var chosen = item.querySelector('.ba-add-to-cart-row-value .ba-custom-select input[type="hidden"]');
    if (chosen && chosen.value) return chosen.value;
    var own = item.querySelector('.omnibus_info[data-mt-own]');
    return own ? own.getAttribute('data-omnibus-id') : null;
  }

  function zero(line) {
    var m = line.textContent.match(/(\d[\d\s\u00a0.,]*)\D*$/);
    return !m || !/[1-9]/.test(m[1]);
  }

  function update(item) {
    var lines = item.querySelectorAll('.omnibus_info');
    if (!lines.length) return;
    var onSale = !!item.querySelector('.ba-add-to-cart-price .ba-add-to-cart-sale-price-wrapper');
    var key = onSale ? current(item) : null;
    for (var i = 0; i < lines.length; i++) {
      var show = key !== null && lines[i].getAttribute('data-omnibus-id') === key && !zero(lines[i]);
      lines[i].classList.toggle('mt-omnibus-on', show);
    }
  }

  /** A list line wraps to more rows: aligned like the prices above it (Gridbox centres them with flexbox). */
  function alignListLines() {
    var lines = document.querySelectorAll('.mt-omnibus-list');
    for (var i = 0; i < lines.length; i++) {
      var align = getComputedStyle(lines[i].parentNode).alignItems;
      if (align === 'center') lines[i].style.textAlign = 'center';
      else if (align === 'flex-end' || align === 'end') lines[i].style.textAlign = 'right';
    }
  }

  function start() {
    alignListLines();
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
