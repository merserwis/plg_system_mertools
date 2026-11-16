#!/usr/bin/env python3
"""Joomla's changelog of MerTools (changelog.xml, the file <changelogurl> points to) made from CHANGELOG.md.

Joomla shows it behind the Changelog button of Extensions: Update (the version offered) and Extensions:
Manage (the version installed): the short list of a version is the titles (###) of its section, without
their emoji. A title is a new feature (addition) in a x.y.0 version and a fix in a x.y.z one; a comment at
its end says otherwise: <!-- fix -->, <!-- addition -->, <!-- change -->, <!-- security -->, <!-- remove -->.

Usage: tools/changelog_xml.py  (writes changelog.xml next to CHANGELOG.md)
"""
import re
import sys
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
KINDS = ('security', 'fix', 'addition', 'change', 'remove')
# Joomla's changelog (Changelog::characterData) makes every piece of text its XML parser hands over a
# point of its own, and the parser cuts the text at a character beyond ASCII and at an entity
# ("Orders — the status" came out as two points): the titles are written in plain ASCII, without & < >
PLAIN = {' — ': ': ', '—': '-', '–': '-', '“': '"', '”': '"', '‘': "'", '’': "'", '…': '...', '&': 'and', '<': '', '>': ''}


def plain(title):
    for a, b in PLAIN.items():
        title = title.replace(a, b)
    title = unicodedata.normalize('NFKD', title).encode('ascii', 'ignore').decode()

    return re.sub(r'\s+', ' ', title).strip()


def versions(text):
    """[(version, [(kind, title)])], newest first, as in CHANGELOG.md."""
    out = []
    for m in re.finditer(r'^## (\d+\.\d+\.\d+)\b.*?$(.*?)(?=^## |\Z)', text, re.M | re.S):
        version, body = m.group(1), m.group(2)
        patch = int(version.split('.')[2]) > 0
        items = []
        for title in re.findall(r'^### (.+?)\s*$', body, re.M):
            mark = re.search(r'<!--\s*(\w+)\s*-->', title)
            kind = mark.group(1) if mark and mark.group(1) in KINDS else ('fix' if patch else 'addition')
            title = re.sub(r'<!--.*?-->', '', title)
            # the emoji at the start (and the variation selector some have) go: Joomla shows plain text
            title = plain(re.sub(r'^[^\w"“(]+', '', title))
            if title:
                items.append((kind, title))
        out.append((version, items))
    return out


def xml(entries):
    lines = ['<?xml version="1.0" encoding="utf-8"?>', '<changelogs>']
    for version, items in entries:
        if not items:
            continue
        lines += ['    <changelog>', '        <element>pkg_mertools</element>', '        <type>package</type>',
                  '        <version>%s</version>' % version]
        for kind in KINDS:
            mine = [t for k, t in items if k == kind]
            if mine:
                lines.append('        <%s>' % kind)
                lines += ['            <item>%s</item>' % t for t in mine]
                lines.append('        </%s>' % kind)
        lines.append('    </changelog>')
    lines.append('</changelogs>')
    return '\n'.join(lines) + '\n'


if __name__ == '__main__':
    entries = versions((ROOT / 'CHANGELOG.md').read_text(encoding='utf-8'))
    if not entries or not entries[0][1]:
        sys.exit('no version with titles in CHANGELOG.md')
    (ROOT / 'changelog.xml').write_text(xml(entries), encoding='utf-8')
    print('changelog.xml: %d versions, newest %s' % (len(entries), entries[0][0]))
