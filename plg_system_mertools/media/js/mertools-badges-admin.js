/**
 * MerTools — the preview of the badges in the settings (Shop → Badges): the big picture and the card of
 * every style show the look of the form as it is now (unsaved changes too); a click on a card chooses
 * its style, a click on a corner of the picture chooses that corner.
 */
(() => {
  'use strict';
  const root = document.querySelector('[data-mt-badges-preview]');
  if (!root) return;
  const name = (key) => 'jform[params][' + key + ']';
  const field = (key) => document.querySelector('[name="' + name(key) + '"]:checked')
    || document.querySelector('[name="' + name(key) + '"]:not([type="radio"])');
  const value = (key, fallback) => {
    const f = field(key);
    return f && f.value !== '' ? f.value : fallback;
  };
  const boxes = root.querySelectorAll('.mt-bp');
  const parts = ['s', 'c', 'k', 'z', 'l'];

  const update = () => {
    const look = {
      s: value('badge_style', 'gridbox'),
      c: value('badge_colors', 'gridbox'),
      k: value('badge_corner', 'tl'),
      z: value('badge_size', 's'),
      l: value('badge_layout', 'column')
    };
    const offset = Math.max(0, Math.min(80, parseInt(value('badge_offset', '20'), 10) || 0));
    boxes.forEach((box) => {
      Array.from(box.classList).forEach((cls) => {
        if (parts.some((p) => cls.startsWith('mt-bp-' + p + '-'))) box.classList.remove(cls);
      });
      // a card keeps its own style
      parts.forEach((p) => box.classList.add('mt-bp-' + p + '-' + (p === 's' && box.dataset.style ? box.dataset.style : look[p])));
      // on a card (a smaller picture) the badges keep about the same share of it
      box.style.setProperty('--mt-off', (box.dataset.style ? Math.round(offset * 0.5) : offset) + 'px');
      box.style.setProperty('--mt-one', value('badge_color', '#1f2328'));
    });
    root.querySelectorAll('[data-mt-style]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.mtStyle === look.s)));
    root.querySelectorAll('[data-mt-corner]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.mtCorner === look.k)));
  };

  const choose = (key, v) => {
    const f = document.querySelector('[name="' + name(key) + '"]');
    if (!f) return;
    f.value = v;
    f.dispatchEvent(new Event('change', { bubbles: true }));
  };
  root.addEventListener('click', (e) => {
    const card = e.target.closest('[data-mt-style]');
    const corner = e.target.closest('[data-mt-corner]');
    if (card) choose('badge_style', card.dataset.mtStyle);
    if (corner) choose('badge_corner', corner.dataset.mtCorner);
    if (card || corner) e.preventDefault();
  });
  const follow = (e) => {
    if (e.target && typeof e.target.name === 'string' && e.target.name.startsWith('jform[params][badge_')) update();
  };
  document.addEventListener('change', follow);
  document.addEventListener('input', follow);
  update();
})();
