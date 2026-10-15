/**
 * MerTools for Gridbox — category pages: a category with subcategories in Gridbox's Categories element
 * opens and closes with a click on its whole row instead of opening its page. Its link is taken off and
 * the row gets the class Gridbox's own script opens and closes on (collapse-categories-list), as the
 * script of the separate Omnibus plugin did.
 */
(function () {
  'use strict';

  function start() {
    var links = document.querySelectorAll('.ba-app-sub-category a');
    for (var i = 0; i < links.length; i++) {
      var link = links[i], icon = link.nextElementSibling;
      if (icon && icon.tagName === 'I') {
        link.removeAttribute('href');
        icon.classList.remove('collapse-categories-list');
        link.parentNode.classList.add('collapse-categories-list');
      }
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
