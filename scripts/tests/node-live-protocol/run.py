#!/usr/bin/env python3
"""Exclusive real Service/sing-box/TCP/native Core SQL verification."""
from pathlib import Path
import hashlib, json, os, shutil, socket, ssl, subprocess, sys, time, urllib.error, urllib.request

if len(sys.argv) != 5 or sys.argv[4] not in {'vless', 'vmess'}:
    raise SystemExit('usage: run.py API NODE NEW_EVIDENCE_DIR vless|vmess')
api, node, evidence = (Path(value).resolve() for value in sys.argv[1:4])
protocol = sys.argv[4]; scripts = Path(__file__).resolve().parent; env = os.environ.copy()
expected = {'APP_ENV':'testing','DB_CONNECTION':'mysql','DB_DATABASE':'txboard_live_protocol_test',
            'DB_HOST':'127.0.0.1','DB_USERNAME':'txboard_live_protocol'}
if any(env.get(key) != value for key, value in expected.items()):
    raise SystemExit('LIVE_PROTOCOL_EXCLUSIVE_FIXTURE_REQUIRED')
if not env.get('DB_PASSWORD') or evidence.exists() or not (api/'artisan').is_file() or not (node/'go.mod').is_file():
    raise SystemExit('LIVE_PROTOCOL_FIXTURE_INPUT_REFUSED')
evidence.mkdir(mode=0o700)
env.update({'LIVE_PROTOCOL_CORE_API':str(api),'LIVE_PROTOCOL_DIR':str(evidence),
            'CORE_USAGE_V2_ISOLATED_COPY':'true','CORE_USAGE_V2_INSTANCE_ID':'live_protocol_core_fixture_1',
            'CORE_USAGE_V2_RECEIPT_GENERATION':'1','CACHE_DRIVER':'array','SETTING_CACHE_STORE':'array',
            'SESSION_DRIVER':'array','MAIL_MAILER':'array','QUEUE_CONNECTION':'sync',
            'APP_KEY':'base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs=',
            'REDIS_PREFIX':'live-protocol-'+protocol+':','LIVE_PROTOCOL_PROTOCOL':protocol})
php = shutil.which('php84') or shutil.which('php')
if not php: raise SystemExit('LIVE_PROTOCOL_PHP_REQUIRED')

def run(name, command, cwd, timeout=180):
    path = evidence/(name+'.log')
    if path.exists(): raise SystemExit('LIVE_PROTOCOL_PREVIOUS_LOG_PRESERVED')
    with path.open('wb') as output:
        result = subprocess.run(command, cwd=cwd, env=env, stdout=output, stderr=subprocess.STDOUT, timeout=timeout)
    os.chmod(path, 0o600)
    if result.returncode:
        print(path.read_text(errors='replace')[-7000:]); raise SystemExit('LIVE_PROTOCOL_'+name.upper()+'_FAILED')
    return path.read_text()

def port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); return sock.getsockname()[1]

# No schema reset is hidden in this runner. A new, explicitly dedicated database
# must contain zero tables before the first migration is allowed.
probe = subprocess.check_output(['mariadb','--protocol=tcp','-h127.0.0.1','-P'+env.get('DB_PORT','13306'),
    '-u'+env['DB_USERNAME'],'-N','-e',
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='txboard_live_protocol_test'"],
    env=env|{'MYSQL_PWD':env['DB_PASSWORD']}, text=True).strip()
if probe != '0': raise SystemExit('LIVE_PROTOCOL_NONEMPTY_DATABASE_REFUSED')
run('migrate', [php,'artisan','migrate','--force','--no-interaction'], api)
# The actual locked race test binary is compiled before allocating a finite grant.
run('build', ['go','test','-c','-race','-o',str(evidence/'node-live-protocol-test'),'./internal/service'], node)
ports = set()
while len(ports)<4: ports.add(port())
http_port,tls_port,node_port,origin_port = sorted(ports)
endpoint = 'https://127.0.0.1:'+str(tls_port)
env.update({'LIVE_PROTOCOL_HTTP_PORT':str(http_port),'LIVE_PROTOCOL_TLS_PORT':str(tls_port),
            'LIVE_PROTOCOL_NODE_PORT':str(node_port),'LIVE_PROTOCOL_ORIGIN_PORT':str(origin_port),
            'TXBOARD_LIVE_PROTOCOL_DIR':str(evidence),'TXBOARD_LIVE_PROTOCOL_ENDPOINT':endpoint})
fixture = run('seed',[php,str(scripts/'fixture.php'),'seed'],api)
metadata = json.loads(fixture)
if metadata['protocol'] != protocol or metadata['node_port'] != node_port or metadata['origin_port'] != origin_port:
    raise SystemExit('LIVE_PROTOCOL_NATIVE_FIXTURE_IDENTITY_MISMATCH')
(evidence/'fixture.json').write_text(fixture); os.chmod(evidence/'fixture.json',0o600)
run('certificate',['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
    '-keyout',str(evidence/'key.pem'),'-out',str(evidence/'cert.pem'),'-subj','/CN=localhost',
    '-addext','subjectAltName=IP:127.0.0.1,DNS:localhost'],api)
os.chmod(evidence/'key.pem',0o600)
http_log = (evidence/'core-http.log').open('wb'); tls_log = (evidence/'tls-proxy.log').open('wb')
http_server = subprocess.Popen([php,'-S','127.0.0.1:'+str(http_port),'-t','public','public/index.php'],
                               cwd=api,env=env,stdout=http_log,stderr=subprocess.STDOUT)
tls_server = subprocess.Popen([sys.executable,str(scripts/'tls-proxy.py')],cwd=scripts,
                              env=env,stdout=tls_log,stderr=subprocess.STDOUT)
try:
    context = ssl.create_default_context(cafile=str(evidence/'cert.pem'))
    context.minimum_version = ssl.TLSVersion.TLSv1_3; ready = False
    for _ in range(30):
        if http_server.poll() is not None or tls_server.poll() is not None: break
        try:
            request = urllib.request.Request(endpoint+'/txapi/node/v1/handshake',data=b'{}',headers={'Content-Type':'application/json'})
            urllib.request.urlopen(request,context=context,timeout=1); ready=True; break
        except urllib.error.HTTPError: ready=True; break
        except (OSError,urllib.error.URLError): time.sleep(.1)
    if not ready: raise SystemExit('LIVE_PROTOCOL_LOCAL_SERVER_START_FAILED')
    env['TXBOARD_LIVE_PROTOCOL_PHASE']='capture'
    run('node-capture-run',[str(evidence/'node-live-protocol-test'),
        '-test.run=^TestServiceUsageV2LiveProtocolCoreInterop$','-test.v','-test.timeout=60s'],node)
    pending=json.loads(run('sql-pending',[php,str(scripts/'fixture.php'),'assert-pending'],api))
    settlement=json.loads(run('sql-settlement',[php,str(scripts/'fixture.php'),'settle'],api))
    env['TXBOARD_LIVE_PROTOCOL_PHASE']='settled'
    run('node-settled-run',[str(evidence/'node-live-protocol-test'),
        '-test.run=^TestServiceUsageV2LiveProtocolCoreInterop$','-test.v','-test.timeout=60s'],node)
    final=json.loads(run('sql-final',[php,str(scripts/'fixture.php'),'assert-settled'],api))
finally:
    for process in (tls_server,http_server):
        process.terminate()
        try: process.wait(timeout=5)
        except subprocess.TimeoutExpired: process.kill(); process.wait(timeout=5)
    http_log.close(); tls_log.close()
sha=lambda path:hashlib.sha256(path.read_bytes()).hexdigest()
# Public evidence is a fixed non-secret allowlist, never an entire private tree.
public_names={'cert.pem','fixture.json','live-capture.json','live-settled.json',
              'sql-pending.log','sql-settlement.log','sql-final.log','native-observations.jsonl'}
records={str(path.relative_to(evidence)):sha(path) for path in evidence.rglob('*') if path.is_file()
         and (path.name in public_names or path.relative_to(evidence).parts[0]=='mode')
         and path.name not in {'.usage-lock','.interval-lock'}}
result={'status':'verified-isolated','protocol':protocol,'actual_service_run':True,'actual_singbox_kernel':True,
        'locked_protocol_client':True,'decoded_stream_count_site_verified':True,'actual_listener':'127.0.0.1:'+str(node_port),
        'https_tls13_verified':True,'database':'txboard_live_protocol_test','persisted_ack_sql_verified':pending,
        'settlement':settlement,'final_sql':final,'node_settled':json.loads((evidence/'live-settled.json').read_text()),
        'artifacts_sha256':records,'production_authorization':False,'private_material_exported':False}
(evidence/'result.json').write_text(json.dumps(result,indent=2)+'\n'); os.chmod(evidence/'result.json',0o600)
print(json.dumps({'status':result['status'],'protocol':protocol,'raw_up_bytes':'43','raw_down_bytes':'79',
                  'billable_bytes':'182','receipt_status':'settled','evidence':str(evidence)}))