#!/usr/bin/env python3
"""Full committed Gateway + only current Internal User files; excludes parallel work."""
import hashlib
import io
import json
import pathlib
import re
import shutil
import subprocess
import sys
import tarfile

source = pathlib.Path(sys.argv[1]).resolve()
target = pathlib.Path(sys.argv[2]).resolve()
dependencies = pathlib.Path(sys.argv[3]).resolve()
if target.exists() or not (dependencies/'node_modules').is_dir():
    raise SystemExit('Expected new snapshot and existing Linux dependencies')
git = ['git', '-c', f'safe.directory={source}', '-C', str(source)]
revision = sys.argv[4] if len(sys.argv) > 4 else 'HEAD'
if revision != 'HEAD' and re.fullmatch(r'[0-9a-f]{40}', revision) is None:
    raise SystemExit('Optional baseline must be a full committed SHA')
head = subprocess.check_output([*git, 'rev-parse', revision], text=True).strip()
archive = subprocess.check_output([*git, 'archive', head])
target.mkdir(parents=True, mode=0o700)
with tarfile.open(fileobj=io.BytesIO(archive)) as bundle:
    bundle.extractall(target, filter='data')
overlays = [
    'apps/gateway/src/config/env.ts', 'apps/gateway/src/config/internal-user.ts',
    'apps/gateway/src/adapters/internal-user-v2.ts', 'apps/gateway/src/services/internal-user-client.ts',
    'apps/gateway/src/services/upstream.ts', 'apps/gateway/src/routes/user.ts', 'apps/gateway/src/routes/orders.ts',
    'apps/gateway/src/services/order-write-failure.ts', 'apps/gateway/src/routes/orders-write.ts',
    'apps/gateway/test/internal-user-v2.test.ts', 'apps/gateway/test/internal-user-client.test.ts',
    'apps/gateway/test/internal-order-list.test.ts', 'apps/gateway/test/internal-user-display.test.ts', 'apps/gateway/test/internal-user-content.test.ts',
    'tests/integration/internal-user-core-fixture.php', 'tests/integration/internal-user-core-gateway.mjs',
    'tests/integration/run-internal-user-linux.sh', 'tests/integration/prepare-internal-user-snapshot.py',
    '.env.example', 'contracts/gateway-v1.md', 'docs/internal-user-integration.md',
]
hashes = {}
for item in overlays:
    data = (source/item).read_bytes()
    if item.endswith('.sh'): data = data.replace(b'\r\n', b'\n')
    destination = target/item
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_bytes(data)
    hashes[item] = hashlib.sha256(data).hexdigest()
shutil.copytree(dependencies/'node_modules', target/'node_modules', symlinks=True)
for manifest in dependencies.glob('apps/*/node_modules'):
    relative = manifest.relative_to(dependencies)
    if not (target/relative).exists(): shutil.copytree(manifest, target/relative, symlinks=True)
(target/'internal-user-snapshot.json').write_text(json.dumps({'committed_gateway_head': head, 'slice_sha256': hashes}, indent=2))
print(json.dumps({'snapshot': str(target), 'committed_gateway_head': head}))
