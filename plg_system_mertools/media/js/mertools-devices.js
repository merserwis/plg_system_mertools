/**
 * MerTools — the settings Gridbox keeps per device, shown so in its editor. Many settings of an element
 * (the number of columns of a gallery, a height, a margin…) are kept for each device on its own: set on a
 * phone they stay on the phone, the computer keeps its own. Nothing in Gridbox's window says which. A
 * small mark beside the name of each such setting says it; blue when a device has a value of its own,
 * the devices and their values in its tip.
 *
 * Gridbox's own rules: a setting is per device when its group is in the settings of the computer
 * (app.setValue writes it to app.edit[app.view]); a device without a value of its own takes it from the
 * one before it (computer → laptop → tablets → phones, app.getValue). Nothing is changed or saved.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-devices-config');
  if (!node) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  const T = cfg.texts || {};
  const app = window.app;
  if (!app) return;

  const style = document.createElement('style');
  // Gridbox sizes and colours the icons in its windows: its own weight overruled
  style.textContent = '.draggable-modal-cp span > i.mt-dev{display:inline-block!important;margin:0 0 0 5px!important;padding:0!important;'
    + 'font-size:12px!important;width:auto!important;height:auto!important;line-height:1!important;color:#b5b5b5!important;cursor:help;vertical-align:0!important}'
    + '.draggable-modal-cp span > i.mt-dev.mt-dev-own{color:#1da6f4!important}';
  document.head.append(style);

  /** the devices of the editor's menu, in Gridbox's order: [view, name] */
  const devices = () => [...document.querySelectorAll('.responsive-context-menu > span[data-view]')]
    .map((s) => [s.dataset.view, (s.querySelector('span') || s).textContent.trim()]);
  const read = (obj, group, sub, option) => {
    let v = obj ? obj[group] : undefined;
    if (v !== undefined && sub) v = v === null || typeof v !== 'object' ? undefined : v[sub];
    if (v !== undefined && option) v = v === null || typeof v !== 'object' ? undefined : v[option];
    return v;
  };
  const shown = (v) => (typeof v === 'boolean' ? (v ? '✓' : '✗') : v !== null && typeof v === 'object' ? '…' : String(v));

  const mark = (item, edit, list) => {
    const el = item.querySelector('[data-option]:not([data-type="reset"])');
    const label = item.querySelector(':scope > span');
    let icon = label && label.querySelector(':scope > .mt-dev');
    // Gridbox's test of a setting kept per device (app.setValue)
    const group = el && (el.dataset.group || el.dataset.option);
    const per = !!el && !el.dataset.state && !!group && (group === 'span' || (edit.desktop && typeof edit.desktop[group] !== 'undefined'));
    if (!per || !label) {
      if (icon) icon.remove();
      return;
    }
    const option = el.dataset.group ? el.dataset.option : '';
    const own = list.filter(([view]) => view !== 'desktop' && read(edit[view], group, el.dataset.subgroup, option) !== undefined)
      .map(([view, name]) => name + ': ' + shown(read(edit[view], group, el.dataset.subgroup, option)));
    if (!icon) {
      icon = document.createElement('i');
      icon.className = 'zmdi zmdi-devices mt-dev';
      label.append(icon);
    }
    icon.classList.toggle('mt-dev-own', own.length > 0);
    const tip = (T.PER || 'Set for each device on its own') + (own.length ? '. ' + (T.OWN || 'Its own value on') + ' ' + own.join('; ') : '');
    icon.title = tip;
    icon.setAttribute('aria-label', tip);
  };

  let last = '';
  const refresh = (force) => {
    const edit = app.edit;
    const modals = [...document.querySelectorAll('.draggable-modal-cp.in')];
    const sign = (edit && edit.type) + '|' + app.view + '|' + modals.map((m) => m.id).join(',');
    if (!force && sign === last && edit === refresh.edit) return;
    last = sign;
    refresh.edit = edit;
    if (!edit || typeof edit !== 'object' || !edit.desktop) {
      document.querySelectorAll('.mt-dev').forEach((i) => i.remove());
      return;
    }
    const list = devices();
    // a setting of its own line, and each field of a row of them (margins, padding: top, right…)
    modals.forEach((m) => m.querySelectorAll('.ba-settings-item, .ba-settings-toolbar > div').forEach((item) => mark(item, edit, list)));
  };

  // a window opened, another element, another device: looked at twice a second while a window is open
  setInterval(() => { if (document.querySelector('.draggable-modal-cp.in')) refresh(false); }, 500);
  // a value changed, Gridbox's undo and redo, its other changes of the element: after Gridbox has written
  // them (its own delay is 300 ms), once for a run of keys or clicks
  let wait = 0;
  const later = (e) => {
    if (e.type !== 'mouseup' && e.type !== 'keyup' && !(e.target.closest && e.target.closest('.draggable-modal-cp'))) return;
    clearTimeout(wait);
    wait = setTimeout(() => refresh(true), 450);
  };
  ['input', 'change', 'mouseup', 'keyup'].forEach((type) => document.addEventListener(type, later, true));
})();
