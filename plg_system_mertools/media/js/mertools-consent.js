/**
 * MerTools for Gridbox — consent to cookies.
 *
 * The visitor's choice (window.mertoolsConsent, read by the first script of the head from the cookie)
 * decides: none yet → the window asks; levels allowed → the scripts waiting for them run (text/plain
 * with data-mertools-consent="<level>", or TermsFeed's cookie-consent="<level>"), in the order of the
 * page. A choice is kept in a cookie of the site and told to Google's Consent Mode (gtag consent
 * update), Microsoft Clarity (consentv2), the dataLayer (event mertools_consent) and the page
 * (event mertools:consent). Withdrawing a level reloads the page, so its trackers stop, and takes out
 * the cookies the known ones left (Google Analytics and Ads, Facebook, Clarity, Hotjar).
 *
 * The window is a dialog: focus goes into it and stays there while it asks, the buttons are real
 * buttons, the switches checkboxes with their labels. Links to #mertools-consent (or elements with the
 * class mertools-consent-open, or TermsFeed's #open_preferences_center) open the settings again.
 */
(function () {
  'use strict';

  var opts = (window.Joomla && Joomla.getOptions && Joomla.getOptions('plg_system_mertools')) || {};
  var cfg = opts.consent;
  if (!cfg) return;
  var t = cfg.t || {};
  var d = document, w = window;
  var LEVELS = ['functionality', 'tracking', 'targeting'];
  var choice = w.mertoolsConsent || null;
  var root = null, box = null, prefs = null, opener = null, lastFocus = null;
  // a modal only when the page behind is dimmed: otherwise the page stays usable, by keyboard too
  var modal = cfg.pos === 'center' && !!cfg.backdrop;

  function granted(level) {
    return !!(choice && choice[level]);
  }

  function g(x) { return x ? 'granted' : 'denied'; }

  // ------------------------------------------------------------ the scripts waiting for consent
  var running = false, queue = [];

  function levelOf(s) {
    return s.getAttribute('data-mertools-consent') || s.getAttribute('cookie-consent') || '';
  }

  function release() {
    var list = d.querySelectorAll('script[type="text/plain"][data-mertools-consent],script[type="text/plain"][cookie-consent]');
    for (var i = 0; i < list.length; i++) {
      var lvl = levelOf(list[i]);
      if (lvl === 'strictly-necessary' || granted(lvl)) queue.push(list[i]);
    }
    if (!running) next();
  }

  function next() {
    var o = queue.shift();
    if (!o) { running = false; return; }
    running = true;
    if (!o.isConnected) { next(); return; }
    var s = d.createElement('script');
    [].forEach.call(o.attributes, function (a) {
      if (a.name !== 'type' && a.name !== 'nonce' && a.name !== 'data-mertools-consent' && a.name !== 'cookie-consent' && a.name !== 'data-mertools-src') s.setAttribute(a.name, a.value);
    });
    if (o.nonce) s.nonce = o.nonce;
    // the address is kept as data-mertools-src, out of the reach of Gridbox's joining of scripts
    var src = o.getAttribute('data-mertools-src') || o.getAttribute('src');
    if (src) {
      s.async = false;
      s.onload = s.onerror = next;
      s.src = src;
      o.replaceWith(s);
    } else {
      s.text = o.text;
      o.replaceWith(s);
      next();
    }
  }

  // ------------------------------------------------------------ keeping and telling the choice
  var TRACKERS = {
    tracking: /^(_ga|_gid|_gat|_clck|_clsk|_hj)/,
    targeting: /^(_gcl|_fbp|_fbc|_uet|_ttp|li_|IDE$)/
  };

  /** the cookies whose names match, deleted for this host and every domain above it */
  function expire(re) {
    var host = location.hostname.split('.'), domains = [''];
    for (var i = 0; i < host.length - 1; i++) domains.push(';domain=.' + host.slice(i).join('.'));
    d.cookie.split('; ').forEach(function (c) {
      var name = c.split('=')[0];
      if (!re.test(name)) return;
      domains.forEach(function (dm) { d.cookie = name + '=;path=/;max-age=0' + dm; });
    });
  }

  function forget(level) {
    if (TRACKERS[level]) expire(TRACKERS[level]);
  }

  function keep() {
    var v = 'f' + (+choice.functionality) + 't' + (+choice.tracking) + 'm' + (+choice.targeting) + '.' + Math.floor(Date.now() / 1000);
    d.cookie = cfg.cookie + '=' + v + ';path=/;max-age=' + (cfg.days * 86400) + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
    // TermsFeed's choice, once carried over or replaced, must not come back when this cookie expires
    expire(/^cookie_consent_(user_accepted|level|user_consent_token)$/);
  }

  function announce() {
    (w.dataLayer = w.dataLayer || []).push({ event: 'mertools_consent', mertools_consent: { functionality: choice.functionality, tracking: choice.tracking, targeting: choice.targeting } });
  }

  function save(pick) {
    var before = choice;
    choice = { functionality: !!pick.functionality, tracking: !!pick.tracking, targeting: !!pick.targeting };
    w.mertoolsConsent = choice;
    keep();
    if (typeof w.gtag === 'function') {
      w.gtag('consent', 'update', {
        ad_storage: g(choice.targeting), ad_user_data: g(choice.targeting), ad_personalization: g(choice.targeting),
        analytics_storage: g(choice.tracking), functionality_storage: g(choice.functionality), personalization_storage: g(choice.functionality)
      });
    }
    if (cfg.clarity && typeof w.clarity === 'function') {
      w.clarity('consentv2', { ad_Storage: g(choice.targeting), analytics_Storage: g(choice.tracking) });
    }
    announce();
    try { w.dispatchEvent(new CustomEvent('mertools:consent', { detail: choice })); } catch (e) { /* old browsers */ }
    // a level taken back: what its scripts started cannot be stopped in this page
    var withdrawn = before && LEVELS.some(function (l) { return before[l] && !choice[l]; });
    if (withdrawn) {
      if (cfg.clean) LEVELS.forEach(function (l) { if (!choice[l]) forget(l); });
      location.reload();
      return;
    }
    release();
  }

  // ------------------------------------------------------------ the window
  function el(tag, cls, text) {
    var e = d.createElement(tag);
    if (cls) e.className = cls;
    if (text) e.textContent = text;
    return e;
  }

  function button(cls, text, fn) {
    var b = el('button', cls, text);
    b.type = 'button';
    b.addEventListener('click', fn);
    return b;
  }

  /** dark or light text on the accent colour, whichever reads better */
  function onAccent(colour) {
    var probe = el('span');
    probe.style.color = colour;
    probe.style.display = 'none';
    d.body.appendChild(probe);
    var m = getComputedStyle(probe).color.match(/[\d.]+/g);
    probe.remove();
    if (!m) return '#111';
    var l = [m[0], m[1], m[2]].map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    var lum = 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2];
    return (lum + 0.05) / 0.05 >= 1.05 / (lum + 0.05) ? '#111' : '#fff';
  }

  function build() {
    root = el('div', 'mtc');
    root.setAttribute('data-pos', cfg.pos);
    root.hidden = true;
    var accent = cfg.accent || getComputedStyle(d.body).getPropertyValue('--primary').trim() || '#1a73e8';
    root.style.setProperty('--mtc-a', accent);
    root.style.setProperty('--mtc-af', onAccent(accent));
    if (modal) root.appendChild(el('div', 'mtc-dim'));
    box = el('div', 'mtc-box');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', modal ? 'true' : 'false');
    box.setAttribute('aria-labelledby', 'mtc-title');
    box.setAttribute('aria-describedby', 'mtc-text');
    box.tabIndex = -1;
    var title = el('h2', 'mtc-title', t.title);
    title.id = 'mtc-title';
    var text = el('p', 'mtc-text', t.text);
    text.id = 'mtc-text';
    if (cfg.policy) {
      text.appendChild(d.createTextNode(' '));
      var a = el('a', '', t.policy);
      a.href = cfg.policy;
      text.appendChild(a);
    }
    box.appendChild(title);
    box.appendChild(text);

    prefs = el('ul', 'mtc-prefs');
    prefs.hidden = true;
    ['necessary'].concat(cfg.levels).forEach(function (l) {
      var li = el('li'), label = el('label', 'mtc-row'), input = el('input');
      input.type = 'checkbox';
      input.name = l;
      input.setAttribute('role', 'switch');
      if (l === 'necessary') { input.checked = true; input.disabled = true; }
      var name = el('span', '', t[l]);
      label.appendChild(name);
      label.appendChild(input);
      label.appendChild(el('span', 'mtc-sw'));
      var desc = el('p', 'mtc-desc', t[l + 'Desc']);
      desc.id = 'mtc-d-' + l;
      input.setAttribute('aria-describedby', desc.id);
      li.appendChild(label);
      li.appendChild(desc);
      prefs.appendChild(li);
    });
    box.appendChild(prefs);

    var btns = el('div', 'mtc-btns');
    var all = {}, none = {};
    LEVELS.forEach(function (l) { all[l] = cfg.levels.indexOf(l) > -1; none[l] = false; });
    btns.appendChild(button('mtc-yes', t.accept, function () { close(); save(all); }));
    btns.appendChild(button('mtc-no', t.reject, function () { close(); save(none); }));
    var more = button('mtc-more', t.settings, function () { showPrefs(true); });
    var saveBtn = button('mtc-save', t.save, function () {
      var c = {};
      LEVELS.forEach(function (l) { var i = prefs.querySelector('input[name="' + l + '"]'); c[l] = !!(i && i.checked); });
      close();
      save(c);
    });
    btns.appendChild(more);
    btns.appendChild(saveBtn);
    box.appendChild(btns);
    root.appendChild(box);

    root.addEventListener('keydown', function (e) {
      if (e.key === 'Tab' && modal) {
        var f = [].filter.call(box.querySelectorAll('button,a[href],input:not([disabled])'), function (x) { return x.offsetParent !== null; });
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && (d.activeElement === first || d.activeElement === box)) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && d.activeElement === last) { e.preventDefault(); first.focus(); }
      } else if (e.key === 'Escape' && choice) {
        // only a visitor who already chose closes it without choosing again
        close();
      }
    });
    root._more = more;
    root._keep = saveBtn;
    d.body.appendChild(root);
  }

  function showPrefs(on) {
    prefs.hidden = !on;
    root._more.hidden = on;
    root._keep.hidden = !on;
    if (on) {
      LEVELS.forEach(function (l) { var i = prefs.querySelector('input[name="' + l + '"]'); if (i) i.checked = granted(l); });
      var first = prefs.querySelector('input:not([disabled])');
      if (first) first.focus();
    }
  }

  function open(withPrefs) {
    if (!root) build();
    lastFocus = d.activeElement;
    root.hidden = false;
    showPrefs(!!withPrefs);
    if (opener) opener.hidden = true;
    if (!withPrefs) box.focus({ preventScroll: true });
  }

  function close() {
    if (!root) return;
    root.hidden = true;
    if (opener) opener.hidden = false;
    if (lastFocus && lastFocus.focus && lastFocus !== d.body) lastFocus.focus({ preventScroll: true });
  }

  // ------------------------------------------------------------ opening the settings again
  function reopenButton() {
    if (cfg.reopen === 'none') return;
    opener = button('mtc-re', '', function () { open(true); });
    opener.setAttribute('aria-label', t.reopen);
    opener.title = t.reopen;
    opener.style[cfg.reopen === 'right' ? 'right' : 'left'] = cfg.rx + 'px';
    opener.style.bottom = cfg.ry + 'px';
    opener.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-4-4 4 4 0 0 1-4-4 2 2 0 0 1-2-2Zm-3.5 7a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm6 5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm-6.5 2a1 1 0 1 1 0 2 1 1 0 0 1 0-2Z"/></svg>';
    d.body.appendChild(opener);
    place();
    w.addEventListener('load', function () { place(); setTimeout(place, 1500); });
    w.addEventListener('resize', place, { passive: true });
  }

  /** above Joomla's accessibility icon or MerTools' floating dark-mode toggle when they share the corner */
  function place() {
    if (!opener) return;
    opener.style.bottom = cfg.ry + 'px';
    var was = opener.hidden;
    opener.hidden = false;
    var me = opener.getBoundingClientRect();
    [].forEach.call(d.querySelectorAll('._access-icon,.mertools-dt-float'), function (o) {
      var r = o.getBoundingClientRect();
      if (!r.width || r.right <= me.left || r.left >= me.right || r.bottom <= me.top || r.top >= me.bottom) return;
      opener.style.bottom = Math.round(innerHeight - r.top + 10) + 'px';
      me = opener.getBoundingClientRect();
    });
    opener.hidden = was;
  }

  d.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href="#mertools-consent"],.mertools-consent-open,#open_preferences_center');
    if (!a) return;
    e.preventDefault();
    open(true);
  });

  function start() {
    reopenButton();
    // the strictly necessary scripts run at once, with or without a choice
    release();
    if (choice) {
      // a choice made in TermsFeed's window before: kept from now on in MerTools' own cookie
      if (d.cookie.indexOf(cfg.cookie + '=') < 0) keep();
      // on every page, for the tags of Tag Manager fired by this event (the term is not renewed)
      announce();
    } else {
      if (opener) opener.hidden = true;
      open(false);
    }
  }

  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', start);
  else start();
}());
