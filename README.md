# MerTools for Gridbox

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
