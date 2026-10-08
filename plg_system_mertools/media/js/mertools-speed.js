/**
 * MerTools for Gridbox — page speed panel (MtspeedField). Measures the chosen pages with the
 * PageSpeed Insights API straight from the administrator's browser (a test takes 20–45 s, longer than
 * PHP may run on many hosts), takes the median of the runs and sends the summary to the plugin,
 * which keeps it with the MerTools version and the tools that were on. Shows every page and device
 * with its baseline (the state before the changes), the latest result and the change between them.
 */
(function () {
  'use strict';

  var API = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
  // good / needs improvement limits of Lighthouse and Core Web Vitals
  var LIMITS = { fcp: [1800, 3000], lcp: [2500, 4000], tbt: [200, 600], cls: [0.1, 0.25], si: [3400, 5800], ttfb: [800, 1800], inp: [200, 500] };
  var COLS = ['lcp', 'tbt', 'cls', 'fcp', 'si', 'ttfb'];
  var NAMES = { lcp: 'LCP', tbt: 'TBT', cls: 'CLS', fcp: 'FCP', si: 'Speed Index', ttfb: 'TTFB', inp: 'INP' };
  var FIELD = { LARGEST_CONTENTFUL_PAINT_MS: 'lcp', INTERACTION_TO_NEXT_PAINT: 'inp', CUMULATIVE_LAYOUT_SHIFT_SCORE: 'cls',
    FIRST_CONTENTFUL_PAINT_MS: 'fcp', EXPERIMENTAL_TIME_TO_FIRST_BYTE: 'ttfb' };

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  }

  function rate(metric, v) {
    if (metric === 'score') { return v >= 90 ? 'mt-good' : (v >= 50 ? 'mt-avg' : 'mt-poor'); }
    var l = LIMITS[metric];
    return v <= l[0] ? 'mt-good' : (v <= l[1] ? 'mt-avg' : 'mt-poor');
  }

  function init(box) {
    var cfg = JSON.parse(box.getAttribute('data-mt-speed'));
    var T = cfg.texts;
    var lang = document.documentElement.lang || undefined;
    var table = box.querySelector('.mt-speed-table');
    var fieldBox = box.querySelector('.mt-speed-field');
    var status = box.querySelector('.mt-speed-status');
    var allBtn = box.querySelector('[data-mt-speed-all]');
    var rows = [];
    var open = {};
    var busy = false;

    function fmt(metric, v) {
      if (metric === 'cls') { return Number(v).toLocaleString(lang, { minimumFractionDigits: 3, maximumFractionDigits: 3 }); }
      if (metric === 'score') { return String(v); }
      return v >= 1000 ? (v / 1000).toLocaleString(lang, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' s' : Math.round(v) + ' ms';
    }
    function when(ts) {
      return new Date(ts * 1000).toLocaleString(lang, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
    function formValue(name, fallback) {
      var f = document.querySelector('[name="jform[params][' + name + ']"]');
      return f ? f.value : fallback;
    }
    function pages() {
      var out = [];
      String(formValue('speed_urls', '/')).split(/\r?\n/).forEach(function (line) {
        line = line.trim();
        if (!line || /[\s"<>\\]/.test(line)) { return; }
        var url = null;
        if (line.charAt(0) === '/' && line.charAt(1) !== '/') {
          url = cfg.root.replace(/^(https?:\/\/[^/]+).*$/i, '$1') + line;
        } else if (/^https?:\/\/[^/]+/i.test(line)) {
          url = line;
        }
        if (url && out.indexOf(url) < 0 && out.length < 10) { out.push(url); }
      });
      return out.length ? out : [cfg.root + '/'];
    }

    function post(action, extra) {
      var body = new FormData();
      body.append(cfg.token, '1');
      Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });
      return fetch('index.php?option=com_plugins&mertools_action=' + action, {
        method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.success) { throw new Error((d && d.message) || T.ERROR); }
        rows = d.rows;
        render();
        return d;
      });
    }

    // ------------------------------------------------------------ measuring

    function fieldOf(exp) {
      var out = {};
      if (!exp || !exp.metrics) { return out; }
      Object.keys(FIELD).forEach(function (k) {
        var m = exp.metrics[k];
        if (m && typeof m.percentile === 'number' && m.category) {
          out[FIELD[k]] = [FIELD[k] === 'cls' ? m.percentile / 100 : m.percentile, m.category];
        }
      });
      return out;
    }

    function summary(d, url, strategy) {
      var lr = d.lighthouseResult, a = lr.audits;
      var num = function (id) { return a[id] && typeof a[id].numericValue === 'number' ? a[id].numericValue : 0; };
      var audits = [];
      Object.keys(a).forEach(function (id) {
        var x = a[id];
        if (x.score === null || x.score === undefined || x.score >= 0.9 || !x.metricSavings) { return; }
        var ms = Math.max(x.metricSavings.LCP || 0, x.metricSavings.FCP || 0, x.metricSavings.TBT || 0);
        var kb = x.details && x.details.overallSavingsBytes ? Math.round(x.details.overallSavingsBytes / 1024) : 0;
        if (ms > 0 || kb > 0) { audits.push({ id: id, title: x.title, ms: Math.round(ms), kb: kb }); }
      });
      audits.sort(function (p, q) { return (q.ms - p.ms) || (q.kb - p.kb); });
      var field = {};
      if (d.loadingExperience && !d.loadingExperience.origin_fallback) { field.page = fieldOf(d.loadingExperience); }
      field.origin = fieldOf(d.originLoadingExperience);
      return {
        url: url, strategy: strategy, runs: 1, score: Math.round((lr.categories.performance.score || 0) * 100),
        fcp: num('first-contentful-paint'), lcp: num('largest-contentful-paint'), tbt: num('total-blocking-time'),
        cls: num('cumulative-layout-shift'), si: num('speed-index'), ttfb: num('server-response-time'),
        field: field, audits: audits.slice(0, 8)
      };
    }

    function runOnce(url, strategy, key) {
      var q = new URLSearchParams({ url: url, strategy: strategy, category: 'performance', key: key, locale: (lang || 'en').slice(0, 2) });
      return fetch(API + '?' + q.toString()).then(function (r) {
        return r.json().then(function (d) {
          if (!r.ok || !d.lighthouseResult) { throw new Error((d && d.error && d.error.message) || ('HTTP ' + r.status)); }
          return summary(d, url, strategy);
        });
      });
    }

    function median(list) {
      var s = list.slice().sort(function (a, b) { return a - b; });
      var m = Math.floor(s.length / 2);
      return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
    }

    function combine(runs) {
      var out = { url: runs[0].url, strategy: runs[0].strategy, runs: runs.length };
      ['score', 'fcp', 'lcp', 'tbt', 'cls', 'si', 'ttfb'].forEach(function (k) {
        out[k] = median(runs.map(function (r) { return r[k]; }));
      });
      // the opportunities and real-user figures of the run closest to the median score
      var best = runs.slice().sort(function (p, q) { return Math.abs(p.score - out.score) - Math.abs(q.score - out.score); })[0];
      out.audits = best.audits;
      out.field = runs[runs.length - 1].field;
      return out;
    }

    // at most 6 tests at once
    function pool(tasks, limit, onDone) {
      var i = 0, running = 0, results = new Array(tasks.length);
      return new Promise(function (resolve) {
        function next() {
          if (i >= tasks.length && running === 0) { resolve(results); return; }
          while (running < limit && i < tasks.length) {
            (function (n) {
              running++;
              tasks[n]().then(function (r) { results[n] = r; }, function (e) { results[n] = e; })
                .then(function () { running--; onDone(); next(); });
            })(i++);
          }
        }
        next();
      });
    }

    function measure(targets) {
      var key = String(formValue('speed_key', '')).trim();
      if (!key) { status.textContent = T.NO_KEY; return; }
      if (busy) { return; }
      busy = true;
      allBtn.disabled = true;
      var runs = Math.max(1, Math.min(5, parseInt(formValue('speed_runs', '3'), 10) || 3));
      var tasks = [], owners = [];
      targets.forEach(function (t, ti) {
        for (var r = 0; r < runs; r++) {
          tasks.push(function () { return runOnce(t.url, t.strategy, key); });
          owners.push(ti);
        }
      });
      var done = 0;
      var progress = function () { status.textContent = T.WORKING.replace('%1$s', done).replace('%2$s', tasks.length); };
      progress();
      pool(tasks, 6, function () { done++; progress(); }).then(function (results) {
        var errors = [];
        var chain = Promise.resolve();
        targets.forEach(function (t, ti) {
          var ok = results.filter(function (r, n) { return owners[n] === ti && r && !(r instanceof Error); });
          var bad = results.filter(function (r, n) { return owners[n] === ti && r instanceof Error; });
          bad.forEach(function (e) { errors.push(e.message); });
          if (ok.length) {
            chain = chain.then(function () { return post('speed_save', { data: JSON.stringify(combine(ok)) }); });
          }
        });
        return chain.then(function () {
          status.textContent = errors.length ? T.API_ERROR + ' ' + errors[0] : T.DONE;
        });
      }).catch(function (e) {
        status.textContent = T.FAILED + ' ' + e.message;
      }).then(function () {
        busy = false;
        allBtn.disabled = false;
      });
    }

    // ------------------------------------------------------------ showing

    function scoreBadge(v) {
      return el('span', 'mt-score ' + rate('score', v), String(v));
    }

    // a change smaller than the usual noise between two tests is not shown
    function noise(metric, now, before) {
      var d = Math.abs(now - before);
      if (metric === 'score') { return d < 1; }
      if (metric === 'cls') { return d < 0.01; }
      return d < Math.max(50, Math.abs(before) * 0.05);
    }

    function delta(metric, now, before) {
      if (before === undefined || before === null || noise(metric, now, before)) { return null; }
      var better = metric === 'score' ? now > before : now < before;
      var diff = metric === 'score' ? (now - before > 0 ? '+' : '') + (now - before)
        : (now - before > 0 ? '+' : '−') + fmt(metric, Math.abs(now - before));
      return el('span', 'mt-delta ' + (better ? 'mt-up' : 'mt-down'), (better ? '▲ ' : '▼ ') + diff);
    }

    function renderField() {
      fieldBox.replaceChildren();
      var latest = rows.find(function (r) { return r.field && r.field.origin && Object.keys(r.field.origin).length; });
      var head = el('div', 'fw-bold', T.FIELD_TITLE);
      fieldBox.append(head);
      if (!latest) { fieldBox.append(el('div', 'mt-small', T.FIELD_NONE)); return; }
      var line = el('div');
      line.append(el('span', 'mt-small', T.FIELD_SITE + ': '));
      ['ttfb', 'fcp', 'lcp', 'inp', 'cls'].forEach(function (m) {
        var f = latest.field.origin[m];
        if (!f) { return; }
        var s = el('span', 'me-3');
        s.append(el('span', null, NAMES[m] + ' '));
        s.append(el('strong', f[1] === 'FAST' ? 'mt-good' : (f[1] === 'AVERAGE' ? 'mt-avg' : 'mt-poor'), fmt(m, f[0])));
        line.append(s);
      });
      line.append(el('span', 'mt-small', '(' + when(latest.measured) + ')'));
      fieldBox.append(line, el('div', 'mt-small', T.FIELD_NOTE));
    }

    function render() {
      renderField();
      table.replaceChildren();
      var thead = el('thead'), tr = el('tr');
      [T.PAGE, T.DEVICE, T.BASELINE, T.LATEST].concat(COLS.map(function (c) { return c === 'ttfb' ? T.SERVER : NAMES[c]; })).concat(['']).forEach(function (h) {
        tr.append(el('th', null, h));
      });
      thead.append(tr);
      table.append(thead);
      var tbody = el('tbody');
      var list = pages();
      rows.forEach(function (r) { if (list.indexOf(r.url) < 0) { list.push(r.url); } });
      list.forEach(function (url) {
        ['mobile', 'desktop'].forEach(function (strategy) {
          var mine = rows.filter(function (r) { return r.url === url && r.strategy === strategy; });
          var last = mine[0], base = mine.find(function (r) { return r.baseline; });
          var row = el('tr');
          var own = cfg.root.replace(/^(https?:\/\/[^/]+).*$/i, '$1');
          var cell = el('td', 'mt-url', url.indexOf(own + '/') === 0 ? url.slice(own.length) : url);
          row.append(cell, el('td', null, strategy === 'mobile' ? T.MOBILE : T.DESKTOP));
          var tdBase = el('td'), tdLast = el('td');
          if (base) { tdBase.append(scoreBadge(base.score), el('div', 'mt-small', when(base.measured))); } else { tdBase.textContent = '–'; }
          if (last) {
            tdLast.append(scoreBadge(last.score));
            var d = base && base.id !== last.id ? delta('score', last.score, base.score) : null;
            if (d) { tdLast.append(d); }
            tdLast.append(el('div', 'mt-small', when(last.measured)));
          } else {
            tdLast.append(el('span', 'mt-small', T.NOT_MEASURED));
          }
          row.append(tdBase, tdLast);
          COLS.forEach(function (c) {
            var td = el('td');
            if (last) {
              td.append(el('span', rate(c, last[c]), fmt(c, last[c])));
              var dd = base && base.id !== last.id ? delta(c, last[c], base[c]) : null;
              if (dd) { var w = el('div'); w.append(dd); td.append(w); }
            }
            row.append(td);
          });
          var act = el('td');
          var mBtn = el('button', 'btn btn-sm btn-outline-primary me-1', T.MEASURE);
          mBtn.type = 'button';
          mBtn.addEventListener('click', function () { measure([{ url: url, strategy: strategy }]); });
          act.append(mBtn);
          if (mine.length) {
            var hBtn = el('button', 'btn btn-sm btn-outline-secondary', open[url + strategy] ? T.HIDE : T.HISTORY);
            hBtn.type = 'button';
            hBtn.addEventListener('click', function () { open[url + strategy] = !open[url + strategy]; render(); });
            act.append(hBtn);
          }
          row.append(act);
          tbody.append(row);
          if (open[url + strategy]) { history(tbody, mine); }
        });
      });
      table.append(tbody);
    }

    function history(tbody, mine) {
      if (mine[0] && mine[0].audits && mine[0].audits.length) {
        var tr = el('tr', 'mt-hist'), td = el('td');
        td.colSpan = 4 + COLS.length + 1;
        td.append(el('div', 'fw-bold', T.OPPORTUNITIES));
        var ul = el('ul', 'mb-1');
        mine[0].audits.forEach(function (a) {
          var parts = [];
          if (a.ms) { parts.push(fmt('lcp', a.ms)); }
          if (a.kb) { parts.push(a.kb + ' KiB'); }
          ul.append(el('li', null, a.title + (parts.length ? ' — ' + T.SAVING + ' ' + parts.join(', ') : '')));
        });
        td.append(ul);
        tr.append(td);
        tbody.append(tr);
      }
      mine.forEach(function (r) {
        var tr = el('tr', 'mt-hist');
        var info = el('td', 'mt-url');
        info.colSpan = 2;
        info.append(el('div', null, when(r.measured) + (r.baseline ? ' — ' + T.IS_BASELINE : '')));
        info.append(el('div', 'mt-small', T.VERSION + ' ' + r.version + ', ' + T.RUNS + ' ' + r.runs + '; ' + T.TOOLS + ' '
          + (r.tools.map(function (t) { return T['TOOL_' + t] || t; }).join(', ') || '–')));
        var sc = el('td');
        sc.colSpan = 2;
        sc.append(scoreBadge(r.score));
        tr.append(info, sc);
        COLS.forEach(function (c) { tr.append(el('td', rate(c, r[c]), fmt(c, r[c]))); });
        var act = el('td');
        if (!r.baseline) {
          var b = el('button', 'btn btn-sm btn-link p-0 me-2', T.SET_BASELINE);
          b.type = 'button';
          b.addEventListener('click', function () { post('speed_baseline', { id: r.id }).catch(function (e) { status.textContent = e.message; }); });
          act.append(b);
        }
        var del = el('button', 'btn btn-sm btn-link text-danger p-0', T.DELETE);
        del.type = 'button';
        del.addEventListener('click', function () {
          if (window.confirm(T.CONFIRM_DELETE)) { post('speed_delete', { id: r.id }).catch(function (e) { status.textContent = e.message; }); }
        });
        act.append(del);
        tr.append(act);
        tbody.append(tr);
      });
    }

    allBtn.addEventListener('click', function () {
      var targets = [];
      pages().forEach(function (url) { targets.push({ url: url, strategy: 'mobile' }, { url: url, strategy: 'desktop' }); });
      measure(targets);
    });
    var urlsField = document.querySelector('[name="jform[params][speed_urls]"]');
    if (urlsField) { urlsField.addEventListener('change', render); }

    status.textContent = T.LOADING;
    post('speed_list').then(function () { status.textContent = ''; }).catch(function (e) { status.textContent = e.message; });
  }

  function start() { document.querySelectorAll('[data-mt-speed]').forEach(init); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
