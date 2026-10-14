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

  // the look: the currency next to a price on one line, as wide as its text ("zł netto" did not fit in
  // Gridbox's 50 px), and the discount line under the sale price
  const style = document.createElement('style');
  style.textContent = '#blog-post-editor-fields-options #ba-group-product-pricing .field-editor-price-wrapper.right-currency-position > span.field-editor-price-currency'
    + '{left:auto!important;right:1px!important;margin-left:0!important;width:auto!important;white-space:nowrap;text-overflow:clip}'
    + '#ba-group-product-pricing .mt-discount-line{display:flex;align-items:center;gap:10px;margin-top:8px}'
    + '#ba-group-product-pricing .mt-discount-line label{margin:0!important;font-size:13px;white-space:nowrap}'
    + '#ba-group-product-pricing .mt-discount-line .field-editor-price-wrapper{flex:0 0 110px;width:110px!important;max-width:110px}';
  document.head.append(style);
  const fitCurrency = (pricing) => pricing.querySelectorAll('.field-editor-price-wrapper.right-currency-position').forEach((wrapper) => {
    const span = wrapper.querySelector(':scope > span.field-editor-price-currency');
    const input = wrapper.querySelector(':scope > input');
    if (span && input && span.offsetWidth > 0) input.style.setProperty('padding-right', (span.offsetWidth + 10) + 'px', 'important');
  });

  const setup = (pricing) => {
    if (pricing.dataset.mtDiscount) return;
    const price = pricing.querySelector('.blog-post-editor-options-group[data-field-key="price"] input');
    const sale = pricing.querySelector('.blog-post-editor-options-group[data-field-key="sale_price"] input');
    if (!price || !sale) return;
    pricing.dataset.mtDiscount = '1';
    const saleWrapper = sale.closest('.field-editor-price-wrapper');
    // a small line under the sale price, in Gridbox's own field style, inside the same field (the
    // three-per-row layout of Gridbox stays as it is)
    const line = document.createElement('div');
    line.className = 'mt-discount-line';
    const id = 'mt-discount-' + Math.random().toString(36).slice(2, 8);
    const label = document.createElement('label');
    label.className = 'ba-field-editor-label';
    label.htmlFor = id;
    label.textContent = cfg.label;
    const wrap = document.createElement('div');
    wrap.className = 'field-editor-price-wrapper right-currency-position mt-discount-wrap';
    const unit = document.createElement('span');
    unit.className = 'field-editor-price-currency';
    unit.textContent = '%';
    const input = document.createElement('input');
    input.type = 'text';
    input.id = id;
    input.setAttribute('inputmode', 'decimal');
    input.setAttribute('placeholder', cfg.placeholder);
    input.title = cfg.hint;
    wrap.append(unit, input);
    line.append(label, wrap);
    saleWrapper.after(line);
    // Gridbox copies what is typed in the price fields to the versions: our field must not reach it
    ['input', 'keyup', 'keydown', 'change', 'focusin', 'focusout', 'paste'].forEach((type) => input.addEventListener(type, (e) => e.stopPropagation()));
    fitCurrency(pricing);
    setTimeout(() => fitCurrency(pricing), 500);
    window.addEventListener('resize', () => fitCurrency(pricing));

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

  // set up once; the currency width again whenever the panel shows (hidden fields have no width)
  const scan = () => document.querySelectorAll('#ba-group-product-pricing').forEach((pricing) => { setup(pricing); if (pricing.offsetParent) fitCurrency(pricing); });
  let queued = false;
  scan();
  // the editor builds its panels as it goes: looked for again after changes, at most once a frame
  new MutationObserver(() => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => { queued = false; scan(); });
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
