/**
 * MerTools for Gridbox — Gridbox tabs as rows on phones (Tool/Tabs.php).
 *
 * On screens up to cfg.width px every tab of a Gridbox tabs item becomes a button row (icon, name,
 * arrow) placed right above its content; the row opens and closes that content. The tab bar is
 * hidden. Gridbox rules set the bar and the panes with the item id, so they are overridden inline.
 * Opening a row also selects Gridbox's own tab (its script does what it does when a tab is shown).
 * Wider screens get the tabs back exactly as Gridbox left them; turning a tablet switches on the fly.
 *
 * Links to a tab (cfg.links): choosing a tab — or opening its row — writes its name in the address
 * ("Dane techniczne" → ?dane_techniczne, history.replaceState, no reload); an address with such a
 * name opens that tab when the page has loaded and scrolls to it, below a fixed header. Titles in
 * other alphabets keep their letters ("Характеристики" → ?характеристики), a tab without a title
 * gets the id of its content, and the same title twice on a page (two tabs items) gets a number
 * the second time (?specifications_2), so every tab has its own address.
 */
(function () {
  'use strict';

  var opts = (window.Joomla && Joomla.getOptions && Joomla.getOptions('plg_system_mertools')) || {};
  var cfg = opts.tabs || { width: 768, first: false };
  var mq = window.matchMedia('(max-width:' + cfg.width + 'px)');
  var items = [];

  function important(el, prop, value) {
    if (value === null) { el.style.removeProperty(prop); } else { el.style.setProperty(prop, value, 'important'); }
  }

  /**
   * Gridbox's slider of a slideset (its jQuery data), or null before Gridbox has built it: the
   * carousel of posts and products sliders (gridboxCarousel, one post per step: slideAction) or the
   * slideset (one page per step: next / prev). Returned as { go(dir), at, pages }.
   */
  function sliderOf(ul) {
    var data = window.$g ? (window.$g(ul).data() || {}) : {};
    for (var k in data) {
      var o = data[k];
      if (!o || typeof o !== 'object') { continue; }
      if (typeof o.slideAction === 'function' && o.childrens) {
        return { obj: o, go: function (dir) { this.obj.slideAction(dir); }, at: +o.currentIndex || 0, pages: o.childrens.length };
      }
      if (typeof o.next === 'function' && typeof o.slideTo === 'function') {
        return { obj: o, go: function (dir) { this.obj[dir](); }, at: +o.currentIndex || 0, pages: o.childCount || 0 };
      }
    }
    return null;
  }

  /**
   * Sliders inside the rows (cfg.sliders): a Gridbox posts or products slider shows one post at a
   * time on a phone, with nothing that says there are more. 'nav' adds a bar under it — previous,
   * "2 / 12", next (Gridbox's own steps) and a hint that it can be swiped; 'stack' shows its posts
   * one under another; 'off' leaves it. Only while the tabs are rows (class mt-tabacc).
   */
  function sliders(pane) {
    var mode = cfg.sliders || 'nav';
    if (mode === 'off') { return; }
    var T = cfg.texts || {};
    [].forEach.call(pane.querySelectorAll('ul.ba-slideset'), function (ul) {
      var items = ul.querySelectorAll('.slideshow-content > li.item:not(.ba-unpublished-html-item)');
      if (ul.mtSl || items.length < 2) { return; }
      ul.mtSl = true;
      if (mode === 'stack') { ul.classList.add('mt-slstack'); return; }
      var bar = document.createElement('div');
      bar.className = 'mt-slnav';
      bar.innerHTML = '<div class="mt-slnav-row"><button type="button" class="mt-slnav-btn mt-slnav-prev"></button>'
        + '<span class="mt-slnav-count" aria-live="polite"></span><button type="button" class="mt-slnav-btn mt-slnav-next"></button></div>'
        + '<div class="mt-slnav-hint"></div>';
      var prev = bar.querySelector('.mt-slnav-prev'), next = bar.querySelector('.mt-slnav-next'), count = bar.querySelector('.mt-slnav-count');
      prev.setAttribute('aria-label', T.prev || 'Previous');
      next.setAttribute('aria-label', T.next || 'Next');
      bar.querySelector('.mt-slnav-hint').textContent = T.hint || '';
      var host = ul.closest('.slideset-wrapper') || ul;
      host.parentNode.insertBefore(bar, host.nextSibling);
      function update() {
        var sl = sliderOf(ul), pages = sl && sl.pages ? sl.pages : items.length;
        var at = sl ? sl.at : 0;
        count.textContent = (Math.min(at, pages - 1) + 1) + ' / ' + pages;
        bar.hidden = pages < 2;
      }
      function go(dir) {
        var sl = sliderOf(ul);
        if (!sl) { return; }
        if (typeof sl.obj.pause === 'function') { sl.obj.pause(); }
        sl.go(dir);
        setTimeout(update, 50);
      }
      prev.addEventListener('click', function () { go('prev'); });
      next.addEventListener('click', function () { go('next'); });
      // swipes and autoplay change the active posts: the number follows (once per frame)
      if (window.MutationObserver) {
        var queued = false;
        new MutationObserver(function () {
          if (queued) { return; }
          queued = true;
          requestAnimationFrame(function () { queued = false; update(); });
        }).observe(ul, { attributes: true, attributeFilter: ['class'], subtree: true });
      }
      ul.mtSlUpdate = update;
      update();
    });
  }

  function build(box, n) {
    var bar = box.querySelector('ul.nav-tabs');
    if (!bar || box.mtTabacc) { return; }
    var rows = [];
    [].forEach.call(bar.querySelectorAll(':scope > li > a[href^="#"]'), function (link, i) {
      var pane = null;
      pane = document.getElementById(link.getAttribute('href').slice(1));
      if (pane && !box.contains(pane)) { pane = null; }
      if (!pane) { return; }
      var head = document.createElement('button');
      head.type = 'button';
      head.className = 'mt-tabacc-head';
      head.id = 'mt-tabacc-' + n + '-' + i;
      head.setAttribute('aria-controls', pane.id);
      var icon = link.querySelector('i');
      if (icon) {
        var ic = icon.cloneNode(true);
        ic.setAttribute('aria-hidden', 'true');
        head.appendChild(ic);
      }
      var title = link.querySelector('.tabs-title');
      var label = document.createElement('span');
      label.textContent = (title || link).textContent.trim();
      head.appendChild(label);
      pane.parentNode.insertBefore(head, pane);
      var row = { head: head, pane: pane, link: link };
      head.addEventListener('click', function () { toggle(row); });
      rows.push(row);
    });
    if (!rows.length) { return; }
    rows.forEach(function (r) { sliders(r.pane); });
    var item = { box: box, bar: bar, rows: rows, on: false };
    rows.forEach(function (r) { r.item = item; });
    box.mtTabacc = item;
    items.push(item);
  }

  function show(row, open) {
    row.head.setAttribute('aria-expanded', open ? 'true' : 'false');
    important(row.pane, 'display', open ? 'block' : 'none');
    important(row.pane, 'opacity', open ? '1' : null);
  }

  /**
   * Sliders and carousels measured while their content was hidden keep a height of 0: Gridbox's
   * carousel sets it at start, and the tab's "shown" makes Gridbox load its module and build the
   * carousel again a moment later, measured again then. So for a few seconds after opening, every
   * carousel of the content that gets a height of 0 is measured again (Gridbox's own setHeight).
   */
  function measure(pane) {
    var $ = window.$g;
    [].forEach.call(pane.querySelectorAll('ul.ba-slideset'), function (ul) { if (ul.mtSlUpdate) { ul.mtSlUpdate(); } });
    if (!$) { return; }
    $(pane).find('ul, .ba-testimonials, .ba-slideshow, .slideset-wrapper').each(function () {
      var data = $(this).data() || {};
      Object.keys(data).forEach(function (k) {
        var o = data[k];
        if (o && typeof o.setHeight === 'function') {
          try { o.setHeight(); if (typeof o.setLeft === 'function') { o.setLeft(); } } catch (e) { /* not a Gridbox slider */ }
        }
      });
    });
  }

  function refresh(pane) {
    measure(pane);
    if (!window.MutationObserver || pane.mtWatch) { return; }
    var queued = false;
    var watch = new MutationObserver(function (list) {
      if (queued) { return; }
      var zero = list.some(function (m) { return m.target.style && m.target.style.height === '0px' && pane.offsetHeight > 0; });
      if (!zero) { return; }
      queued = true;
      requestAnimationFrame(function () { queued = false; measure(pane); });
    });
    watch.observe(pane, { attributes: true, attributeFilter: ['style'], subtree: true });
    pane.mtWatch = watch;
    setTimeout(function () { watch.disconnect(); pane.mtWatch = null; measure(pane); }, 6000);
  }

  // the space above a row that follows open content
  function gaps(item) {
    item.rows.forEach(function (r, i) {
      r.head.classList.toggle('mt-after-open', i > 0 && item.rows[i - 1].head.getAttribute('aria-expanded') === 'true');
    });
  }

  function toggle(row) {
    var item = row.item;
    var open = row.head.getAttribute('aria-expanded') !== 'true';
    if (open) {
      // visible first: Gridbox measures its sliders and carousels when the tab is shown
      var still = item.rows.filter(function (r) { return r.head.getAttribute('aria-expanded') === 'true'; });
      show(row, true);
      var li = row.link.parentNode;
      if (li && li.classList.contains('active')) {
        // already Gridbox's active tab: no "shown" from its script, so it is sent here
        if (window.$g) { window.$g(row.link).trigger('shown'); }
      } else {
        row.link.click();
      }
      // Gridbox's script hides the other panes: the rows that are open stay open
      still.concat([row]).forEach(function (r) { show(r, true); });
      refresh(row.pane);
      mark(row.link);
      var top = row.head.getBoundingClientRect().top;
      if (top < 0 || top > window.innerHeight * 0.8) {
        row.head.scrollIntoView({ block: 'start', behavior: 'smooth' });
      }
    } else {
      show(row, false);
      unmark(row.link);
    }
    gaps(item);
  }

  function mode() {
    items.forEach(function (item) {
      var phone = mq.matches;
      if (phone === item.on) { return; }
      item.on = phone;
      item.box.classList.toggle('mt-tabacc', phone);
      important(item.bar, 'display', phone ? 'none' : null);
      item.rows.forEach(function (r, i) {
        if (phone) {
          r.pane.setAttribute('role', 'region');
          r.pane.setAttribute('aria-labelledby', r.head.id);
          show(r, cfg.first ? r.pane.classList.contains('active') || (i === 0 && !item.box.querySelector('.tab-pane.active')) : false);
          if (i === item.rows.length - 1) { gaps(item); }
        } else {
          r.pane.removeAttribute('role');
          r.pane.removeAttribute('aria-labelledby');
          important(r.pane, 'display', null);
          important(r.pane, 'opacity', null);
        }
      });
    });
  }

  // ---------------------------------------------------------------- links to a tab

  // tab name → link (the names of 1.6 and earlier too, so the addresses shared then still work)
  var names = Object.create(null);

  /** lower case without accents (ł → l) */
  function plain(text) {
    var t = String(text).toLowerCase().replace(/ł/g, 'l');
    if (t.normalize) { t = t.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    return t;
  }

  /** "Dane techniczne" → "dane_techniczne": lower case, no accents (ł → l), anything else "_" */
  function slug(text) {
    return plain(text).replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 80);
  }

  // letters of other alphabets (ß, ø, Greek, Cyrillic, Arabic, Hebrew, Indic, Chinese, Japanese,
  // Korean…), which slug() would drop; punctuation and symbols (– „ ” ° × Ω €) are not among them
  var LETTERS = '\\u00c0-\\u00d6\\u00d8-\\u00f6\\u00f8-\\u1fff\\u2c00-\\u2dff\\u3040-\\u9fff\\ua000-\\ud7ff\\uf900-\\ufaff';
  var OTHER = new RegExp('[' + LETTERS + ']');
  var NOT_WORD = new RegExp('[^a-z0-9' + LETTERS + ']+', 'g');

  /** "Характеристики" → "характеристики": the title's own letters, anything else "_" */
  function letters(text) {
    var t = String(text).toLowerCase();
    if (t.normalize) { t = t.normalize('NFC'); }
    return t.replace(NOT_WORD, '_').replace(/^_+|_+$/g, '').slice(0, 80);
  }

  function decoded(p) {
    try { return decodeURIComponent(p.replace(/\+/g, ' ')); } catch (e) { return ''; }
  }

  /** the address with this tab name instead of any other tab name (other parameters kept as they are) */
  function address(name) {
    var parts = location.search.replace(/^\?/, '').split('&').filter(function (p) {
      return p !== '' && !(p.indexOf('=') < 0 && names[decoded(p).toLowerCase()]);
    });
    if (name) { parts.push(encodeURIComponent(name)); }
    return location.pathname + (parts.length ? '?' + parts.join('&') : '') + location.hash;
  }

  /** is this tab's name (or its name of 1.6) in the address? */
  function named(link) {
    return location.search.replace(/^\?/, '').split('&').some(function (p) {
      return p && p.indexOf('=') < 0 && names[decoded(p).toLowerCase()] === link;
    });
  }

  function mark(link) {
    if (!cfg.links || !link || !link.mtTab) { return; }
    var url = address(link.mtTab);
    if (url !== location.pathname + location.search + location.hash) {
      try { history.replaceState(history.state, '', url); } catch (e) { /* a sandboxed page */ }
    }
  }

  function unmark(link) {
    if (!cfg.links || !link || !link.mtTab || !named(link)) { return; }
    try { history.replaceState(history.state, '', address('')); } catch (e) { /* a sandboxed page */ }
  }

  /** the lowest edge of a fixed or sticky bar covering the top of the window (a sticky header), else 0 */
  function covered() {
    var low = 0;
    var seen = [];
    [0.25, 0.5, 0.75].forEach(function (x) {
      var list = document.elementsFromPoint ? document.elementsFromPoint(window.innerWidth * x, 2) : [];
      list.forEach(function (el) {
        for (var e = el; e && e !== document.body && e !== document.documentElement; e = e.parentElement) {
          if (seen.indexOf(e) >= 0) { break; }
          seen.push(e);
          var pos = getComputedStyle(e).position;
          if (pos === 'fixed' || pos === 'sticky') {
            var r = e.getBoundingClientRect();
            if (r.top <= 2 && r.bottom < window.innerHeight * 0.5) { low = Math.max(low, r.bottom); }
            break;
          }
        }
      });
    });
    return low;
  }

  /** the tab bar (or the row on phones) at the top of the window, below a fixed header */
  function scrollTo(el) {
    var go = function (smooth) {
      var y = el.getBoundingClientRect().top + window.pageYOffset - 12;
      window.scrollTo({ top: Math.max(0, y), behavior: smooth ? 'smooth' : 'auto' });
    };
    go(false);
    // a header that becomes fixed once the page scrolls, and pictures above that arrive late
    var user = false;
    var stop = function () { user = true; };
    ['wheel', 'touchstart', 'keydown', 'pointerdown'].forEach(function (ev) { window.addEventListener(ev, stop, { once: true, passive: true }); });
    [150, 700, 1500].forEach(function (t) {
      setTimeout(function () {
        if (user) { return; }
        var top = el.getBoundingClientRect().top;
        var low = covered();
        if (Math.abs(top - (low + 12)) > 4) { window.scrollBy({ top: top - low - 12, behavior: 'auto' }); }
      }, t);
    });
  }

  function openFromAddress() {
    var wanted = location.search.replace(/^\?/, '').split('&').filter(function (p) { return p && p.indexOf('=') < 0; })
      .map(function (p) { return decoded(p).toLowerCase(); });
    var link = null;
    wanted.some(function (w) { link = names[w] || null; return !!link; });
    if (!link) { return; }
    if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
    var box = link.closest('.ba-item-tabs');
    var item = box && box.mtTabacc;
    var row = item && item.on ? item.rows.filter(function (r) { return r.link === link; })[0] : null;
    if (row) {
      if (row.head.getAttribute('aria-expanded') !== 'true') { toggle(row); }
      scrollTo(row.head);
    } else {
      if (!link.parentNode.classList.contains('active')) { link.click(); }
      scrollTo(link.closest('ul.nav-tabs') || box || link);
    }
  }

  function links() {
    var all = [].map.call(document.querySelectorAll('.ba-item-tabs ul.nav-tabs > li > a[href^="#"]'), function (link) {
      var title = link.querySelector('.tabs-title');
      var text = (title || link).textContent.trim();
      var old = slug(text);
      // first the names 1.6 and earlier gave, to the same tabs (the first tab with the name), so the
      // addresses shared then open what they opened
      if (old && !names[old]) { names[old] = link; }
      // a title with letters slug() drops keeps them; a tab without a title: the id of its content
      return { link: link, name: (OTHER.test(plain(text)) ? letters(text) : old) || slug(link.getAttribute('href').slice(1)) };
    });
    all.forEach(function (t) {
      var link = t.link, name = t.name, base = name, k = 2;
      if (!name) { return; }
      // the same title on another tab of the page (two tabs items): _2, _3…
      while (names[name] && names[name] !== link) { name = base + '_' + k++; }
      link.mtTab = name;
      names[name] = link;
      link.addEventListener('click', function () { mark(link); });
    });
    if (!Object.keys(names).length) { return; }
    // after Gridbox has set up its tabs and the pictures above have their place
    var later = function () { setTimeout(openFromAddress, 60); };
    if (document.readyState === 'complete') { later(); } else { window.addEventListener('load', later); }
  }

  function start() {
    if (cfg.accordion !== false) {
      [].forEach.call(document.querySelectorAll('.ba-item-tabs'), build);
      if (items.length) {
        mode();
        if (mq.addEventListener) { mq.addEventListener('change', mode); } else if (mq.addListener) { mq.addListener(mode); }
      }
    }
    if (cfg.links) { links(); }
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
