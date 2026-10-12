#!/usr/bin/env python3
"""Archive a Gateway tree, then overlay the current Internal slice only.

Usage: <gateway-source> <new-target> <dependency-source> [revision] [source-prefix]
The prefix defaults to the source's path below its Git root (gateway/ in the
monorepo). Pass an explicit empty prefix for a pre-merge Gateway revision.
"""
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
git_root = next((path for path in (source, *source.parents) if (path/'.git').exists()), None)
if git_root is None:
    raise SystemExit('Gateway source must belong to a Git checkout')
git = ['git', '-c', f'safe.directory={git_root}', '-C', str(git_root)]
source_prefix = sys.argv[5] if len(sys.argv) > 5 else source.relative_to(git_root).as_posix()
if source_prefix == '.':
    source_prefix = ''
if source_prefix not in ('', 'gateway'):
    raise SystemExit('Source prefix must be gateway or empty for a pre-merge revision')
revision = sys.argv[4] if len(sys.argv) > 4 else 'HEAD'
if revision != 'HEAD' and re.fullmatch(r'[0-9a-f]{40}', revision) is None:
    raise SystemExit('Optional baseline must be a full committed SHA')
head = subprocess.check_output([*git, 'rev-parse', revision], text=True).strip()
tree = f'{head}:{source_prefix}' if source_prefix else f'{head}^{{tree}}'
tree_id = subprocess.check_output([*git, 'rev-parse', tree], text=True).strip()
# A wrong baseline/prefix must fail before creating a partial snapshot.
subprocess.check_call([*git, 'cat-file', '-e', f'{tree_id}:packages/theme-sdk/package.json'])
archive = subprocess.check_output([*git, 'archive', tree_id])
target.mkdir(parents=True, mode=0o700)
with tarfile.open(fileobj=io.BytesIO(archive)) as bundle:
    bundle.extractall(target, filter='data')
overlays = [
    'apps/gateway/src/app.ts', 'apps/gateway/src/index.ts', 'apps/gateway/test/internal-login.test.ts',
    'apps/gateway/test/internal-email-code.test.ts', 'apps/gateway/src/config/policies.ts',
    'apps/gateway/src/routes/account.ts', 'apps/gateway/src/routes/public.ts', 'apps/gateway/src/services/crypto.ts',
    'apps/gateway/src/config/env.ts', 'apps/gateway/src/config/internal-user.ts',
    'apps/gateway/src/adapters/internal-user-v2.ts', 'apps/gateway/src/services/internal-user-client.ts',
    'apps/gateway/src/services/upstream.ts', 'apps/gateway/src/routes/user.ts', 'apps/gateway/src/routes/orders.ts',
    'apps/gateway/src/services/order-write-failure.ts', 'apps/gateway/src/routes/orders-write.ts',
    'apps/gateway/test/internal-user-v2.test.ts', 'apps/gateway/test/internal-user-client.test.ts', 'apps/gateway/test/internal-public-read.test.ts',
    'apps/gateway/test/internal-order-list.test.ts', 'apps/gateway/test/internal-user-display.test.ts', 'apps/gateway/test/internal-user-content.test.ts',
    'tests/integration/internal-user-core-fixture.php', 'tests/integration/internal-user-core-gateway.mjs',
    'tests/integration/internal-email-router.php', 'tests/integration/internal-email-core-gateway.mjs',
    'tests/integration/run-internal-user-linux.sh', 'tests/integration/run-internal-email-linux.sh',
    'tests/integration/prepare-internal-user-snapshot.py',
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
metadata = {'committed_gateway_head': head, 'committed_gateway_tree': tree_id,
            'gateway_source_prefix': source_prefix, 'slice_sha256': hashes}
(target/'internal-user-snapshot.json').write_text(json.dumps(metadata, indent=2))
print(json.dumps({'snapshot': str(target), **metadata}))
