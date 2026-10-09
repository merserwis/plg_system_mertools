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
  var cfg = opts.tabs || { width: 768, first: false };
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

  /**
   * Sliders and carousels measured while their content was hidden keep a height of 0: Gridbox's
   * carousel sets it at start, and the tab's "shown" makes Gridbox load its module and build the
   * carousel again a moment later, measured again then. So for a few seconds after opening, every
   * carousel of the content that gets a height of 0 is measured again (Gridbox's own setHeight).
   */
  function measure(pane) {
    var $ = window.$g;
    if (!$) { return; }
    $(pane).find('ul, .ba-testimonials, .ba-slideshow, .slideset-wrapper').each(function () {
      var data = $(this).data() || {};
      Object.keys(data).forEach(function (k) {
        var o = data[k];
        if (o && typeof o.setHeight === 'function') {
          try { o.setHeight(); if (typeof o.setLeft === 'function') { o.setLeft(); } } catch (e) { /* not a Gridbox slider */ }
        }
      });
    });
  }

  function refresh(pane) {
    measure(pane);
    if (!window.MutationObserver || pane.mtWatch) { return; }
    var queued = false;
    var watch = new MutationObserver(function (list) {
      if (queued) { return; }
      var zero = list.some(function (m) { return m.target.style && m.target.style.height === '0px' && pane.offsetHeight > 0; });
      if (!zero) { return; }
      queued = true;
      requestAnimationFrame(function () { queued = false; measure(pane); });
    });
    watch.observe(pane, { attributes: true, attributeFilter: ['style'], subtree: true });
    pane.mtWatch = watch;
    setTimeout(function () { watch.disconnect(); pane.mtWatch = null; measure(pane); }, 6000);
  }

  // the space above a row that follows open content
  function gaps(item) {
    item.rows.forEach(function (r, i) {
      r.head.classList.toggle('mt-after-open', i > 0 && item.rows[i - 1].head.getAttribute('aria-expanded') === 'true');
    });
  }

  function toggle(row) {
    var item = row.head.closest('.mt-tabacc').mtTabacc;
    var open = row.head.getAttribute('aria-expanded') !== 'true';
    if (open) {
      // visible first: Gridbox measures its sliders and carousels when the tab is shown
      var still = item.rows.filter(function (r) { return r.head.getAttribute('aria-expanded') === 'true'; });
      show(row, true);
      var li = row.link.parentNode;
      if (li && li.classList.contains('active')) {
        // already Gridbox's active tab: no "shown" from its script, so it is sent here
        if (window.$g) { window.$g(row.link).trigger('shown'); }
      } else {
        row.link.click();
      }
      // Gridbox's script hides the other panes: the rows that are open stay open
      still.concat([row]).forEach(function (r) { show(r, true); });
      refresh(row.pane);
      var top = row.head.getBoundingClientRect().top;
      if (top < 0 || top > window.innerHeight * 0.8) {
        row.head.scrollIntoView({ block: 'start', behavior: 'smooth' });
      }
    } else {
      show(row, false);
    }
    gaps(item);
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
          if (i === item.rows.length - 1) { gaps(item); }
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
