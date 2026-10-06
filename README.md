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
