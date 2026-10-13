import assert from 'node:assert/strict';
import https from 'node:https';
import http from 'node:http';
import {readFileSync,writeFileSync,openSync,closeSync,readdirSync,unlinkSync,rmSync} from 'node:fs';
import {createHash,createPrivateKey,sign,randomBytes,randomUUID} from 'node:crypto';
import {spawn,execFileSync} from 'node:child_process';
import {setTimeout as sleep} from 'node:timers/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const api=process.env.INTERNAL_CORE_API,fixture=process.env.INTERNAL_FIXTURE_DIR;
assert.ok(api && fixture);
const directory=path.dirname(fileURLToPath(import.meta.url));
const actors=JSON.parse(readFileSync(`${fixture}/actors.json`)),evidence=JSON.parse(readFileSync(`${fixture}/public-evidence.json`));
const ca=readFileSync(`${fixture}/ca.pem`),checks=[],children=[];
const sha=value=>createHash('sha256').update(value).digest('hex');
const loginInput={email:'login-admin@example.test',password:'fixture-admin-password'};
const mutate=(...args)=>execFileSync('php',[`${directory}/fixture.php`,...args],{cwd:api,env:process.env});
const state=()=>JSON.parse(mutate('state'));
const body=input=>JSON.stringify({contract_version:'1',request_id:randomUUID(),deadline_at:Math.floor(Date.now()/1000)+5,input});
let issuedBearer;
function start(command,args,label){
  const fd=openSync(`${fixture}/${label}.log`,'a',0o600);
  const child=spawn(command,args,{cwd:api,env:process.env,detached:true,stdio:['ignore',fd,fd]});
  closeSync(fd);children.push(child);
}
function proof(raw){
  const now=Math.floor(Date.now()/1000),route='/txapi/internal/v1/operations/auth.admin.login';
  const header=Buffer.from(JSON.stringify({alg:'RS256',typ:'JWT',kid:'admin-1'})).toString('base64url');
  const claims=Buffer.from(JSON.stringify({v:'internal-readonly/1',epoch:1,iss:'txboard-local-tls-boundary',aud:'txboard-core-local',
    service:'admin-gateway',cert_sha256:evidence.certificates.admin,method:'POST',path:route,body_sha256:sha(raw),actor_sha256:sha(''),
    iat:now,nbf:now-2,exp:now+10,jti:randomBytes(16).toString('hex')})).toString('base64url');
  const unsigned=`${header}.${claims}`;
  return `${unsigned}.${sign('RSA-SHA256',Buffer.from(unsigned),createPrivateKey(readFileSync(`${fixture}/admin-1.proof.key`))).toString('base64url')}`;
}
function request({operation='auth.admin.login',input=loginInput,raw=body(input),cert='admin',authorization,direct=false,serviceProof}={}){
  return new Promise((resolve,reject)=>{
    const options=direct?{hostname:'127.0.0.1',port:19081}:{hostname:'127.0.0.1',port:19443,servername:'internal-core.local',ca,
      minVersion:'TLSv1.3',maxVersion:'TLSv1.3',agent:false,key:readFileSync(`${fixture}/${cert}.key`),cert:readFileSync(`${fixture}/${cert}.pem`)};
    const req=(direct?http:https).request({...options,method:'POST',path:`/txapi/internal/v1/operations/${operation}`,
      headers:{'content-type':'application/json','content-length':Buffer.byteLength(raw),
        ...(authorization!==undefined?{authorization}:{}),...(serviceProof?{'x-txboard-service-proof':serviceProof}:{})},timeout:6000},res=>{
      const chunks=[];let size=0;
      res.on('data',chunk=>{size+=chunk.length;if(size>65536)req.destroy(new Error('Unbounded response'));else chunks.push(chunk);});
      res.on('error',reject);res.on('end',()=>{try{const text=Buffer.concat(chunks).toString('utf8');resolve({status:res.statusCode,
        body:JSON.parse(text),text,headers:res.headers,tls:req.socket?.getProtocol?.()});}catch{reject(new Error('Expected bounded JSON'));}});
    });
    req.on('error',reject);req.on('timeout',()=>req.destroy(new Error('Fixture timeout')));req.end(raw);
  });
}
async function status(options,expected){
  const result=await request(options);assert.equal(result.status,expected,`Expected ${expected}, received ${result.status} (${result.body.error?.code??'success'})`);
  for(const secret of ['fixture-admin-password','private-subscription','PRIVATE KEY','synthetic-private-admin-failure'])assert.ok(!result.text.includes(secret));
  if(expected!==200){assert.ok(!result.text.includes('private-admin-path'));assert.ok(!result.text.includes('Bearer '));}
  return result;
}
async function check(name,run){await run();checks.push(name);process.stdout.write(`ok ${checks.length}: ${name}\n`);}
try{
  start('php',['-S','127.0.0.1:19081','-t','public','public/index.php'],'core-http');
  start('node',[`${api}/internal-readonly-fixtures/tls-boundary.mjs`,`${fixture}/boundary.json`],'tls-boundary');
  let ready=false;
  for(let i=0;i<40;i++){try{await request({operation:'admin.identity.get',input:{admin_path:'wrong'},authorization:'Bearer readiness-fixture'});ready=true;break;}catch{await sleep(100);}}
  assert.ok(ready,'Fixture server ready');
  await check('real Admin mTLS native login private credential delivery',async()=>{
    const r=await status({},200);assert.equal(r.tls,'TLSv1.3');assert.deepEqual(Object.keys(r.body.data),['auth_data','is_admin','secure_path']);
    assert.equal(r.body.data.is_admin,true);assert.equal(r.body.data.secure_path,'private-admin-path');
    assert.match(r.headers['cache-control'],/no-store/);issuedBearer=r.body.data.auth_data;assert.equal(state().token_count,1);
  });
  await check('issued credential reaches current Admin identity',async()=>{
    const r=await status({operation:'admin.identity.get',input:{admin_path:'private-admin-path'},authorization:issuedBearer},200);
    assert.equal(r.body.data.id,actors.ids.admin);
  });
  await check('User certificate cannot reach Admin login',()=>status({cert:'user'},403));
  await check('ordinary User and staff never receive admin credential',async()=>{
    for(const role of ['user','staff'])await status({input:{...loginInput,email:`login-${role}@example.test`}},403);
    assert.equal(state().token_count,1);
  });
  await check('any Authorization header rejected on guest login',async()=>{
    await status({authorization:issuedBearer},400);await status({authorization:''},400);
  });
  await check('wrong password and banned admin reject without new session',async()=>{
    await status({input:{...loginInput,password:'wrong-password'}},401);
    mutate('mutate','ban');await status({},401);mutate('mutate','unban');assert.equal(state().token_count,1);
  });
  await check('caller role unknown input and nonsynthetic account refused',async()=>{
    await status({input:{...loginInput,is_admin:true}},400);
    await status({input:{...loginInput,email:'real-admin@example.com'}},403);assert.equal(state().token_count,1);
  });
  await check('verified proof replay cannot issue another session',async()=>{
    const raw=body(loginInput),serviceProof=proof(raw);
    await status({direct:true,raw,serviceProof},200);await status({direct:true,raw,serviceProof},409);assert.equal(state().token_count,2);
  });
  await check('demoted admin cannot receive management path',async()=>{
    mutate('mutate','demote');await status({},403);mutate('mutate','promote');assert.equal(state().token_count,2);
  });
  await check('enabled CAPTCHA never contacts external service',async()=>{
    mutate('mutate','captcha-on');await status({},400);mutate('mutate','captcha-off');assert.equal(state().token_count,2);
  });
  await check('real audit failure hides committed credential without rollback',async()=>{
    mutate('audit-fail');await status({},503);mutate('audit-restore');assert.equal(state().token_count,3);
  });
  await check('revocation invalidates prior successful management credential',async()=>{
    mutate('mutate','revoke');await status({operation:'admin.identity.get',input:{admin_path:'private-admin-path'},authorization:issuedBearer},401);
    assert.equal(state().token_count,0);assert.equal(mutate('audit-check').toString(),'safe');
  });
  writeFileSync(`${fixture}/result.json`,JSON.stringify({checks,passed:checks.length,tls:'TLSv1.3',...state(),synthetic_only:true,
    no_gateway_admin_route:true,unknown_result_session_preserved:true},null,2)+'\n');
  process.stdout.write(`Passed ${checks.length} real Admin login checks; sanitized receipt retained.\n`);
}finally{
  for(const child of children){try{process.kill(-child.pid,'SIGTERM');}catch{}}
  await sleep(100);
  for(const name of readdirSync(fixture))if(name.endsWith('.key')||name.endsWith('.csr')||name.endsWith('.ext')||name.endsWith('.cnf')
    ||['actors.json','boundary.json','core-trust.json','index.txt','index.txt.attr','serial','crlnumber'].some(prefix=>name.startsWith(prefix))){try{unlinkSync(`${fixture}/${name}`);}catch{}}
  rmSync(`${fixture}/newcerts`,{recursive:true,force:true});
}
