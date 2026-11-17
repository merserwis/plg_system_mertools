/**
 * MerTools — Gridbox's list of orders: the status of many orders at once. A button in the toolbar opens
 * Gridbox's own window of a status for the orders ticked; its Apply gives each of them the status (and
 * the comment) through Gridbox's own change of a status (orders.updateStatus: the history, the e-mail to
 * the customer, stock and promo codes), one order after another, and the list shows the new status.
 * A box Mark as read (ticked by default) marks the unread ones among them as read through Gridbox's own
 * opening of an order (orders.getOrder) — their grey rows and the count of new orders in the menu go, as
 * when they are opened; with it ticked the window applies also without a status, marking them only.
 * A box Send the customer an e-mail (ticked by default): unticked, Gridbox's change is asked with
 * mertools_quiet and MerTools holds its e-mails back (GridboxLists::ordersQuietMail).
 * More buttons: Select unread ticks the grey rows (and only them), Mark as unread makes the orders
 * ticked unread again (mertools_action=orders_unread), counted in the menu as new ones; Mark all as read
 * (orders_read_all) all the new ones of every page; Download PDF Gridbox's PDF of each order ticked (its
 * own layout=pdf, as the button of an order), one order as its PDF, more in one ZIP made here.
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
  const unread = (id) => !!document.querySelector('tr.unread-order[data-id="' + id + '"]');
  const statusOf = (m) => {
    const v = (m.querySelector('.orders-status-select input[type="hidden"]') || {}).value;
    return v && window.app.statuses && window.app.statuses[v] ? v : '';
  };
  const readBox = (m) => m.querySelector('#mt-orders-bulk-read');
  const mailBox = (m) => m.querySelector('#mt-orders-bulk-mail');
  const post = async (data) => {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    fd.append(cfg.token, '1');
    const res = await fetch(cfg.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    return res.json();
  };
  let fresh = Number(cfg.unread) || 0; // the new orders Gridbox counts
  /** The counts of new orders in Gridbox's menu (the shop, its orders, its context menu): up or down, made or gone */
  const bump = (delta) => {
    if (delta > 0) {
      document.querySelectorAll('li[data-ind="store"] > a, li[data-ind="orders"] > a, .store-context-menu a[href$="view=orders"]').forEach((a) => {
        if (a.querySelector('.unread-comments-count[data-type="orders"]')) return;
        const c = document.createElement('span');
        c.className = 'unread-comments-count';
        c.dataset.type = 'orders';
        c.textContent = '0';
        a.append(c);
      });
    }
    document.querySelectorAll('.unread-comments-count[data-type="orders"]').forEach((c) => {
      const n = Number(c.textContent) + delta;
      if (n > 0) c.textContent = n;
      else c.remove();
    });
  };
  const rows = () => [...form.querySelectorAll('tr[data-id]')].filter((tr) => tr.querySelector('input[name="cid[]"]'));
  /** a box of a row ticked or not as Gridbox's own click on it does: Joomla's count of the boxes ticked,
   * Gridbox's change (its CSV export, off while only bookings are ticked) */
  const tick = (tr, on) => {
    const box = tr && tr.querySelector('input[name="cid[]"]');
    if (!box || box.checked === on) return;
    box.checked = on;
    if (window.Joomla && typeof window.Joomla.isChecked === 'function') window.Joomla.isChecked(on, form);
    box.dispatchEvent(new Event('change', { bubbles: true }));
  };
  const untickedAll = () => {
    if (form.boxchecked) {
      form.boxchecked.value = ticked().length;
      form.boxchecked.dispatchEvent(new Event('change'));
    }
    const all = form.querySelector('input[name="checkall-toggle"]');
    if (all && !ticked().length) all.checked = false;
  };
  /** Apply: with a status chosen (Gridbox's own), or with Mark as read ticked and an unread order among them */
  const ready = (m) => {
    const apply = m.querySelector('.apply-order-status');
    if (!apply || !m.dataset.mtBulk) return;
    const box = readBox(m);
    const ids = m.dataset.mtBulk.split(',');
    apply.classList.toggle('active-button', !!statusOf(m) || (!!box && box.checked && ids.some(unread)));
  };

  // the buttons, beside Gridbox's own (Delete, Import / Export CSV)
  const tool = (id, icon, text) => {
    const wrap = document.createElement('div');
    wrap.id = id;
    wrap.innerHTML = '<button class="btn btn-primary disabled" type="button" aria-disabled="true"><span class="' + icon + '" aria-hidden="true"></span> </button>';
    wrap.firstChild.append(text);
    toolbar.append(wrap);
    return wrap.firstChild;
  };
  const button = tool('toolbar-mt-status', 'icon-loop', T.BUTTON || 'Status');
  const selectUnread = tool('toolbar-mt-select-unread', 'icon-check-square', T.SELECT_UNREAD || 'Select unread');
  const markUnread = tool('toolbar-mt-unread', 'icon-eye-slash', T.UNREAD || 'Mark as unread');
  const readAll = tool('toolbar-mt-read-all', 'icon-eye', T.READ_ALL || 'Mark all as read');
  const pdf = tool('toolbar-mt-pdf', 'icon-file-alt', T.PDF || 'PDF');
  const able = (b, on) => {
    b.classList.toggle('disabled', !on);
    if (on) b.removeAttribute('aria-disabled');
    else b.setAttribute('aria-disabled', 'true');
  };
  const refresh = () => {
    const ids = ticked();
    able(button, ids.length > 0);
    able(selectUnread, !!form.querySelector('tr.unread-order[data-id]'));
    able(markUnread, ids.some((id) => !unread(id)));
    able(readAll, fresh > 0 || !!form.querySelector('tr.unread-order[data-id]'));
    able(pdf, ids.length > 0);
  };
  // Joomla.checkAll() ticks the boxes without a change event: looked at after every click too
  form.addEventListener('change', refresh);
  // the new orders counted by the grey of their rows, whoever changes it: MerTools, or Gridbox opening an order
  new MutationObserver((records) => {
    records.forEach((r) => {
      if (!r.target.matches || !r.target.matches('tr[data-id]')) return;
      const was = (r.oldValue || '').split(/\s+/).includes('unread-order');
      const now = r.target.classList.contains('unread-order');
      if (was !== now) fresh = Math.max(0, fresh + (now ? 1 : -1));
    });
    refresh();
  }).observe(form, { subtree: true, attributes: true, attributeFilter: ['class'], attributeOldValue: true });
  form.addEventListener('click', () => setTimeout(refresh, 0));
  refresh();

  const bulk = (m, ids) => {
    const nav = m.querySelector('.order-status-tabs-wrapper .nav-tabs');
    let title = m.querySelector('.mt-orders-bulk-title');
    let read = m.querySelector('.mt-orders-bulk-read');
    if (!ids) {
      delete m.dataset.mtBulk;
      if (title) title.remove();
      if (read) read.remove();
      const area = m.querySelector('textarea');
      if (area) ['height', 'min-height'].forEach((p) => area.style.removeProperty(p));
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
    if (!read) {
      // Gridbox's own boxes (as in its options of a product), under the comment
      read = document.createElement('div');
      read.className = 'mt-orders-bulk-read';
      const row = (id, text, top) => {
        const r = document.createElement('div');
        r.className = 'order-status-group-element ba-checkbox-parent';
        r.style.cssText = 'margin:' + top + 'px 0 0;display:flex;align-items:center;min-height:20px';
        r.innerHTML = '<label class="ba-checkbox ba-hide-checkbox"><input type="checkbox" id="' + id + '" checked><span></span></label><label for="' + id + '"></label>';
        r.lastChild.textContent = text;
        // Joomla's labels of a window are as wide as it, Gridbox's here small capitals: as the comment's text
        r.firstChild.style.cssText = 'flex:none;inline-size:20px !important;width:20px !important;min-width:0 !important;height:20px;margin:0';
        r.lastChild.style.cssText = 'cursor:pointer;margin:0 0 0 12px;flex:1;text-align:left;text-transform:none;letter-spacing:normal;'
          + 'font-size:14px;font-weight:400;line-height:20px;color:inherit';
        read.append(r);
      };
      row('mt-orders-bulk-mail', T.MAIL || 'Send the customer an e-mail', 20);
      row('mt-orders-bulk-read', T.READ || 'Mark as read', 12);
      const group = m.querySelector('.order-status-options-group');
      (group || m.querySelector('#change-order-status') || m.querySelector('.modal-body')).append(read);
      readBox(m).addEventListener('change', () => ready(m));
    }
    read.querySelectorAll('input').forEach((box) => { box.checked = true; });
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
    if (area) {
      area.value = '';
      area.style.height = area.style.minHeight = '130px'; // the boxes under it, in the window without scrolling
    }
    ready(m);
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
  /** Gridbox's own opening of an order marks it read; its row and the counts of the menu as Gridbox does then */
  const markRead = async (id) => {
    try {
      const order = JSON.parse(await window.app.fetch('index.php?option=com_gridbox&task=orders.getOrder', { id: id }));
      if (!order || String(order.id) !== String(id)) return false;
    } catch (err) {
      return false;
    }
    const tr = document.querySelector('tr.unread-order[data-id="' + id + '"]');
    if (tr) {
      tr.classList.remove('unread-order');
      bump(-1);
    }
    return true;
  };
  const run = async (m, ids, status, comment, read, mail) => {
    running = true;
    window.addEventListener('beforeunload', stay);
    const failed = [];
    const unmarked = []; // the status changed, marking read failed: named, not changed again (its e-mail)
    let marked = 0;
    for (let i = 0; i < ids.length; i++) {
      window.app.showLoading(fmt(status ? T.WORKING : T.READING, i + 1, ids.length));
      let ok = true;
      if (status) {
        try {
          const data = { id: ids[i], status: status, comment: comment };
          if (!mail) data.mertools_quiet = 1;
          const answer = JSON.parse(await window.app.fetch('index.php?option=com_gridbox&task=orders.updateStatus', data));
          // Gridbox answers {}; MerTools' refusal of a change without e-mails {success: false}
          ok = answer !== null && answer.success !== false;
        } catch (err) {
          ok = false;
        }
      }
      // an order whose status failed stays as it was, unread too; without a status, only marked read
      if (ok && read && unread(ids[i])) {
        if (await markRead(ids[i])) marked++;
        else if (status) unmarked.push(ids[i]);
        else ok = false;
      }
      if (!ok) {
        failed.push(ids[i]);
        continue;
      }
      if (status) {
        const s = window.app.statuses[status];
        document.querySelectorAll('tr[data-id="' + ids[i] + '"] .order-status-cell').forEach((cell) => {
          cell.style.setProperty('--order-status-color', s.color);
          const t = cell.querySelector('.order-status-title');
          if (t) t.textContent = s.title;
        });
      }
      tick(form.querySelector('tr[data-id="' + ids[i] + '"]'), false);
    }
    untickedAll();
    refresh();
    $(m).modal('hide');
    bulk(m, null);
    // Gridbox's Notice is a class of its script (not on window)
    if (typeof Notice === 'function' && Notice.hideNotification) Notice.hideNotification(); // eslint-disable-line no-undef
    const numbers = (list) => list.map((id) => {
      const td = document.querySelector('tr[data-id="' + id + '"] .order-number-td');
      return td ? td.textContent.trim() : '#' + id;
    }).join(', ');
    const alerts = [];
    if (failed.length) alerts.push(fmt(status ? T.FAILED : T.READ_FAILED, failed.length, numbers(failed)));
    if (unmarked.length) alerts.push(fmt(T.READ_FAILED, unmarked.length, numbers(unmarked)));
    setTimeout(() => {
      if (alerts.length) window.app.showNotice(alerts.join(' '), 'ba-alert');
      else window.app.showNotice(status ? fmt(T.DONE, ids.length) : fmt(T.READ_DONE, marked));
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
    const status = statusOf(m);
    const box = readBox(m);
    const read = !!box && box.checked;
    const ids = m.dataset.mtBulk.split(',').filter((v) => /^\d+$/.test(v));
    if (running || !apply.classList.contains('active-button') || (!status && !(read && ids.some(unread)))) return;
    apply.classList.remove('active-button');
    const mail = mailBox(m);
    run(m, ids, status, (m.querySelector('textarea') || {}).value || '', read, !mail || mail.checked);
  }, true);

  // Select unread: the grey rows of the page ticked, the others not
  selectUnread.addEventListener('click', (e) => {
    e.preventDefault();
    if (selectUnread.classList.contains('disabled')) return;
    rows().forEach((tr) => tick(tr, tr.classList.contains('unread-order')));
    refresh();
  });

  // Mark as unread: the orders ticked that were read, grey again and counted in the menu
  let marking = false;
  markUnread.addEventListener('click', async (e) => {
    e.preventDefault();
    const ids = ticked().filter((id) => !unread(id));
    if (marking || !ids.length) return;
    marking = true;
    window.app.showLoading(fmt(T.UNREAD || ''));
    let done = null;
    try {
      const data = await post({ mertools_action: 'orders_unread', ids: ids.join(',') });
      done = data && data.success ? (data.ids || []).map(String) : null;
    } catch (err) {
      done = null;
    }
    if (typeof Notice === 'function' && Notice.hideNotification) Notice.hideNotification(); // eslint-disable-line no-undef
    if (done) {
      done.forEach((id) => {
        const tr = form.querySelector('tr[data-id="' + id + '"]');
        if (tr) tr.classList.add('unread-order');
      });
      if (done.length) bump(done.length);
      rows().forEach((tr) => { if (ids.includes(tr.dataset.id)) tick(tr, false); });
      untickedAll();
    }
    refresh();
    marking = false;
    setTimeout(() => {
      if (done) window.app.showNotice(fmt(T.UNREAD_DONE, done.length));
      else window.app.showNotice(fmt(T.UNREAD_FAILED), 'ba-alert');
    }, 450);
  });

  // Mark all as read: every new order, of every page
  readAll.addEventListener('click', async (e) => {
    e.preventDefault();
    if (marking || readAll.classList.contains('disabled') || !window.confirm(fmt(T.READ_ALL_ASK, fresh))) return;
    marking = true;
    window.app.showLoading(T.READ_ALL || '');
    let count = null;
    try {
      const data = await post({ mertools_action: 'orders_read_all' });
      count = data && data.success ? Number(data.count) || 0 : null;
    } catch (err) {
      count = null;
    }
    if (typeof Notice === 'function' && Notice.hideNotification) Notice.hideNotification(); // eslint-disable-line no-undef
    if (count !== null) {
      form.querySelectorAll('tr.unread-order[data-id]').forEach((tr) => tr.classList.remove('unread-order'));
      if (count) bump(-count);
      fresh = 0;
    }
    refresh();
    marking = false;
    setTimeout(() => {
      if (count !== null) window.app.showNotice(fmt(T.READ_DONE, count));
      else window.app.showNotice(fmt(T.READ_ALL_FAILED), 'ba-alert');
    }, 450);
  });

  // ---------------------------------------------------------------- Download PDF
  const CRC = new Uint32Array(256).map((_, n) => {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    return c >>> 0;
  });
  const crc32 = (bytes) => {
    let c = 0xffffffff;
    for (let i = 0; i < bytes.length; i++) c = CRC[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
  };
  /** a ZIP of the files as they are (PDF is compressed already): [name, Uint8Array] */
  const zip = (files) => {
    const enc = new TextEncoder();
    const now = new Date();
    const time = (now.getHours() << 11) | (now.getMinutes() << 5) | (now.getSeconds() >> 1);
    const date = ((now.getFullYear() - 1980) << 9) | ((now.getMonth() + 1) << 5) | now.getDate();
    const parts = [];
    const dir = [];
    let offset = 0;
    files.forEach(([name, data]) => {
      const n = enc.encode(name);
      const crc = crc32(data);
      const head = new DataView(new ArrayBuffer(30));
      [[0, 0x04034b50, 4], [4, 20, 2], [6, 0x0800, 2], [8, 0, 2], [10, time, 2], [12, date, 2], [14, crc, 4], [18, data.length, 4], [22, data.length, 4],
        [26, n.length, 2], [28, 0, 2]].forEach(([at, v, size]) => (size === 4 ? head.setUint32(at, v, true) : head.setUint16(at, v, true)));
      const entry = new DataView(new ArrayBuffer(46));
      [[0, 0x02014b50, 4], [4, 20, 2], [6, 20, 2], [8, 0x0800, 2], [10, 0, 2], [12, time, 2], [14, date, 2], [16, crc, 4], [20, data.length, 4],
        [24, data.length, 4], [28, n.length, 2], [30, 0, 2], [32, 0, 2], [34, 0, 2], [36, 0, 2], [38, 0, 4], [42, offset, 4]]
        .forEach(([at, v, size]) => (size === 4 ? entry.setUint32(at, v, true) : entry.setUint16(at, v, true)));
      parts.push(head, n, data);
      dir.push(entry, n);
      offset += 30 + n.length + data.length;
    });
    const size = dir.reduce((sum, p) => sum + p.byteLength, 0);
    const end = new DataView(new ArrayBuffer(22));
    [[0, 0x06054b50, 4], [4, 0, 2], [6, 0, 2], [8, files.length, 2], [10, files.length, 2], [12, size, 4], [16, offset, 4], [20, 0, 2]]
      .forEach(([at, v, len]) => (len === 4 ? end.setUint32(at, v, true) : end.setUint16(at, v, true)));
    return new Blob([...parts, ...dir, end], { type: 'application/zip' });
  };
  const save = (blob, name) => {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 60000);
  };
  const numberOf = (id) => {
    const td = document.querySelector('tr[data-id="' + id + '"] .order-number-td');
    return td ? td.textContent.trim() : '#' + id;
  };
  let fetching = false;
  pdf.addEventListener('click', async (e) => {
    e.preventDefault();
    const ids = ticked();
    if (fetching || !ids.length) return;
    fetching = true;
    window.addEventListener('beforeunload', stay);
    const files = [];
    const failed = [];
    const names = new Set();
    for (let i = 0; i < ids.length; i++) {
      window.app.showLoading(fmt(T.PDF_WORKING, i + 1, ids.length));
      try {
        // Gridbox's own PDF of an order (the button PDF of its window)
        const res = await fetch('index.php?option=com_gridbox&view=orders&layout=pdf&tmpl=component&id=' + ids[i], { credentials: 'same-origin' });
        const data = new Uint8Array(await res.arrayBuffer());
        if (!res.ok || String.fromCharCode(...data.slice(0, 5)) !== '%PDF-') throw new Error('not a PDF');
        const given = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(res.headers.get('Content-Disposition') || '');
        let name = (given ? decodeURIComponent(given[1]) : 'order-' + numberOf(ids[i]).replace(/^#/, '') + '.pdf').replace(/[\\/:*?"<>|]+/g, '-');
        for (let n = 2; names.has(name); n++) name = name.replace(/(?:-\d+)?\.pdf$/i, '') + '-' + n + '.pdf';
        names.add(name);
        files.push([name, data]);
        // Gridbox's PDF opens the order: read now, as its row and the counts show
        const tr = form.querySelector('tr.unread-order[data-id="' + ids[i] + '"]');
        if (tr) {
          tr.classList.remove('unread-order');
          bump(-1);
        }
      } catch (err) {
        failed.push(ids[i]);
      }
    }
    if (typeof Notice === 'function' && Notice.hideNotification) Notice.hideNotification(); // eslint-disable-line no-undef
    if (files.length === 1) save(new Blob([files[0][1]], { type: 'application/pdf' }), files[0][0]);
    else if (files.length) save(zip(files), 'orders-' + new Date().toISOString().slice(0, 10) + '.zip');
    window.removeEventListener('beforeunload', stay);
    fetching = false;
    refresh();
    if (failed.length) setTimeout(() => window.app.showNotice(fmt(T.PDF_FAILED, failed.length, failed.map(numberOf).join(', ')), 'ba-alert'), 450);
  });
})();
