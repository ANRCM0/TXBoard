#!/usr/bin/env python3
"""Archive committed Core and overlay only the reviewed internal slice (not concurrent work)."""
import io
import json
import pathlib
import re
import subprocess
import sys
import tarfile

source = pathlib.Path(sys.argv[1]).resolve()
target = pathlib.Path(sys.argv[2]).resolve()
vendor = pathlib.Path(sys.argv[3]).resolve()
if target.exists() or not (source / 'api/routes/txapi.php').is_file() or not (vendor / 'autoload.php').is_file():
    raise SystemExit('Expected a new isolated target, Core repository and existing vendor')
revision = sys.argv[4] if len(sys.argv) > 4 else 'HEAD'
if revision != 'HEAD' and re.fullmatch(r'[0-9a-f]{40}', revision) is None:
    raise SystemExit('Optional baseline must be a full committed SHA')
head = subprocess.check_output(['git', '-c', f'safe.directory={source}', '-C', str(source), 'rev-parse', revision], text=True).strip()
archive = subprocess.check_output(['git', '-c', f'safe.directory={source}', '-C', str(source), 'archive', head])
target.mkdir(parents=True, mode=0o700)
with tarfile.open(fileobj=io.BytesIO(archive)) as bundle:
    bundle.extractall(target, filter='data')
overlays = [
    'api/app/Domains/InternalReadOnly', 'api/app/Http/Middleware/InternalServiceProof.php',
    'api/app/Http/Controllers/Txapi/InternalReadOnlyController.php', 'api/config/internal_api_slice.php',
    'api/database/migrations/2026_10_12_000008_create_internal_readonly_slice_state.php',
    'api/tests/Feature/InternalReadOnly', 'api/internal-readonly-fixtures', 'scripts/tests/internal-readonly',
]
import shutil
import hashlib
hashes = {}
for overlay in overlays:
    item = source / overlay
    files = sorted(item.rglob('*')) if item.is_dir() else [item]
    for path in files:
        if not path.is_file() or '__pycache__' in path.parts or path.suffix == '.pyc':
            continue
        relative = path.relative_to(source)
        destination = target / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        content = path.read_bytes()
        if path.suffix == '.sh':
            content = content.replace(b'\r\n', b'\n')
        destination.write_bytes(content)
        hashes[str(relative)] = hashlib.sha256(content).hexdigest()
route = target / 'api/routes/txapi.php'
content = route.read_text()
marker = "// Versioned TX-Node control plane; scoped bearer credentials, separate from user"
block = """// Local/testing-only current read slice. Service proof + final Core actor authorization are both required.
Route::prefix('internal/v1')->middleware(\\App\\Http\\Middleware\\InternalServiceProof::class)->group(function () {
    Route::post('operations/{id}', [\\App\\Http\\Controllers\\Txapi\\InternalReadOnlyController::class, 'execute']);
});

"""
if "Route::prefix('internal/v1')" not in content:
    if content.count(marker) != 1:
        raise SystemExit('Committed route insertion point is ambiguous')
    route.write_text(content.replace(marker, block + marker))
hashes['api/routes/txapi.php'] = hashlib.sha256(route.read_bytes()).hexdigest()
# Composer's relative project classmap must resolve to this snapshot, not the donor application.
shutil.copytree(vendor, target / 'api/vendor', symlinks=False)
(target / 'internal-snapshot.json').write_text(json.dumps({'committed_core_head': head, 'slice_sha256': hashes}, indent=2))
print(json.dumps({'committed_core_head': head, 'snapshot': str(target)}))
