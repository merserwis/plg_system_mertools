# Changelog

All changes of **MerTools for Gridbox**, newest first. **MerTools is in beta:** every version so far is a beta version — test each tool on your site before relying on it. Each version is also published as a [GitHub release](https://github.com/merserwis/plg_system_mertools/releases) with its installation package.

## 0.0.8 (Beta) — 2026-10-07

### 📖 Sepia theme (new)

- A third, warm paper-like theme for comfortable reading (option *Sepia theme*, on by default). The toggle button switches **light → dark → sepia**, its icon showing the next theme (moon, open book, sun). Sepia can also be the default for new visitors.
- The site colours are adapted to it the same way as in dark mode: white backgrounds take the paper tone, neutral grey or black text the warm brown one; brand colours stay.

### 🎚️ Intensity with a live preview (new)

- A slider *Intensity* (0–100) makes the dark theme softer (lighter greys) or deeper (never pure black); 50 is the palette as designed.
- A **live preview** in the settings shows a small page in the chosen palette, intensity, custom colours and accent as they are changed, before saving — with tabs for dark, sepia and light.

### 🌙 Dark mode

- **Better Search** live results and results page are adapted now: their colours are written with `color-mix()`, which the browser reports as `color(srgb …)` — every CSS colour notation is read now.
- **Breadcrumbs**: the triangles between the items (borders of `::before` / `::after` in the colour of the item) take the same dark colour as the items; thin light borders take the theme's border colour.
- **Text on photos and videos** (hero sections with a parallax image or a video background) keeps its colour — the colours behind it cannot be measured. Text that dark mode itself turned dark there (a Gridbox background variable used as text colour) gets the light text colour.
- Fix: the colour fixes are switched on before they are measured, so text is checked against the already darkened backgrounds (some text could stay dark on a dark background).

- **Gradient backgrounds** are adapted too: the light colours of a gradient (e.g. a footer fading from white to grey) are darkened like plain backgrounds, vivid brand colours in it stay.
- **Backgrounds that appear later**: Gridbox shows some section backgrounds only when the section scrolls into view (lazy loading) and gives the header a background when it becomes sticky. An element whose classes change is now adapted again together with its content — and when it returns to its previous state, so does its colour.

## 0.0.7 (Beta) — 2026-10-07

### 🌙 Dark mode

- **Logo for dark mode** (new): choose a version of the logo for the dark background (e.g. with light lettering) in the media manager; it replaces the site logo in dark mode from the first paint, also with Gridbox's lazy loading. By default the Gridbox Logo element in the header; more logos can be added with a CSS selector.
- **Page cache really emptied**: since Joomla 4 the site keeps its page cache in `administrator/cache` (or the configured cache path); 0.0.6 emptied the wrong folder, so a site with *System - Page Cache* still served the previous pages after an update. Installing, updating and saving the settings now empty the right one.
- **Colours checked again when late styles arrive**: a stylesheet loaded after the page (or a lazy section) no longer leaves a light section unadapted — everything is checked again at once, without flicker.
- **Text written by CSS** (`content: "…"` on `::before` / `::after`, e.g. the message of a cookie banner) is adapted too.

## 0.0.6 (Beta) — 2026-10-07

### 🌙 Dark mode

- **Button size in pixels**: the size of the toggle button is now typed in px (24–96, default 44) instead of chosen from a list; the icon is about half of it. Sites that had *small*, *medium* or *large* get 36, 44 or 52 px.
- **Changes visible at once**: installing or updating MerTools and saving its settings now empty Joomla's page cache (*System - Page Cache*). Until now a site with the page cache on kept serving pages with the previous script and settings, so the dark mode looked unchanged after an update.

### 🐞 Fixes

- Installing or updating no longer shows the warning *JInstaller::Install: File does not exist […]/media/css* — the package declared an empty `media/css` folder (the styles are added inline), and Joomla does not unpack empty folders. The installation itself was not affected.

## 0.0.5 (Beta) — 2026-10-07

### 🌙 Dark mode — readable on every Gridbox site

- **Colours adapted to the site** (new option *Adapt the site colours*, on by default). Gridbox writes many colours straight into the styles of sections and elements — text, links, headings, the header and accordion backgrounds — so the dark palette alone did not reach them: dark text stayed on the dark background and a white header kept its light menu text. In dark mode light backgrounds are now darkened and text that is too dark for its background is lightened just enough to be easy to read (4.5:1), keeping its colour tone. Readable brand colours stay as they are, texts on vivid buttons keep the site's design, and content loaded later (tabs, search results) is adapted too. The light theme is not changed at all.
- **Bigger, clearer toggle button**: three sizes (small 36 px, medium 44 px — default, large 52 px), a stronger icon, and in the menu it takes the colour of the menu links.
- **Always visible**: on phones, where the menu is folded behind the hamburger, the button moves next to the hamburger; when that is not possible it floats in the corner. The floating button now stays in the visible part of the screen also on pages that are wider than the phone screen (it used to end up outside it).
- The position options are now named after what they do: *At the end of the menu*, *At the start of the menu*, *Floating button*.

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
