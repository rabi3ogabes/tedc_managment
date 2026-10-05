#!/usr/bin/env python3
"""Fails when strings.dart has the same key twice in one language map (flutter analyze rejects it)."""
import re, sys
s = open('mobile/lib/core/l10n/strings.dart', encoding='utf-8').read()
maps = re.split(r'static const Map<String, String> _(?:ar|en) = \{', s)[1:]
bad = 0
for i, m in enumerate(maps):
    body = m.split('\n  };')[0]
    seen = {}
    for n, line in enumerate(body.split('\n')):
        k = re.match(r"\s+'([^']+)':", line)
        if k:
            if k.group(1) in seen:
                print(f"map {i}: duplicate key {k.group(1)}"); bad += 1
            seen[k.group(1)] = n
sys.exit(1 if bad else 0)
