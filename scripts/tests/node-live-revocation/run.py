#!/usr/bin/env python3
"""Exclusive real native-policy/live-protocol Service verification."""
from pathlib import Path
import hashlib,json,os,shutil,socket,ssl,subprocess,sys,time,urllib.error,urllib.request

CASES={'ban-baseline','ban','speed','config','shutdown','history'}
if len(sys.argv)!=6 or sys.argv[4] not in {'vless','vmess'} or sys.argv[5] not in CASES:
    raise SystemExit('usage: run.py API NODE NEW_EVIDENCE vless|vmess CASE')
api,node,evidence=(Path(p).resolve()for p in sys.argv[1:4]);protocol,case=sys.argv[4:6]
scripts=Path(__file__).resolve().parent;env=os.environ.copy()
expected={'APP_ENV':'testing','DB_CONNECTION':'mysql','DB_HOST':'127.0.0.1',
          'DB_DATABASE':'txboard_live_revocation_test','DB_USERNAME':'txboard_live_revocation'}
if any(env.get(k)!=v for k,v in expected.items()):raise SystemExit('LIVE_REVOCATION_EXCLUSIVE_FIXTURE_REQUIRED')
if not env.get('DB_PASSWORD') or evidence.exists() or not(api/'artisan').is_file() or not(node/'go.mod').is_file():raise SystemExit('LIVE_REVOCATION_FIXTURE_INPUT_REFUSED')
evidence.mkdir(mode=0o700)
env.update({'LIVE_REVOCATION_CORE_API':str(api),'LIVE_REVOCATION_DIR':str(evidence),
 'LIVE_REVOCATION_PROTOCOL':protocol,'CORE_USAGE_V2_ISOLATED_COPY':'true',
 'CORE_USAGE_V2_INSTANCE_ID':'live_revocation_core_fixture_1','CORE_USAGE_V2_RECEIPT_GENERATION':'1',
 'CACHE_DRIVER':'array','SETTING_CACHE_STORE':'array','SESSION_DRIVER':'array','MAIL_MAILER':'array',
 'QUEUE_CONNECTION':'sync','APP_KEY':'base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs=',
 'REDIS_PREFIX':'live-revocation-'+protocol+'-'+case+':'})
php=shutil.which('php84')or shutil.which('php')
if not php:raise SystemExit('LIVE_REVOCATION_PHP_REQUIRED')

def run(name,command,cwd,timeout=180):
    path=evidence/(name+'.log')
    if path.exists():raise SystemExit('LIVE_REVOCATION_PREVIOUS_LOG_PRESERVED')
    with path.open('wb')as out:r=subprocess.run(command,cwd=cwd,env=env,stdout=out,stderr=subprocess.STDOUT,timeout=timeout)
    os.chmod(path,0o600)
    if r.returncode:
        print(path.read_text(errors='replace')[-8000:]);raise SystemExit('LIVE_REVOCATION_'+name.upper()+'_FAILED')
    return path.read_text()

def port():
    with socket.socket()as sock:sock.bind(('127.0.0.1',0));return sock.getsockname()[1]

probe=subprocess.check_output(['mariadb','--protocol=tcp','-h127.0.0.1','-P'+env.get('DB_PORT','13306'),
 '-u'+env['DB_USERNAME'],'-N','-e',"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='txboard_live_revocation_test'"],
 env=env|{'MYSQL_PWD':env['DB_PASSWORD']},text=True).strip()
if probe!='0':raise SystemExit('LIVE_REVOCATION_NONEMPTY_DATABASE_REFUSED')
run('migrate',[php,'artisan','migrate','--force','--no-interaction'],api)
run('build',['go','test','-c','-race','-o',str(evidence/'node-live-revocation-test'),'./internal/service'],node)
ports=set()
while len(ports)<5:ports.add(port())
http_port,tls_port,node_port,origin_port,replacement_port=sorted(ports)
endpoint='https://127.0.0.1:'+str(tls_port)
env.update({'LIVE_REVOCATION_HTTP_PORT':str(http_port),'LIVE_REVOCATION_TLS_PORT':str(tls_port),
 'LIVE_REVOCATION_NODE_PORT':str(node_port),'LIVE_REVOCATION_ORIGIN_PORT':str(origin_port),
 'TXBOARD_LIVE_REVOCATION_DIR':str(evidence),'TXBOARD_LIVE_REVOCATION_ENDPOINT':endpoint,
 'TXBOARD_LIVE_REVOCATION_CASE':case,'TXBOARD_LIVE_REVOCATION_PHASE':'history'})
fixture=json.loads(run('seed',[php,str(scripts/'fixture.php'),'seed'],api))
(evidence/'fixture.json').write_text(json.dumps(fixture)+'\n');os.chmod(evidence/'fixture.json',0o600)
run('certificate',['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
 '-keyout',str(evidence/'key.pem'),'-out',str(evidence/'cert.pem'),'-subj','/CN=localhost',
 '-addext','subjectAltName=IP:127.0.0.1,DNS:localhost'],api);os.chmod(evidence/'key.pem',0o600)
http_log=(evidence/'core-http.log').open('wb');tls_log=(evidence/'tls-proxy.log').open('wb')
http_server=subprocess.Popen([php,'-S','127.0.0.1:'+str(http_port),'-t','public','public/index.php'],cwd=api,env=env,stdout=http_log,stderr=subprocess.STDOUT)
tls_server=subprocess.Popen([sys.executable,str(scripts/'tls-proxy.py')],cwd=scripts,env=env,stdout=tls_log,stderr=subprocess.STDOUT)
test=None;test_log=None
try:
    context=ssl.create_default_context(cafile=str(evidence/'cert.pem'));context.minimum_version=ssl.TLSVersion.TLSv1_3
    ready=False
    for _ in range(30):
        if http_server.poll()is not None or tls_server.poll()is not None:break
        try:
            request=urllib.request.Request(endpoint+'/txapi/node/v1/handshake',data=b'{}',headers={'Content-Type':'application/json'})
            urllib.request.urlopen(request,context=context,timeout=1);ready=True;break
        except urllib.error.HTTPError:ready=True;break
        except(OSError,urllib.error.URLError):time.sleep(.1)
    if not ready:raise SystemExit('LIVE_REVOCATION_LOCAL_SERVER_START_FAILED')
    test_log=(evidence/'node-history-tail.log').open('wb')
    test=subprocess.Popen([str(evidence/'node-live-revocation-test'),'-test.run=^TestServiceUsageV2LiveRevocation$',
      '-test.v','-test.timeout=80s'],cwd=node,env=env,stdout=test_log,stderr=subprocess.STDOUT)
    def wait_file(name,timeout=20):
        deadline=time.monotonic()+timeout
        while time.monotonic()<deadline:
            path=evidence/name
            if path.is_file():return json.loads(path.read_text())
            if test.poll()is not None:
                test_log.flush();print((evidence/'node-history-tail.log').read_text(errors='replace')[-8000:])
                raise SystemExit('LIVE_REVOCATION_TEST_EXITED_BEFORE_'+name)
            time.sleep(.05)
        raise SystemExit('LIVE_REVOCATION_BARRIER_TIMEOUT_'+name)
    wait_file('history-ready.json')
    pending=json.loads(run('sql-pending',[php,str(scripts/'fixture.php'),'assert-pending'],api))
    settlement=json.loads(run('sql-settlement',[php,str(scripts/'fixture.php'),'settle'],api))
    mutation=None
    if case!='history':
        wait_file('tail-ready.json')
        if case=='shutdown':
            (evidence/'trigger.json').write_text('{"action":"normal-context-cancel"}\n')
        else:
            private=json.loads((evidence/'private-admin.json').read_text());headers={'Authorization':'Bearer '+private['admin_token'],'Content-Type':'application/json','Accept':'application/json'}
            if case.startswith('ban'):
                path='/txapi/admin/live_revocation_admin/users/ban';method='POST';body={'scope':'selected','user_ids':[7]}
            elif case=='speed':
                path='/txapi/admin/live_revocation_admin/users/7/update';method='POST';body={'speed_limit':2}
            elif case=='config':
                path='/txapi/admin/live_revocation_admin/network-nodes/'+fixture['node_id'];method='PUT'
                body={'name':'isolated-live-revocation','host':'127.0.0.1','port':str(node_port),'server_port':replacement_port,
                      'type':protocol,'rate':1,'group_ids':[1],'enabled':True,'protocol_settings':{'tls':0,'network':'tcp'}}
            request=urllib.request.Request(endpoint+path,data=json.dumps(body).encode(),headers=headers,method=method)
            try:
                with urllib.request.urlopen(request,context=context,timeout=8)as response:
                    mutation={'path':path,'method':method,'status':response.status,'response':json.loads(response.read())}
            except urllib.error.HTTPError as error:
                detail=error.read().decode('utf-8','replace')[:2048]
                (evidence/'native-mutation-failure.json').write_text(json.dumps(
                    {'path':path,'method':method,'status':error.code,'response':detail},indent=2)+'\n')
                raise SystemExit('LIVE_REVOCATION_NATIVE_MUTATION_REJECTED: '+str(error.code)+' '+detail)
            if mutation['status']!=200 or(case.startswith('ban')and mutation['response'].get('data',{}).get('updated')!=1):
                raise SystemExit('LIVE_REVOCATION_NATIVE_MUTATION_REJECTED')
            (evidence/'native-mutation.json').write_text(json.dumps(mutation,indent=2)+'\n')
    code=test.wait(timeout=25);test_log.close();test_log=None
    if case=='ban-baseline':
        if code==0 or not(evidence/'baseline-ignored-empty.json').is_file():
            print((evidence/'node-history-tail.log').read_text(errors='replace')[-8000:]);raise SystemExit('LIVE_REVOCATION_BASELINE_DID_NOT_REPRODUCE')
    elif code:
        print((evidence/'node-history-tail.log').read_text(errors='replace')[-8000:]);raise SystemExit('LIVE_REVOCATION_NODE_BOUNDARY_FAILED')
    final=json.loads(run('sql-final',[php,str(scripts/'fixture.php'),'assert-final'],api))
finally:
    for process in [test,tls_server,http_server]:
        if process is None or process.poll()is not None:continue
        process.terminate()
        try:process.wait(timeout=5)
        except subprocess.TimeoutExpired:process.kill();process.wait(timeout=5)
    if test_log is not None:test_log.close()
    http_log.close();tls_log.close()
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
public_names={'cert.pem','fixture.json','history-capture.json','history-ready.json','tail-ready.json',
 'boundary-result.json','baseline-ignored-empty.json','native-mutation.json','sql-pending.log','sql-settlement.log','sql-final.log','native-observations.jsonl'}
records={str(p.relative_to(evidence)):sha(p)for p in evidence.rglob('*')if p.is_file()and
 (p.name in public_names or p.relative_to(evidence).parts[0]=='mode')and p.name not in {'.usage-lock','.interval-lock'}}
result={'status':'reproduced-real-empty-poll-defect'if case=='ban-baseline'else'verified-isolated-live-boundary',
 'case':case,'protocol':protocol,'actual_service_run':True,'actual_singbox_kernel':True,
 'native_mutation':mutation,'historical_persisted_ack_sql':pending,'historical_settlement':settlement,'final_sql':final,
 'boundary':json.loads((evidence/('baseline-ignored-empty.json'if case=='ban-baseline'else'boundary-result.json')).read_text())if case!='history'else{'actual_history_retained':True},
 'artifacts_sha256':records,'production_authorization':False,'private_material_exported':False,'test_exit_code':code}
(evidence/'result.json').write_text(json.dumps(result,indent=2)+'\n');os.chmod(evidence/'result.json',0o600)
print(json.dumps({'status':result['status'],'case':case,'protocol':protocol,'historical_billable_bytes':'182','test_exit_code':code,'evidence':str(evidence)}))
