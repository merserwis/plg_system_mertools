/**
 * MerTools — fields filled in the Gridbox editor: an SEO or sharing field of the page that is empty when
 * the editor opens gets the text set for it in MerTools (as a rule Gridbox's data tags, [Page Title] |
 * [Site Name], [Intro Text]…). A field with anything in it is left as it is. The field is in the editor
 * twice (the panel of a post and the settings window): both get it, and it is saved with the page.
 */
(() => {
  'use strict';
  const node = document.getElementById('mertools-fields-config');
  if (!node) return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  const fields = cfg.fields || {};

  const fill = () => {
    Object.keys(fields).forEach((name) => {
      const els = [...document.querySelectorAll('.page-settings-input-trigger[name="' + name + '"]')];
      if (!els.length || !fields[name] || els.some((el) => el.value.trim() !== '')) return;
      els.forEach((el) => { el.value = fields[name]; });
      // Gridbox's own handler keeps the copies of a field the same
      els[0].dispatchEvent(new Event('input', { bubbles: true }));
    });
  };
  if (document.readyState === 'complete') fill(); else window.addEventListener('load', fill, { once: true });
})();
