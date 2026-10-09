/**
 * MerTools for Gridbox — Gridbox tabs as rows on phones (Tool/Tabs.php).
 *
 * On screens up to cfg.width px every tab of a Gridbox tabs item becomes a button row (icon, name,
 * arrow) placed right above its content; the row opens and closes that content. The tab bar is
 * hidden. Gridbox rules set the bar and the panes with the item id, so they are overridden inline.
 * Opening a row also selects Gridbox's own tab (its script does what it does when a tab is shown).
 * Wider screens get the tabs back exactly as Gridbox left them; turning a tablet switches on the fly.
 */
(function () {
  'use strict';

  var opts = (window.Joomla && Joomla.getOptions && Joomla.getOptions('plg_system_mertools')) || {};
  var cfg = opts.tabs || { width: 768, first: true };
  var mq = window.matchMedia('(max-width:' + cfg.width + 'px)');
  var items = [];

  function important(el, prop, value) {
    if (value === null) { el.style.removeProperty(prop); } else { el.style.setProperty(prop, value, 'important'); }
  }

  function build(box, n) {
    var bar = box.querySelector('ul.nav-tabs');
    if (!bar || box.mtTabacc) { return; }
    var rows = [];
    [].forEach.call(bar.querySelectorAll(':scope > li > a[href^="#"]'), function (link, i) {
      var pane = null;
      try { pane = box.querySelector(link.getAttribute('href')); } catch (e) { pane = null; }
      if (!pane) { return; }
      var head = document.createElement('button');
      head.type = 'button';
      head.className = 'mt-tabacc-head';
      head.id = 'mt-tabacc-' + n + '-' + i;
      head.setAttribute('aria-controls', pane.id);
      var icon = link.querySelector('i');
      if (icon) {
        var ic = icon.cloneNode(true);
        ic.setAttribute('aria-hidden', 'true');
        head.appendChild(ic);
      }
      var title = link.querySelector('.tabs-title');
      var label = document.createElement('span');
      label.textContent = (title || link).textContent.trim();
      head.appendChild(label);
      pane.parentNode.insertBefore(head, pane);
      var row = { head: head, pane: pane, link: link };
      head.addEventListener('click', function () { toggle(row); });
      rows.push(row);
    });
    if (!rows.length) { return; }
    var item = { box: box, bar: bar, rows: rows, on: false };
    box.mtTabacc = item;
    items.push(item);
  }

  function show(row, open) {
    row.head.setAttribute('aria-expanded', open ? 'true' : 'false');
    important(row.pane, 'display', open ? 'block' : 'none');
    important(row.pane, 'opacity', open ? '1' : null);
  }

  function toggle(row) {
    var open = row.head.getAttribute('aria-expanded') !== 'true';
    if (open) {
      // Gridbox's own tab: its script runs as for a shown tab (lazy images, sliders…)
      row.link.click();
      var item = row.head.closest('.mt-tabacc').mtTabacc;
      // Gridbox's script may hide the other panes: the rows that are open stay open
      item.rows.forEach(function (r) { show(r, r === row || r.head.getAttribute('aria-expanded') === 'true'); });
      var top = row.head.getBoundingClientRect().top;
      if (top < 0 || top > window.innerHeight * 0.8) {
        row.head.scrollIntoView({ block: 'start', behavior: 'smooth' });
      }
    } else {
      show(row, false);
    }
  }

  function mode() {
    items.forEach(function (item) {
      var phone = mq.matches;
      if (phone === item.on) { return; }
      item.on = phone;
      item.box.classList.toggle('mt-tabacc', phone);
      important(item.bar, 'display', phone ? 'none' : null);
      item.rows.forEach(function (r, i) {
        if (phone) {
          r.pane.setAttribute('role', 'region');
          r.pane.setAttribute('aria-labelledby', r.head.id);
          show(r, cfg.first ? r.pane.classList.contains('active') || (i === 0 && !item.box.querySelector('.tab-pane.active')) : false);
        } else {
          r.pane.removeAttribute('role');
          r.pane.removeAttribute('aria-labelledby');
          important(r.pane, 'display', null);
          important(r.pane, 'opacity', null);
        }
      });
    });
  }

  function start() {
    [].forEach.call(document.querySelectorAll('.ba-item-tabs'), build);
    if (!items.length) { return; }
    mode();
    if (mq.addEventListener) { mq.addEventListener('change', mode); } else if (mq.addListener) { mq.addListener(mode); }
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
