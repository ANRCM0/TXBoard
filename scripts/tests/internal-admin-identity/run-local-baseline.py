"""Optional setup of one new, exclusively synthetic local database; no reset."""
import os,pathlib,subprocess,sys
snapshot=pathlib.Path(sys.argv[1]).resolve()
if not str(snapshot).startswith('/opt/txboard-baseline/internal-admin-identity-') or not (snapshot/'manifest.json').is_file():
    raise SystemExit('Expected reviewed standalone Admin identity snapshot')
database='txboard_admin_identity_retry_test' if snapshot.name.endswith('-r2') else 'txboard_admin_identity_test'
# Both names are literal exclusive fixtures; keep the failed database intact.
sql=f"""CREATE DATABASE IF NOT EXISTS {database};
CREATE USER IF NOT EXISTS 'txboard_admin_identity'@'127.0.0.1' IDENTIFIED BY 'admin-identity-local-test-only';
GRANT ALL ON {database}.* TO 'txboard_admin_identity'@'127.0.0.1';
"""
subprocess.run(['mariadb','--socket=/opt/txboard-baseline/mysql/mysql.sock','-uroot'],input=sql,text=True,check=True)
env=dict(os.environ,DB_DATABASE=database,DB_HOST='127.0.0.1',DB_PORT='13306',
    DB_USERNAME='txboard_admin_identity',DB_PASSWORD='admin-identity-local-test-only')
subprocess.run(['bash',str(snapshot/'scripts/tests/internal-admin-identity/run.sh'),str(snapshot/'api')],env=env,check=True)
