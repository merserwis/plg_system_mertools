/**
 * MerTools — conditional logic of the fields of Gridbox's checkout form (the rules made in the editor of
 * the checkout page, sent by the plugin as mertools-conditions-config: the rules of each checkout form).
 *
 * A rule holds when all its conditions do. A field with a rule that shows it is visible only while one
 * of those holds; a field with a rule that hides it is hidden while one of those holds (hiding wins). A
 * hidden field counts as not filled in the conditions of other rules (a field shown by a field that is
 * hidden hides too), is not required (Gridbox's check of the form passes it over, its warning goes) and
 * goes into the order empty; what was typed in stays in it for when it is shown again.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-conditions-config');
  if (!node) return;
  let forms;
  try { forms = JSON.parse(node.textContent); } catch (e) { return; }
  if (!forms || typeof forms !== 'object') return;
  const HIDDEN = 'mt-logic-hidden';

  // a value as compared: the text of an option without its tags and entities (Gridbox writes the value
  // of a check box as the escaped text, "A &amp; B"; the editor offers it as "A & B"), without the
  // spaces of the ends, in any case
  const decoder = document.createElement('textarea');
  const text = (value) => {
    decoder.innerHTML = String(value).replace(/<[^>]*>/g, '');

    return decoder.value.replace(/\s+/g, ' ').trim().toLocaleLowerCase();
  };
  // a number as people type it: 1 234,5 or 1234.5
  const number = (value) => {
    const t = String(value).replace(/[\s ]/g, '').replace(',', '.');

    return t !== '' && Number.isFinite(Number(t)) ? Number(t) : NaN;
  };

  /** what a field holds: the values chosen or typed in, none when empty or hidden */
  const values = (field) => {
    if (!field || field.classList.contains(HIDDEN)) return [];
    const type = field.dataset.type;
    if (type === 'checkbox' || type === 'radio' || type === 'acceptance') {
      return [...field.querySelectorAll('input:checked')].map((input) => input.value || '1');
    }
    if (type === 'country') {
      // the name of the country chosen (the field keeps its number)
      const select = field.querySelector('select[data-type="country"]');

      return select && select.value ? [select.options[select.selectedIndex].text] : [];
    }
    const input = field.querySelector('input, textarea, select');

    return input && input.value.trim() !== '' ? [input.value] : [];
  };

  const holds = (cond, list) => {
    const want = text(cond.value);
    switch (cond.op) {
      case 'filled': return list.length > 0;
      case 'empty': return list.length === 0;
      case 'equal': return list.some((v) => text(v) === want);
      case 'not-equal': return !list.some((v) => text(v) === want);
      case 'contains': return list.some((v) => text(v).includes(want));
      case 'not-contains': return !list.some((v) => text(v).includes(want));
      case 'greater':
      case 'less': {
        const b = number(cond.value);

        return list.some((v) => {
          const a = number(v);

          return !Number.isNaN(a) && !Number.isNaN(b) && (cond.op === 'greater' ? a > b : a < b);
        });
      }
      default: return false;
    }
  };

  const checkout = () => (window.app && window.app.checkoutForm) || null;

  /** a field hidden or shown; a warning of Gridbox's check on what is hidden goes with it */
  const hide = (field, hidden) => {
    if (field.classList.contains(HIDDEN) === hidden) return false;
    field.classList.toggle(HIDDEN, hidden);
    const form = checkout();
    if (hidden && form && typeof form.removeAlertTooltip === 'function') {
      [field, ...field.querySelectorAll('input, select, textarea')].forEach((el) => {
        if (el.alertTooltip) form.removeAlertTooltip(el);
      });
    }

    return true;
  };

  const follow = (item, rules) => {
    const field = (id) => item.querySelector('.ba-checkout-form-fields[data-name="' + id + '"]');
    const targets = new Map();
    rules.forEach((rule) => rule.then.forEach((action) => {
      const el = field(action.field);
      if (!el) return;
      if (!targets.has(el)) targets.set(el, { show: [], hide: [] });
      targets.get(el)[action.action === 'hide' ? 'hide' : 'show'].push(rule);
    }));
    const ok = (rule) => rule.when.every((cond) => holds(cond, values(field(cond.field))));
    // again while something changes: a hidden field empties the conditions on it (a bound on rules
    // that would hide and show each other for ever)
    for (let pass = 0; pass <= targets.size; pass++) {
      let changed = false;
      targets.forEach((by, el) => {
        const visible = (!by.show.length || by.show.some(ok)) && !by.hide.some(ok);
        changed = hide(el, !visible) || changed;
      });
      if (!changed) break;
    }
    item.classList.add('mt-logic');
  };

  const items = [];
  Object.keys(forms).forEach((id) => {
    const item = document.getElementById(id);
    if (item && Array.isArray(forms[id])) items.push([item, forms[id]]);
  });
  if (!items.length) return;
  const run = () => items.forEach(([item, rules]) => follow(item, rules));
  run();
  // Gridbox changes the fields also with jQuery's events (the country: its hidden field), which only
  // jQuery's own handlers hear
  const $ = window.$g || window.jQuery;
  items.forEach(([item]) => {
    if ($) $(item).on('input change', run);
    else ['input', 'change'].forEach((type) => item.addEventListener(type, run));
  });

  // Gridbox's check of the form and the data of the order (its checkoutForm, from a module it loads
  // when the page is shown): a hidden field is neither required nor sent
  const wrap = (form) => {
    if (!form || typeof form !== 'object' || form.mtLogic) return;
    form.mtLogic = true;
    const toggle = form.toggleAlertTooltip;
    if (typeof toggle === 'function') {
      form.toggleAlertTooltip = function (alert, el, ...rest) {
        return toggle.call(this, alert && !(el && typeof el.closest === 'function' && el.closest('.' + HIDDEN)), el, ...rest);
      };
    }
    const pick = form.pickData;
    if (typeof pick === 'function') {
      form.pickData = function (...args) {
        const data = pick.apply(this, args);
        if (data && typeof data === 'object') {
          document.querySelectorAll('.ba-checkout-form-fields.' + HIDDEN + '[data-name]').forEach((el) => {
            if (Object.prototype.hasOwnProperty.call(data, el.dataset.name)) data[el.dataset.name] = '';
          });
        }

        return data;
      };
    }
  };
  const app = window.app;
  if (!app) return;
  if (app.checkoutForm) {
    wrap(app.checkoutForm);
  } else {
    let form;
    try {
      Object.defineProperty(app, 'checkoutForm', {
        configurable: true,
        enumerable: true,
        get: () => form,
        set: (value) => { form = value; wrap(value); },
      });
    } catch (e) { /* the place order button still checks the fields shown */ }
  }
})();
