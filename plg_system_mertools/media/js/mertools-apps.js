/**
 * MerTools for Gridbox — apps against deletion, in the Gridbox administrator. The X of a locked app's
 * card (and the trash icon of an app in a folder of apps) shows a lock and, clicked, says how to unlock
 * the app instead of opening Gridbox's question; so does Delete app in the toolbar of a single page app.
 * Gridbox loads its views into the page, so clicks are caught on the document (before Gridbox's own
 * handlers on the body) and new cards are marked as they come. The server refuses the deletion all the
 * same (MerTools::appDeleteGuard); this only spares the question and a card that seems to be gone.
 */
(function () {
  'use strict';

  var node = document.getElementById('mertools-apps-config');
  if (!node) { return; }
  var cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  var unlocked = (cfg.unlocked || []).map(Number);
  var SEL = '.delete-gridbox-app-item[data-id], .remove-group-app[data-id]';
  var TOOLBAR = 'joomla-toolbar-button[task="apps.deleteApp"], [data-task="apps.deleteApp"], [onclick*="apps.deleteApp"]';

  function locked(id) {
    id = parseInt(id, 10);
    return id > 0 && unlocked.indexOf(id) === -1;
  }

  /** The app of a single page app's view (Gridbox changes the address as it loads its views). */
  function current() {
    var p = new URLSearchParams(window.location.search);
    return p.get('view') === 'single' ? parseInt(p.get('id'), 10) || cfg.current || 0 : 0;
  }

  function say(id) {
    var text = cfg.texts.refused.replace('%s', (cfg.titles && cfg.titles[id]) || '#' + id);
    if (window.app && typeof window.app.showNotice === 'function') {
      window.app.showNotice(text, 'ba-alert');
    } else {
      window.alert(text);
    }
  }

  function mark() {
    document.querySelectorAll(SEL).forEach(function (b) {
      if (b.dataset.mtLocked || !locked(b.dataset.id)) { return; }
      b.dataset.mtLocked = '1';
      b.classList.add('mt-app-locked');
      var icon = b.matches('i') ? b : b.querySelector('i');
      if (icon) {
        icon.classList.remove('zmdi-close', 'zmdi-delete');
        icon.classList.add('zmdi-lock');
      }
      var tip = b.querySelector('.ba-tooltip');
      if (tip) {
        tip.textContent = cfg.texts.tip;
      } else {
        b.setAttribute('title', cfg.texts.tip);
      }
      b.setAttribute('aria-label', cfg.texts.tip);
    });
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) { return; }
    var b = e.target.closest(SEL);
    if (b && locked(b.dataset.id)) {
      e.preventDefault();
      e.stopImmediatePropagation();
      say(parseInt(b.dataset.id, 10));
      return;
    }
    var id = current();
    if (id && locked(id) && e.target.closest(TOOLBAR)) {
      e.preventDefault();
      e.stopImmediatePropagation();
      say(id);
    }
  }, true);

  /** Delete app reached another way than a click (a keyboard shortcut of the toolbar). */
  function wrap() {
    var J = window.Joomla;
    if (!J || typeof J.submitbutton !== 'function' || J.submitbutton.mtApps) { return; }
    var original = J.submitbutton;
    J.submitbutton = function (task) {
      var id = current();
      if (task === 'apps.deleteApp' && id && locked(id)) {
        say(id);
        return false;
      }
      return original.apply(this, arguments);
    };
    J.submitbutton.mtApps = true;
  }

  var style = document.createElement('style');
  style.textContent = '.mt-app-locked,.mt-app-locked i{cursor:not-allowed!important}.mt-app-locked i.zmdi-lock{opacity:.6}';
  document.head.append(style);

  var queued = false;
  new MutationObserver(function () {
    if (queued) { return; }
    queued = true;
    window.requestAnimationFrame(function () { queued = false; mark(); wrap(); });
  }).observe(document.documentElement, { childList: true, subtree: true });

  function start() { mark(); wrap(); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
  window.addEventListener('load', start);
})();
