#!/usr/bin/env python3
"""Optional runner for the explicitly isolated TXBoard-Baseline laboratory, not an installer."""
import os
import pathlib
import subprocess
import sys

snapshot = pathlib.Path(sys.argv[1]).resolve()
if not str(snapshot).startswith('/opt/txboard-baseline/internal-') or not (snapshot/'internal-snapshot.json').is_file():
    raise SystemExit('Expected reviewed standalone internal snapshot')
socket = '/opt/txboard-baseline/mysql/mysql.sock'
sql = '''CREATE DATABASE IF NOT EXISTS txboard_internal_test;
CREATE USER IF NOT EXISTS 'txboard_internal_fixture'@'127.0.0.1' IDENTIFIED BY 'internal-local-test-only';
GRANT ALL ON txboard_internal_test.* TO 'txboard_internal_fixture'@'127.0.0.1';
'''
subprocess.run(['mariadb', f'--socket={socket}', '-uroot'], input=sql, text=True, check=True)
environment = dict(os.environ, DB_DATABASE='txboard_internal_test', DB_HOST='127.0.0.1', DB_PORT='13306',
    DB_USERNAME='txboard_internal_fixture', DB_PASSWORD='internal-local-test-only')
subprocess.run(['bash', str(snapshot/'scripts/tests/internal-readonly/run.sh'), str(snapshot/'api')], env=environment, check=True)
