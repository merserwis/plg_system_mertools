#!/bin/bash
# Build pkg_mertools-<version>.zip (plugin + admin menu component). Usage: ./build.sh
set -e
cd "$(dirname "$0")"
V=$(grep -oP '(?<=<version>)[^<]+' pkg_mertools.xml | head -1)
rm -rf build && mkdir -p build/packages
# the plugin with minified scripts next to the sources (Joomla loads the .min.js unless debug is on)
cp -r plg_system_mertools build/plg
if command -v terser >/dev/null; then
  for f in build/plg/media/js/*.js; do
    case "$f" in *.min.js) continue ;; esac
    terser "$f" -c passes=2 -m --comments false -o "${f%.js}.min.js"
  done
else
  echo "terser not found: package without minified scripts" >&2
fi
(cd build/plg && zip -qrX ../packages/plg_system_mertools.zip .)
(cd com_mertools && zip -qrX ../build/packages/com_mertools.zip .)
cp pkg_mertools.xml build/
rm -f "pkg_mertools-$V.zip"
(cd build && zip -qrX "../pkg_mertools-$V.zip" pkg_mertools.xml packages)
echo "pkg_mertools-$V.zip"
