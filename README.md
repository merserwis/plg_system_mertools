# MerTools for Gridbox (Beta)

> **Beta version.** MerTools is still in beta: test each tool on your site before relying on it, and report problems in the [issues](https://github.com/merserwis/plg_system_mertools/issues).

A Joomla system plugin with fixes and optimisations for **Balbooa Gridbox** that cannot be done from Gridbox's own settings. Each fix is a separate tool that can be turned on or off. **The Gridbox core files are never changed**, so Gridbox updates install cleanly.

Built for the Merserwis shop (Joomla 6, Balbooa Gridbox, PHP 8.5), in the same style as [Better Search for Gridbox](https://github.com/merserwis/plg_system_bettersearch).

## Tools

### 🔗 Canonical URL — collapse duplicate slashes

Gridbox serves a page under any number of slashes in the address:

```
/oferta
//oferta
//////oferta
/oferta/mierniki////mierniki-instalacji-elektrycznych
```

All of these return the same page with **status 200**, so search engines see several addresses for one page (**duplicate content**) and the link equity is split across them.

MerTools redirects every such address to the one clean address with single slashes, e.g. `//////oferta/mierniki` → `/oferta/mierniki`, with a **301** (permanent, recommended) or **302** (temporary) redirect.

- Only the **path** is normalized; the query string after `?` is left exactly as it is (a `//` there can be a real value, e.g. `?return=https://…`).
- Percent-encoded slashes (`%2F`) are part of a segment value and are not touched.
- The address always stays on the same host, so this can never redirect off-site.
- Runs in `onAfterInitialise`, before routing, so Gridbox's own routing is untouched.

**Settings:** tab *Canonical URL* — on/off and the redirect type.

#### If it still opens with duplicate slashes and does not redirect (Cloudflare / CDN)

If the addresses keep opening with duplicate slashes (status 200, no redirect) even with the tool on, the site is behind **Cloudflare** (or another CDN/proxy) that **cleans the address before it reaches the server** — it merges the extra slashes itself, so this plugin never sees them and cannot redirect them. The redirect then has to be set at Cloudflare, the only place that still knows the original address. It is a one-time setup and needs no paid plan (no regular expressions are used):

1. Log in to `dash.cloudflare.com` and choose the domain.
2. Open **Rules → Redirect Rules** and click **Create rule**.
3. Rule name, e.g. *Collapse duplicate slashes*.
4. Under **When incoming requests match**, switch to **Edit expression** and paste:
   ```
   (raw.http.request.uri ne http.request.uri and (http.request.method eq "GET" or http.request.method eq "HEAD"))
   ```
5. Under **Then**, set **Type = Dynamic** and the **URL expression**:
   ```
   concat("https://", http.host, http.request.uri)
   ```
6. **Status code 301**, **Preserve query string = off**.
7. Click **Deploy**.
8. If it still does not redirect, open **Rules → Settings → URL Normalization** and turn on *Normalize incoming URLs*.

Test by opening `https://your-site/////oferta` — it should return a **301** to the single-slash address. Leave this plugin's tool on as well: it still handles any request that reaches the server directly (not through the CDN).

How it works: `raw.http.request.uri` is the original address the visitor sent; `http.request.uri` is the address after Cloudflare's normalization (single slashes). When they differ, the rule sends a 301 to the clean one. A clean address has nothing to normalize, so the two are equal and no redirect loop happens.

### 🛒 Shop — links to a product option

A link to a chosen product option or set (Gridbox adds it to the address, e.g. `?Zestawy+Metrel+MI+3155=…`) opens the product with that option selected, with its price, SKU and images, so a customer can be sent a link to a specific set. Without it, Gridbox opened such links with nothing selected whenever the option group name had a space, a dot or a square bracket, because PHP renames those parameters, or when the option name had a symbol written as HTML code (e.g. a red dot `&#128308;`), because Joomla's input filter decodes it. Server side, Gridbox pages only, nothing in the Gridbox files is changed. Works with every kind of Gridbox option (drop-down list, tags, colours, images, radio buttons) and with several option groups in one product. On by default, tab *Shop*.

### 📞 Phone numbers — click to call

Phone numbers written as plain text (contact rows, footers, product pages) become `tel:` links, so a tap on a phone dials them — no need to add links by hand in Gridbox. Polish numbers in the usual forms (`22 531 00 94`, `(22) 531-00-94`, `533 394 222`, with or without +48) and international numbers starting with `+` are found; links, buttons, forms, code and numbers that are not phones (NIP, REGON, KRS, bank accounts, serial numbers, prices, standards, fax numbers) are left alone. The links look like the text around them (or like the site's links), the country code is added for dialling from abroad (default +48), and areas can be excluded with a CSS selector. A small script does it in the browser when it is idle; the page content is not changed. On by default, tab *Phone numbers*.

### 📱 Page layout — no sideways shift on phones

A Gridbox element that sticks out to the right (a row with a slide-in animation or a motion effect, an off-canvas menu) makes phones lay the page out wider than the screen. Gridbox hides that part on `<body>`, but the browser still widens the page, so everything fixed to the screen edges — the hamburger in a fixed header, the accessibility button, floating buttons — ends up off-screen. MerTools keeps the page as wide as the screen (`overflow-x: clip` on `html` and `body`; unlike `hidden` it creates no scroll container, so sticky elements and scrolling keep working). On by default, tab *Page layout*.

### 🌙 Dark mode

An elegant dark theme for the whole front-end, with a toggle button in the header. It is built from Gridbox's own CSS colour variables (which the theme sets on `html body`), so overriding them for a dark palette flips the whole site cleanly — nothing is inverted, so brand colours and images stay intact. The palettes are soft dark greys and blues, **never a harsh pure black**, so they are easy on the eyes.

- **Palettes:** Slate (default), Charcoal, Midnight, Warm, Sepia (dark brown paper, cream text), Dim, or Custom (every colour set by hand).
- **Intensity:** a slider 0–100 — softer (lighter greys) or deeper (never pure black); 50 is the palette as designed. A **live preview** in the settings shows the result while you change the palette, intensity, colours and accent.
- **Palette dots:** when the pointer is over the toggle button (or on a long press on phones), dots in the colours of the palettes slide out; a visitor picks e.g. Sepia instead of Slate and dark mode switches to it, remembered. Which palettes are offered is set in the settings.
- **Accent as in Gridbox:** keep the site's brand accent in dark mode, or choose another.
- **Default for new visitors:** Automatic (follows the visitor's system setting), Light or Dark. A remembered choice always wins, and the theme is applied before the first paint (no flash).
- **Adapted colours:** Gridbox writes many colours straight into section and element styles (text, links, headings, header and accordion backgrounds), so the variables alone do not reach them. Bright brand-coloured buttons are drawn as outlined dark buttons (or softened, or kept — an option). In dark mode the script darkens light backgrounds (also the light colours of gradients, and backgrounds that Gridbox adds later — lazy sections, the sticky header) and lightens text that is too dark for its background just enough for a 4.5:1 contrast, keeping its hue; readable brand colours and texts on vivid buttons stay as designed, and content loaded later is adapted too. The fixes are data attributes used only by the dark theme, so the light theme is unchanged. Option *Adapt the site colours* (on by default).
- **Logo for dark mode:** a second logo (light lettering) chosen in the media manager replaces the header logo in dark mode, also with Gridbox lazy loading; other logos via a CSS selector.
- **Toggle button:** at the end (or start) of the menu, in the colour of the menu links, in the size you type in pixels (24–96, default 44). On phones, where the menu is folded behind the hamburger, it moves next to the hamburger, or floats in the corner of the visible screen. A floating button can also be chosen: bottom right, bottom left, above or next to the site’s accessibility button, with the distances from the edges in pixels. The header selector can be changed for other templates. Phones can have their own position: next to the hamburger, floating, by the accessibility button, inside any element given by a CSS selector, or hidden — from a width you set (default 768 px).
- **Options:** smooth cross-fade on switch; optionally dim bright photos in dark mode.
- **Light on the page:** colours are read in batches, what is on screen first and the rest while the browser is idle, so scrolling never waits; a click switches the theme at once. The package ships minified scripts (8 KB gzipped).

How it works: the toggle sets `data-mertools-theme="dark"` on `<html>`; the plugin's CSS then redefines the Gridbox variables (`--bg-primary`, `--text`, `--title`, `--border`, …) for that state at a higher specificity (`html[data-mertools-theme="dark"] body`). The choice is kept in `localStorage`. The Gridbox core files are not touched.

**Settings:** tab *Dark mode*. Saving the settings, installing and updating empty Joomla's page cache (`administrator/cache/page`, or the configured cache path), so the change is visible at once. If the site is behind Cloudflare, its cache needs no purge — the script address changes with every version.

## Languages

English (default) and Polish. The settings follow the language of the Joomla administrator; for any other language — or a text a translation lacks — English is shown.

## Installation

1. Download `pkg_mertools-<version>.zip` from [Releases](https://github.com/merserwis/plg_system_mertools/releases).
2. Install it in Joomla (Extensions → Install). The package installs the system plugin and an administrator menu entry.
3. The plugin is enabled on a fresh install. Open its settings from **Components → MerTools for Gridbox** (the menu entry opens the plugin's settings page directly).

## Package structure

| Extension | What it is |
|---|---|
| `plg_system_mertools` | The system plugin — the tools and their settings. |
| `com_mertools` | A thin administrator component: the sidebar menu entry that opens the plugin settings. |

## Requirements

- Joomla 6 (tested with Joomla 6.1.4)
- Balbooa Gridbox
- PHP 8.2+ (tested with PHP 8.5.11)

## License

GNU General Public License version 3. Copyright © 2026 Merserwis.
