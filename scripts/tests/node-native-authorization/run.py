#!/usr/bin/env python3
"""Real Core/Workerman, Machine siblings and finite signed admission gate.

Caller provisions a new empty dedicated schema. This runner never resets a DB,
flushes Redis, deletes old evidence, changes SSH or touches a production host.
"""
from pathlib import Path
import hashlib
import json
import os
import shutil
import signal
import socket
import ssl
import stat
import subprocess
import sys
import time
import urllib.error
import urllib.request
from tls_proxy import NativeAuthorizationTLSProxy


def fixture_json(raw, stage, required):
    try:
        document = json.loads(raw)
    except (ValueError, TypeError):
        raise RuntimeError('NATIVE_AUTHORIZATION_' + stage.upper() + '_INVALID_JSON') from None
    if not isinstance(document, dict) or any(key not in document for key in required):
        raise RuntimeError('NATIVE_AUTHORIZATION_' + stage.upper() + '_INVALID_DOCUMENT')
    return document


# Ordered exactly as authorization.State's Go struct. All values in the pinned
# state format are ASCII scalars; compact UTF-8 bytes equal json.Marshal(State).
AUTHORITY_STATE_FIELDS = (
    'version', 'core_instance_id', 'policy_version', 'node_id', 'machine_id',
    'key_id', 'public_key', 'key_epoch', 'revision', 'issued_at', 'expires_at',
    'last_observed_at', 'payload_digest',
)


def persisted_authority_document(raw):
    try:
        envelope = json.loads(raw)
    except (ValueError, TypeError):
        raise RuntimeError('NATIVE_AUTHORIZATION_PERSISTED_STATE_INVALID_JSON') from None
    if not isinstance(envelope, dict) or set(envelope) != {'state', 'digest'}:
        raise RuntimeError('NATIVE_AUTHORIZATION_PERSISTED_STATE_ENVELOPE_INVALID')
    state = envelope['state']
    if not isinstance(state, dict) or set(state) != set(AUTHORITY_STATE_FIELDS):
        raise RuntimeError('NATIVE_AUTHORIZATION_PERSISTED_STATE_FIELDS_INVALID')
    ordered = {name: state[name] for name in AUTHORITY_STATE_FIELDS}
    canonical = json.dumps(ordered, ensure_ascii=False, separators=(',', ':')).encode()
    if hashlib.sha256(canonical).hexdigest() != envelope['digest']:
        raise RuntimeError('NATIVE_AUTHORIZATION_PERSISTED_STATE_DIGEST_MISMATCH')
    return state


def main():
    if len(sys.argv) not in (4, 5):
        raise SystemExit('usage: run.py API NODE NEW_EVIDENCE [singbox|xray]')
    api, node, evidence = (Path(value).resolve() for value in sys.argv[1:4])
    kernel = sys.argv[4] if len(sys.argv) == 5 else 'singbox'
    scripts = Path(__file__).resolve().parent
    if kernel not in ('singbox', 'xray'):
        raise SystemExit('NATIVE_AUTHORIZATION_KERNEL_REFUSED')
    expected = {'APP_ENV': 'testing', 'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1',
                'DB_DATABASE': 'txboard_native_authorization_test_' + kernel, 'DB_USERNAME': 'txboard_native_authorization'}
    if any(os.environ.get(key) != value for key, value in expected.items()):
        raise SystemExit('NATIVE_AUTHORIZATION_EXCLUSIVE_FIXTURE_REQUIRED')
    if os.name != 'posix' or kernel not in ('singbox', 'xray') or evidence.exists() or not os.environ.get('DB_PASSWORD'):
        raise SystemExit('NATIVE_AUTHORIZATION_INPUT_REFUSED')
    if not (api / 'artisan').is_file() or not (node / 'go.mod').is_file():
        raise SystemExit('NATIVE_AUTHORIZATION_SOURCE_REQUIRED')
    php = shutil.which('php84') or shutil.which('php')
    mysql = shutil.which('mariadb') or shutil.which('mysql')
    if not php or not mysql or not shutil.which('go') or not shutil.which('openssl'):
        raise SystemExit('NATIVE_AUTHORIZATION_TOOLS_REQUIRED')
    go = json.loads(subprocess.check_output(['go', 'env', '-json', 'GOPATH', 'GOMODCACHE', 'GOCACHE', 'CGO_ENABLED'], text=True))
    if go.get('CGO_ENABLED') != '1' or any(not go.get(key) for key in ('GOPATH', 'GOMODCACHE', 'GOCACHE')):
        raise SystemExit('NATIVE_AUTHORIZATION_GO_RACE_ENV_REQUIRED')
    env = {key: os.environ[key] for key in ('PATH', 'LANG', 'LC_ALL', 'TMPDIR', 'GOTOOLCHAIN', 'GOMAXPROCS') if key in os.environ}
    env.update(go)
    env.update(expected | {
        'DB_PORT': os.environ.get('DB_PORT', '13306'), 'DB_PASSWORD': os.environ['DB_PASSWORD'],
        'APP_DEBUG': 'false', 'CACHE_DRIVER': 'redis', 'SETTING_CACHE_STORE': 'redis',
        'SESSION_DRIVER': 'array', 'QUEUE_CONNECTION': 'redis', 'MAIL_MAILER': 'array',
        'AUTHORITY_WRITER_FENCE_ENABLED': 'false', 'CORE_USAGE_V2_ISOLATED_COPY': 'false',
        'TXBOARD_NATIVE_NODE_WS_ENABLED': 'true', 'NATIVE_TRAFFIC_RECEIPTS_ENABLED': 'true',
        'NATIVE_NODE_AUTHORIZATION_ENABLED': 'true',
        'NATIVE_NODE_AUTHORIZATION_CORE_INSTANCE_ID': 'isolated-native-authorization-core',
        'NATIVE_NODE_AUTHORIZATION_KEY_ID': 'isolated-ed25519-v1', 'NATIVE_NODE_AUTHORIZATION_KEY_EPOCH': '1',
        'NATIVE_NODE_AUTHORIZATION_LEASE_SECONDS': '40', 'NATIVE_NODE_AUTHORIZATION_MAX_CLOCK_SKEW_SECONDS': '1',
        'APP_KEY': 'base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs=',
        'REDIS_HOST': '127.0.0.1', 'REDIS_PORT': os.environ.get('REDIS_PORT', '16379'),
        'REDIS_DB': os.environ.get('REDIS_DB', '54'), 'REDIS_CACHE_DB': os.environ.get('REDIS_CACHE_DB', '55'),
        'REDIS_PREFIX': 'native-authorization-' + hashlib.sha256(str(evidence).encode()).hexdigest()[:12] + ':',
        'NATIVE_AUTHORIZATION_API': str(api), 'NATIVE_AUTHORIZATION_DIR': str(evidence),
        'NATIVE_AUTHORIZATION_KERNEL': kernel,
    })
    if not env['REDIS_DB'].isdigit() or not env['REDIS_CACHE_DB'].isdigit() or env['REDIS_DB'] == env['REDIS_CACHE_DB']:
        raise SystemExit('NATIVE_AUTHORIZATION_DISTINCT_REDIS_DATABASES_REQUIRED')
    query = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" + expected["DB_DATABASE"] + "'"
    probe = subprocess.check_output([mysql, '--protocol=tcp', '-h127.0.0.1', '-P' + env['DB_PORT'],
        '-u' + env['DB_USERNAME'], '-N', '-e', query], env=env | {'MYSQL_PWD': env['DB_PASSWORD']}, text=True).strip()
    if probe != '0':
        raise SystemExit('NATIVE_AUTHORIZATION_NONEMPTY_SCHEMA_REFUSED')
    evidence.mkdir(mode=0o700)

    def artifact(name, value):
        target, temporary = evidence / name, evidence / (name + '.write')
        with temporary.open('x', encoding='utf-8') as output:
            os.chmod(temporary, 0o600)
            json.dump(value, output, sort_keys=True, indent=2)
            output.write('\n')
            output.flush()
            os.fsync(output.fileno())
        os.rename(temporary, target)
        directory = os.open(evidence, os.O_RDONLY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)

    def command(name, arguments, cwd, timeout=180):
        path = evidence / (name + '.log')
        with path.open('xb') as output:
            os.chmod(path, 0o600)
            result = subprocess.run(arguments, cwd=cwd, env=env, stdout=output, stderr=subprocess.STDOUT, timeout=timeout)
        if result.returncode:
            raise RuntimeError('NATIVE_AUTHORIZATION_' + name.upper() + '_FAILED')
        return path.read_text()

    # A random key exists only in this runner's Core subprocess environment. No
    # seed is written to the trust pin, Node environment, SQL or public report.
    key = json.loads(subprocess.check_output([php, '-r',
        '$s=random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);$k=sodium_crypto_sign_seed_keypair($s);'
        '$b=fn($v)=>rtrim(strtr(base64_encode($v),"+/","-_"),"=");'
        'echo json_encode(["seed"=>$b($s),"public_key"=>$b(sodium_crypto_sign_publickey($k))],JSON_THROW_ON_ERROR);'],
        env=env, text=True))
    env['NATIVE_NODE_AUTHORIZATION_PRIVATE_SEED'] = key.pop('seed')
    trust = key | {'protocol_version': 1, 'core_instance_id': env['NATIVE_NODE_AUTHORIZATION_CORE_INSTANCE_ID'],
                   'key_id': env['NATIVE_NODE_AUTHORIZATION_KEY_ID'], 'key_epoch': '1', 'algorithm': 'Ed25519'}
    artifact('trust.json', trust)
    ports = set()
    while len(ports) < 7:
        with socket.socket() as connection:
            connection.bind(('127.0.0.1', 0))
            ports.add(connection.getsockname()[1])
    http_port, ws_port, tls_port, first_port, second_port, origin_port, new_port = sorted(ports)
    env.update(NATIVE_AUTHORIZATION_NODE_PORT_0=str(first_port), NATIVE_AUTHORIZATION_NODE_PORT_1=str(second_port),
               NATIVE_AUTHORIZATION_ORIGIN_PORT=str(origin_port), NATIVE_AUTHORIZATION_NEW_PORT=str(new_port))
    command('migrate', [php, 'artisan', 'migrate', '--force', '--no-interaction'], api)
    overlay = {'Replace': {str(node / 'internal/machine/native_authorization_fixture_live_test.go'):
                           str(scripts / 'native_core_authorization_live_test.go')}}
    artifact('overlay.json', overlay)
    command('build', ['go', 'test', '-overlay=' + str(evidence / 'overlay.json'), '-c', '-race',
                     '-o', str(evidence / 'native-authorization-test'), './internal/machine'], node, 600)
    fixture = fixture_json(command('seed', [php, str(scripts / 'fixture.php'), 'seed'], api),
                           'seed', ('machine_id', 'token', 'nodes', 'origin_port'))
    artifact('fixture.json', fixture)
    command('certificate', ['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
        '-keyout', str(evidence / 'key.pem'), '-out', str(evidence / 'cert.pem'), '-subj', '/CN=localhost',
        '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1'], evidence)
    for name in ('key.pem', 'cert.pem'):
        os.chmod(evidence / name, 0o600)
    endpoint = 'https://127.0.0.1:' + str(tls_port)
    env.update(APP_URL=endpoint, SSL_CERT_FILE=str(evidence / 'cert.pem'))
    node_env = {key: value for key, value in env.items()
                if not key.startswith(('DB_', 'NATIVE_NODE_AUTHORIZATION_', 'NATIVE_AUTHORIZATION_', 'REDIS_', 'APP_'))}
    node_env.update(TXBOARD_NATIVE_AUTHORIZATION_DIR=str(evidence), TXBOARD_NATIVE_AUTHORIZATION_URL=endpoint,
                    TXBOARD_NATIVE_AUTHORIZATION_KERNEL=kernel)
    observation = evidence / 'native-observations.jsonl'
    observation.touch(mode=0o600)
    proxy = NativeAuthorizationTLSProxy(tls_port, http_port, ws_port, str(evidence / 'cert.pem'), str(evidence / 'key.pem'), str(observation))
    processes, logs, test = [], [], None
    states, case_evidence, authority_states = {}, {}, {}

    def start(name, arguments, cwd, process_env=None):
        output = (evidence / (name + '.log')).open('xb')
        os.chmod(evidence / (name + '.log'), 0o600)
        logs.append(output)
        process = subprocess.Popen(arguments, cwd=cwd, env=process_env or env, stdout=output,
                                   stderr=subprocess.STDOUT, start_new_session=True)
        processes.append(process)
        return process

    def start_test(phase):
        return start('node-' + phase, [str(evidence / 'native-authorization-test'),
                     '-test.run=^TestNativeCoreAuthorizationLive$', '-test.v', '-test.timeout=160s'],
                     node, node_env | {'TXBOARD_NATIVE_AUTHORIZATION_PHASE': phase})

    context = ssl.create_default_context(cafile=str(evidence / 'cert.pem'))

    def request(path, method, payload=None, headers=None):
        body = None if payload is None else json.dumps(payload).encode()
        incoming = urllib.request.Request(endpoint + path, data=body, method=method,
            headers={'Content-Type': 'application/json'} | (headers or {}))
        try:
            with urllib.request.urlopen(incoming, context=context, timeout=8) as response:
                return response.status, json.loads(response.read(4 << 20))
        except urllib.error.HTTPError as error:
            return error.code, json.loads(error.read(4 << 20))

    def wait_for(predicate, code, timeout=20):
        deadline = time.monotonic() + timeout
        while not predicate():
            if test is not None and test.poll() is not None:
                raise RuntimeError('NATIVE_AUTHORIZATION_NODE_EXITED_' + code)
            if time.monotonic() > deadline:
                raise RuntimeError('NATIVE_AUTHORIZATION_' + code + '_TIMEOUT')
            time.sleep(.03)

    def barrier(stage, action=None, timeout=20, acknowledge=True):
        path = evidence / (stage + '.ready.json')
        wait_for(path.exists, 'BARRIER_' + stage.upper(), timeout)
        incoming = json.loads(path.read_text())
        result = action(incoming) if action else {'ok': True}
        case_evidence[stage] = incoming
        if acknowledge:
            artifact(stage + '.ack.json', result or {'ok': True})
        return incoming

    def state(stage):
        result = fixture_json(command(stage + '-state', [php, str(scripts / 'fixture.php'), 'state'], api),
                              'state', ('users', 'authorization', 'receipts', 'ledger', 'period_usage'))
        states[stage] = result
        return result

    def persisted_authority(stage):
        rows = []
        for child in fixture['nodes']:
            path = evidence / 'kernel' / 'authorization' / ('node-' + str(child['id'])) / 'state.json'
            if path.is_symlink() or stat.S_IMODE(path.stat().st_mode) != 0o600 or stat.S_IMODE(path.parent.stat().st_mode) != 0o700:
                raise RuntimeError('NATIVE_AUTHORIZATION_PRIVATE_LINUX_STATE_REQUIRED')
            document = persisted_authority_document(path.read_text())
            if document.get('node_id') != child['id'] or document.get('machine_id') != fixture['machine_id'] or document.get('key_epoch') != '1' or document.get('policy_version') != 'native-node-authorization/v1' or document.get('core_instance_id') != trust['core_instance_id']:
                raise RuntimeError('NATIVE_AUTHORIZATION_PERSISTED_SCOPE_OR_POLICY_MISMATCH')
            rows.append({key: document[key] for key in ('node_id', 'machine_id', 'key_epoch', 'revision', 'issued_at', 'expires_at', 'policy_version')})
        authority_states[stage] = rows

    def deliveries(node_id):
        return [entry for entry in proxy.event_snapshot() if entry.get('authorization', {}).get('node_id') == node_id]

    def require_delivery(node_id, user_count):
        wait_for(lambda: any(entry['authorization']['user_count'] == user_count for entry in deliveries(node_id)),
                 'SIGNED_DELIVERY_' + str(node_id), 12)

    try:
        http = start('core-http', [php, '-S', '127.0.0.1:' + str(http_port), '-t', 'public', 'public/index.php'], api)
        workerman = start('core-workerman', [php, 'artisan', 'ws-server', 'start', '--host=127.0.0.1', '--port=' + str(ws_port)], api)
        traffic_worker = start('traffic-worker', [php, 'artisan', 'queue:work', 'redis', '--queue=node_sync,traffic_fetch', '--sleep=1', '--tries=1'], api)
        proxy.start()
        deadline = time.monotonic() + 10
        while True:
            if any(process.poll() is not None for process in (http, workerman, traffic_worker)):
                raise RuntimeError('NATIVE_AUTHORIZATION_CORE_PROCESS_START_FAILED')
            try:
                with socket.create_connection(('127.0.0.1', ws_port), timeout=.2):
                    pass
                status, _ = request('/txapi/node/v1/handshake', 'POST', {})
                if status == 401:
                    break
            except (OSError, urllib.error.URLError, ValueError):
                pass
            if time.monotonic() > deadline:
                raise RuntimeError('NATIVE_AUTHORIZATION_CORE_READY_TIMEOUT')
            time.sleep(.1)
        status, login = request('/txapi/auth/admin/login', 'POST',
            {'email': 'native-authorization-admin@example.test', 'password': 'SyntheticAuthorizationAdmin2026'})
        bearer = login.get('data', {}).get('auth_data', '')
        if status != 200 or not bearer.startswith('Bearer ') or login.get('data', {}).get('is_admin') is not True:
            raise RuntimeError('NATIVE_AUTHORIZATION_ADMIN_LOGIN_FAILED')
        test = start_test('capture')
        primary, sibling = fixture['nodes']
        hint_boundary = {}

        def initial(_):
            for child in fixture['nodes']:
                require_delivery(child['id'], 1)
            upgrades = [event for event in proxy.event_snapshot() if event['kind'] == 'ws-upgrade' and event['status'] == 101]
            if len(upgrades) != 1 or upgrades[0]['machine_id'] != str(fixture['machine_id']):
                raise RuntimeError('NATIVE_AUTHORIZATION_ONE_SHARED_MACHINE_SOCKET_REQUIRED')
            state('first-admission')
            persisted_authority('first-admission')
            proxy.block_users(primary['id'], True)
            hint_boundary['started'] = time.monotonic_ns()
            status, _ = request('/txapi/admin/native_authorization_admin/users/' + str(primary['user_id']) + '/update',
                                'POST', {'banned': True}, {'Authorization': bearer})
            if status != 200:
                raise RuntimeError('NATIVE_AUTHORIZATION_REAL_ADMIN_BAN_FAILED')
            return {'ok': True}

        barrier('first-admission', initial)

        def hint_observed(_):
            def observed():
                entries = [entry for entry in proxy.event_snapshot() if entry['monotonic_ns'] >= hint_boundary['started']]
                return any(entry['kind'] == 'ws-frame' and entry.get('event') in ('sync.users', 'sync.user.delta') for entry in entries) and any(
                    entry['kind'] == 'native-offline' and entry['path'] == '/txapi/node/v1/users' and entry['node_id'] == str(primary['id']) for entry in entries)
            wait_for(observed, 'ACTUAL_UNSIGNED_WS_HINT_AND_SIGNED_HTTP_BLOCK', 8)
            proxy.block_users(primary['id'], False)
            return {'ok': True}

        barrier('unsigned-hint', hint_observed)

        def revoked(_):
            require_delivery(primary['id'], 0)
            current = state('signed-revoked')
            users = {int(row['id']): row for row in current['users']}
            if not users[primary['user_id']]['banned'] or users[sibling['user_id']]['banned']:
                raise RuntimeError('NATIVE_AUTHORIZATION_CHILD_SCOPE_REVOKE_FAILED')
            status, _ = request('/txapi/admin/native_authorization_admin/users/' + str(primary['user_id']) + '/update',
                                'POST', {'banned': False}, {'Authorization': bearer})
            if status != 200:
                raise RuntimeError('NATIVE_AUTHORIZATION_REAL_ADMIN_UNBAN_FAILED')
            return {'ok': True}

        barrier('signed-revoked', revoked)
        offline_boundary = {}

        def offline(_):
            # Capture last Core-issued deadlines before refusing all native HTTP
            # and closing the actual shared WSS transport.
            offline_boundary['last_deliveries'] = {str(child['id']): deliveries(child['id'])[-1] for child in fixture['nodes']}
            offline_boundary['started_wall_time'] = time.time()
            offline_boundary['started_monotonic_ns'] = time.monotonic_ns()
            proxy.set_offline(True)
            return {'ok': True}

        barrier('offline-start', offline)
        barrier('offline-before-expiry')
        expired = barrier('expired', timeout=55, acknowledge=False)
        offline_boundary['expired_observed_monotonic_ns'] = time.monotonic_ns()
        elapsed = (offline_boundary['expired_observed_monotonic_ns'] - offline_boundary['started_monotonic_ns']) / 1e9
        remaining = min(entry['authorization']['expires_at'] for entry in offline_boundary['last_deliveries'].values()) - offline_boundary['started_wall_time']
        if elapsed < remaining - 2:
            raise RuntimeError('NATIVE_AUTHORIZATION_STOPPED_BEFORE_SIGNED_EXPIRY')
        if elapsed > 48:
            raise RuntimeError('NATIVE_AUTHORIZATION_FINITE_EXPIRY_BOUND_EXCEEDED')
        state('expired')
        persisted_authority('expired')
        deadlines = {row['node_id']: row['expires_at'] for row in authority_states['expired']}
        for closure in expired['closures']:
            offset = closure['closed_wall_ns'] / 1e9 - deadlines[closure['node_id']]
            if offset < -2 or offset > 2:
                raise RuntimeError('NATIVE_AUTHORIZATION_CHILD_IO_CUTOFF_OUTSIDE_SIGNED_BOUNDARY')
        os.killpg(test.pid, signal.SIGKILL)
        if test.wait(timeout=3) != -signal.SIGKILL:
            raise RuntimeError('NATIVE_AUTHORIZATION_CAPTURE_SIGKILL_BOUNDARY_FAILED')
        original_pid = expired['pid']
        test = start_test('offline-restart')
        restarted = barrier('offline-restart')
        if restarted['pid'] == original_pid or not restarted['both_listeners_closed']:
            raise RuntimeError('NATIVE_AUTHORIZATION_DISTINCT_OFFLINE_RESTART_REQUIRED')
        if test.wait(timeout=15) != 0:
            raise RuntimeError('NATIVE_AUTHORIZATION_OFFLINE_RESTART_CHECK_FAILED')
        test = None
        proxy.set_offline(False)
        test = start_test('recovery')

        def recovered(incoming):
            if incoming['pid'] in (original_pid, restarted['pid']):
                raise RuntimeError('NATIVE_AUTHORIZATION_DISTINCT_RECOVERY_PROCESS_REQUIRED')
            def settled():
                snapshot = state('replay-' + str(time.monotonic_ns()))
                return all(any(int(row['server_id']) == spool['node_id'] and row['batch_id'] == spool['batch_id']
                               for row in snapshot['ledger']) for spool in expired['spools'])
            wait_for(settled, 'ORIGINAL_EXPIRED_TRAFFIC_LEDGER_REPLAY', 20)
            entries = proxy.event_snapshot()
            for spool in expired['spools']:
                reports = [entry for entry in entries if entry['kind'] == 'http' and entry['path'] == '/txapi/node/v1/report'
                           and entry.get('batch_id') == spool['batch_id'] and entry.get('node_id') == str(spool['node_id'])
                           and entry['status'] == 202]
                if not reports or any(entry['traffic'] != spool['traffic'] or entry['traffic_segments'] != spool['traffic_segments'] for entry in reports):
                    raise RuntimeError('NATIVE_AUTHORIZATION_TRAFFIC_ID_OR_PERIOD_CHANGED')
            state('recovered')
            persisted_authority('recovered')
            return {'ok': True}

        barrier('recovery', recovered)
        if test.wait(timeout=15) != 0:
            raise RuntimeError('NATIVE_AUTHORIZATION_REAL_RECOVERY_TEST_FAILED')
        signed = [entry for entry in proxy.event_snapshot() if 'authorization' in entry]
        for child in fixture['nodes']:
            revisions = [int(entry['authorization']['revision']) for entry in signed if entry['authorization']['node_id'] == child['id']]
            if not revisions or revisions != sorted(set(revisions)):
                raise RuntimeError('NATIVE_AUTHORIZATION_MONOTONIC_CORE_REVISION_FAILED')
        if any('no-store' not in entry['cache_control'] for entry in signed):
            raise RuntimeError('NATIVE_AUTHORIZATION_NO_STORE_REQUIRED')
        source_hashes = {path.name: hashlib.sha256(path.read_bytes()).hexdigest() for path in scripts.iterdir() if path.is_file()}
        source_manifest = api.parent / 'manifest.json'
        source_manifest_sha256 = hashlib.sha256(source_manifest.read_bytes()).hexdigest() if source_manifest.is_file() else None
        report = {'status': 'passed', 'fixture': 'synthetic-dedicated-mysql-loopback-redis', 'kernel': kernel,
                  'protocols': ['vless', 'vmess'], 'lease_seconds': 40, 'case_evidence': case_evidence,
                  'offline_boundary': offline_boundary, 'script_sha256': source_hashes,
                  'persisted_authority_states': authority_states, 'frozen_source_manifest_sha256': source_manifest_sha256,
                  'ci_source_commit': os.environ.get('GITHUB_SHA'), 'ci_run_id': os.environ.get('GITHUB_RUN_ID'),
                  'io_cutoff_observation_tolerance_seconds': 2,
                  'signed_delivery_count': len(signed), 'traffic_original_batch_count': len(expired['spools']),
                  'excluded': ['production Provider delivery', 'Installer key provisioning', 'production PKI',
                               'key rotation overlap', 'HA or disk anti-rollback', 'full protocol matrix']}
        artifact('result.json', report)
        print(json.dumps({'status': 'passed', 'kernel': kernel, 'signed_deliveries': len(signed),
                          'original_traffic_batches_replayed': len(expired['spools'])}, sort_keys=True))
    finally:
        for process in reversed(processes):
            if process.poll() is None:
                try:
                    os.killpg(process.pid, signal.SIGTERM)
                except ProcessLookupError:
                    pass
                try:
                    process.wait(timeout=8)
                except subprocess.TimeoutExpired:
                    try:
                        os.killpg(process.pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                    process.wait(timeout=3)
        if proxy.thread.is_alive():
            proxy.close()
        else:
            proxy.server.server_close()
        for output in logs:
            output.close()


if __name__ == '__main__':
    main()
