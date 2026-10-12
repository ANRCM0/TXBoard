#!/usr/bin/env python3
"""Explicit reset of our completed local fixture; never used implicitly by run.sh."""
import subprocess

database = 'txboard_internal_test'
command = ['mariadb', '--socket=/opt/txboard-baseline/mysql/mysql.sock', '-uroot', '--batch', '--skip-column-names']
read = "SELECT email FROM tx_user ORDER BY email; SELECT trade_no FROM tx_order ORDER BY id;"
rows = subprocess.check_output([*command, database], input=read, text=True).splitlines()
expected = ['internal-admin@example.test', 'internal-other@example.test', 'internal-user@example.test', 'internal-discounted-order']
if rows != expected:
    raise SystemExit('Refusing reset: database is not this exact completed disposable fixture')
# The database name is a literal, verified exclusive fixture; production databases cannot be selected.
subprocess.run(command, input='DROP DATABASE txboard_internal_test; CREATE DATABASE txboard_internal_test;', text=True, check=True)
print('Explicitly reset only verified txboard_internal_test; nonce/audit now fresh')
