#!/usr/bin/env python3
"""Keep docs/rfp/gap-register.md consistent with its own full register table (the source of truth):
the summary counts, the «Open gaps by phase» checklist (tick + current status line) and the 31 mandatory items,
whose status is derived from the requirements each one is made of.  Usage: python3 scripts/rfp_sync.py"""
import re

path = 'docs/rfp/gap-register.md'
ICON = {'available': '✅ Available', 'partial': '🟡 Partial', 'missing': '🔴 Missing'}
EMO = {'✅': 'available', '🟡': 'partial', '🔴': 'missing'}

# The requirements behind each mandatory item (items 1–6 are vendor qualifications).
MANDATORY = {
    7: ['UX-05', 'UX-06', 'UX-07', 'UX-08'], 8: ['HOM-06'], 9: ['TYP-04'], 10: ['TYP-05'], 11: ['TYP-07', 'TYP-11', 'TYP-13', 'TYP-15'],
    12: ['TYP-18'], 13: ['CAR-01'], 14: ['EXT-01', 'EXT-02', 'EXT-03'], 15: ['NDS-01', 'NDS-02', 'NDS-03', 'NDS-04', 'NDS-07'],
    16: ['ENR-01', 'ENR-02', 'ENR-03', 'ENR-06', 'ENR-07'], 17: ['REG-01', 'REG-03', 'REG-04', 'REG-05'], 18: ['ATT-02', 'ATT-07'], 19: ['CNT-02'],
    20: ['PAS-01', 'PAS-10', 'PAS-14'], 21: ['ROM-08'], 22: ['WDR-01', 'WDR-02', 'WDR-03', 'WDR-04', 'WDR-05'],
    23: ['SRV-01', 'SRV-02', 'SRV-03', 'SRV-04', 'SRV-05', 'SRV-06', 'SRV-07', 'SRV-08'], 24: ['KIT-01'], 25: ['PLC-01', 'PLC-02', 'PLC-03', 'PLC-04', 'PLC-05'],
    26: ['EVL-01', 'EVL-02', 'EVL-06'], 27: ['EXM-01', 'EXM-03', 'EXM-04', 'EXM-05', 'EXM-10'], 28: ['NTF-01', 'NTF-02', 'NTF-03', 'NTF-05', 'NTF-06', 'NTF-13', 'NTF-14'],
    29: ['CPD-01', 'CPD-02', 'CPD-03', 'CPD-04', 'CPD-05', 'CPD-06'], 30: ['RBA-13'], 31: ['AI-01', 'AI-02', 'AI-03', 'AI-04', 'AI-05'],
}

text = open(path, encoding='utf-8').read()
lines = text.split('\n')
row_re = re.compile(r'^\| ([A-Z]+-\d+)( ★)? \| (.*?) \| (✅|🟡|🔴) (?:Available|Partial|Missing) \| (.*) \| (\S+) \|$')
reg = {}
for i, l in enumerate(lines):
    m = row_re.match(l)
    if m:
        reg[m.group(1)] = {'status': EMO[m.group(4)], 'evidence': m.group(5).strip()}

out = []
i = 0
while i < len(lines):
    l = lines[i]
    m = re.match(r'- \[[ x]\] \*\*([A-Z]+-\d+)\*\*', l)
    if m and m.group(1) in reg:
        r = reg[m.group(1)]
        out.append(re.sub(r'^- \[[ x]\]', '- [x]' if r['status'] == 'available' else '- [ ]', l))
        if i + 1 < len(lines) and lines[i + 1].lstrip().startswith('_Now:_'):
            i += 1
        out.append(f"  _Now:_ {ICON[r['status']]} — {r['evidence']}")
        i += 1
        continue
    mm = re.match(r'^\| (\d+) \| (.+?) \| (✅|🟡|🔴|⚪) (\w+) \| (.*?) \|$', l)
    if mm and int(mm.group(1)) in MANDATORY:
        ids = MANDATORY[int(mm.group(1))]
        st = [reg[x]['status'] for x in ids]
        status = 'missing' if 'missing' in st else 'partial' if 'partial' in st else 'available'
        open_ids = [x for x in ids if reg[x]['status'] != 'available']
        done = [x for x in ids if x not in open_ids]
        note = (', '.join(ids) + ' — all available.') if not open_ids else ('Open: ' + ', '.join(f"{x} ({reg[x]['status']})" for x in open_ids) + (f"; {', '.join(done)} available." if done else '.'))
        out.append(f"| {mm.group(1)} | {mm.group(2)} | {ICON[status]} | {note} |")
        i += 1
        continue
    out.append(l)
    i += 1

text = '\n'.join(out)
counts = {k: sum(1 for r in reg.values() if r['status'] == k) for k in ICON}
text = re.sub(r'\| 278 \| \d+ \| \d+ \| \d+ \| \d+ \|', f"| {len(reg)} | {counts['available']} | {counts['partial']} | {counts['missing']} | {counts['partial'] + counts['missing']} |", text, count=1)
open(path, 'w', encoding='utf-8').write(text)
print(len(reg), counts)
