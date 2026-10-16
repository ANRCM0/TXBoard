"""Remove private fixture material even when seeding or JSON parsing fails."""
import pathlib,shutil,sys
fixture=pathlib.Path(sys.argv[1]).resolve()
if fixture.parent != pathlib.Path('/tmp') or not fixture.name.startswith('txboard-internal-order-cancel.'):
    raise SystemExit('Expected one explicitly created Admin consumer fixture')
for path in fixture.iterdir():
    if path.is_file() and (path.suffix in ['.key','.csr','.ext','.cnf'] or any(path.name.startswith(prefix)
        for prefix in ['actors.json','admin-config.json','user-config.json','boundary.json','core-trust.json','index.txt','serial','crlnumber','hook-mode'])):
        path.unlink()
certs=fixture/'newcerts'
if certs.is_dir() and not certs.is_symlink():
    shutil.rmtree(certs)
