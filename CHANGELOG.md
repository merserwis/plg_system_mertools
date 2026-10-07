# Changelog

All changes of **MerTools for Gridbox**, newest first. **MerTools is in beta:** every version so far is a beta version — test each tool on your site before relying on it. Each version is also published as a [GitHub release](https://github.com/merserwis/plg_system_mertools/releases) with its installation package.

## 0.0.4 (Beta) — 2026-10-07

### 🌍 Languages

- The settings are in **English (default) and Polish** again. German, French, Czech and Dutch, added in 0.0.3, are removed; their files are removed on update, and those administrators see English.

## 0.0.3 (Beta) — 2026-10-07

### 🧪 Beta

- MerTools is marked as a **beta version** in the extension list, the plugin settings, the package description and on every release.

### 🌍 Languages

- The settings are now in **English (default), German, Polish, French, Czech and Dutch** and follow the language of the Joomla administrator; any other language shows English, also text by text where a translation lacks one.

## 0.0.2 (Beta) — 2026-10-06

### 🌙 Dark mode

- **New tool *Dark mode*** (tab *Dark mode*): an elegant dark theme for the whole front-end, with a **toggle button in the header**. It is built from Gridbox's own colour variables, so the site adapts cleanly — nothing is inverted, brand colours and images stay intact. The palettes are soft dark greys and blues, **never a harsh pure black**, so they are easy on the eyes.
- **Palettes:** *Slate* (elegant blue-grey, default), *Charcoal* (neutral grey), *Midnight* (deep blue), *Warm* (soft brown), *Dim* (low contrast), and *Custom* — set every colour yourself (background, cards, headings, text, muted text, borders, hover, shadow).
- **Accent as in Gridbox:** the site's brand accent is kept in dark mode by default, or you can set a different accent for dark mode.
- **Default for new visitors:** *Automatic* (follows the visitor's system light/dark setting), *Light* or *Dark*. The visitor's own choice is remembered and always wins. The theme is set before the first paint, so there is no flash.
- **Toggle button:** shown in the header next to the menu (or at the start/end of the header, or as a floating button that is always visible — best for mobile). The header element can be changed for other templates.
- **Options:** a smooth cross-fade when switching, and optionally calming the brightness of photos in dark mode.
- No Gridbox core file is changed.

## 0.0.1 (Beta) — 2026-10-06

First version.

### 🔗 Canonical URL (collapse duplicate slashes)

- Gridbox serves a page under any number of slashes in the address — `/oferta`, `//oferta` and `//////oferta` all open the same page with status 200. Search engines then see several addresses for one page (duplicate content) and the ranking is split.
- MerTools redirects every such address to the one clean address with single slashes (`//////oferta/mierniki` → `/oferta/mierniki`). Only the path is changed; the query string after `?` is left as it is, and the address always stays on this site.
- Settings (tab *Canonical URL*): on/off and the redirect type (301 permanent, recommended, or 302 temporary).
- Runs before routing, so it does not interfere with Gridbox's own routing. The Gridbox core files are not changed.
- If the site is behind Cloudflare (or another CDN) that merges the slashes *before* they reach the server, PHP never sees them and the redirect cannot be done in the plugin; the settings panel and the README then give a step-by-step Cloudflare Redirect Rule for that case.
