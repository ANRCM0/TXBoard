import os,pathlib,subprocess,sys
snapshot=pathlib.Path(sys.argv[1]).resolve()
if not str(snapshot).startswith('/opt/txboard-baseline/internal-admin-users-') or not (snapshot/'manifest.json').is_file():
    raise SystemExit('Expected reviewed standalone Admin plan list snapshot')
database='txboard_admin_users_corrected_test' if snapshot.name.endswith('-r4') else 'txboard_admin_users_final_test' if snapshot.name.endswith('-r3') else 'txboard_admin_users_retry_test' if snapshot.name.endswith('-r2') else 'txboard_admin_users_test'
sql=f"""CREATE DATABASE IF NOT EXISTS {database};
CREATE USER IF NOT EXISTS 'txboard_admin_users'@'127.0.0.1' IDENTIFIED BY 'admin-users-local-test-only';
GRANT ALL ON {database}.* TO 'txboard_admin_users'@'127.0.0.1';
"""
subprocess.run(['mariadb','--socket=/opt/txboard-baseline/mysql/mysql.sock','-uroot'],input=sql,text=True,check=True)
env=dict(os.environ,DB_DATABASE=database,DB_HOST='127.0.0.1',DB_PORT='13306',
    DB_USERNAME='txboard_admin_users',DB_PASSWORD='admin-users-local-test-only')
subprocess.run(['bash',str(snapshot/'scripts/tests/internal-admin-users/run.sh'),str(snapshot/'api')],env=env,check=True)
