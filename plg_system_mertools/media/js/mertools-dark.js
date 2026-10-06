/**
 * MerTools for Gridbox — dark mode toggle. The theme itself is set very early by a small inline
 * script in the head (no flash); this script only builds the toggle button in the header, switches
 * the theme on click, remembers the choice, and follows the system setting when the visitor has
 * made no choice and the default mode is "auto". The Gridbox core is not touched.
 */
(function () {
  'use strict';

  var opts = (window.Joomla && Joomla.getOptions) ? Joomla.getOptions('plg_system_mertools') : null;
  var cfg = (opts && opts.dark) || {};
  var KEY = cfg.key || 'mertools-theme';

  function current() {
    return document.documentElement.getAttribute('data-mertools-theme') === 'dark' ? 'dark' : 'light';
  }

  function apply(theme) {
    document.documentElement.setAttribute('data-mertools-theme', theme === 'dark' ? 'dark' : 'light');
    update();
  }

  function store(theme) {
    try { localStorage.setItem(KEY, theme); } catch (e) { /* private mode */ }
  }

  function stored() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }

  var btn = null;

  function update() {
    if (!btn) return;
    var dark = current() === 'dark';
    btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    var label = dark ? (cfg.toLight || 'Switch to light mode') : (cfg.toDark || 'Switch to dark mode');
    btn.setAttribute('aria-label', label);
    btn.setAttribute('title', label);
  }

  var SUN = '<svg class="mertools-dt-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4.2"></circle><path d="M12 2.5v2.4M12 19.1v2.4M4.2 4.2l1.7 1.7M18.1 18.1l1.7 1.7M2.5 12h2.4M19.1 12h2.4M4.2 19.8l1.7-1.7M18.1 5.9l1.7-1.7"></path></svg>';
  var MOON = '<svg class="mertools-dt-moon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.5 14.3a8.5 8.5 0 0 1-10.8-10.8 0.7 0.7 0 0 0-0.9-0.9 9.8 9.8 0 1 0 12.6 12.6 0.7 0.7 0 0 0-0.9-0.9z"></path></svg>';

  function build() {
    if (btn || cfg.toggle === false) return;
    btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mertools-dt';
    btn.innerHTML = MOON + SUN;
    btn.addEventListener('click', function () {
      var next = current() === 'dark' ? 'light' : 'dark';
      apply(next);
      store(next);
    });

    var host = null;
    try { host = document.querySelector(cfg.selector || 'header.header'); } catch (e) { host = null; }
    host = host || document.querySelector('header');

    // the Gridbox header lays its items out on a grid, so a bare button floats; the reliable spot is
    // the menu list itself — the button becomes one more item, aligned with the menu links. Query
    // each selector in turn (querySelector with a list returns the first in DOM order, not the
    // order we prefer — the menu wrapper div would win over the actual <ul>).
    var navList = null;
    if (host && cfg.place !== 'float') {
      var sels = ['ul.mod-menu', '.ba-item-main-menu ul', '.ba-menu-wrapper ul', 'nav ul', 'ul.nav'];
      for (var i = 0; i < sels.length && !navList; i++) {
        navList = host.querySelector(sels[i]);
      }
    }

    if (navList) {
      var li = document.createElement('li');
      li.className = 'nav-item mertools-dt-li';
      li.appendChild(btn);
      if (cfg.place === 'start') {
        navList.insertBefore(li, navList.firstChild);
      } else {
        navList.appendChild(li);
      }
    } else if (host && cfg.place !== 'float') {
      host.appendChild(btn);
    } else {
      // no header / no menu: a discreet floating button
      btn.classList.add('mertools-dt-float');
      document.body.appendChild(btn);
    }
    update();
  }

  // follow the system when the visitor made no explicit choice and the default is "auto"
  if (cfg.def === 'auto' && window.matchMedia) {
    try {
      matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        var s = stored();
        if (s !== 'dark' && s !== 'light') {
          apply(e.matches ? 'dark' : 'light');
        }
      });
    } catch (e) { /* older browsers */ }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', build);
  } else {
    build();
  }
})();
