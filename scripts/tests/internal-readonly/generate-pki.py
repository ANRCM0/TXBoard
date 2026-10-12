#!/usr/bin/env python3
"""Generate disposable real CA, leaf certificates and CRL, with distinct proof-signing RSA keys."""
import datetime as dt
import json
import os
import pathlib
import subprocess
import sys
import time

directory = pathlib.Path(sys.argv[1]).resolve()
directory.mkdir(mode=0o700, parents=True, exist_ok=True)
os.umask(0o077)
def run(*args):
    return subprocess.check_output(['openssl', *map(str, args)], stderr=subprocess.DEVNULL)
run('req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2', '-keyout', directory/'ca.key',
    '-out', directory/'ca.pem', '-subj', '/CN=TXBoard Local Fixture CA', '-addext', 'basicConstraints=critical,CA:true')
(directory/'index.txt').write_text('')
(directory/'serial').write_text('1000\n')
(directory/'crlnumber').write_text('1000\n')
(directory/'newcerts').mkdir(exist_ok=True)
config = directory/'ca.cnf'
config.write_text(f'''[ca]
default_ca = fixture
[fixture]
database = {directory}/index.txt
new_certs_dir = {directory}/newcerts
certificate = {directory}/ca.pem
private_key = {directory}/ca.key
serial = {directory}/serial
crlnumber = {directory}/crlnumber
default_md = sha256
default_days = 1
default_crl_days = 1
policy = match
unique_subject = no
[match]
commonName = supplied
''')
now = int(time.time())
def stamp(seconds):
    return dt.datetime.fromtimestamp(seconds, dt.timezone.utc).strftime('%Y%m%d%H%M%SZ')
for name, san, start, end, usage in [
    ('server', 'internal-core.local', now-60, now+3600, 'serverAuth'),
    ('user', 'user-gateway.internal', now-60, now+3600, 'clientAuth'),
    ('admin', 'admin-gateway.internal', now-60, now+3600, 'clientAuth'),
    ('wrong-san', 'visitor.internal', now-60, now+3600, 'clientAuth'),
    ('multi-san', 'user-gateway.internal,DNS:visitor.internal', now-60, now+3600, 'clientAuth'),
    ('expired', 'user-gateway.internal', now-3600, now-60, 'clientAuth'),
    ('revoked', 'user-gateway.internal', now-60, now+3600, 'clientAuth'),
    ('short', 'user-gateway.internal', now-60, now+60, 'clientAuth'),
]:
    run('req', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', directory/f'{name}.key',
        '-out', directory/f'{name}.csr', '-subj', f'/CN={name}')
    ext = directory/f'{name}.ext'
    ext.write_text(f'basicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage={usage}\nsubjectAltName=DNS:{san}\n')
    run('ca', '-batch', '-config', config, '-in', directory/f'{name}.csr', '-out', directory/f'{name}.pem',
        '-startdate', stamp(start), '-enddate', stamp(end), '-extfile', ext, '-notext')
run('ca', '-config', config, '-revoke', directory/'revoked.pem', '-batch')
run('ca', '-config', config, '-gencrl', '-out', directory/'ca.crl')
run('req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-keyout', directory/'bad-ca.key',
    '-out', directory/'bad-ca.pem', '-subj', '/CN=Untrusted Fixture CA')
run('req', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', directory/'bad-client.key',
    '-out', directory/'bad-client.csr', '-subj', '/CN=untrusted')
run('x509', '-req', '-in', directory/'bad-client.csr', '-CA', directory/'bad-ca.pem', '-CAkey', directory/'bad-ca.key',
    '-CAcreateserial', '-days', '1', '-extfile', directory/'user.ext', '-out', directory/'bad-client.pem')

def fingerprint(name):
    return run('x509', '-in', directory/f'{name}.pem', '-noout', '-fingerprint', '-sha256').decode().strip().split('=')[1].replace(':', '').lower()
services = {}
for role in ['user', 'admin']:
    certificates = {}
    for leaf in ([role, 'short', 'revoked'] if role == 'user' else [role]):
        end = now + 60 if leaf == 'short' else now + 3600
        certificates[fingerprint(leaf)] = {'revoked': False, 'not_before': now-60, 'not_after': end}
    services[f'{role}-gateway'] = {'certificates': certificates}
keys = {}
for role, epoch in [('user', 1), ('admin', 1), ('user', 2)]:
    kid = f'{role}-{epoch}'
    run('genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048', '-out', directory/f'{kid}.proof.key')
    run('pkey', '-in', directory/f'{kid}.proof.key', '-pubout', '-out', directory/f'{kid}.proof.pub')
    keys[kid] = {'epoch': epoch, 'service': f'{role}-gateway', 'revoked': False, 'not_before': now-60,
        'not_after': now+3600, 'public_key_path': str(directory/f'{kid}.proof.pub')}
trust = {'services': services, 'proof_keys': keys}
(directory/'core-trust.json').write_text(json.dumps(trust, indent=2))
boundary = {'environment': 'testing', 'listen_host': '127.0.0.1', 'listen_port': 19443, 'core_port': 19081,
    'server_key': str(directory/'server.key'), 'server_cert': str(directory/'server.pem'),
    'client_ca': str(directory/'ca.pem'), 'client_crl': str(directory/'ca.crl'),
    'services': services, 'proof_keys': {kid: dict(key, private_key_path=str(directory/f'{kid}.proof.key')) for kid, key in keys.items()},
    'service_kids': {'user-gateway': 'user-1', 'admin-gateway': 'admin-1'}}
(directory/'boundary.json').write_text(json.dumps(boundary, indent=2))
(directory/'public-evidence.json').write_text(json.dumps({'generated_at': now, 'tls': 'TLSv1.3',
    'certificates': {name: fingerprint(name) for name in ['server', 'user', 'admin', 'short', 'revoked']},
    'short_certificate_expires_at': now+60, 'proof_algorithm': 'RS256', 'proof_key_epochs': [1, 2]}, indent=2))
