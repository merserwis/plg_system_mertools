/**
 * MerTools — "Discount %" in the Gridbox editor of a product: typing a percentage sets the sale price
 * (price × (1 − %/100), rounded to the store's decimals) and the sale price of every version / set from
 * its own price, the way the Gridbox discount badge reads it. 0 or empty removes the sale price. The
 * field shows the discount of a sale price typed by hand; once a percentage was typed, a new regular
 * price recomputes the sale price. Only the sale prices are saved (by Gridbox), not the percentage.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-discount-config');
  if (!node) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  const decimals = Math.max(0, Math.min(4, Number(cfg.decimals) || 2));
  const round = (value, places) => { const m = 10 ** places; return Math.round(value * m) / m; };
  const num = (text) => { const n = parseFloat(String(text || '').replace(/\s|%/g, '').replace(',', '.')); return Number.isFinite(n) ? n : NaN; };
  const money = (value) => String(round(value, decimals));
  const set = (input, value) => {
    if (input.value === value) return;
    input.value = value;
    // Gridbox updates its data (and the discount badge) on input
    input.dispatchEvent(new Event('input', { bubbles: true }));
  };

  const setup = (pricing) => {
    if (pricing.dataset.mtDiscount) return;
    const price = pricing.querySelector('.blog-post-editor-options-group[data-field-key="price"] input');
    const sale = pricing.querySelector('.blog-post-editor-options-group[data-field-key="sale_price"] input');
    if (!price || !sale) return;
    pricing.dataset.mtDiscount = '1';
    const saleGroup = sale.closest('.blog-post-editor-options-group');
    // a copy of Gridbox's own field (same look), without the classes Gridbox collects for the save
    const group = saleGroup.cloneNode(true);
    group.classList.remove('product-data', 'trigger-variations-table', 'ba-alert-label');
    group.classList.add('mt-discount-group');
    group.dataset.fieldKey = 'mertools_discount';
    group.removeAttribute('data-id');
    group.querySelectorAll('[data-field-key], [data-id]').forEach((el) => { el.removeAttribute('data-field-key'); el.removeAttribute('data-id'); });
    const label = [...group.querySelectorAll('label, .ba-settings-item-title, span')].find((el) => el.textContent.trim() !== '' && !el.classList.contains('field-editor-price-currency') && !el.querySelector('input'));
    if (label) label.textContent = cfg.label;
    group.querySelectorAll('.field-editor-price-currency').forEach((el) => { el.textContent = '%'; });
    group.querySelectorAll('.ba-tooltip, .help-tooltip, i.zmdi-help').forEach((el) => el.remove());
    const input = group.querySelector('input');
    if (!input) return;
    input.value = '';
    input.removeAttribute('name');
    input.removeAttribute('data-key');
    input.setAttribute('data-decimals', '2');
    input.setAttribute('inputmode', 'decimal');
    input.setAttribute('placeholder', cfg.placeholder);
    input.setAttribute('aria-label', cfg.label);
    input.title = cfg.hint;
    saleGroup.after(group);

    let typed = false;
    const show = () => {
      const p = num(price.value);
      const s = num(sale.value);
      input.value = sale.value.trim() !== '' && p > 0 && s >= 0 && s < p ? String(round(100 - (s * 100) / p, 2)) : '';
    };
    const apply = () => {
      const raw = input.value.trim();
      const pct = raw === '' ? 0 : num(raw);
      input.style.borderColor = Number.isNaN(pct) || pct < 0 || pct >= 100 ? '#dc2626' : '';
      if (Number.isNaN(pct) || pct < 0 || pct >= 100) return;
      const p = num(price.value);
      set(sale, pct === 0 || !(p > 0) ? '' : money(p * (1 - pct / 100)));
      document.querySelectorAll('#ba-group-product-variations .variations-table-row').forEach((row) => {
        const vp = row.querySelector('input[data-key="price"]');
        const vs = row.querySelector('input[data-key="sale_price"]');
        if (!vp || !vs) return;
        const n = num(vp.value);
        set(vs, pct === 0 || !(n > 0) ? '' : money(n * (1 - pct / 100)));
      });
    };
    show();
    input.addEventListener('input', () => {
      typed = true;
      clearTimeout(input.mtDelay);
      input.mtDelay = setTimeout(apply, 350);
    });
    price.addEventListener('input', () => {
      clearTimeout(price.mtDelay);
      price.mtDelay = setTimeout(() => { if (typed) apply(); else show(); }, 400);
    });
    sale.addEventListener('input', (e) => {
      // a sale price typed by hand: the field shows its discount, and the percentage no longer leads
      if (e.isTrusted) { typed = false; show(); }
    });
  };

  const scan = () => document.querySelectorAll('#ba-group-product-pricing').forEach(setup);
  let queued = false;
  scan();
  // the editor builds its panels as it goes: looked for again after changes, at most once a frame
  new MutationObserver(() => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => { queued = false; scan(); });
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
