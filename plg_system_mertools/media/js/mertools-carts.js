/**
 * MerTools for Gridbox — the cart clean-up panel in the plugin settings (MtcartsField): figures,
 * "Check" (what would be removed now) and "Clean now". Requests go to the plugin's
 * mertools_action handler with the form token; the days and the empty-cart choice are taken from
 * the form as it is on screen, also before saving.
 */
(function () {
  'use strict';

  function init(box) {
    var cfg = JSON.parse(box.getAttribute('data-mt-carts'));
    var T = cfg.texts;
    var figures = box.querySelector('.mt-carts-figures');
    var result = box.querySelector('.mt-carts-result');
    var buttons = box.querySelectorAll('[data-mt-cart]');
    var lang = document.documentElement.lang || undefined;

    function num(n) { return Number(n || 0).toLocaleString(lang); }
    function mb(bytes) { return (Number(bytes || 0) / 1048576).toLocaleString(lang, { maximumFractionDigits: 1, minimumFractionDigits: 1 }) + ' MB'; }
    function when(ts) {
      return new Date(ts * 1000).toLocaleString(lang, { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
    function fill(text, values) {
      return String(text).replace(/%(\d)\$s/g, function (m, i) { return values[i - 1]; }).replace('%s', values[0]);
    }
    function field(name, fallback) {
      var el = document.querySelector('[name="jform[params][' + name + ']"]:checked') || document.querySelector('select[name="jform[params][' + name + ']"], input[type="number"][name="jform[params][' + name + ']"], input[type="text"][name="jform[params][' + name + ']"]');
      return el ? el.value : fallback;
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

    function show(stats) {
      figures.replaceChildren();
      row(T.CARTS, num(stats.carts));
      row(T.WITH_PRODUCTS, num(stats.withProducts));
      row(T.EMPTY, num(stats.carts - stats.withProducts));
      row(T.PRODUCTS, num(stats.products));
      row(T.SIZE, mb(stats.size));
      row(T.WATCH_SINCE, stats.trackSince ? when(stats.trackSince) : T.WATCH_NONE);
      var now = Date.now() / 1000;
      row(T.ABANDONED_FROM, stats.abandonedFrom ? (stats.abandonedFrom <= now ? T.ABANDONED_NOW : when(stats.abandonedFrom)) : T.LATER);
      var last = stats.last;
      row(T.LAST, last ? fill(T[last.by === 'auto' ? 'BY_AUTO' : 'BY_MANUAL'], [when(last.at), num(last.empty), num(last.abandoned), num(last.products)])
        + (last.done ? '' : ' ' + T.NOT_FINISHED) : T.LAST_NONE);
    }

    function message(kind, text) {
      var div = document.createElement('div');
      div.className = 'alert alert-' + kind + ' mb-0';
      div.textContent = text;
      result.replaceChildren(div);
    }

    function call(action) {
      var body = new FormData();
      body.append(cfg.token, '1');
      body.append('days', field('cart_days', '30'));
      body.append('empty', field('cart_empty', '1'));
      return fetch('index.php?option=com_plugins&mertools_action=' + action, {
        method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); });
    }

    function busy(on) {
      buttons.forEach(function (b) { b.disabled = on; });
    }

    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var action = btn.getAttribute('data-mt-cart');
        if (action === 'cart_clean' && !window.confirm(T.CONFIRM)) {
          return;
        }
        busy(true);
        message('info', T.WORKING);
        call(action).then(function (data) {
          busy(false);
          if (!data || !data.success) {
            message('danger', T.ERROR + ' ' + ((data && data.message) || ''));
            return;
          }
          var r = data.result;
          if (r.busy) {
            message('warning', T.BUSY);
          } else if (!r.empty && !r.abandoned && !r.products) {
            message('success', T.NOTHING + (r.attached ? ' ' + fill(T.ATTACHED, [num(r.attached)]) : '')
              + (r.sizeBefore !== undefined ? ' ' + fill(T.OPTIMIZED, [mb(r.sizeBefore), mb(r.sizeAfter)]) : ''));
          } else {
            var text = fill(action === 'cart_check' ? T.WOULD : T.DID, [num(r.empty), num(r.abandoned), num(r.products)]);
            if (r.attached) {
              text += ' ' + fill(T.ATTACHED, [num(r.attached)]);
            }
            if (action === 'cart_clean' && !r.done) {
              text += ' ' + T.NOT_FINISHED;
            }
            if (r.sizeBefore !== undefined) {
              text += ' ' + fill(T.OPTIMIZED, [mb(r.sizeBefore), mb(r.sizeAfter)]);
            }
            message(action === 'cart_check' ? 'info' : 'success', text);
          }
          if (data.stats) {
            show(data.stats);
          }
        }).catch(function () {
          busy(false);
          message('danger', T.ERROR);
        });
      });
    });

    figures.textContent = T.LOADING;
    call('cart_stats').then(function (data) {
      if (data && data.success) {
        show(data.stats);
      } else {
        figures.textContent = (data && data.message) || T.ERROR;
        busy(true);
      }
    }).catch(function () { figures.textContent = T.ERROR; });
  }

  function start() {
    document.querySelectorAll('[data-mt-carts]').forEach(init);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
