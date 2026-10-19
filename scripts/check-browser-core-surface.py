#!/usr/bin/env python3
"""Check the generated public browser allowlists without starting the application."""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
contract = json.loads((ROOT / 'contracts/core-api/operations.v1.json').read_text(encoding='utf-8'))
surface = json.loads((ROOT / 'api/resources/contracts/browser-surfaces.v1.json').read_text(encoding='utf-8'))
assert surface == {'version': 1, **{domain: contract['surfaces'][domain] for domain in ('user', 'admin')}}
for domain in ('user', 'admin'):
    ids = surface[domain]
    assert len(ids) == len(set(ids)) and len(ids) <= contract['limits']['surface_operations']
    for name in ids:
        assert name in contract['operations'] and domain in contract['operations'][name]['domains'], name
    source = (ROOT / f'packages/core-sdk/src/{domain}.generated.ts').read_text(encoding='utf-8')
    match = re.search(r'CORE_' + domain.upper() + r'_OPERATIONS = (\[[^\n]+\]) as const', source)
    assert match and json.loads(match[1]) == ids, 'SDK surface drift: ' + domain
for relative, receiver in (('web/user/src/native', r'(?:user[.]call|actions[.]run)'),
                           ('web/admin/src/native', r'admin[.](?:read|write)')):
    domain = 'user' if '/user/' in relative else 'admin'
    for path in (ROOT / relative).rglob('*'):
        if path.suffix not in ('.ts', '.tsx', '.vue'): continue
        for name in re.findall(receiver + r"[(] *'([^']+)'", path.read_text(encoding='utf-8')):
            assert name in surface[domain], str(path.relative_to(ROOT)) + ': ' + name
assert not (ROOT / 'gateway/apps/gateway').exists(), 'Retired Gateway process restored'
print(json.dumps({'check': 'Core browser surface reachability',
                  'user': len(surface['user']), 'admin': len(surface['admin']),
                  'runtimeVerified': False}, sort_keys=True))
