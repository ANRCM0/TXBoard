import pathlib
import shutil
import sys

path = pathlib.Path(sys.argv[1]).resolve()
if path.parent != pathlib.Path('/tmp') or not path.name.startswith('txboard-internal-readonly.'):
    raise SystemExit('Unexpected fixture cleanup directory')
for item in path.iterdir():
    if item.name.endswith('.log') or item.name in ['result.json', 'public-evidence.json']:
        continue
    if item.is_dir() and not item.is_symlink():
        shutil.rmtree(item)
    else:
        item.unlink()
