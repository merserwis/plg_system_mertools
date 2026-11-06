/**
 * MerTools — the badges of a product on the picture of its page (Shop → Badges): the server puts them
 * into the product's slideshow and gallery; when Gridbox swaps the pictures of a variation it writes
 * them anew, and this puts the badges back (over the slides, or on the first picture of the gallery).
 *
 * The sale badge of a product with versions carries the text of each one (data-mt-sale: version key or
 * product id => "- n%", at the price shown, with the store's sales): it follows the version chosen, as
 * Gridbox redraws its price box at every change of version (each redraw followed, no timer).
 */
(() => {
  'use strict';
  const first = document.querySelector('[data-mt-badges]');
  if (!first || !window.MutationObserver) return;
  const html = first.outerHTML;
  const items = document.querySelectorAll('.ba-item-product-slideshow, .ba-item-product-gallery');
  const saleSpan = first.querySelector('[data-mt-sale]');
  let texts = null;
  try {
    texts = saleSpan ? JSON.parse(saleSpan.getAttribute('data-mt-sale')) : null;
  } catch (e) {
    texts = null;
  }
  let shown = saleSpan ? saleSpan.textContent : '';

  /** Gridbox's working copy of the element "Add to cart" (it holds the chosen version), else its settings. */
  const data = (item) => {
    const gb = window.app;
    const all = window.gridboxItems || {};
    if (gb && gb.items && gb.items[item.id]) return gb.items[item.id];
    for (const group in all) {
      if (all[group] && all[group][item.id]) return all[group][item.id];
    }
    return null;
  };
  /** The key of the version chosen (as Omnibus reads it), null when none is known. */
  const chosen = () => {
    const box = document.querySelector('.ba-add-to-cart-price');
    const item = box && box.closest('.ba-item[id]');
    if (!item) return null;
    const obj = data(item);
    if (obj && obj.product && obj.product.variation) return String(obj.product.variation);
    const input = item.querySelector('.ba-add-to-cart-row-value .ba-custom-select input[type="hidden"]');
    return input && input.value ? input.value : null;
  };
  const sale = () => {
    if (!texts) return;
    const key = chosen();
    if (key !== null && Object.prototype.hasOwnProperty.call(texts, key)) shown = texts[key];
    document.querySelectorAll('[data-mt-badges] [data-mt-sale]').forEach((span) => {
      if (span.textContent !== shown) span.textContent = shown;
    });
  };
  const place = () => {
    items.forEach((item) => {
      if (item.querySelector('[data-mt-badges]')) return;
      const slides = item.querySelector('.slideshow-content');
      if (slides) {
        slides.insertAdjacentHTML('afterbegin', html);
        return;
      }
      const picture = item.querySelector('.ba-gridbox-gallery-image');
      if (picture) {
        picture.classList.add('mt-badge-host');
        picture.insertAdjacentHTML('beforeend', html);
      }
    });
    sale();
  };
  const watch = new MutationObserver(place);
  items.forEach((item) => watch.observe(item, { childList: true, subtree: true }));
  if (texts) {
    const prices = new MutationObserver(sale);
    document.querySelectorAll('.ba-add-to-cart-price').forEach((box) => prices.observe(box, { childList: true }));
    sale();
  }
})();
