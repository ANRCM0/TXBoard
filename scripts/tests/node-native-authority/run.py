#!/usr/bin/env python3
"""Exclusive ordinary native-v1 Core/Service disable-and-restart verification."""
from pathlib import Path
import hashlib
import http.client
import http.server
import json
import os
import shutil
import socket
import ssl
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

if len(sys.argv) != 5 or sys.argv[4] not in {'vless', 'vmess'}:
    raise SystemExit('usage: run.py API NODE NEW_EVIDENCE vless|vmess')
api, node, evidence = (Path(p).resolve() for p in sys.argv[1:4])
protocol = sys.argv[4]
scripts = Path(__file__).resolve().parent
expected = {'APP_ENV': 'testing', 'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1',
            'DB_DATABASE': 'txboard_native_control_test', 'DB_USERNAME': 'txboard_native_control'}
if any(os.environ.get(k) != v for k, v in expected.items()):
    raise SystemExit('NATIVE_AUTHORITY_EXCLUSIVE_FIXTURE_REQUIRED')
if not os.environ.get('DB_PASSWORD') or evidence.exists() or not (api/'artisan').is_file() or not (node/'go.mod').is_file():
    raise SystemExit('NATIVE_AUTHORITY_FIXTURE_INPUT_REFUSED')
php = shutil.which('php84') or shutil.which('php')
mysql = shutil.which('mariadb') or shutil.which('mysql')
if not php or not mysql or not shutil.which('go') or not shutil.which('openssl'):
    raise SystemExit('NATIVE_AUTHORITY_TOOLS_REQUIRED')
# Do not inherit migration/Provider/v2 flags, authentication or production settings.
env = {k: os.environ[k] for k in ('PATH', 'LANG', 'LC_ALL', 'TMPDIR', 'GOPATH', 'GOMODCACHE', 'GOCACHE', 'GOTOOLCHAIN', 'CGO_ENABLED') if k in os.environ}
env.update(expected | {'DB_PASSWORD': os.environ['DB_PASSWORD'], 'DB_PORT': os.environ.get('DB_PORT', '13306'),
    'APP_DEBUG': 'false', 'CACHE_DRIVER': 'array', 'SETTING_CACHE_STORE': 'array', 'SESSION_DRIVER': 'array',
    'QUEUE_CONNECTION': 'sync', 'MAIL_MAILER': 'array',
    'AUTHORITY_WRITER_FENCE_ENABLED': 'false', 'CORE_USAGE_V2_ISOLATED_COPY': 'false',
    'TXBOARD_NATIVE_NODE_WS_ENABLED': 'false',
    'APP_KEY': 'base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs=',
    'REDIS_HOST': '127.0.0.1', 'REDIS_PORT': os.environ.get('REDIS_PORT', '16379'),
    'REDIS_DB': os.environ.get('REDIS_DB', '44'), 'REDIS_CACHE_DB': os.environ.get('REDIS_CACHE_DB', '45'),
    'REDIS_PREFIX': 'native-authority-core-'+protocol+':',
    'NATIVE_AUTHORITY_CORE_API': str(api), 'NATIVE_AUTHORITY_DIR': str(evidence), 'NATIVE_AUTHORITY_PROTOCOL': protocol})
evidence.mkdir(mode=0o700)

def artifact(name, value):
    path = evidence/name
    with path.open('x', encoding='utf-8') as output:
        os.chmod(path, 0o600)
        json.dump(value, output, indent=2, sort_keys=True)
        output.write('\n')
        output.flush()
        os.fsync(output.fileno())

def run(name, command, cwd, timeout=180):
    path = evidence/(name+'.log')
    with path.open('xb') as output:
        os.chmod(path, 0o600)
        result = subprocess.run(command, cwd=cwd, env=env, stdout=output, stderr=subprocess.STDOUT, timeout=timeout)
    if result.returncode:
        # Child logs remain private. They are never copied into public diagnostics.
        raise RuntimeError('NATIVE_AUTHORITY_'+name.upper()+'_FAILED')
    return path.read_text()

def port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]

query = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='txboard_native_control_test'"
probe = subprocess.check_output([mysql, '--protocol=tcp', '-h127.0.0.1', '-P'+env['DB_PORT'],
    '-u'+env['DB_USERNAME'], '-N', '-e', query], env=env | {'MYSQL_PWD': env['DB_PASSWORD']}, text=True).strip()
if probe != '0':
    raise SystemExit('NATIVE_AUTHORITY_NONEMPTY_DATABASE_REFUSED')
run('migrate', [php, 'artisan', 'migrate', '--force', '--no-interaction'], api)
run('build', ['go', 'test', '-c', '-race', '-o', str(evidence/'native-authority-core-test'), './internal/service'], node, timeout=600)
ports = set()
while len(ports) < 4:
    ports.add(port())
http_port, tls_port, node_port, origin_port = sorted(ports)
env.update({'NATIVE_AUTHORITY_NODE_PORT': str(node_port), 'NATIVE_AUTHORITY_ORIGIN_PORT': str(origin_port)})
fixture = json.loads(run('seed', [php, str(scripts/'fixture.php'), 'seed'], api))
artifact('fixture.json', fixture)
run('certificate', ['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
    '-keyout', str(evidence/'key.pem'), '-out', str(evidence/'cert.pem'), '-subj', '/CN=localhost',
    '-addext', 'subjectAltName=IP:127.0.0.1,DNS:localhost'], api)
os.chmod(evidence/'key.pem', 0o600)
os.chmod(evidence/'cert.pem', 0o600)
endpoint = 'https://127.0.0.1:'+str(tls_port)
env.update({'TXBOARD_NATIVE_AUTHORITY_CORE_DIR': str(evidence), 'TXBOARD_NATIVE_AUTHORITY_CORE_URL': endpoint,
            'SSL_CERT_FILE': str(evidence/'cert.pem')})
observation_lock = threading.Lock()
observations = []
observation_file = evidence/'native-observations.jsonl'
observation_file.touch(mode=0o600, exist_ok=False)

def observe(record):
    with observation_lock:
        observations.append(record)
        with observation_file.open('a', encoding='utf-8') as output:
            output.write(json.dumps(record, sort_keys=True)+'\n')
            output.flush()
            os.fsync(output.fileno())

admin_path = '/txapi/admin/native_control_admin/network-nodes/'+str(fixture['node_id'])
allowed = {'/txapi/node/v1/handshake', '/txapi/node/v1/config', '/txapi/node/v1/users',
           '/txapi/node/v1/report', '/txapi/auth/admin/login', admin_path}
class Proxy(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self, *_):
        pass
    def do_GET(self): self.forward()
    def do_POST(self): self.forward()
    def do_PATCH(self): self.forward()
    def forward(self):
        if self.path not in allowed:
            self.send_error(404); return
        length = self.headers.get('Content-Length', '0')
        if not length.isdigit() or int(length) > 1048576:
            self.send_error(413); return
        body = self.rfile.read(int(length))
        if len(body) != int(length):
            self.send_error(400); return
        connection = http.client.HTTPConnection('127.0.0.1', http_port, timeout=8)
        started = time.time()
        try:
            headers = {k: v for k, v in self.headers.items() if k.lower() not in {'host', 'connection', 'transfer-encoding'}}
            connection.request(self.command, self.path, body, headers)
            response = connection.getresponse()
            content = response.read(1048577)
            if len(content) > 1048576:
                self.send_error(502); return
            try: payload = json.loads(content)
            except (ValueError, UnicodeError): payload = {}
            record = {'path': self.path, 'method': self.command, 'status': response.status,
                      'started_at': started, 'completed_at': time.time()}
            # Login body and response contain secrets: retain no digests or body.
            if self.path.startswith('/txapi/node/v1/'):
                record.update({'node_id_header': self.headers.get('X-TX-Node-ID'),
                               'response_sha256': hashlib.sha256(content).hexdigest(),
                               'error_code': payload.get('error', {}).get('code')})
                if self.path.endswith('/users') and response.status == 200:
                    record['user_ids'] = [u.get('id') for u in payload.get('data', {}).get('users', [])]
                if self.path.endswith('/handshake') and response.status == 200:
                    data = payload.get('data', {})
                    record['protocol_version'] = data.get('protocol_version')
                    record['usage_v2_advertised'] = 'usage_v2' in data
                if self.path.endswith('/report'):
                    record['report_accepted'] = payload.get('data', {}).get('accepted')
            observe(record)
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in {'connection', 'transfer-encoding', 'content-length'}:
                    self.send_header(key, value)
            self.send_header('Content-Length', str(len(content)))
            self.send_header('Connection', 'close')
            self.end_headers()
            self.wfile.write(content)
            self.close_connection = True
        except (OSError, http.client.HTTPException):
            self.send_error(502)
        finally:
            connection.close()

http_log = (evidence/'core-http.log').open('xb'); os.chmod(evidence/'core-http.log', 0o600)
server = subprocess.Popen([php, '-S', '127.0.0.1:'+str(http_port), '-t', 'public', 'public/index.php'],
                          cwd=api, env=env, stdout=http_log, stderr=subprocess.STDOUT)
proxy = http.server.ThreadingHTTPServer(('127.0.0.1', tls_port), Proxy)
proxy.daemon_threads = True
server_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
server_context.minimum_version = ssl.TLSVersion.TLSv1_2
server_context.load_cert_chain(str(evidence/'cert.pem'), str(evidence/'key.pem'))
proxy.socket = server_context.wrap_socket(proxy.socket, server_side=True)
proxy_thread = threading.Thread(target=proxy.serve_forever, daemon=True)
proxy_thread.start()
test = None
test_log = None
client_context = ssl.create_default_context(cafile=str(evidence/'cert.pem'))

def request(path, method, payload, headers):
    encoded = None if payload is None else json.dumps(payload).encode()
    req = urllib.request.Request(endpoint+path, data=encoded, method=method,
                                 headers={'Content-Type': 'application/json'} | headers)
    try:
        with urllib.request.urlopen(req, context=client_context, timeout=8) as response:
            return response.status, json.loads(response.read(1048576))
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read(1048576))

try:
    for _ in range(40):
        if server.poll() is not None: raise RuntimeError('NATIVE_AUTHORITY_CORE_START_FAILED')
        try:
            status, _ = request('/txapi/node/v1/handshake', 'POST', {}, {})
            if status == 401: break
        except (OSError, urllib.error.URLError, json.JSONDecodeError): pass
        time.sleep(.1)
    else: raise RuntimeError('NATIVE_AUTHORITY_CORE_READY_TIMEOUT')
    status, login = request('/txapi/auth/admin/login', 'POST',
        {'email': 'native-control-admin@example.test', 'password': 'SyntheticAdminControl2026'}, {})
    bearer = login.get('data', {}).get('auth_data', '')
    if status != 200 or not bearer.startswith('Bearer ') or login.get('data', {}).get('is_admin') is not True:
        raise RuntimeError('NATIVE_AUTHORITY_ADMIN_LOGIN_FAILED')
    # Native HTTP-issued administrator bearer stays private to this runner process.
    test_log = (evidence/'node-live.log').open('xb'); os.chmod(evidence/'node-live.log', 0o600)
    test = subprocess.Popen([str(evidence/'native-authority-core-test'), '-test.run=^TestServiceNativeAuthorityCoreLive$',
                            '-test.v', '-test.timeout=45s'], cwd=node, env=env, stdout=test_log, stderr=subprocess.STDOUT)
    deadline = time.monotonic()+12
    while not (evidence/'ready.json').is_file():
        if test.poll() is not None: raise RuntimeError('NATIVE_AUTHORITY_TEST_EXITED_BEFORE_READY')
        if time.monotonic() >= deadline: raise RuntimeError('NATIVE_AUTHORITY_READY_BARRIER_TIMEOUT')
        time.sleep(.03)
    status, mutation = request(admin_path, 'PATCH', {'enabled': False}, {'Authorization': bearer})
    if status != 200 or mutation.get('data', {}).get('ok') is not True:
        raise RuntimeError('NATIVE_AUTHORITY_ADMIN_DISABLE_FAILED')
    sql = json.loads(run('assert-disabled', [php, str(scripts/'fixture.php'), 'assert-disabled'], api))
    artifact('mutation.json', {'http_status': status, 'sql': sql, 'actual_admin_http': True})
    if test.wait(timeout=30) != 0:
        raise RuntimeError('NATIVE_AUTHORITY_NODE_LIVE_FAILED')
    test_log.close(); test_log = None
    result = json.loads((evidence/'core-live-result.json').read_text())
    with observation_lock: captured = list(observations)
    initial = [r for r in captured if r['path'] == '/txapi/node/v1/handshake' and r['status'] == 200]
    snapshots = [r for r in captured if r['path'] == '/txapi/node/v1/users' and r['status'] == 200]
    refusals = [r for r in captured if r['path'] in {'/txapi/node/v1/config', '/txapi/node/v1/users'} and r['status'] == 404 and r.get('error_code') == 'NODE_NOT_FOUND']
    restart_refusals = [r for r in captured if r['path'] == '/txapi/node/v1/handshake' and r['status'] == 404 and r.get('error_code') == 'NODE_NOT_FOUND']
    if not initial or any(r.get('usage_v2_advertised') for r in initial) or not snapshots or not any(fixture['user_id'] in r.get('user_ids', []) for r in snapshots) or not refusals or not restart_refusals:
        raise RuntimeError('NATIVE_AUTHORITY_REAL_CORE_CALL_CHAIN_REQUIRED')
    public = result | {'actual_admin_http': True, 'actual_sql_disabled': True, 'verified_tls': True,
                       'core_snapshot_refusal_observed': True, 'core_restart_refusal_observed': True,
                       'scope': 'single-native-node-disable-v1', 'machine_all_node_shutdown_proven': False,
                       'workerman_ws_proven': False, 'production_authority_complete': False,
                       'artifact_sha256': {name: hashlib.sha256((evidence/name).read_bytes()).hexdigest()
                           for name in ('core-live-result.json', 'native-observations.jsonl', 'mutation.json')}}
    artifact('result.json', public)
    print(json.dumps(public, sort_keys=True))
finally:
    if test is not None and test.poll() is None:
        test.terminate()
        try: test.wait(timeout=5)
        except subprocess.TimeoutExpired: test.kill(); test.wait(timeout=5)
    if test_log is not None: test_log.close()
    proxy.shutdown(); proxy.server_close(); proxy_thread.join(timeout=5)
    server.terminate()
    try: server.wait(timeout=5)
    except subprocess.TimeoutExpired: server.kill(); server.wait(timeout=5)
    http_log.close()
