#!/usr/bin/env python3
"""Static checks only: closed admin bindings, browser surface, and legacy-route accounting."""

import collections
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
contract = json.loads((ROOT / 'contracts/core-api/operations.v1.json').read_text(encoding='utf-8'))
bindings = json.loads((ROOT / 'contracts/core-api/admin-bindings.v1.json').read_text(encoding='utf-8'))['bindings']
migration = json.loads((ROOT / 'contracts/core-api/admin-migration.v1.json').read_text(encoding='utf-8'))
surface = contract['surfaces']['admin']
assert len(surface) == len(set(surface)) == 172
registry = (ROOT / 'api/app/Domains/InternalApi/OperationRegistry.php').read_text(encoding='utf-8')
for name, binding in bindings.items():
    spec = contract['operations'][name]
    assert name in surface and spec['domains'] == ['admin'] and spec['actor'] == 'admin', name
    assert spec['permission'] and spec['idempotency'] in ('read', 'workflow', 'domain'), name
    assert not re.search(r"'" + re.escape(name) + r"'\s*=>", registry), 'Duplicate registry binding: ' + name
    assert re.fullmatch(r'[A-Za-z][A-Za-z0-9]*Controller', binding['controller']), name
    path = ROOT / 'api/app/Http/Controllers/Txapi/Admin' / (binding['controller'] + '.php')
    assert 'public function ' + binding['method'] + '(' in path.read_text(encoding='utf-8'), name
    assert set(binding['parameters'].values()) <= set(spec['input']['required']), name
    if binding['payload'] is not None:
        assert spec['input']['properties'][binding['payload']]['type'] == 'array', name
    assert spec['input']['additionalProperties'] is False, name
    assert spec['input']['type'] == 'object', name
routes_source = (ROOT / migration['source']).read_text(encoding='utf-8')
start = routes_source.index("Route::prefix('admin/{admin_path}')")
end = routes_source.index("Route::post('auth/login'", start)
part = routes_source[start:end]
pattern = r"Route::(get|post|put|patch|delete)\('([^']+)'\s*,\s*\[([\\A-Za-z0-9_]+)::class,\s*'([^']+)'\]"
actual = {(verb.upper(), path, controller.split('\\')[-1], method)
          for verb, path, controller, method in re.findall(pattern, part)}
recorded = {(row['method'], row['path'], row['controller'], row['action']) for row in migration['routes']}
assert actual == recorded, {'missing': sorted(actual - recorded), 'stale': sorted(recorded - actual)}
for row in migration['routes']:
    assert row['status'] in ('bound', 'native-replacement', 'native-owner-required', 'dedicated-adapter-required', 'adapter-required')
    assert row['note'] and set(row['operations']) <= set(surface), row
    if row['status'] in ('bound', 'native-replacement'):
        assert row['operations'], row
assert len(recorded) == len(migration['routes']), 'Duplicate legacy route'
print(json.dumps({'check': 'static admin unification', 'operations': len(contract['operations']),
                  'adminSurface': len(surface), 'controllerBindings': len(bindings),
                  'legacyRoutes': len(recorded),
                  'migration': dict(collections.Counter(row['status'] for row in migration['routes'])),
                  'runtimeVerified': False}, sort_keys=True))
