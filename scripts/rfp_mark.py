#!/usr/bin/env python3
"""Mark requirements in docs/rfp/gap-register.md: python3 scripts/rfp_mark.py marks.json  (id -> [status, evidence])"""
import json, re, sys

ICON = {'available': '✅ Available', 'partial': '🟡 Partial', 'missing': '🔴 Missing'}
path = 'docs/rfp/gap-register.md'
marks = json.load(open(sys.argv[1]))
lines = open(path, encoding='utf-8').read().split('\n')
out, i = [], 0
while i < len(lines):
    line = lines[i]
    m = re.match(r'- \[[ x]\] \*\*([A-Z]+-\d+)\*\*', line)
    if m and m.group(1) in marks:
        status, ev = marks[m.group(1)]
        out.append(line.replace('- [ ]', '- [x]', 1))
        out.append(f'  _Now:_ {ICON[status]} — {ev}')
        i += 2
        continue
    t = re.match(r'\| ([A-Z]+-\d+)( ★)? \| (.*?) \| (✅ Available|🟡 Partial|🔴 Missing) \| (.*) \| (\S+) \|$', line)
    if t and t.group(1) in marks:
        status, ev = marks[t.group(1)]
        ph = '—' if status == 'available' else t.group(6)
        out.append(f'| {t.group(1)}{t.group(2) or ""} | {t.group(3)} | {ICON[status]} | {ev} | {ph} |')
        i += 1
        continue
    out.append(line)
    i += 1
open(path, 'w', encoding='utf-8').write('\n'.join(out))
print('updated', len(marks))
