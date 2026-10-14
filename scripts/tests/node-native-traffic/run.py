#!/usr/bin/env python3
"""Exclusive native traffic acceptance, async settlement, quota/reset gate."""
from pathlib import Path
import hashlib,json,os,shutil,signal,socket,ssl,subprocess,sys,time,urllib.error,urllib.request
from decimal import Decimal,ROUND_FLOOR
from tls_proxy import NativeTLSProxy
if len(sys.argv)!=5 or sys.argv[4]not in {'vless','vmess'}:raise SystemExit('usage: run.py API NODE NEW_EVIDENCE vless|vmess')
api,node,evidence=(Path(p).resolve()for p in sys.argv[1:4]);protocol=sys.argv[4];scripts=Path(__file__).resolve().parent
expected={'APP_ENV':'testing','DB_CONNECTION':'mysql','DB_HOST':'127.0.0.1','DB_DATABASE':'txboard_native_traffic_test','DB_USERNAME':'txboard_native_traffic'}
if any(os.environ.get(k)!=v for k,v in expected.items())or evidence.exists()or not os.environ.get('DB_PASSWORD'):raise SystemExit('NATIVE_TRAFFIC_EXCLUSIVE_FIXTURE_REQUIRED')
php=shutil.which('php84')or shutil.which('php');mysql=shutil.which('mariadb')or shutil.which('mysql')
if not php or not mysql or not shutil.which('go')or not shutil.which('openssl'):raise SystemExit('NATIVE_TRAFFIC_TOOLS_REQUIRED')
go=json.loads(subprocess.check_output(['go','env','-json','GOPATH','GOMODCACHE','GOCACHE','CGO_ENABLED'],text=True))
if any(not go.get(k)for k in ('GOPATH','GOMODCACHE','GOCACHE'))or go.get('CGO_ENABLED')!='1':raise SystemExit('NATIVE_TRAFFIC_RACE_ENV_REQUIRED')
env={k:os.environ[k]for k in ('PATH','LANG','LC_ALL','TMPDIR','GOTOOLCHAIN')if k in os.environ};env.update(go)
env.update(expected|{'DB_PORT':os.environ.get('DB_PORT','13306'),'DB_PASSWORD':os.environ['DB_PASSWORD'],'APP_DEBUG':'false','CACHE_DRIVER':'redis','SETTING_CACHE_STORE':'redis','SESSION_DRIVER':'array','QUEUE_CONNECTION':'redis','REDIS_QUEUE_CONNECTION':'default','MAIL_MAILER':'array','NATIVE_TRAFFIC_RECEIPTS_ENABLED':'true','AUTHORITY_WRITER_FENCE_ENABLED':'false','CORE_USAGE_V2_ISOLATED_COPY':'false','TXBOARD_NATIVE_NODE_WS_ENABLED':'true','APP_KEY':'base64:ZdD07lazsdQQmdBAJ2tA5WjufeI2hP8xlP6zgmvCMZs=','REDIS_HOST':'127.0.0.1','REDIS_PORT':os.environ.get('REDIS_PORT','16379'),'REDIS_DB':os.environ.get('REDIS_DB','60'),'REDIS_CACHE_DB':os.environ.get('REDIS_CACHE_DB','61'),'REDIS_PREFIX':'native-traffic-'+hashlib.sha256(str(evidence).encode()).hexdigest()[:12]+':','NATIVE_TRAFFIC_API':str(api),'NATIVE_TRAFFIC_DIR':str(evidence),'NATIVE_TRAFFIC_PROTOCOL':protocol})
if not env['REDIS_DB'].isdigit()or not env['REDIS_CACHE_DB'].isdigit()or int(env['REDIS_DB'])==int(env['REDIS_CACHE_DB']):raise SystemExit('NATIVE_TRAFFIC_DISTINCT_REDIS_REQUIRED')
evidence.mkdir(mode=0o700)
def artifact(name,value):
    path=evidence/name;temp=evidence/(name+'.write')
    with temp.open('x',encoding='utf-8')as out:os.chmod(temp,0o600);json.dump(value,out,sort_keys=True,indent=2);out.write('\n');out.flush();os.fsync(out.fileno())
    os.rename(temp,path);directory=os.open(evidence,os.O_RDONLY)
    try:os.fsync(directory)
    finally:os.close(directory)
def command(name,args,cwd,timeout=180):
    path=evidence/(name+'.log')
    with path.open('xb')as out:os.chmod(path,0o600);result=subprocess.run(args,cwd=cwd,env=env,stdout=out,stderr=subprocess.STDOUT,timeout=timeout)
    if result.returncode:raise RuntimeError('NATIVE_TRAFFIC_'+name.upper()+'_FAILED')
    return path.read_text()
def port():
    with socket.socket()as sock:sock.bind(('127.0.0.1',0));return sock.getsockname()[1]
ports=set()
while len(ports)<5:ports.add(port())
http_port,ws_port,tls_port,node_port,origin_port=sorted(ports);env.update(NATIVE_TRAFFIC_NODE_PORT=str(node_port),NATIVE_TRAFFIC_ORIGIN_PORT=str(origin_port))
query="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='txboard_native_traffic_test'"
probe=subprocess.check_output([mysql,'--protocol=tcp','-h127.0.0.1','-P'+env['DB_PORT'],'-u'+env['DB_USERNAME'],'-N','-e',query],env=env|{'MYSQL_PWD':env['DB_PASSWORD']},text=True).strip()
if probe!='0':raise SystemExit('NATIVE_TRAFFIC_NONEMPTY_SCHEMA_REFUSED')
command('migrate',[php,'artisan','migrate','--force','--no-interaction'],api)
command('build',['go','test','-c','-race','-o',str(evidence/'native-traffic-test'),'./internal/service'],node,600)
fixture=json.loads(command('seed',[php,str(scripts/'fixture.php'),'seed'],api));artifact('fixture.json',fixture)
command('certificate',['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(evidence/'key.pem'),'-out',str(evidence/'cert.pem'),'-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1'],evidence)
os.chmod(evidence/'key.pem',0o600);os.chmod(evidence/'cert.pem',0o600)
endpoint='https://127.0.0.1:'+str(tls_port);env.update(TXBOARD_NATIVE_TRAFFIC_DIR=str(evidence),TXBOARD_NATIVE_TRAFFIC_URL=endpoint,SSL_CERT_FILE=str(evidence/'cert.pem'),APP_URL=endpoint)
observations=evidence/'native-observations.jsonl';observations.touch(mode=0o600)
proxy=NativeTLSProxy(tls_port,http_port,ws_port,str(evidence/'cert.pem'),str(evidence/'key.pem'),str(observations));context=ssl.create_default_context(cafile=str(evidence/'cert.pem'))
processes=[];logs=[];test=None;state_number=0

def start(name,args,cwd):
    path=evidence/(name+'.log');out=path.open('xb');os.chmod(path,0o600);logs.append(out);process=subprocess.Popen(args,cwd=cwd,env=env,stdout=out,stderr=subprocess.STDOUT,start_new_session=True);processes.append(process);return process

def request(path,method,payload,headers):
    encoded=None if payload is None else json.dumps(payload).encode();req=urllib.request.Request(endpoint+path,data=encoded,method=method,headers={'Content-Type':'application/json'}|headers)
    try:
        with urllib.request.urlopen(req,context=context,timeout=8)as response:return response.status,json.loads(response.read(4<<20))
    except urllib.error.HTTPError as error:return error.code,json.loads(error.read(4<<20))

def state():
    global state_number
    state_number+=1
    return json.loads(command('state-'+str(state_number),[php,str(scripts/'fixture.php'),'state'],api))

def wait_state(predicate,label,timeout=25):
    deadline=time.monotonic()+timeout
    while True:
        if test is not None and test.poll()is not None:raise RuntimeError('NATIVE_TRAFFIC_TEST_EARLY_EXIT_'+label)
        value=state()
        if predicate(value):return value
        if time.monotonic()>deadline:raise RuntimeError('NATIVE_TRAFFIC_'+label+'_TIMEOUT')
        time.sleep(.15)

def barrier(stage,callback):
    path=evidence/(stage+'.ready.json');deadline=time.monotonic()+40
    while not path.exists():
        if test.poll()is not None:raise RuntimeError('NATIVE_TRAFFIC_EXITED_BEFORE_'+stage)
        if time.monotonic()>deadline:raise RuntimeError('NATIVE_TRAFFIC_BARRIER_'+stage+'_TIMEOUT')
        time.sleep(.03)
    value=json.loads(path.read_text());result=callback(value);artifact(stage+'.ack.json',result or {'ok':True})

def immutable(row):return {k:row[k]for k in ('node_id','batch_id','raw_payload_hash','settlement_input','ledger_payload_hash','content_hash','accepted_at')}
def totals(value, require_settled=True):
    raw={};billed={}
    for row in value['receipts']:
        if require_settled and row['status']!='settled':raise RuntimeError('NATIVE_TRAFFIC_UNSETTLED_RECEIPT')
        prepared=json.loads(row['settlement_input']);rate=Decimal(str(prepared['server']['rate']))
        for uid,pair in prepared['data'].items():
            raw.setdefault(uid,[0,0]);billed.setdefault(uid,[0,0])
            for direction in (0,1):raw[uid][direction]+=int(pair[direction])
        segments=prepared.get('segments')
        if segments is None:
            entries=[(uid,pair)for uid,pair in prepared['data'].items()]
        else:
            entries=[(str(seg['user_id']),[seg['upload'],seg['download']])for seg in segments]
        for uid,pair in entries:
            billed.setdefault(uid,[0,0])
            for direction in (0,1):billed[uid][direction]+=int((Decimal(pair[direction])*rate).to_integral_value(rounding=ROUND_FLOOR))
    return raw,billed

def receipt_hashes(row):
    prepared=json.loads(row['settlement_input']);raw=prepared['data'];segments=prepared.get('segments')
    original=raw if segments is None else [raw,segments]
    ledger=[prepared['server']['rate'],raw] if segments is None else [prepared['server']['rate'],raw,segments]
    encode=lambda value:json.dumps(value,separators=(',',':'),ensure_ascii=False).encode()
    if row['raw_payload_hash']!=hashlib.sha256(encode(original)).hexdigest() or row['ledger_payload_hash']!=hashlib.sha256(encode(ledger)).hexdigest():raise RuntimeError('NATIVE_TRAFFIC_CANONICAL_RECEIPT_HASH_INVALID')

def check_periods(value):
    expected={}
    for row in value['receipts']:
        if row['status']!='settled':raise RuntimeError('NATIVE_TRAFFIC_PENDING_PERIOD_UNCONFIRMED')
        receipt_hashes(row);prepared=json.loads(row['settlement_input']);rate=Decimal(str(prepared['server']['rate']))
        if not prepared.get('segments'):raise RuntimeError('NATIVE_TRAFFIC_SIGNED_SEGMENTS_REQUIRED')
        for segment in prepared['segments']:
            fields=segment['period'].split('.')
            if len(fields)!=4 or fields[0]!='p1' or int(fields[1])!=segment['user_id']:raise RuntimeError('NATIVE_TRAFFIC_OBSERVED_PERIOD_IDENTITY_INVALID')
            identity=(segment['user_id'],int(fields[2]));target=expected.setdefault(identity,[0,0,0,0])
            for direction,key in enumerate(['upload','download']):
                target[direction]+=segment[key];target[direction+2]+=int((Decimal(segment[key])*rate).to_integral_value(rounding=ROUND_FLOOR))
    actual={(row['user_id'],row['reset_count']):[int(row[k])for k in ['raw_upload','raw_download','billed_upload','billed_download']]for row in value['period_usage']}
    if actual!=expected:raise RuntimeError('NATIVE_TRAFFIC_HISTORICAL_PERIOD_LEDGER_MISMATCH')
    for user in value['users']:
        current=expected.get((user['id'],int(user['reset_count'])),[0,0,0,0])
        if [int(user['u']),int(user['d'])]!=current[2:]:raise RuntimeError('NATIVE_TRAFFIC_OLD_BYTES_CHARGED_TO_CURRENT_PERIOD')
    return expected

def reset_primary(stage,epoch):
    status,body=request('/txapi/admin/native_traffic_admin/traffic-resets/users/'+str(fixture['users'][0]['id'])+'/reset','POST',{'reason':'isolated real '+stage},{'Authorization':bearer})
    if status!=200 or body.get('data',{}).get('user_id')!=fixture['users'][0]['id']:raise RuntimeError('NATIVE_TRAFFIC_PERIOD_ADMIN_RESET_FAILED')
    after=state();primary=next(u for u in after['users']if u['id']==fixture['users'][0]['id'])
    if int(primary['reset_count'])!=epoch or int(primary['u'])or int(primary['d'])or after['reset_log_count']!=epoch:raise RuntimeError('NATIVE_TRAFFIC_PERIOD_RESET_NOT_ATOMIC')
    return after

def process_fault(stage,receipt_id):
    path=evidence/(stage+'.log')
    with path.open('xb')as out:
        os.chmod(path,0o600)
        result=subprocess.run([php,str(scripts/'receipt_fault.php'),stage,str(receipt_id)],cwd=api,env=env,stdout=out,stderr=subprocess.STDOUT,timeout=30)
    observed=[json.loads(line)for line in path.read_text().splitlines()if line.startswith('{')]
    if result.returncode!=-signal.SIGKILL or len(observed)!=1 or observed[0].get('observed_stage')!=stage or observed[0].get('receipt_id')!=receipt_id:raise RuntimeError('NATIVE_TRAFFIC_EXACT_SIGKILL_STAGE_REQUIRED')
    return {'return_code':result.returncode,'observed':observed[0],'log_sha256':hashlib.sha256(path.read_bytes()).hexdigest()}

def stop_worker(process):
    if process.poll()is not None:raise RuntimeError('NATIVE_TRAFFIC_WORKER_EARLY_EXIT')
    os.killpg(process.pid,signal.SIGTERM)
    process.wait(timeout=10)

try:
    http=start('core-http',[php,'-S','127.0.0.1:'+str(http_port),'-t','public','public/index.php'],api)
    worker=start('core-workerman',[php,'artisan','ws-server','start','--host=127.0.0.1','--port='+str(ws_port)],api);proxy.start()
    deadline=time.monotonic()+10
    while True:
        if http.poll()is not None or worker.poll()is not None:raise RuntimeError('NATIVE_TRAFFIC_CORE_START_FAILED')
        try:
            with socket.create_connection(('127.0.0.1',ws_port),timeout=.2):pass
            status,_=request('/txapi/node/v1/handshake','POST',{},{} )
            if status==401:break
        except (OSError,ValueError,urllib.error.URLError):pass
        if time.monotonic()>deadline:raise RuntimeError('NATIVE_TRAFFIC_CORE_READY_TIMEOUT')
        time.sleep(.1)
    status,login=request('/txapi/auth/admin/login','POST',{'email':'native-traffic-admin@example.test','password':'SyntheticTrafficAdmin2026'},{} )
    bearer=login.get('data',{}).get('auth_data','')
    if status!=200 or not bearer.startswith('Bearer '):raise RuntimeError('NATIVE_TRAFFIC_ADMIN_LOGIN_FAILED')
    env['TXBOARD_NATIVE_TRAFFIC_PHASE']='capture'
    test=start('node-live',[str(evidence/'native-traffic-test'),'-test.run=^TestNativeCoreTrafficLive$','-test.v','-test.timeout=240s'],node)
    stages={};first_receipt={};worker_process=None
    def initial(value):
        global test
        accepted=wait_state(lambda s:len(s['receipts'])==1 and s['receipts'][0]['status']=='pending','DURABLE_ACCEPTANCE',12)
        if accepted['ledger']or any(int(u['u'])or int(u['d'])for u in accepted['users']):raise RuntimeError('NATIVE_TRAFFIC_ACK_CLAIMED_SETTLEMENT')
        first=accepted['receipts'][0];first_receipt.update(immutable(first));prepared=json.loads(first['settlement_input'])
        expected={str(fixture['users'][0]['id']):[43,79]}
        if prepared['data']!=expected or Decimal(str(prepared['server']['rate']))!=Decimal('1.5'):raise RuntimeError('NATIVE_TRAFFIC_ACTUAL_DIRECTION_OR_RATE_INVALID')
        segments=prepared.get('segments')
        if not segments or len(segments)!=1 or segments[0]!={'user_id':fixture['users'][0]['id'],'period':value['observed_period'],'upload':43,'download':79}:raise RuntimeError('NATIVE_TRAFFIC_ACTUAL_PERIOD_SEGMENT_REQUIRED')
        receipt_hashes(first)
        pending_files=list((evidence/'kernel').glob('*.pending.json'))
        if len(pending_files)!=1:raise RuntimeError('NATIVE_TRAFFIC_LOST_ACK_PENDING_SPOOL_REQUIRED')
        pending_bytes=pending_files[0].read_bytes();pending=json.loads(pending_bytes)
        if pending.get('Payload',{}).get('BatchID')!=first['batch_id']or pending.get('Payload',{}).get('Traffic')!=expected or pending.get('Payload',{}).get('traffic_segments')!=segments:raise RuntimeError('NATIVE_TRAFFIC_PENDING_ID_BODY_MISMATCH')
        artifact('lost-ack-spool.json',{'batch_id':first['batch_id'],'pending_file_sha256':hashlib.sha256(pending_bytes).hexdigest(),'raw_payload_hash':first['raw_payload_hash'],'actual_raw_direction':expected})
        events=proxy.event_snapshot()
        if not any(e.get('ack_dropped_after_core_response')and e.get('batch_id')==first['batch_id']for e in events):raise RuntimeError('NATIVE_TRAFFIC_REAL_ACK_LOSS_REQUIRED')
        status,listing=request('/txapi/admin/native_traffic_admin/network-nodes','GET',None,{'Authorization':bearer});row=next((r for r in listing.get('data',[])if r['id']==fixture['node_id']),None)
        if status!=200 or row is None:raise RuntimeError('NATIVE_TRAFFIC_NODE_DTO_REQUIRED')
        fields=['type','name','host','port','server_port','rate','show','enabled','group_ids','route_ids','parent_id','machine_id','tags','custom_outbounds','custom_routes','cert_config','protocol_settings'];update={k:row[k]for k in fields if k in row};update['rate']=2.5;update['port']=str(update['port'])
        status,_=request('/txapi/admin/native_traffic_admin/network-nodes/'+str(fixture['node_id']),'PUT',update,{'Authorization':bearer})
        if status!=200:raise RuntimeError('NATIVE_TRAFFIC_ADMIN_RATE_CHANGE_FAILED')
        # Real process death with pending-only storage; the replacement process
        # gets fresh snapshots but never manufactures another initial43/79.
        old_pid=test.pid
        os.killpg(old_pid,signal.SIGKILL)
        if test.wait(timeout=5)!=-signal.SIGKILL:raise RuntimeError('NATIVE_TRAFFIC_NODE_SIGKILL_REQUIRED')
        if pending_files[0].read_bytes()!=pending_bytes:raise RuntimeError('NATIVE_TRAFFIC_RESTART_CHANGED_PENDING_BODY')
        env['TXBOARD_NATIVE_TRAFFIC_PHASE']='resume'
        test=start('node-restarted',[str(evidence/'native-traffic-test'),'-test.run=^TestNativeCoreTrafficLive$','-test.v','-test.timeout=240s'],node)
        artifact('pending-process-restart.json',{'old_pid':old_pid,'new_pid':test.pid,'old_process_return_code':-signal.SIGKILL,'pending_file_sha256':hashlib.sha256(pending_bytes).hexdigest(),'same_batch_id':first['batch_id'],'no_new_initial_traffic':True})
        deadline=time.monotonic()+25
        while True:
            attempts=[e for e in proxy.event_snapshot()if e['kind']=='http'and e['path']=='/txapi/node/v1/report'and e.get('batch_id')==first['batch_id']and e['status']==202]
            if len(attempts)>=2:break
            if time.monotonic()>deadline:raise RuntimeError('NATIVE_TRAFFIC_SAME_ID_REPLAY_TIMEOUT')
            time.sleep(.05)
        if any(e.get('traffic')!=expected or e.get('traffic_segments')!=segments for e in attempts):raise RuntimeError('NATIVE_TRAFFIC_REPLAY_BODY_CHANGED')
        replay=state()
        if len(replay['receipts'])!=1 or immutable(replay['receipts'][0])!=first_receipt or replay['ledger']:raise RuntimeError('NATIVE_TRAFFIC_REPLAY_CHANGED_ACCEPTANCE')
        dropped=json.loads(command('drop-wakeup',[php,str(scripts/'fixture.php'),'drop-wakeup'],api))
        if dropped['pending_receipts']!=1 or dropped['queued_jobs_removed']<1:raise RuntimeError('NATIVE_TRAFFIC_LOST_WAKEUP_SQL_REQUIRED')
        before_fault=process_fault('before-ledger-commit',first['id']);before=state()
        if before['ledger'] or immutable(before['receipts'][0])!=first_receipt or before['receipts'][0]['status']!='pending' or any(int(u['u'])or int(u['d'])for u in before['users']):raise RuntimeError('NATIVE_TRAFFIC_PRECOMMIT_SIGKILL_ROLLBACK_FAILED')
        after_fault=process_fault('after-ledger-commit',first['id']);after=state()
        primary_after=next(u for u in after['users']if u['id']==fixture['users'][0]['id'])
        if len(after['ledger'])!=1 or after['receipts'][0]['status']!='pending' or immutable(after['receipts'][0])!=first_receipt or [int(primary_after['u']),int(primary_after['d'])]!=[64,118]:raise RuntimeError('NATIVE_TRAFFIC_POSTCOMMIT_SIGKILL_BOUNDARY_FAILED')
        artifact('settlement-sigkill.json',{'before':before_fault,'after':after_fault,'before_state':before,'after_state':after})
        command('sql-recovery',[php,'artisan','traffic:native-recover','--limit=10'],api)
        recovered=state();raw,billed=totals(recovered)
        primary=next(u for u in recovered['users']if u['id']==fixture['users'][0]['id'])
        if len(recovered['ledger'])!=1 or immutable(recovered['receipts'][0])!=first_receipt or [int(primary['u']),int(primary['d'])]!=[64,118]or [int(recovered['node']['u']),int(recovered['node']['d'])]!=[43,79]:raise RuntimeError('NATIVE_TRAFFIC_FROZEN_RECOVERY_MISMATCH')
        check_periods(recovered)
        stages['initial']=accepted;stages['recovered']=recovered
        return {'ok':True}
    barrier('initial-bytes',initial)
    worker_process=start('traffic-worker',[php,'artisan','queue:work','redis','--queue=traffic_fetch','--sleep=1','--tries=1','--timeout=25'],api)
    def quota(value):
        settled=wait_state(lambda s:len(s['receipts'])>=2 and all(r['status']=='settled'for r in s['receipts']) and totals(s)[0]=={str(fixture['users'][0]['id']):[4139,8271],str(fixture['users'][1]['id']):[37,19]} and any(u['id']==fixture['users'][0]['id']and int(u['u'])+int(u['d'])>=int(u['transfer_enable'])for u in s['users']),'ASYNC_SETTLEMENT',20)
        if worker_process.poll()is not None:raise RuntimeError('NATIVE_TRAFFIC_REAL_WORKER_FAILED')
        raw,billed=totals(settled)
        for user in settled['users']:
            if [int(user['u']),int(user['d'])]!=billed.get(str(user['id']),[0,0]):raise RuntimeError('NATIVE_TRAFFIC_BILLED_DIRECTION_MISMATCH')
        if len(settled['ledger'])!=len(settled['receipts']):raise RuntimeError('NATIVE_TRAFFIC_UNIQUE_LEDGER_MISMATCH')
        if [int(settled['node']['u']),int(settled['node']['d'])]!=[sum(p[0]for p in raw.values()),sum(p[1]for p in raw.values())]:raise RuntimeError('NATIVE_TRAFFIC_NODE_RAW_MISMATCH')
        command('quota-notify',[php,'artisan','check:traffic-exceeded'],api);stages['quota']=settled;return {'ok':True}
    barrier('quota-bytes',quota)
    reset_snapshot={}
    def reset(value):
        before=wait_state(lambda s:all(r['status']=='settled'for r in s['receipts'])and totals(s)[0].get(str(fixture['users'][1]['id']))==[74,38],'SIBLING_USAGE',12)
        status,body=request('/txapi/admin/native_traffic_admin/traffic-resets/users/'+str(fixture['users'][0]['id'])+'/reset','POST',{'reason':'isolated real native quota recovery'}, {'Authorization':bearer})
        if status!=200 or body.get('data',{}).get('user_id')!=fixture['users'][0]['id']:raise RuntimeError('NATIVE_TRAFFIC_REAL_ADMIN_RESET_FAILED')
        after=state();primary=next(u for u in after['users']if u['id']==fixture['users'][0]['id'])
        if int(primary['u'])or int(primary['d'])or int(primary['reset_count'])!=1 or after['reset_log_count']!=1:raise RuntimeError('NATIVE_TRAFFIC_RESET_ATOMIC_RESULT_REQUIRED')
        reset_snapshot.update(after);stages['pre-reset']=before;return {'ok':True}
    barrier('quota-enforced',reset)
    def after_reset(value):
        settled=wait_state(lambda s:all(r['status']=='settled'for r in s['receipts'])and next((int(u['u'])==42 and int(u['d'])==72 for u in s['users']if u['id']==fixture['users'][0]['id']),False),'RESET_FRESH_SETTLEMENT',15)
        raw,billed=totals(settled);expected_raw={str(fixture['users'][0]['id']):[4156,8300],str(fixture['users'][1]['id']):[74,38]}
        if raw!=expected_raw or len(settled['ledger'])!=len(settled['receipts']):raise RuntimeError('NATIVE_TRAFFIC_COMPLETE_RAW_BYTE_ACCOUNTING_FAILED')
        for user in settled['users']:
            stats=[r for r in settled['stats']if r['user_id']==user['id']]
            if [sum(int(r['u'])for r in stats),sum(int(r['d'])for r in stats)]!=billed.get(str(user['id']),[0,0]):raise RuntimeError('NATIVE_TRAFFIC_STAT_DIRECTION_MISMATCH')
        if immutable(settled['receipts'][0])!=first_receipt:raise RuntimeError('NATIVE_TRAFFIC_OLD_ACCEPTANCE_CHANGED')
        check_periods(settled)
        stages['reset']=settled;return {'ok':True}
    barrier('reset-bytes',after_reset)
    period_phase={}
    def queued_start(value):
        stop_worker(worker_process)
        current=state()
        if any(r['status']!='settled'for r in current['receipts']):raise RuntimeError('NATIVE_TRAFFIC_QUEUE_LATE_STARTED_UNDRAINED')
        period_phase['queue_before']=current;period_phase['queue_old_period']=value['observed_period']
        return {'ok':True}
    barrier('queued-late-start',queued_start)
    def queued_old(value):
        accepted=wait_state(lambda s:totals(s,False)[0]=={str(fixture['users'][0]['id']):[4179,8331],str(fixture['users'][1]['id']):[74,38]},'QUEUED_OLD_ACCEPTANCE',15)
        newrows=[r for r in accepted['receipts']if r['id']not in {old['id']for old in period_phase['queue_before']['receipts']}]
        if not newrows or any(row['status']!='pending'for row in newrows):raise RuntimeError('NATIVE_TRAFFIC_QUEUE_WAS_NOT_PAUSED')
        for row in newrows:
            receipt_hashes(row)
            if any(seg['period']!=value['observed_period']for seg in json.loads(row['settlement_input'])['segments']):raise RuntimeError('NATIVE_TRAFFIC_PRE_RESET_SEGMENT_WAS_RELABELLED')
        period_phase['queue_accepted_immutable']={r['id']:immutable(r)for r in newrows}
        period_phase['queue_reset']=reset_primary('queued-late-settlement',2)
        worker_processes.append(start('traffic-worker-periods',[php,'artisan','queue:work','redis','--queue=traffic_fetch','--sleep=1','--tries=1','--timeout=25'],api))
        settled=wait_state(lambda s:all(r['status']=='settled'for r in s['receipts']),'QUEUED_LATE_SETTLEMENT',15)
        check_periods(settled)
        for row in settled['receipts']:
            if row['id']in period_phase['queue_accepted_immutable']and immutable(row)!=period_phase['queue_accepted_immutable'][row['id']]:raise RuntimeError('NATIVE_TRAFFIC_QUEUED_IMMUTABLE_INPUT_CHANGED')
        stages['queued-late']=settled;return {'ok':True}
    worker_processes=[]
    barrier('queued-late-bytes',queued_old)
    def queued_fresh(value):
        settled=wait_state(lambda s:totals(s,False)[0]=={str(fixture['users'][0]['id']):[4220,8384],str(fixture['users'][1]['id']):[74,38]}and all(r['status']=='settled'for r in s['receipts']),'QUEUED_FRESH_USAGE',15)
        check_periods(settled)
        current=next(u for u in settled['users']if u['id']==fixture['users'][0]['id'])
        if [int(current['u']),int(current['d'])]!=[102,132]:raise RuntimeError('NATIVE_TRAFFIC_NEW_EPOCH2_QUOTA_INVALID')
        stages['queued-fresh']=settled;return {'ok':True}
    barrier('queued-fresh-bytes',queued_fresh)
    def held_start(value):
        period_phase['held_before']=state();proxy.hold_reports();return {'ok':True}
    barrier('held-report-start',held_start)
    def held_old(value):
        deadline=time.monotonic()+12
        while not any(e['kind']=='report-held'and e.get('traffic',{}).get(str(fixture['users'][0]['id']))==[59,61]for e in proxy.event_snapshot()):
            if time.monotonic()>deadline:raise RuntimeError('NATIVE_TRAFFIC_REAL_OLD_REPORT_NOT_HELD')
            time.sleep(.03)
        before_reset=state()
        if before_reset['receipts']!=period_phase['held_before']['receipts']:raise RuntimeError('NATIVE_TRAFFIC_HELD_REPORT_ALREADY_ACCEPTED')
        period_phase['held_old_period']=value['observed_period'];period_phase['held_reset']=reset_primary('held-old-report',3)
        return {'ok':True}
    barrier('held-old-bytes',held_old)
    def held_new(value):
        if value['observed_epoch']!=3:raise RuntimeError('NATIVE_TRAFFIC_NODE_NEW_PERIOD_NOT_OBSERVED')
        period_phase['observed_current_period']=value['observed_period']
        proxy.drop_next_report_ack()
        proxy.release_reports()
        settled=wait_state(lambda s:totals(s,False)[0]=={str(fixture['users'][0]['id']):[4279,8445],str(fixture['users'][1]['id']):[74,38]}and all(r['status']=='settled'for r in s['receipts']),'HELD_OLD_PERIOD_SETTLEMENT',15)
        check_periods(settled)
        newrows=[r for r in settled['receipts']if r['id']not in {old['id']for old in period_phase['held_before']['receipts']}]
        if not newrows or any(seg['period']!=period_phase['held_old_period']for row in newrows for seg in json.loads(row['settlement_input'])['segments']):raise RuntimeError('NATIVE_TRAFFIC_DELAYED_REPORT_RELABELLED_CURRENT')
        period_phase['held_rows_immutable']={row['id']:immutable(row)for row in newrows}
        period_phase['held_batch_ids']=[row['batch_id']for row in newrows]
        stages['held-late']=settled;return {'ok':True}
    barrier('held-new-period',held_new)
    def held_fresh(value):
        settled=wait_state(lambda s:totals(s,False)[0]=={str(fixture['users'][0]['id']):[4346,8516],str(fixture['users'][1]['id']):[74,38]}and all(r['status']=='settled'for r in s['receipts']),'FINAL_EPOCH3_USAGE',25)
        check_periods(settled);raw,billed=totals(settled)
        current=next(u for u in settled['users']if u['id']==fixture['users'][0]['id'])
        if [int(current['u']),int(current['d'])]!=[167,177]or int(current['reset_count'])!=3 or len(settled['ledger'])!=len(settled['receipts']):raise RuntimeError('NATIVE_TRAFFIC_FINAL_PERIOD_ACCOUNTING_INVALID')
        for user in settled['users']:
            stats=[row for row in settled['stats']if row['user_id']==user['id']]
            if [sum(int(r['u'])for r in stats),sum(int(r['d'])for r in stats)]!=billed.get(str(user['id']),[0,0]):raise RuntimeError('NATIVE_TRAFFIC_FINAL_HISTORICAL_STATS_MISMATCH')
        if [int(settled['node']['u']),int(settled['node']['d'])]!=[sum(v[0]for v in raw.values()),sum(v[1]for v in raw.values())]:raise RuntimeError('NATIVE_TRAFFIC_FINAL_RAW_NODE_MISMATCH')
        for row in settled['receipts']:
            if row['id']in period_phase['held_rows_immutable']and immutable(row)!=period_phase['held_rows_immutable'][row['id']]:raise RuntimeError('NATIVE_TRAFFIC_LATE_PERIOD_REPLAY_CHANGED_INPUT')
        observed=proxy.event_snapshot()
        for batch_id in period_phase['held_batch_ids']:
            replay_attempts=[event for event in observed if event['kind']=='http' and event['path']=='/txapi/node/v1/report' and event.get('batch_id')==batch_id and event['status']==202]
            if len(replay_attempts)<2 or not any(event.get('ack_dropped_after_core_response')for event in replay_attempts):raise RuntimeError('NATIVE_TRAFFIC_LATE_PERIOD_EXACT_REPLAY_REQUIRED')
            first_payload=(replay_attempts[0]['traffic'],replay_attempts[0].get('traffic_segments'))
            if any((event['traffic'],event.get('traffic_segments'))!=first_payload for event in replay_attempts):raise RuntimeError('NATIVE_TRAFFIC_LATE_PERIOD_REPLAY_RELABELLED')
        stages['period-final']=settled;return {'ok':True}
    barrier('held-fresh-bytes',held_fresh)
    if test.wait(timeout=10)!=0:raise RuntimeError('NATIVE_TRAFFIC_ACTUAL_NODE_TEST_FAILED')
    result=json.loads((evidence/'traffic-live-result.json').read_text())
    artifact('result.json',{'actual_core_workerman_redis_worker':True,'protocol':protocol,'database':'txboard_native_traffic_test','durable_ack_before_settlement':True,'actual_lost_ack_same_id':True,'lost_queue_wakeup_sql_recovery':True,'initial_frozen_rate':'1.5','later_current_rate':'2.5','actual_raw_primary':[4346,8516],'actual_raw_sibling':[74,38],'quota_and_reset_protocol_verified':True,'signed_observed_reset_period_attribution':True,'queued_late_settlement_historical_only':True,'held_late_report_historical_only':True,'pending_only_actual_process_restart':True,'before_after_actual_settlement_sigkill':True,'offline_time_lease_attested':False,'worker_pids':[worker_process.pid]+[process.pid for process in worker_processes],'stages':stages,'go_result':result,'observations_sha256':hashlib.sha256(observations.read_bytes()).hexdigest()})
    print('Exclusive actual native traffic/async settlement/quota/reset gate passed.')
finally:
    for process in reversed(processes):
        if process.poll()is None:
            try:os.killpg(process.pid,signal.SIGTERM)
            except ProcessLookupError:pass
            try:process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                try:os.killpg(process.pid,signal.SIGKILL)
                except ProcessLookupError:pass
                process.wait(timeout=3)
    try:proxy.close()
    except RuntimeError:pass
    for output in logs:output.close()
