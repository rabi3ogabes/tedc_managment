#!/usr/bin/env python3
"""Writes docs/rfp/phase-tests.json: for every implementation phase, the backend test files its commits added or changed.

Run from the repository root:  python3 scripts/rfp_phase_tests.py
The deliverables traceability matrix reads it to link a requirement to the tests of the phase that delivered it.
"""
import json
import re
import subprocess
from collections import defaultdict

log = subprocess.run(['git', 'log', '--name-only', '--format=@@%s'], capture_output=True, text=True, check=True).stdout
phases = defaultdict(set)
current = None
for line in log.splitlines():
    if line.startswith('@@'):
        m = re.match(r'@@Phase (\d+)', line)
        current = int(m.group(1)) if m else None
    elif current is not None and line.startswith('backend/tests/') and line.endswith('.php'):
        phases[current].add(line.split('/')[-1])
out = {str(k): sorted(v) for k, v in sorted(phases.items())}
with open('docs/rfp/phase-tests.json', 'w') as f:
    json.dump(out, f, indent=1)
    f.write('\n')
print({k: len(v) for k, v in out.items()})
