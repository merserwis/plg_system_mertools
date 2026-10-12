/**
 * MerTools for Gridbox — the lighter copies of the site's pictures in the plugin settings
 * (MtimagesField): how many pictures have copies, how many wait, the space the copies take, and the
 * buttons that make the waiting ones now or remove all copies. Requests go to the plugin's
 * mertools_action handler with the form token.
 */
(function () {
  'use strict';

  function init(box) {
    var cfg = JSON.parse(box.getAttribute('data-mt-images'));
    var T = cfg.texts;
    var figures = box.querySelector('.mt-images-figures');
    var result = box.querySelector('.mt-images-result');
    var run = box.querySelector('[data-mt-images-run]');
    var clear = box.querySelector('[data-mt-images-clear]');
    var lang = document.documentElement.lang || undefined;

    function num(n) { return Number(n || 0).toLocaleString(lang); }
    function mb(bytes) { return (Number(bytes || 0) / 1048576).toLocaleString(lang, { maximumFractionDigits: 1, minimumFractionDigits: 1 }) + ' MB'; }
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
      row(T.READY, num(s.ok));
      row(T.PENDING, num(s.pending));
      row(T.SKIPPED, num(s.skipped));
      if (s.failed) { row(T.FAILED, num(s.failed)); }
      row(T.FILES, num(s.files) + ' · ' + mb(s.bytes));
      run.disabled = !s.supported;
      if (!s.supported) { message('warning', T.NO_GD); }
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

    run.addEventListener('click', function () {
      run.disabled = true;
      result.textContent = T.LOADING;
      post('img_run').then(function (d) {
        message('success', d.stats.pending ? T.RUN_MORE.replace('%s', num(d.stats.pending)) : T.RUN_DONE);
      }, function (e) { message('danger', e.message); }).then(function () { run.disabled = false; });
    });
    clear.addEventListener('click', function () {
      if (!window.confirm(T.CLEAR_CONFIRM)) { return; }
      clear.disabled = true;
      post('img_clear').then(function () { message('success', T.CLEARED); }, function (e) { message('danger', e.message); })
        .then(function () { clear.disabled = false; });
    });
    result.textContent = T.LOADING;
    post('img_stats').then(function () { if (result.textContent === T.LOADING) { result.textContent = ''; } }, function (e) { message('danger', e.message); });
  }

  function start() { document.querySelectorAll('[data-mt-images]').forEach(init); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
