/**
 * MerTools — Gridbox's dashboard: the sales statistics open in the range of dates last chosen (day,
 * week, month, year, or own dates with them) instead of always the day. Kept in this browser. Gridbox
 * shows its range first; once its own start is done, the range kept is chosen through its own list
 * (and, for own dates, its own date event), so it works it out as for a click.
 */
(() => {
  'use strict';
  const KEY = 'mertools-gridbox-dashboard-range';
  const select = document.querySelector('.ba-store-statistic-select');
  const $ = window.jQuery;
  if (!select || !$) return;
  const hidden = select.querySelector('input[type="hidden"]');
  const text = select.querySelector('input[type="text"]');
  const wrapper = select.closest('.ba-store-statistic-header-filter-wrapper');
  const read = () => {
    try {
      const v = JSON.parse(localStorage.getItem(KEY) || 'null');
      return v && /^[dwmyc]$/.test(v.type) ? v : null;
    } catch (e) {
      return null;
    }
  };
  const keep = (v) => {
    try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* private mode: not kept */ }
  };
  const day = (s) => typeof s === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(s);
  let restoring = true;

  const restore = () => {
    const saved = read();
    if (saved && (saved.type !== hidden.value || saved.type === 'c')) {
      const li = select.querySelector('li[data-value="' + saved.type + '"]');
      if (li) {
        text.value = li.dataset.text || li.textContent.trim();
        const own = saved.type === 'c' && day(saved.from) && day(saved.to) && wrapper;
        // own dates: Gridbox's change would ask for today's figures and its date event for those dates,
        // both at once, and draw whichever answer comes last — only the second is asked for
        const page = own && typeof DashboardPage === 'function' && typeof DashboardPage.statisticFilter === 'function' ? DashboardPage : null;
        const filter = page && page.statisticFilter;
        if (page) page.statisticFilter = () => {};
        try {
          $(hidden).val(saved.type).trigger('change');
        } finally {
          if (page) page.statisticFilter = filter;
        }
        if (own) {
          wrapper.querySelectorAll('.ba-store-statistic-custom-action input').forEach((input) => {
            input.value = input.dataset.key === 'to' ? saved.to : saved.from;
          });
          $(wrapper).trigger('dateUpdated', [saved.from, saved.to]);
        }
      }
    }
    restoring = false;
  };
  // what the user chooses is kept (the arrows that move the day or week are not a choice of range)
  $(hidden).on('change', function () {
    if (restoring) return;
    const old = read();
    keep(this.value === 'c' && old && old.type === 'c' ? old : { type: this.value });
  });
  if (wrapper) {
    $(wrapper).on('dateUpdated', (e, from, to) => {
      if (!restoring && day(from) && day(to)) keep({ type: 'c', from, to });
    });
  }
  // after Gridbox's own start (it sets its range and asks for its figures once its script has run)
  let tries = 0;
  const wait = () => {
    if ((window.app && window.app.statistic) || ++tries > 50) {
      restore();
    } else {
      setTimeout(wait, 100);
    }
  };
  wait();
})();
