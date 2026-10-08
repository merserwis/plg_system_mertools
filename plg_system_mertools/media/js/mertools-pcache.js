/**
 * MerTools for Gridbox — the page cache panel in the plugin settings (MtpagecacheField): pages and
 * Gridbox requests kept now, their size, and the button that empties the cache. Requests go to the
 * plugin's mertools_action handler with the form token.
 */
(function () {
  'use strict';

  function init(box) {
    var cfg = JSON.parse(box.getAttribute('data-mt-pcache'));
    var T = cfg.texts;
    var figures = box.querySelector('.mt-pcache-figures');
    var result = box.querySelector('.mt-pcache-result');
    var button = box.querySelector('[data-mt-pcache-clear]');
    var lang = document.documentElement.lang || undefined;

    function num(n) { return Number(n || 0).toLocaleString(lang); }
    function mb(bytes) { return (Number(bytes || 0) / 1048576).toLocaleString(lang, { maximumFractionDigits: 1, minimumFractionDigits: 1 }) + ' MB'; }
    function when(ts) {
      return ts ? new Date(ts * 1000).toLocaleString(lang, { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : T.NEVER;
    }
    function row(label, value) {
      var dt = document.createElement('dt');
      dt.className = 'col-sm-5 fw-normal';
      dt.textContent = label;
      var dd = document.createElement('dd');
      dd.className = 'col-sm-7 mb-1';
      dd.textContent = value;
      figures.append(dt, dd);
    }
    function show(s) {
      figures.replaceChildren();
      row(T.PAGES, num(s.pages));
      row(T.DATA, num(s.data));
      row(T.SIZE, mb(s.size));
      row(T.OLDEST, s.oldest ? when(s.oldest) : '–');
      row(T.PURGED, when(s.purged));
    }
    function message(kind, text) {
      var div = document.createElement('div');
      div.className = 'alert alert-' + kind + ' mb-0';
      div.textContent = text;
      result.replaceChildren(div);
    }
    function post(action) {
      var body = new FormData();
      body.append(cfg.token, '1');
      return fetch('index.php?option=com_plugins&mertools_action=' + action, {
        method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.success) { throw new Error((d && d.message) || T.ERROR); }
        show(d.stats);
        return d;
      });
    }

    button.addEventListener('click', function () {
      button.disabled = true;
      post('pcache_clear').then(function () { message('success', T.CLEARED); }, function (e) { message('danger', e.message); })
        .then(function () { button.disabled = false; });
    });
    result.textContent = T.LOADING;
    post('pcache_stats').then(function () { result.textContent = ''; }, function (e) { message('danger', e.message); });
  }

  function start() { document.querySelectorAll('[data-mt-pcache]').forEach(init); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
