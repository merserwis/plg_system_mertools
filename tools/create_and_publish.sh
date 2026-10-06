#!/bin/bash
# First publish of MerTools: create the GitHub repo merserwis/plg_system_mertools (if absent),
# push main, create release v<version> with the package, verify from outside, remove the token.
# Token in /dev/shm/gh-token (needs repo-creation rights). Usage: tools/create_and_publish.sh 0.0.1
set -e
V=${1:?version}
R=merserwis/plg_system_mertools
cd ~/dev/mertools
Z=pkg_mertools-$V.zip
[ -f "$Z" ] || ./build.sh
S=$(sha256sum "$Z" | cut -d' ' -f1)
grep -q "<sha256>$S</sha256>" update.xml || { echo "sha of $Z not in update.xml - aborting"; exit 1; }
T=$(cat /dev/shm/gh-token)
AUTH="Authorization: Bearer $T"
# 1. create the repo if it does not exist (user account, public)
code=$(curl -s -o /tmp/claude-1000/repo.json -w '%{http_code}' -H "$AUTH" -H "Accept: application/vnd.github+json" https://api.github.com/repos/$R)
if [ "$code" = "404" ]; then
  curl -s -o /tmp/claude-1000/repo.json -w "create repo %{http_code}\n" -X POST -H "$AUTH" -H "Accept: application/vnd.github+json" \
    https://api.github.com/user/repos \
    -d '{"name":"plg_system_mertools","description":"MerTools for Gridbox — fixes and optimisations for Balbooa Gridbox that its own settings cannot do (Joomla 6, PHP 8.5), without touching the Gridbox core.","homepage":"https://www.merserwis.pl","private":false,"has_issues":true,"has_wiki":false,"has_projects":false}'
else
  echo "repo exists (HTTP $code)"
fi
# 2. push main
B=$(printf 'x-access-token:%s' "$T" | base64 -w0)
git -c http.extraHeader="Authorization: Basic $B" push -u https://github.com/$R.git main:main 2>&1 | grep -v -i authorization | tail -2
# 3. create the release with notes from the version's CHANGELOG section
python3 -c "import json,re;body=re.search(r'^## $V .*?\n(.*?)(?=^## |\Z)',open('CHANGELOG.md',encoding='utf-8').read(),re.S|re.M);print(json.dumps({'tag_name':'v$V','target_commitish':'main','name':'$V','body':(body.group(1).strip() if body else '$V'),'draft':False,'prerelease':False}))" > /tmp/claude-1000/rel.json
curl -s -o /tmp/claude-1000/r.json -w "release %{http_code}\n" -X POST -H "$AUTH" -H "Accept: application/vnd.github+json" https://api.github.com/repos/$R/releases --data @/tmp/claude-1000/rel.json
RID=$(python3 -c "import json;print(json.load(open('/tmp/claude-1000/r.json')).get('id',''))")
[ -n "$RID" ] || { echo "no release id; response:"; cat /tmp/claude-1000/r.json | head -c 400; exit 1; }
curl -s -o /tmp/claude-1000/a.json -w "asset %{http_code}\n" -X POST -H "$AUTH" -H "Content-Type: application/zip" --data-binary @"$Z" "https://uploads.github.com/repos/$R/releases/$RID/assets?name=$Z"
rm -f /tmp/claude-1000/rel.json /tmp/claude-1000/r.json /tmp/claude-1000/a.json /tmp/claude-1000/repo.json
shred -u /dev/shm/gh-token && echo "token removed"
sleep 6
D=$(curl -sL https://github.com/$R/releases/download/v$V/$Z | sha256sum | cut -d' ' -f1)
U=$(curl -sL https://raw.githubusercontent.com/$R/main/update.xml | grep -o "<version>$V</version>" | head -1)
echo "download sha ok: $([ "$D" = "$S" ] && echo yes || echo NO) | update.xml lists $V: ${U:+yes}"
