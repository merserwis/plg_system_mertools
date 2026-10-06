#!/bin/bash
# Build pkg_mertools-<version>.zip (plugin + admin menu component). Usage: ./build.sh
set -e
cd "$(dirname "$0")"
V=$(grep -oP '(?<=<version>)[^<]+' pkg_mertools.xml | head -1)
rm -rf build && mkdir -p build/packages
(cd plg_system_mertools && zip -qrX ../build/packages/plg_system_mertools.zip .)
(cd com_mertools && zip -qrX ../build/packages/com_mertools.zip .)
cp pkg_mertools.xml build/
rm -f "pkg_mertools-$V.zip"
(cd build && zip -qrX "../pkg_mertools-$V.zip" pkg_mertools.xml packages)
echo "pkg_mertools-$V.zip"
