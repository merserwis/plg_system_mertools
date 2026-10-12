/**
 * MerTools for Gridbox — recommended settings (MtchecksField): Cloudflare's Rocket Loader is seen
 * only in the page a guest gets, so the site's home page is fetched here without cookies and its
 * scripts are looked at; "Check again" reloads the settings page.
 */
(function () {
  'use strict';

  var ICONS = { ok: 'icon-check text-success', bad: 'icon-times text-danger', info: 'icon-info-circle text-info' };

  function init(box) {
    var cfg = JSON.parse(box.getAttribute('data-mt-checks'));
    var T = cfg.texts;
    var row = box.querySelector('[data-mt-check="CF_ROCKET"]');
    var reload = box.querySelector('[data-mt-checks-reload]');
    if (reload) {
      reload.addEventListener('click', function () { window.location.reload(); });
    }
    if (!row) { return; }

    function show(state, text) {
      var icon = row.querySelector('td span[aria-hidden]');
      icon.className = ICONS[state];
      row.querySelector('td .visually-hidden').textContent = T['S_' + state.toUpperCase()];
      row.querySelector('.mt-check-now').textContent = text;
    }

    fetch(cfg.root + (cfg.root.indexOf('?') < 0 ? '?' : '&') + 'mtcheck=' + Date.now(), { credentials: 'omit', cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        // Rocket Loader adds its script and rewrites the type of the page's scripts ("<hash>-text/javascript")
        var on = /rocket-loader\.min\.js/.test(html) || /type="[0-9a-f]{20,}-text\/javascript"/.test(html);
        show(on ? 'bad' : 'ok', on ? T.V_ON : T.V_OFF);
      })
      .catch(function () { show('info', T.V_UNKNOWN); });
  }

  function start() { document.querySelectorAll('[data-mt-checks]').forEach(init); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
