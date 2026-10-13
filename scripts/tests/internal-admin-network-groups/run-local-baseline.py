import os,pathlib,subprocess,sys
snapshot=pathlib.Path(sys.argv[1]).resolve()
if not str(snapshot).startswith('/opt/txboard-baseline/internal-admin-network-groups-') or not (snapshot/'manifest.json').is_file():
    raise SystemExit('Expected frozen Admin network group snapshot')
sql="""CREATE DATABASE IF NOT EXISTS txboard_admin_network_groups_test;
CREATE USER IF NOT EXISTS 'txboard_admin_network_groups'@'127.0.0.1' IDENTIFIED BY 'admin-network-groups-local-test-only';
GRANT ALL ON txboard_admin_network_groups_test.* TO 'txboard_admin_network_groups'@'127.0.0.1';
"""
subprocess.run(['mariadb','--socket=/opt/txboard-baseline/mysql/mysql.sock','-uroot'],input=sql,text=True,check=True)
env=dict(os.environ,DB_DATABASE='txboard_admin_network_groups_test',DB_HOST='127.0.0.1',DB_PORT='13306',
    DB_USERNAME='txboard_admin_network_groups',DB_PASSWORD='admin-network-groups-local-test-only')
subprocess.run(['bash',str(snapshot/'scripts/tests/internal-admin-network-groups/run.sh'),str(snapshot/'api')],env=env,check=True)
