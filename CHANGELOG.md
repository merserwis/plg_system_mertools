# Changelog

All changes of **MerTools for Gridbox**, newest first. Each version is also published as a [GitHub release](https://github.com/merserwis/plg_system_mertools/releases) with its installation package.

## 0.0.1 — 2026-10-06

First version.

### 🔗 Canonical URL (collapse duplicate slashes)

- Gridbox serves a page under any number of slashes in the address — `/oferta`, `//oferta` and `//////oferta` all open the same page with status 200. Search engines then see several addresses for one page (duplicate content) and the ranking is split.
- MerTools redirects every such address to the one clean address with single slashes (`//////oferta/mierniki` → `/oferta/mierniki`). Only the path is changed; the query string after `?` is left as it is, and the address always stays on this site.
- Settings (tab *Canonical URL*): on/off and the redirect type (301 permanent, recommended, or 302 temporary).
- Runs before routing, so it does not interfere with Gridbox's own routing. The Gridbox core files are not changed.
