/**
 * MerTools for Gridbox — the main menu on screens a little too narrow for it.
 *
 * hold: a drop-down or mega menu of a top item stays open while the mouse goes from the item down to it.
 *   Gridbox puts the panel under the whole menu, not under the item, so when the menu has wrapped onto a
 *   second line there is a strip between the item and its panel; the item loses :hover there and the panel
 *   disappears under the cursor. While the pointer is in that strip (between the bottom of the item and
 *   the top of the panel, across the panel's width) the item keeps the class mt-menu-hold, which shows the
 *   panel as :hover does. Another top item, the item itself or the panel ends it, and so does leaving the strip.
 *
 * fit: a horizontal menu that wraps onto a second line gets narrower spaces between its items (the side
 *   padding of the top links, then their letter spacing) step by step until it fits on one line; if even the
 *   narrowest step does not fit, the menu is left as it was (inline values of the links given back as they
 *   were). Measured again after a resize and once the fonts are loaded. Menus in hamburger mode and vertical
 *   menus (a top item as wide as the menu) are left alone.
 */
(function () {
  'use strict';

  var cfg = (window.Joomla && Joomla.getOptions) ? (Joomla.getOptions('plg_system_mertools') || {}).menu : null;
  if (!cfg) { return; }

  var PANELS = ':scope > .tabs-content-wrapper, :scope > .nav-child, :scope > .mod-menu__sub';
  var HOLD = 'mt-menu-hold';

  function menus() {
    return Array.prototype.slice.call(document.querySelectorAll('.ba-item-main-menu ul.nav, .ba-item-main-menu ul.mod-menu'))
      .filter(function (ul) { return !ul.parentElement.closest('ul'); });
  }

  function topItems(ul) {
    return Array.prototype.filter.call(ul.children, function (li) { return li.tagName === 'LI'; });
  }

  /* ---------- hold ---------- */

  var held = null;

  function release() {
    if (held) {
      held.classList.remove(HOLD);
      held = null;
      document.removeEventListener('pointermove', onMove, true);
    }
  }

  function panelOf(li) {
    return li.querySelector(PANELS);
  }

  // the strip between the item and its open panel, or null when the panel is not a drop-down below the item
  function strip(li) {
    var panel = panelOf(li);
    if (!panel || getComputedStyle(panel).position !== 'absolute') { return null; }
    var a = li.getBoundingClientRect(), p = panel.getBoundingClientRect();
    if (!p.width || !p.height || p.top <= a.bottom + 1) { return null; }
    return { left: Math.min(a.left, p.left), right: Math.max(a.right, p.right), top: a.bottom - 2, bottom: p.top + 2 };
  }

  function inside(r, x, y) {
    return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
  }

  function onMove(e) {
    if (!held) { return; }
    var r = strip(held);
    if (!r || !inside(r, e.clientX, e.clientY)) { release(); }
  }

  function onLeave(e) {
    if (e.pointerType && e.pointerType !== 'mouse') { return; }
    var li = e.currentTarget;
    release();
    li.classList.add(HOLD); // the panel stays shown while it is measured
    var r = strip(li);
    if (r && inside(r, e.clientX, e.clientY)) {
      held = li;
      document.addEventListener('pointermove', onMove, true);
    } else {
      li.classList.remove(HOLD);
    }
  }

  function onEnter(e) {
    if (held && held !== e.currentTarget) { release(); }
    if (held === e.currentTarget) { release(); } // back on the item or in its panel: :hover shows it again
  }

  function bindHold(ul) {
    if (ul.closest('.clickable-dropdown, .vertical-menu')) { return; }
    topItems(ul).forEach(function (li) {
      if (!panelOf(li)) {
        li.addEventListener('pointerenter', onEnter);
        return;
      }
      li.addEventListener('pointerenter', onEnter);
      li.addEventListener('pointerleave', onLeave);
    });
  }

  /* ---------- fit ---------- */

  var STEPS = [1, 0.8, 0.6, 0.45, 0.3];

  function links(ul) {
    return topItems(ul).map(function (li) { return li.querySelector(':scope > a, :scope > span'); }).filter(Boolean);
  }

  // a horizontal menu: no top item takes a whole line of the menu (in hamburger mode and in a vertical
  // menu every item is as wide as the menu, so they stand one under another without wrapping)
  function horizontal(ul, items) {
    if (ul.closest('.vertical-menu')) { return false; }
    var width = ul.clientWidth;
    return width > 0 && items.every(function (li) { return li.getBoundingClientRect().width < width - 1; });
  }

  function wrapped(ul) {
    var items = topItems(ul).filter(function (li) { return li.offsetParent; });
    if (items.length < 2 || !horizontal(ul, items)) { return false; }
    var top = Math.round(items[0].getBoundingClientRect().top);
    return items.some(function (li) { return li.getBoundingClientRect().top - top > 2; });
  }

  // the inline values a link had before MerTools touched it, given back instead of removed
  var PROPS = ['padding-left', 'padding-right', 'letter-spacing'];
  var original = typeof WeakMap === 'function' ? new WeakMap() : null;

  function remember(a) {
    if (!original || original.has(a)) { return; }
    var o = {};
    PROPS.forEach(function (p) { o[p] = [a.style.getPropertyValue(p), a.style.getPropertyPriority(p)]; });
    original.set(a, o);
  }

  function restore(a) {
    var o = original && original.get(a);
    if (!o) { return; }
    PROPS.forEach(function (p) {
      if (o[p][0]) {
        a.style.setProperty(p, o[p][0], o[p][1]);
      } else {
        a.style.removeProperty(p);
      }
    });
  }

  function setStep(ul, base, k) {
    links(ul).forEach(function (a, i) {
      if (k === 1) {
        restore(a);
        return;
      }
      var b = base[i];
      remember(a);
      // the padding goes first, the letter spacing only in the last steps
      a.style.setProperty('padding-left', (b.pl * k).toFixed(1) + 'px', 'important');
      a.style.setProperty('padding-right', (b.pr * k).toFixed(1) + 'px', 'important');
      if (k < 0.6 && b.ls > 0) {
        a.style.setProperty('letter-spacing', (b.ls * (k + 0.4)).toFixed(2) + 'px', 'important');
      }
    });
  }

  function fit(ul) {
    var base;
    setStep(ul, null, 1);
    if (!wrapped(ul)) { return; }
    base = links(ul).map(function (a) {
      var s = getComputedStyle(a);
      return { pl: parseFloat(s.paddingLeft) || 0, pr: parseFloat(s.paddingRight) || 0, ls: parseFloat(s.letterSpacing) || 0 };
    });
    for (var i = 1; i < STEPS.length; i++) {
      setStep(ul, base, STEPS[i]);
      if (!wrapped(ul)) { return; }
    }
    setStep(ul, base, 1);
  }

  function fitAll() {
    menus().forEach(fit);
  }

  function start() {
    if (cfg.hold) { menus().forEach(bindHold); }
    if (cfg.fit) {
      var t = null;
      fitAll();
      window.addEventListener('resize', function () {
        clearTimeout(t);
        t = setTimeout(fitAll, 150);
      });
      if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fitAll); }
      window.addEventListener('load', fitAll);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
