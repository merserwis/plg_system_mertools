/**
 * MerTools — edit lock, in the Gridbox editor that holds the lock of its page: confirms the lock every
 * 30 s (with "something was done since the last time"), gives it back when the editor is closed, and
 * shows a bar when the lock was taken over by somebody else (their saves win: ours are refused) or
 * when the editor was idle so long that the lock went free.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-lock-config');
  if (!node) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }

  let active = true;
  let lost = false;
  let idle = false;
  let last = Date.now();
  let bar = null;

  const form = (action, extra) => {
    const fd = new FormData();
    fd.append('mertools_lock', action);
    fd.append('key', cfg.key);
    fd.append('page', String(cfg.page));
    fd.append('tab', cfg.tab);
    fd.append(cfg.token, '1');
    Object.entries(extra || {}).forEach(([k, v]) => fd.append(k, String(v)));
    return fd;
  };

  const show = (text, kind) => {
    if (!bar) {
      bar = document.createElement('div');
      bar.setAttribute('role', 'alert');
      bar.style.cssText = 'position:fixed;left:0;right:0;top:0;z-index:2147483000;padding:10px 16px;font:600 14px/1.4 -apple-system,Segoe UI,Roboto,Arial,sans-serif;'
        + 'text-align:center;box-shadow:0 2px 10px rgba(0,0,0,.25)';
      document.body.append(bar);
    }
    bar.textContent = text;
    bar.style.background = kind === 'lost' ? '#b91c1c' : '#b45309';
    bar.style.color = '#fff';
  };
  const hide = () => { if (bar) { bar.remove(); bar = null; } };

  const ping = async () => {
    if (lost) return;
    const was = active;
    active = false;
    try {
      const res = await fetch(cfg.url + '?mertools_lock=ping', { method: 'POST', body: form('ping', { active: was ? 1 : 0 }), credentials: 'same-origin' });
      const data = await res.json();
      if (data.lost) {
        lost = true;
        show(data.message, 'lost');
        return;
      }
      if (data.ok && idle && was) {
        idle = false;
        hide();
      }
    } catch (e) {
      // a lost connection: the next confirmation tries again
    }
    if (cfg.idle > 0 && !idle && Date.now() - last > cfg.idle * 1000) {
      idle = true;
      show(cfg.texts.idle, 'idle');
    }
  };

  const mark = () => {
    active = true;
    last = Date.now();
    if (idle && !lost) ping();
  };
  const watch = (doc) => ['mousedown', 'keydown', 'wheel', 'touchstart', 'input'].forEach((type) => doc.addEventListener(type, mark, { passive: true, capture: true }));
  watch(document);
  // the page canvas and the panels of the editor are frames of the same site
  const frames = () => document.querySelectorAll('iframe').forEach((frame) => {
    try {
      const doc = frame.contentDocument;
      if (doc && doc.__mtLock !== true) {
        doc.__mtLock = true;
        watch(doc);
      }
    } catch (e) {
      // another site's frame (a video): not ours to watch
    }
  });
  frames();
  setInterval(frames, 5000);
  setInterval(ping, Math.max(10, cfg.ping) * 1000);

  window.addEventListener('pagehide', () => {
    if (!lost && navigator.sendBeacon) navigator.sendBeacon(cfg.url + '?mertools_lock=release', form('release'));
  });
})();
