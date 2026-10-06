#!/bin/bash
# Re-publish the SAME version to an existing repo+release: push main, replace the release asset
# with the current package (new sha). Token in /dev/shm/gh-token. Usage: tools/republish_same_version.sh 0.0.1
set -e
V=${1:?version}
R=merserwis/plg_system_mertools
cd ~/dev/mertools
Z=pkg_mertools-$V.zip
[ -f "$Z" ] || ./build.sh
S=$(sha256sum "$Z" | cut -d' ' -f1)
grep -q "<sha256>$S</sha256>" update.xml || { echo "sha of $Z not in update.xml - aborting"; exit 1; }
T=$(cat /dev/shm/gh-token); AUTH="Authorization: Bearer $T"
B=$(printf 'x-access-token:%s' "$T" | base64 -w0)
git -c http.extraHeader="Authorization: Basic $B" push https://github.com/$R.git main:main 2>&1 | grep -vi authorization | tail -2
# find release id by tag
RID=$(curl -s -H "$AUTH" "https://api.github.com/repos/$R/releases/tags/v$V" | python3 -c "import json,sys;print(json.load(sys.stdin).get('id',''))")
[ -n "$RID" ] || { echo "release v$V not found"; exit 1; }
# delete old asset(s) with the same name, then upload the new one
for AID in $(curl -s -H "$AUTH" "https://api.github.com/repos/$R/releases/$RID/assets" | python3 -c "import json,sys;[print(a['id']) for a in json.load(sys.stdin) if a['name']=='$Z']"); do
  curl -s -o /dev/null -w "delete old asset $AID: %{http_code}\n" -X DELETE -H "$AUTH" "https://api.github.com/repos/$R/releases/assets/$AID"
done
curl -s -o /dev/null -w "upload new asset: %{http_code}\n" -X POST -H "$AUTH" -H "Content-Type: application/zip" --data-binary @"$Z" "https://uploads.github.com/repos/$R/releases/$RID/assets?name=$Z"
shred -u /dev/shm/gh-token && echo "token removed"
sleep 6
D=$(curl -sL https://github.com/$R/releases/download/v$V/$Z | sha256sum | cut -d' ' -f1)
echo "download sha matches new build: $([ "$D" = "$S" ] && echo YES || echo NO)"
curl -sL https://raw.githubusercontent.com/$R/main/update.xml | grep -o "<sha256>$S</sha256>" | head -1 && echo "update.xml on main has new sha" || echo "update.xml sha mismatch on main"
