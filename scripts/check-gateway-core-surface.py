#!/usr/bin/env python3
"""Static transport vocabulary and reviewed browser call reachability."""
import ast
import json
import re
from pathlib import Path
ROOT = Path(__file__).resolve().parent.parent
contract = json.loads((ROOT / 'contracts/core-api/operations.v1.json').read_text(encoding='utf-8'))['operations']
source = (ROOT / 'gateway/apps/gateway/src/services/native-internal.ts').read_text(encoding='utf-8')
section = source.split('export const NATIVE_INTERNAL_OPERATIONS = {', 1)[1].split('} as const', 1)[0]
entries = []
for line in section.splitlines():
    key, _, value = line.strip().removesuffix(',').partition(': ')
    if key.startswith(chr(39)) and value.startswith('['):
        entries.append((ast.literal_eval(key), ast.literal_eval(value)))
assert len(entries) == len(dict(entries)), 'Duplicate native operation'
known = dict(entries)
assert set(known) == set(contract) | {'system.capabilities.get', 'settings.public.get', 'subscription.links.list', 'subscription.links.issue', 'subscription.links.revoke'}, {'missing': sorted(set(contract)-set(known)), 'unknown': sorted(set(known)-set(contract)-{'system.capabilities.get', 'settings.public.get', 'subscription.links.list', 'subscription.links.issue', 'subscription.links.revoke'})}
for name, spec in contract.items():
    assert set(known[name]) == set(spec['domains']), name
source = (ROOT / 'gateway/apps/gateway/src/contracts/core-user.ts').read_text(encoding='utf-8')
section = source.split('export const CORE_USER_OPERATIONS = [', 1)[1].split('] as const', 1)[0]
surface = re.findall(r"'([^']+)'", section)
assert len(surface) == len(set(surface)), "Duplicate user browser operation"
for name in surface:
    assert name in contract and 'user' in contract[name]['domains'], name
for path in (ROOT / 'web/user/src/native').rglob('*'):
    if path.suffix not in ('.ts', '.vue'): continue
    for name in re.findall(r"(?:user[.]call|actions[.]run)[(] *'([^']+)'", path.read_text(encoding='utf-8')):
        assert name in surface, str(path.relative_to(ROOT)) + ": " + name
print(json.dumps({'check': 'static Core transport reachability', 'internal_operations': len(known), 'browser_user_operations': len(surface), 'runtimeVerified': False}, sort_keys=True))
