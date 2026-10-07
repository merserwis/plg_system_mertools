/**
 * MerTools for Gridbox — dark mode. The theme itself is set very early by a small inline script in
 * the head (no flash); this script
 *  - builds the toggle button and keeps it where a visitor can see it (the menu on desktop, beside
 *    the hamburger on phones, or floating in the visible part of the screen),
 *  - switches the theme on click, remembers the choice and follows the system when "auto",
 *  - adapts the colours Gridbox writes into its element styles (they do not use the theme
 *    variables): light backgrounds are darkened and text too dark for its background is lightened
 *    just enough to be readable, keeping its hue. The fixes are data attributes that only the dark
 *    theme CSS uses, so the light theme is untouched. The Gridbox core is not touched.
 */
(function () {
  'use strict';

  var opts = (window.Joomla && Joomla.getOptions) ? Joomla.getOptions('plg_system_mertools') : null;
  if (!opts) {
    var el = document.querySelector('script.joomla-script-options');
    try { opts = el ? JSON.parse(el.textContent).plg_system_mertools : null; } catch (e) { opts = null; }
  }
  var cfg = (opts && opts.dark) || {};
  var KEY = cfg.key || 'mertools-theme';
  var root = document.documentElement;

  function current() {
    return root.getAttribute('data-mertools-theme') === 'dark' ? 'dark' : 'light';
  }

  function store(theme) {
    try { localStorage.setItem(KEY, theme); } catch (e) { /* private mode */ }
  }

  function stored() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }

  // ------------------------------------------------------------------ colour adaptation

  var SKIP = { SCRIPT: 1, STYLE: 1, LINK: 1, META: 1, NOSCRIPT: 1, BR: 1, IMG: 1, PICTURE: 1, SOURCE: 1, VIDEO: 1, AUDIO: 1,
    CANVAS: 1, IFRAME: 1, SVG: 1, PATH: 1, OBJECT: 1, EMBED: 1, TEMPLATE: 1 };
  var FORM = { INPUT: 1, TEXTAREA: 1, SELECT: 1 };
  var pal = null;

  function parse(c) {
    var m = /rgba?\(([^)]+)\)/.exec(c || '');
    if (!m) return null;
    var p = m[1].split(/[\s,\/]+/).filter(Boolean).map(parseFloat);
    return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1];
  }

  function cssColor(value) {
    if (!value) return null;
    var probe = document.createElement('i');
    probe.style.color = value;
    probe.style.display = 'none';
    document.body.appendChild(probe);
    var c = parse(getComputedStyle(probe).color);
    probe.remove();
    return c;
  }

  function lum(c) {
    var a = [c[0], c[1], c[2]].map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
  }

  function contrast(a, b) {
    var x = lum(a), y = lum(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
  }

  function mix(a, b, t) {
    return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t, a.length > 3 ? a[3] : 1];
  }

  function over(fg, bg) {
    return fg[3] >= 1 ? fg : mix(bg, fg, fg[3]).slice(0, 3).concat([1]);
  }

  function sat(c) {
    var mx = Math.max(c[0], c[1], c[2]) / 255, mn = Math.min(c[0], c[1], c[2]) / 255, l = (mx + mn) / 2;
    return mx === mn ? 0 : (mx - mn) / (1 - Math.abs(2 * l - 1));
  }

  function rgb(c) {
    var s = Math.round(c[0]) + ',' + Math.round(c[1]) + ',' + Math.round(c[2]);
    return c.length > 3 && c[3] < 1 ? 'rgba(' + s + ',' + (+c[3].toFixed(3)) + ')' : 'rgb(' + s + ')';
  }

  function palette() {
    if (pal) return pal;
    var p = cfg.pal || {};
    var accent = cfg.accent || getComputedStyle(document.body).getPropertyValue('--primary').trim();
    pal = {
      bg: cssColor(p.bg) || [27, 34, 48, 1],
      surface: cssColor(p.surface) || [35, 44, 61, 1],
      dark: cssColor(p.bg_dark) || [16, 21, 31, 1],
      title: cssColor(p.title) || [232, 236, 243, 1],
      accent: cssColor(accent)
    };
    // the colours dark mode itself gives to text (the theme variables)
    pal.own = [p.title, p.text, p.muted, p.bg, p.surface, p.bg_dark].map(cssColor).filter(Boolean);
    return pal;
  }

  function ownColour(c) {
    return palette().own.some(function (o) {
      return Math.abs(o[0] - c[0]) + Math.abs(o[1] - c[1]) + Math.abs(o[2] - c[2]) < 6 && Math.abs((o[3] || 1) - (c[3] || 1)) < 0.05;
    });
  }

  /** The colour behind an element (after the fixes), or null when it is an image. */
  function backdrop(el) {
    var layers = [];
    for (var e = el; e && e.nodeType === 1; e = e.parentElement) {
      var cs = getComputedStyle(e);
      if (cs.backgroundImage !== 'none' && !/gradient/.test(cs.backgroundImage)) return null;
      var c = parse(cs.backgroundColor);
      if (c && c[3] > 0) {
        layers.push(c);
        if (c[3] >= 1) break;
      }
    }
    var b = palette().bg;
    for (var i = layers.length - 1; i >= 0; i--) b = over(layers[i], b);
    return b;
  }

  function ownText(el) {
    if (FORM[el.tagName]) return true;
    for (var n = el.firstChild; n; n = n.nextSibling) {
      if (n.nodeType === 3 && n.nodeValue.trim() !== '') return true;
    }
    // font icons (an <i> or <span> with an icon class and no text)
    return (el.tagName === 'I' || el.tagName === 'SPAN') && !el.firstElementChild && /icon|zmdi|fa-|flaticon|ba-icon/.test(el.className || '');
  }

  function fixBackground(el, cs) {
    if (cs.backgroundImage !== 'none' && !/gradient/.test(cs.backgroundImage)) return;
    var c = parse(cs.backgroundColor);
    if (!c || c[3] < 0.35) return;
    var l = lum(c);
    // dark and mid colours stay; vivid brand colours (buttons, badges) stay
    if (l < 0.5 || (sat(c) > 0.55 && l < 0.8)) return;
    var p = palette(), wide = el.getBoundingClientRect().width >= window.innerWidth * 0.9;
    var nb = mix(c, wide ? p.bg : p.surface, wide ? 0.96 : 0.9);
    nb[3] = c[3];
    el.style.setProperty('--mt-bg', rgb(nb));
    el.setAttribute('data-mt-bg', '');
  }

  function fixText(el, cs) {
    var fg = parse(cs.color);
    if (!fg) return;
    var bg = backdrop(el);
    if (!bg) return;
    var size = parseFloat(cs.fontSize) || 16;
    var need = (size >= 24 || (size >= 18.6 && parseInt(cs.fontWeight, 10) >= 700)) ? 3 : 4.5;
    var shown = over(fg, bg), now = contrast(shown, bg);
    if (now >= need) return;
    // text on a vivid brand colour (buttons, badges) keeps the site's design — unless its colour
    // comes from the dark palette (a theme variable), i.e. dark mode itself changed it
    var lb = lum(bg);
    if (sat(bg) > 0.5 && lb > 0.06 && lb < 0.6 && !ownColour(fg)) return;
    // lighten or darken, whichever reaches a comfortable contrast with the smallest change of colour
    // (a fixed text always aims at 4.5:1, also when the large-text minimum of 3:1 would pass)
    need = 4.5;
    var p = palette(), fix = null, step = 1, best = null, bestC = now;
    [p.title, p.dark].forEach(function (towards) {
      for (var t = 0.15; t <= 1.0001; t += 0.15) {
        var c = mix(shown, towards, Math.min(t, 1)), cr = contrast(c, bg);
        if (cr >= need) {
          if (t < step) { step = t; fix = c; }
          return;
        }
        if (cr > bestC) { bestC = cr; best = c; }
      }
    });
    fix = fix || (bestC >= now + 0.5 ? best : null);
    if (!fix) return;
    el.style.setProperty('--mt-fg', rgb(fix));
    if (el.closest('a') && p.accent && contrast(p.accent, bg) >= 3) {
      el.style.setProperty('--mt-fg-h', rgb(p.accent));
    }
    el.setAttribute('data-mt-fg', '');
  }

  function adapt(scope) {
    if (cfg.adaptive === false || current() !== 'dark' || !document.body) return;
    root.classList.add('mertools-dt-calc');
    var list = scope === document.body ? document.body.querySelectorAll('*') : [scope].concat([].slice.call(scope.querySelectorAll('*')));
    var i, el;
    // backgrounds first: the text check reads the fixed backgrounds
    for (i = 0; i < list.length; i++) {
      el = list[i];
      if (SKIP[el.tagName.toUpperCase()] || el.hasAttribute('data-mt-bg') || el.closest('.mertools-dt')) continue;
      fixBackground(el, getComputedStyle(el));
    }
    for (i = 0; i < list.length; i++) {
      el = list[i];
      if (SKIP[el.tagName.toUpperCase()] || el.hasAttribute('data-mt-fg') || el.closest('.mertools-dt') || !ownText(el)) continue;
      fixText(el, getComputedStyle(el));
    }
    void root.offsetWidth;
    root.classList.remove('mertools-dt-calc');
  }

  var done = false, pending = [], timer = 0;

  function adaptAll() {
    if (done || current() !== 'dark') return;
    done = true;
    adapt(document.body);
  }

  function watch() {
    if (cfg.adaptive === false || !window.MutationObserver) return;
    new MutationObserver(function (records) {
      if (!done) return;
      records.forEach(function (r) {
        r.addedNodes.forEach(function (n) { if (n.nodeType === 1 && !n.classList.contains('mertools-dt')) pending.push(n); });
      });
      if (pending.length && !timer) {
        timer = setTimeout(function () {
          var nodes = pending; pending = []; timer = 0;
          nodes.forEach(function (n) { if (n.isConnected) adapt(n); });
        }, 200);
      }
    }).observe(document.body, { childList: true, subtree: true });
  }

  function apply(theme) {
    root.setAttribute('data-mertools-theme', theme === 'dark' ? 'dark' : 'light');
    if (theme === 'dark') adaptAll();
    update();
  }

  // ------------------------------------------------------------------ toggle button

  var btn = null;

  /** In the menu the button takes the colour of the menu links (read again after a theme switch). */
  function matchMenu() {
    if (!btn || !li || !li.parentNode) return;
    var a = li.parentNode.querySelector('li:not(.mertools-dt-li) > a, li:not(.mertools-dt-li) a');
    if (a) btn.style.setProperty('--mt-dt-color', getComputedStyle(a).color);
  }

  function update() {
    if (!btn) return;
    btn.style.removeProperty('--mt-dt-color');
    matchMenu();
    var dark = current() === 'dark';
    btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    var label = dark ? (cfg.toLight || 'Switch to light mode') : (cfg.toDark || 'Switch to dark mode');
    btn.setAttribute('aria-label', label);
    btn.setAttribute('title', label);
  }

  var SUN = '<svg class="mertools-dt-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4.2"></circle><path d="M12 2.5v2.4M12 19.1v2.4M4.2 4.2l1.7 1.7M18.1 18.1l1.7 1.7M2.5 12h2.4M19.1 12h2.4M4.2 19.8l1.7-1.7M18.1 5.9l1.7-1.7"></path></svg>';
  var MOON = '<svg class="mertools-dt-moon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.5 14.3a8.5 8.5 0 0 1-10.8-10.8 0.7 0.7 0 0 0-0.9-0.9 9.8 9.8 0 1 0 12.6 12.6 0.7 0.7 0 0 0-0.9-0.9z"></path></svg>';

  /** Is the element really on screen (inside the visible part of the page, not hidden)? */
  function onScreen(el) {
    if (!el || !el.isConnected) return false;
    var r = el.getBoundingClientRect();
    if (r.width < 4 || r.height < 4) return false;
    var vv = window.visualViewport, vw = vv ? vv.width : window.innerWidth, left = vv ? vv.offsetLeft : 0;
    if (r.right > left + vw + 1 || r.left < left - 1) return false;
    for (var e = el; e && e !== document.body; e = e.parentElement) {
      var cs = getComputedStyle(e);
      if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) < 0.05) return false;
    }
    return true;
  }

  function header() {
    var host = null;
    try { host = document.querySelector(cfg.selector || 'header.header'); } catch (e) { host = null; }
    return host || document.querySelector('header');
  }

  function navList(host) {
    // the Gridbox header lays its items out on a grid, so a bare button floats; the reliable spot is
    // the menu list itself. Query each selector in turn (a selector list returns the first in DOM
    // order, which would be the menu wrapper, not the <ul>).
    var sels = ['ul.mod-menu', '.ba-item-main-menu ul', '.ba-menu-wrapper ul', 'nav ul', 'ul.nav'];
    for (var i = 0; i < sels.length; i++) {
      var ul = host.querySelector(sels[i]);
      if (ul) return ul;
    }
    return null;
  }

  function hamburger(host) {
    var sels = ['.open-menu', '.ba-hamburger-menu .open-menu', '[class*="hamburger"] > i', '.navbar-toggler'];
    for (var i = 0; i < sels.length; i++) {
      var h = host.querySelector(sels[i]);
      if (h && onScreen(h)) return h;
    }
    return null;
  }

  var li = null;

  function detach() {
    btn.classList.remove('mertools-dt-float', 'mertools-dt-burger');
    ['right', 'bottom', 'top', 'left', 'position'].forEach(function (k) { btn.style.removeProperty(k); });
    if (li && li.parentNode) li.parentNode.removeChild(li);
    if (btn.parentNode) btn.parentNode.removeChild(btn);
  }

  function placeFloat() {
    detach();
    btn.classList.add('mertools-dt-float');
    document.body.appendChild(btn);
    keepFloatVisible();
  }

  /** A fixed element is placed against the layout viewport; on a phone whose page is wider than the
   *  screen that is partly off-screen, so the offsets follow the visible part (visualViewport). */
  function keepFloatVisible() {
    if (!btn || !btn.classList.contains('mertools-dt-float')) return;
    var vv = window.visualViewport;
    if (!vv) return;
    var right = window.innerWidth - (vv.offsetLeft + vv.width) + 18;
    var bottom = window.innerHeight - (vv.offsetTop + vv.height) + 18;
    btn.style.setProperty('right', Math.max(right, 8) + 'px');
    btn.style.setProperty('bottom', Math.max(bottom, 8) + 'px');
  }

  function place() {
    if (!btn) return;
    var host = header();
    if (cfg.place === 'float' || !host) {
      placeFloat();
      return;
    }
    // 1. in the menu (desktop)
    var ul = navList(host);
    if (ul) {
      detach();
      li = li || document.createElement('li');
      li.className = 'nav-item mertools-dt-li';
      li.appendChild(btn);
      if (cfg.place === 'start') ul.insertBefore(li, ul.firstChild); else ul.appendChild(li);
      if (onScreen(btn)) { matchMenu(); return; }
    }
    // 2. beside the hamburger (phones: the menu is closed, off-screen)
    var burger = hamburger(host);
    if (burger) {
      detach();
      btn.classList.add('mertools-dt-burger');
      burger.parentNode.insertBefore(btn, burger);
      // just left of the hamburger, centred on it: measured on screen, so it works whatever the
      // containing block of the header column is
      btn.style.position = 'absolute';
      btn.style.left = '0px';
      btn.style.top = '0px';
      // the icon itself: the hamburger wrapper is often wider than its icon
      var icon = burger.querySelector('i, svg, img, span') || burger;
      var r0 = btn.getBoundingClientRect(), rb = icon.getBoundingClientRect();
      if (rb.width < 4) rb = burger.getBoundingClientRect();
      btn.style.left = Math.round(rb.left - r0.width - 12 - r0.left) + 'px';
      btn.style.top = Math.round(rb.top + (rb.height - r0.height) / 2 - r0.top) + 'px';
      if (onScreen(btn)) return;
    }
    // 3. floating in the visible part of the screen
    placeFloat();
  }

  function build() {
    if (btn || cfg.toggle === false) return;
    btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mertools-dt';
    btn.innerHTML = MOON + SUN;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var next = current() === 'dark' ? 'light' : 'dark';
      apply(next);
      store(next);
    });
    place();
    update();

    var t = 0;
    function replace() {
      clearTimeout(t);
      t = setTimeout(place, 250);
    }
    window.addEventListener('resize', replace);
    window.addEventListener('orientationchange', replace);
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', keepFloatVisible);
      window.visualViewport.addEventListener('scroll', keepFloatVisible);
    }
    // Gridbox lays the header out after its own scripts run; check the spot once more
    window.addEventListener('load', function () { setTimeout(place, 300); });
  }

  // follow the system when the visitor made no explicit choice and the default is "auto"
  if (cfg.def === 'auto' && window.matchMedia) {
    try {
      matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        var s = stored();
        if (s !== 'dark' && s !== 'light') apply(e.matches ? 'dark' : 'light');
      });
    } catch (e) { /* older browsers */ }
  }

  function start() {
    build();
    adaptAll();
    update();
    watch();
    // late styles (lazy sections, fonts) — one more pass over what appeared meanwhile
    window.addEventListener('load', function () {
      if (current() === 'dark') setTimeout(function () { adapt(document.body); }, 400);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
