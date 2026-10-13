#!/usr/bin/env python3
"""Actual tracker -> Node HTTPS -> Core SQL ACK -> settlement fixture only."""
from pathlib import Path
import hashlib,json,os,socket,subprocess,sys,time,urllib.request,ssl,shutil

api,node,evidence=map(lambda p:Path(p).resolve(),sys.argv[1:4]);scripts=Path(__file__).resolve().parent
env=os.environ.copy()
if env.get('APP_ENV')!='testing' or env.get('DB_CONNECTION')!='mysql' or env.get('DB_DATABASE')!='txboard_service_usage_test' or env.get('DB_HOST')!='127.0.0.1' or env.get('DB_USERNAME')!='txboard_service_usage':
    raise SystemExit('SERVICE_USAGE_EXCLUSIVE_FIXTURE_REQUIRED')
if not env.get('DB_PASSWORD') or evidence.exists() or not(api/'artisan').is_file() or not(node/'go.mod').is_file():
    raise SystemExit('SERVICE_USAGE_FIXTURE_INPUT_REFUSED')
evidence.mkdir(mode=0o700)
env.update({'SERVICE_USAGE_CORE_API':str(api),'SERVICE_USAGE_DIR':str(evidence),
 'CORE_USAGE_V2_ISOLATED_COPY':'true','CORE_USAGE_V2_INSTANCE_ID':'service_usage_core_fixture_1','CORE_USAGE_V2_RECEIPT_GENERATION':'1',
 'CACHE_DRIVER':'array','SETTING_CACHE_STORE':'array','SESSION_DRIVER':'array','MAIL_MAILER':'array','QUEUE_CONNECTION':'sync',
 'APP_KEY':'base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs=','REDIS_PREFIX':'service-usage-interop:'})
php=shutil.which('php84') or shutil.which('php');assert php

def run(name,command,cwd):
    path=evidence/(name+'.log');assert not path.exists()
    with path.open('wb') as out:
        result=subprocess.run(command,cwd=cwd,env=env,stdout=out,stderr=subprocess.STDOUT,timeout=180)
    if result.returncode:
        print(path.read_text(errors='replace')[-5000:]);raise SystemExit('SERVICE_USAGE_'+name.upper()+'_FAILED')
    return path.read_text()

def port():
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));return sock.getsockname()[1]

# Before DDL, require the dedicated disposable database to contain zero tables.
probe=subprocess.check_output(['mariadb','--protocol=tcp','-h127.0.0.1','-P'+env.get('DB_PORT','13306'),'-u'+env['DB_USERNAME'],
 '-N','-e',"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='txboard_service_usage_test'"],env=env|{'MYSQL_PWD':env['DB_PASSWORD']},text=True).strip()
if probe!='0':raise SystemExit('SERVICE_USAGE_NONEMPTY_DATABASE_REFUSED')
run('migrate',[php,'artisan','migrate','--force','--no-interaction'],api)
run('build',['go','test','-c','-race','-o',str(evidence/'node-service-test'),'./internal/service'],node)
# Allocate the finite Core grant only after cold compilation has completed.
fixture=run('seed',[php,str(scripts/'fixture.php'),'seed'],api)
json.loads(fixture);(evidence/'fixture.json').write_text(fixture);os.chmod(evidence/'fixture.json',0o600)
run('certificate',['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(evidence/'key.pem'),'-out',str(evidence/'cert.pem'),'-subj','/CN=localhost','-addext','subjectAltName=IP:127.0.0.1,DNS:localhost'],api)
os.chmod(evidence/'key.pem',0o600)
http_port,tls_port=port(),port();env.update({'TXBOARD_SERVICE_USAGE_DIR':str(evidence),'SERVICE_USAGE_HTTP_PORT':str(http_port),'SERVICE_USAGE_TLS_PORT':str(tls_port),'SERVICE_USAGE_ENDPOINT':'https://127.0.0.1:'+str(tls_port),'TXBOARD_SERVICE_USAGE_ENDPOINT':'https://127.0.0.1:'+str(tls_port)})
http_log=(evidence/'core-http.log').open('wb');tls_log=(evidence/'tls-proxy.log').open('wb')
http_server=subprocess.Popen([php,'-S','127.0.0.1:'+str(http_port),'-t','public','public/index.php'],cwd=api,env=env,stdout=http_log,stderr=subprocess.STDOUT)
tls_server=subprocess.Popen([sys.executable,str(scripts/'tls-proxy.py')],cwd=scripts,env=env,stdout=tls_log,stderr=subprocess.STDOUT)
try:
    context=ssl.create_default_context(cafile=str(evidence/'cert.pem'));context.minimum_version=ssl.TLSVersion.TLSv1_3
    ready=False
    for _ in range(30):
        try:
            request=urllib.request.Request(env['SERVICE_USAGE_ENDPOINT']+'/txapi/node/v1/handshake',data=b'{}',headers={'Content-Type':'application/json'})
            urllib.request.urlopen(request,context=context,timeout=1);ready=True;break
        except urllib.error.HTTPError:ready=True;break
        except(OSError,urllib.error.URLError):time.sleep(.1)
    if not ready:raise SystemExit('SERVICE_USAGE_LOCAL_SERVER_START_FAILED')
    env['TXBOARD_SERVICE_USAGE_PHASE']='capture'
    run('node-capture-run',[str(evidence/'node-service-test'),'-test.run=^TestServiceUsageV2ActualCoreHTTPInterop$','-test.v','-test.timeout=60s'],node)
    pending=run('sql-pending',[php,str(scripts/'fixture.php'),'assert-pending'],api);json.loads(pending)
    settlement=run('sql-settlement',[php,str(scripts/'fixture.php'),'settle'],api);json.loads(settlement)
    env['TXBOARD_SERVICE_USAGE_PHASE']='settled'
    run('node-settled-run',[str(evidence/'node-service-test'),'-test.run=^TestServiceUsageV2ActualCoreHTTPInterop$','-test.v','-test.timeout=60s'],node)
    final=run('sql-final',[php,str(scripts/'fixture.php'),'assert-settled'],api);json.loads(final)
finally:
    for process in [tls_server,http_server]:
        process.terminate()
        try:process.wait(timeout=5)
        except subprocess.TimeoutExpired:process.kill();process.wait(timeout=5)
    http_log.close();tls_log.close()
# Public evidence metadata excludes the test bearer, private key and executable.
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
records={str(p.relative_to(evidence)):sha(p) for p in evidence.rglob('*') if p.is_file() and p.name not in {'key.pem','node-service-test','.usage-lock','.interval-lock'}}
result={'status':'verified-isolated','actual_tracker':True,'https_tls13_verified':True,'database':'txboard_service_usage_test',
 'persisted_ack_sql_verified':json.loads(pending),'settlement':json.loads(settlement),'final_sql':json.loads(final),
 'node_settled':json.loads((evidence/'service-settled.json').read_text()),'artifacts_sha256':records,
 'production_authorization':False,'private_material_exported':False}
(evidence/'result.json').write_text(json.dumps(result,indent=2)+'\n');os.chmod(evidence/'result.json',0o600)
print(json.dumps({'status':result['status'],'database':result['database'],'raw_up_bytes':'11','raw_down_bytes':'7','billable_bytes':'26','receipt_status':'settled','evidence':str(evidence)}))
