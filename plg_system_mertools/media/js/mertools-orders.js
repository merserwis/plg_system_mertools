/**
 * MerTools — Gridbox's list of orders: the status of many orders at once. A button in the toolbar opens
 * Gridbox's own window of a status for the orders ticked; its Apply gives each of them the status (and
 * the comment) through Gridbox's own change of a status (orders.updateStatus: the history, the e-mail to
 * the customer, stock and promo codes), one order after another, and the list shows the new status.
 * Opened from an order, the window is Gridbox's as before.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-orders-config');
  const form = document.getElementById('adminForm');
  const toolbar = document.getElementById('toolbar');
  if (!node || !form || !toolbar || !window.app || !window.jQuery) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  const T = cfg.texts || {};
  /** Joomla's %d / %1$d / %2$s in the texts */
  const fmt = (s, ...a) => {
    let i = 0;
    return String(s || '').replace(/%(?:(\d)\$)?[ds]/g, (m, n) => String(a[n ? n - 1 : i++] ?? ''));
  };
  // Gridbox's $g is set only on DOMContentLoaded, after this script: the same jQuery
  const $ = (el) => (window.$g || window.jQuery)(el);
  const modal = () => document.getElementById('orders-status-modal');
  const ticked = () => [...form.querySelectorAll('input[name="cid[]"]:checked')].map((c) => c.value).filter((v) => /^\d+$/.test(v));

  // the button, beside Gridbox's own (Delete, Import / Export CSV)
  const wrap = document.createElement('div');
  wrap.id = 'toolbar-mt-status';
  wrap.innerHTML = '<button class="btn btn-primary disabled" type="button" aria-disabled="true"><span class="icon-loop" aria-hidden="true"></span> </button>';
  const button = wrap.firstChild;
  button.append(T.BUTTON || 'Status');
  toolbar.append(wrap);
  const refresh = () => {
    const none = ticked().length === 0;
    button.classList.toggle('disabled', none);
    if (none) button.setAttribute('aria-disabled', 'true');
    else button.removeAttribute('aria-disabled');
  };
  // Joomla.checkAll() ticks the boxes without a change event: looked at after every click too
  form.addEventListener('change', refresh);
  form.addEventListener('click', () => setTimeout(refresh, 0));
  refresh();

  const bulk = (m, ids) => {
    const nav = m.querySelector('.order-status-tabs-wrapper .nav-tabs');
    let title = m.querySelector('.mt-orders-bulk-title');
    if (!ids) {
      delete m.dataset.mtBulk;
      if (title) title.remove();
      if (nav) nav.style.display = '';
      return;
    }
    m.dataset.mtBulk = ids.join(',');
    if (!title) {
      title = document.createElement('p');
      title.className = 'mt-orders-bulk-title';
      title.style.cssText = 'margin:0 0 20px;font-size:16px;font-weight:500;line-height:24px';
      (m.querySelector('#change-order-status') || m.querySelector('.modal-body')).prepend(title);
    }
    title.textContent = fmt(T.TITLE, ids.length);
    // the history is of one order: only the status and the comment
    if (nav) {
      nav.style.display = 'none';
      nav.querySelectorAll('li').forEach((li, i) => li.classList.toggle('active', i === 0));
    }
    m.querySelectorAll('.tab-content > .tab-pane').forEach((p) => p.classList.toggle('active', p.id === 'change-order-status'));
    const select = m.querySelector('.orders-status-select');
    if (select) {
      select.querySelector('input[type="hidden"]').value = '';
      select.querySelector('input[type="text"]').value = '';
      select.style.removeProperty('--status-color');
    }
    const area = m.querySelector('textarea');
    if (area) area.value = '';
    const apply = m.querySelector('.apply-order-status');
    if (apply) apply.classList.remove('active-button');
  };

  button.addEventListener('click', (e) => {
    e.preventDefault();
    const ids = ticked();
    const m = modal();
    if (!ids.length || !m) return;
    bulk(m, ids);
    $(m).modal();
  });

  // an order's own window: Gridbox's, without the list of the orders ticked
  document.addEventListener('click', (e) => {
    const m = modal();
    if (m && m.dataset.mtBulk && e.target.closest && e.target.closest('.edit-order-status')) bulk(m, null);
  }, true);

  let running = false;
  // leaving the page while orders are still being changed leaves the rest unchanged: the browser asks first
  const stay = (e) => { e.preventDefault(); e.returnValue = ''; };
  const run = async (m, ids, status, comment) => {
    running = true;
    window.addEventListener('beforeunload', stay);
    const failed = [];
    for (let i = 0; i < ids.length; i++) {
      window.app.showLoading(fmt(T.WORKING, i + 1, ids.length));
      let ok = false;
      try {
        const text = await window.app.fetch('index.php?option=com_gridbox&task=orders.updateStatus', { id: ids[i], status: status, comment: comment });
        ok = JSON.parse(text) !== null;
      } catch (err) {
        ok = false;
      }
      if (!ok) {
        failed.push(ids[i]);
        continue;
      }
      const s = window.app.statuses[status];
      document.querySelectorAll('tr[data-id="' + ids[i] + '"] .order-status-cell').forEach((cell) => {
        cell.style.setProperty('--order-status-color', s.color);
        const t = cell.querySelector('.order-status-title');
        if (t) t.textContent = s.title;
      });
      const box = form.querySelector('input[name="cid[]"][value="' + ids[i] + '"]');
      if (box) box.checked = false;
    }
    if (form.boxchecked) {
      form.boxchecked.value = ticked().length;
      form.boxchecked.dispatchEvent(new Event('change'));
    }
    const all = form.querySelector('input[name="checkall-toggle"]');
    if (all && !ticked().length) all.checked = false;
    refresh();
    $(m).modal('hide');
    bulk(m, null);
    // Gridbox's Notice is a class of its script (not on window)
    if (typeof Notice === 'function' && Notice.hideNotification) Notice.hideNotification(); // eslint-disable-line no-undef
    const numbers = failed.map((id) => {
      const td = document.querySelector('tr[data-id="' + id + '"] .order-number-td');
      return td ? td.textContent.trim() : '#' + id;
    });
    setTimeout(() => {
      if (failed.length) window.app.showNotice(fmt(T.FAILED, failed.length, numbers.join(', ')), 'ba-alert');
      else window.app.showNotice(fmt(T.DONE, ids.length));
    }, 450);
    window.removeEventListener('beforeunload', stay);
    running = false;
  };

  // Apply in the window opened for the orders ticked: MerTools' change instead of Gridbox's of one order
  document.addEventListener('click', (e) => {
    const apply = e.target.closest && e.target.closest('.apply-order-status');
    const m = modal();
    if (!apply || !m || !m.dataset.mtBulk) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const status = (m.querySelector('.orders-status-select input[type="hidden"]') || {}).value;
    if (running || !apply.classList.contains('active-button') || !status || !window.app.statuses || !window.app.statuses[status]) return;
    apply.classList.remove('active-button');
    const ids = m.dataset.mtBulk.split(',').filter((v) => /^\d+$/.test(v));
    run(m, ids, status, (m.querySelector('textarea') || {}).value || '');
  }, true);
})();
