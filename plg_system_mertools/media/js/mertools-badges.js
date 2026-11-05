/**
 * MerTools — the badges of a product on the picture of its page (Shop → Badges): the server puts them
 * into the product's slideshow and gallery; when Gridbox swaps the pictures of a variation it writes
 * them anew, and this puts the badges back (over the slides, or on the first picture of the gallery).
 */
(() => {
  'use strict';
  const first = document.querySelector('[data-mt-badges]');
  if (!first || !window.MutationObserver) return;
  const html = first.outerHTML;
  const items = document.querySelectorAll('.ba-item-product-slideshow, .ba-item-product-gallery');
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
  };
  const watch = new MutationObserver(place);
  items.forEach((item) => watch.observe(item, { childList: true, subtree: true }));
})();
