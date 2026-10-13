import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {closeSync,openSync,readFileSync,writeFileSync} from 'node:fs';
import {setTimeout as sleep} from 'node:timers/promises';
import {loadInternalAdminConfig} from '../../../gateway/apps/gateway/dist/config/internal-admin.js';
import {internalAdminRequest} from '../../../gateway/apps/gateway/dist/services/internal-admin-client.js';

const api=process.env.INTERNAL_CORE_API,dir=process.env.INTERNAL_FIXTURE_DIR;
assert.ok(api&&dir);
const actors=JSON.parse(readFileSync(`${dir}/actors.json`,'utf8'));
const processes=[],checks=[];
let credential,path;
function start(command,args,label){
  const log=openSync(`${dir}/${label}.log`,'a',0o600);
  const child=spawn(command,args,{cwd:api,env:{...process.env},detached:true,stdio:['ignore',log,log]});
  closeSync(log);processes.push(child);
}
function mutate(...args){return execFileSync('php',[new URL('./fixture.php',import.meta.url).pathname,...args],{cwd:api,env:process.env,encoding:'utf8'});}
function configuration(role){
  const file=`${dir}/${role}-config.json`;
  writeFileSync(file,JSON.stringify({origin:'https://127.0.0.1:19443',servername:'internal-core.local',
    ca_path:`${dir}/ca.pem`,crl_path:`${dir}/ca.crl`,cert_path:`${dir}/${role}.pem`,key_path:`${dir}/${role}.key`}),{mode:0o600});
  return loadInternalAdminConfig({APP_ENV:'testing',GATEWAY_INTERNAL_ADMIN_ENABLED:'true',GATEWAY_INTERNAL_ADMIN_CONFIG_FILE:file});
}
async function check(name,run){await run();checks.push(name);process.stdout.write(`ok ${checks.length}: ${name}\n`);}
const loginInput=(role='admin')=>({email:`login-${role}@example.test`,password:'fixture-admin-password'});
async function rejected(promise,status){
  let caught;
  try{await promise;}catch(error){caught=error;}
  assert.ok(caught,'Operation must reject');assert.equal(caught.status,status);
  for(const secret of [credential,path,'fixture-admin-password','private-subscription','PRIVATE KEY','synthetic-private']){
    if(secret)assert.ok(!String(caught).includes(secret),'Error must stay private');
  }
}
try{
  const config=configuration('admin');
  start('php',['-S','127.0.0.1:19081','-t','public','public/index.php'],'core-http');
  start('node',[`${api}/internal-readonly-fixtures/tls-boundary.mjs`,`${dir}/boundary.json`],'tls-boundary');
  for(let i=0;i<60;i++){
    try{if((await fetch('http://127.0.0.1:19081/api/health')).ok)break;}catch{}
    if(i===59)throw new Error('Loopback Core fixture failed readiness');await sleep(100);
  }
  await check('dedicated Admin certificate and config; User and production identity refused',async()=>{
    assert.equal(config.certificate.subjectAltName,'DNS:admin-gateway.internal');
    assert.throws(()=>configuration('user'),/Invalid local\/testing Internal Admin configuration/);
    assert.throws(()=>loadInternalAdminConfig({APP_ENV:'production',GATEWAY_INTERNAL_ADMIN_ENABLED:'true',
      GATEWAY_INTERNAL_ADMIN_CONFIG_FILE:`${dir}/admin-config.json`}),/Invalid local\/testing/);
  });
  await check('real Gateway client Admin native login private credential delivery',async()=>{
    const result=await internalAdminRequest(config,'login',{input:loginInput()});
    assert.equal(result.data.is_admin,true);assert.match(result.data.auth_data,/^Bearer /);
    credential=result.data.auth_data;path=result.data.secure_path;
  });
  await check('issued credential reaches current Core identity',async()=>{
    const result=await internalAdminRequest(config,'identity',{auth:credential,input:{admin_path:path}});
    assert.deepEqual(result.data,{id:actors.ids.admin,email:'login-admin@example.test',is_admin:true});
  });
  await check('Gateway preserves greater-than-53-bit dashboard bytes without Number conversion',async()=>{
    const result=await internalAdminRequest(config,'dashboard',{auth:credential,input:{admin_path:path}});
    assert.deepEqual(result.data.todayTraffic,{upload:'9007199254740993',download:'13',total:'9007199254741006'});
    assert.equal(result.data.totalUsers,3);assert.equal(result.data.todayIncome,'0');
  });
  await check('ordinary User and staff cannot obtain management credentials',async()=>{
    const before=JSON.parse(mutate('state')).token_count;
    for(const role of ['user','staff'])await rejected(internalAdminRequest(config,'login',{input:loginInput(role)}),403);
    assert.equal(JSON.parse(mutate('state')).token_count,before);
  });
  await check('guest auth caller role and extra parameters reject before Core nonce admission',async()=>{
    const before=JSON.parse(mutate('state')).audit_count;
    await rejected(internalAdminRequest(config,'login',{auth:credential,input:loginInput()}),400);
    await rejected(internalAdminRequest(config,'identity',{auth:credential,input:{admin_path:path,actor_id:actors.ids.admin}}),400);
    await rejected(internalAdminRequest(config,'login',{input:{...loginInput(),email:'admin@example.com'}}),400);
    assert.equal(JSON.parse(mutate('state')).audit_count,before);
  });
  await check('native memberships current relations and group pages remain exact and read only',async()=>{
    const before=mutate('business-hash');
    const first=await internalAdminRequest(config,'networkGroups',{auth:credential,input:{admin_path:path,page:1,per_page:1}});
    assert.deepEqual(first.data.pagination,{page:1,per_page:1,total:2,last_page:2});
    assert.equal(first.data.items[0].id,actors.groups[1]);assert.equal(first.data.items[0].users_count,1);
    assert.equal(first.data.items[0].plans_count,1);assert.equal(first.data.items[0].server_count,2);
    const second=await internalAdminRequest(config,'networkGroups',{auth:credential,input:{admin_path:path,page:2,per_page:1}});
    assert.equal(second.data.items[0].id,actors.groups[0]);assert.equal(second.data.items[0].server_count,1);
    const empty=await internalAdminRequest(config,'networkGroups',{auth:credential,input:{admin_path:path,page:3,per_page:1}});
    assert.deepEqual(empty.data.items,[]);assert.equal(mutate('business-hash'),before);
    for(const secret of ['password','private-node','protocol_settings','group_ids'])assert.ok(!JSON.stringify(first).includes(secret));
  });
  await check('closed group input rejected before transport and current role required',async()=>{
    const before=JSON.parse(mutate('state')).audit_count;
    await rejected(internalAdminRequest(config,'networkGroups',{auth:credential,input:{admin_path:path,page:1,per_page:101}}),400);
    assert.equal(JSON.parse(mutate('state')).audit_count,before);
    mutate('mutate','demote');await rejected(internalAdminRequest(config,'networkGroups',{auth:credential,input:{admin_path:path,page:1,per_page:20}}),403);
    mutate('mutate','promote');
  });
  await check('current ban prevents identity and cached dashboard then current demotion rejects',async()=>{
    mutate('mutate','ban');
    for(const operation of ['identity','dashboard','networkGroups'])await rejected(internalAdminRequest(config,operation,{auth:credential,input:{admin_path:path,...(operation==='networkGroups'?{page:1,per_page:20}:{})}}),403);
    mutate('mutate','unban');mutate('mutate','demote');
    await rejected(internalAdminRequest(config,'dashboard',{auth:credential,input:{admin_path:path}}),403);
    mutate('mutate','promote');
  });
  await check('unknown current management path is absent',()=>rejected(internalAdminRequest(config,'dashboard',
    {auth:credential,input:{admin_path:'wrong-fixture-path'}}),404));
  await check('wrong current group management path is rejected',()=>rejected(internalAdminRequest(config,'networkGroups',
    {auth:credential,input:{admin_path:'wrong-fixture-path',page:1,per_page:20}}),404));
  await check('audit failure rejects the group read without mutating business state',async()=>{
    const before=mutate('business-hash');mutate('audit-fail');
    await rejected(internalAdminRequest(config,'networkGroups',{auth:credential,input:{admin_path:path,page:1,per_page:20}}),503);
    assert.equal(mutate('business-hash'),before);mutate('audit-restore');
  });
  await check('real audit failure hides committed login; no client retry or compensation',async()=>{
    const before=JSON.parse(mutate('state')).token_count;mutate('audit-fail');
    await rejected(internalAdminRequest(config,'login',{input:loginInput()}),503);
    assert.equal(JSON.parse(mutate('state')).token_count,before+1);mutate('audit-restore');
  });
  await check('current native credential revocation invalidates subsequent reads',async()=>{
    mutate('mutate','revoke');
    for(const operation of ['identity','dashboard','networkGroups'])await rejected(internalAdminRequest(config,operation,{auth:credential,input:{admin_path:path,...(operation==='networkGroups'?{page:1,per_page:20}:{})}}),401);
    mutate('revoke-all');assert.equal(JSON.parse(mutate('state')).token_count,0);assert.equal(mutate('audit-check'),'safe');
  });
  writeFileSync(`${dir}/result.json`,JSON.stringify({checks,passed:checks.length,...JSON.parse(mutate('state')),
    synthetic_only:true,no_public_admin_route:true,no_native_fallback:true,no_business_state_in_gateway:true},null,2)+'\n');
  process.stdout.write(`Passed ${checks.length} real Core/Gateway Admin consumer checks; sanitized evidence retained.\n`);
}finally{
  for(const process of processes){try{globalThis.process.kill(-process.pid,'SIGTERM');}catch{}}
  await sleep(100);
}
