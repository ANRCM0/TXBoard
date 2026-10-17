#!/usr/bin/env python3
"""Verify the exact generic Kernel candidate used by the proof extensions."""
import argparse
import hashlib
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--freeze', action='store_true')
parser.add_argument('--sync-resource', action='store_true')
args = parser.parse_args()
snapshot = root / 'contracts/kernel/v1.files.json'
files = {path.relative_to(root).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
         for path in sorted((root / 'api/app/Core/Kernel').rglob('*.php'))}
value = {'schema': 1, 'kernel_api': 1, 'stability': 'candidate', 'files': files}
resource = root / 'api/resources/contracts/kernel.v1.files.json'
if args.freeze:
    if snapshot.exists():
        raise SystemExit('Kernel candidate is already frozen; review version policy before replacing its evidence')
    snapshot.parent.mkdir(parents=True, exist_ok=True)
    snapshot.write_text(json.dumps(value, indent=2) + '\n', encoding='utf-8', newline='\n')
elif not snapshot.exists() or json.loads(snapshot.read_text(encoding='utf-8')) != value:
    raise SystemExit('Kernel v1 candidate changed; proof extensions require an unchanged generic Kernel')
if args.freeze or args.sync_resource:
    resource.write_text(json.dumps(value, indent=2) + '\n', encoding='utf-8', newline='\n')
elif not resource.exists() or json.loads(resource.read_text(encoding='utf-8')) != value:
    raise SystemExit('Packaged Kernel manifest is stale')
print(f'Kernel v1 candidate: {len(files)} frozen files verified')
