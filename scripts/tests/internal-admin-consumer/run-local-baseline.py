import os,pathlib,subprocess,sys
snapshot=pathlib.Path(sys.argv[1]).resolve()
if not str(snapshot).startswith('/opt/txboard-baseline/internal-admin-consumer-') or not (snapshot/'manifest.json').is_file():
    raise SystemExit('Expected reviewed standalone Admin login snapshot')
sql="""CREATE DATABASE IF NOT EXISTS txboard_admin_consumer_test;
CREATE USER IF NOT EXISTS 'txboard_admin_consumer'@'127.0.0.1' IDENTIFIED BY 'admin-consumer-local-test-only';
GRANT ALL ON txboard_admin_consumer_test.* TO 'txboard_admin_consumer'@'127.0.0.1';
"""
subprocess.run(['mariadb','--socket=/opt/txboard-baseline/mysql/mysql.sock','-uroot'],input=sql,text=True,check=True)
env=dict(os.environ,DB_DATABASE='txboard_admin_consumer_test',DB_HOST='127.0.0.1',DB_PORT='13306',
    DB_USERNAME='txboard_admin_consumer',DB_PASSWORD='admin-consumer-local-test-only')
subprocess.run(['bash',str(snapshot/'scripts/tests/internal-admin-consumer/run.sh'),str(snapshot/'api')],env=env,check=True)
