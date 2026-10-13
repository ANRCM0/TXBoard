import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {closeSync,openSync,readFileSync,writeFileSync} from 'node:fs';
import {setTimeout as sleep} from 'node:timers/promises';
import {loadConfig} from '../../../gateway/apps/gateway/dist/config/env.js';
import {createGatewayApp} from '../../../gateway/apps/gateway/dist/app.js';
import {internalUserRequest} from '../../../gateway/apps/gateway/dist/services/internal-user-client.js';
import {createTXBoardClient} from '../../../gateway/packages/theme-sdk/dist/index.js';
const api=process.env.INTERNAL_CORE_API,dir=process.env.INTERNAL_FIXTURE_DIR;assert.ok(api&&dir);
const actors=JSON.parse(readFileSync(`${dir}/actors.json`,'utf8'));const checks=[],children=[];let trade;
function fixture(...args){return execFileSync('php',[new URL('./fixture.php',import.meta.url).pathname,...args],{cwd:api,env:process.env,encoding:'utf8'});}
const state=()=>JSON.parse(fixture('state'));
function start(command,args,name){const fd=openSync(`${dir}/${name}.log`,'a',0o600);const child=spawn(command,args,{cwd:api,env:process.env,detached:true,stdio:['ignore',fd,fd]});closeSync(fd);children.push(child);}
async function check(name,action){await action();checks.push(name);process.stdout.write(`ok ${checks.length}: ${name}\n`);}
async function rejected(promise,status){await assert.rejects(promise,error=>error.status===status&&!String(error).includes('private-'))}
const file=`${dir}/user-config.json`;writeFileSync(file,JSON.stringify({origin:'https://127.0.0.1:19443',servername:'internal-core.local',ca_path:`${dir}/ca.pem`,crl_path:`${dir}/ca.crl`,cert_path:`${dir}/user.pem`,key_path:`${dir}/user.key`}),{mode:0o600});
const baseEnv={APP_ENV:'testing',TXBOARD_UPSTREAM_URL:'https://native-never.example.test',GATEWAY_INTERNAL_USER_ENABLED:'true',GATEWAY_INTERNAL_USER_CONFIG_FILE:file,GATEWAY_INTERNAL_ORDER_CANCEL_ENABLED:'true'};
const config=loadConfig(baseEnv);let nativeCalls=0;const never=async()=>{nativeCalls++;throw new Error('Native fallback forbidden')};
const app=createGatewayApp(config,never);let attempts=0;
const sdk=createTXBoardClient({baseURL:'http://127.0.0.1/gateway/v1',getToken:()=>actors.credentials.user,fetchImpl:async(input,init)=>{attempts++;return app.request(String(input),init)},maxRetries:10,baseRetryDelay:1});
const cancel=(credential,tradeNo)=>internalUserRequest(config,'userOrderCancel',{auth:credential,tradeNo,body:{}});
try{
 start('php',['-S','127.0.0.1:19081',new URL('./server-router.php',import.meta.url).pathname],'core-http');
 start('node',[`${api}/internal-readonly-fixtures/tls-boundary.mjs`,`${dir}/boundary.json`],'tls-boundary');
 for(let i=0;i<60;i++){try{if((await fetch('http://127.0.0.1:19081/api/health')).ok)break;}catch{}if(i===59)throw new Error('Core readiness failed');await sleep(100);}
 await check('strict independent local flag and production configuration refuse',async()=>{
  assert.throws(()=>loadConfig({...baseEnv,APP_ENV:'production'}));assert.throws(()=>loadConfig({...baseEnv,GATEWAY_INTERNAL_ORDER_CANCEL_ENABLED:'1'}));
  const off=createGatewayApp({...config,internalOrderCancelEnabled:false},never);const response=await off.request('/gateway/v1/orders/private/cancel',{method:'POST',headers:{Authorization:actors.credentials.user,'Content-Type':'application/json'},body:'{}'});assert.equal(response.status,405);assert.equal(nativeCalls,0);
 });
 await check('real native creation reserves wallet and coupon through fixed Internal operation',async()=>{
  const result=await internalUserRequest({...config,orderWriteEnabled:true},'userOrderCreate',{auth:actors.credentials.user,body:{plan_id:actors.plan_id,period:'month_price',coupon_code:'CANCELFIXTURE'},headers:{'Idempotency-Key':'cancel-fixture-original'}});trade=result.trade_no;
  assert.equal(state().balance,0);assert.equal(state().coupon_slots,0);assert.deepEqual(state().order_statuses,[0]);
 });
 await check('foreign missing and Admin actors cannot cancel owner order',async()=>{
  await rejected(cancel(actors.credentials.foreign,trade),404);await rejected(cancel(actors.credentials.user,'missing'),404);await rejected(cancel(actors.credentials.admin,trade),403);assert.equal(state().balance,0);
 });
 await check('current ban and promotion are rechecked at Core each attempt',async()=>{
  fixture('mutate','ban');await rejected(cancel(actors.credentials.user,trade),403);fixture('mutate','unban');fixture('mutate','promote');await rejected(cancel(actors.credentials.user,trade),403);fixture('mutate','demote');assert.deepEqual(state().order_statuses,[0]);
 });
 await check('SDK legacy route restores prepaid wallet and one coupon slot exactly once',async()=>{
  assert.deepEqual(await sdk.orders.cancel(trade),{ok:true});assert.equal(attempts,1);assert.equal(state().balance,500);assert.equal(state().coupon_slots,1);assert.deepEqual(state().order_statuses,[2]);assert.equal(state().capture_events,0);
 });
 await check('duplicate cancellation returns409 once and does not refund or restore twice',async()=>{
  const before=attempts;await rejected(sdk.orders.cancel(trade),409);assert.equal(attempts,before+1);assert.equal(state().balance,500);assert.equal(state().coupon_slots,1);
 });
 await check('closed query body and absent Bearer reject before Core audit',async()=>{
  const before=state().audit_count;
  for(const [path,body,auth,status]of[[`/gateway/v1/orders/${trade}/cancel?owner_id=1`,'{}',actors.credentials.user,400],[`/gateway/v1/orders/${trade}/cancel`,'{"owner_id":"1"}',actors.credentials.user,400],[`/gateway/v1/orders/${trade}/cancel`,'{}',undefined,401]]){
   const response=await app.request(path,{method:'POST',headers:{'Content-Type':'application/json',...(auth?{Authorization:auth}:{})},body});assert.equal(response.status,status);
  }assert.equal(state().audit_count,before);assert.equal(nativeCalls,0);
 });
 await check('before-hook Exception rolls back and diagnostic remains fixed',async()=>{
  const next=JSON.parse(fixture('new-order')).trade_no;fixture('hook','before-fail');await rejected(cancel(actors.credentials.user,next),409);fixture('hook','none');assert.equal(state().balance,500);assert.equal(state().order_statuses.at(-1),0);
 });
 await check('after-hook Exception yields native409 after commit without automatic compensation',async()=>{
  const next=JSON.parse(fixture('new-order')).trade_no;fixture('hook','after-fail');const before=attempts;await assert.rejects(sdk.orders.cancel(next),error=>error.status===409&&error.needsStateConfirmation===true);assert.equal(attempts,before+1);fixture('hook','none');assert.equal(state().balance,600);assert.equal(state().order_statuses.at(-1),2);await rejected(cancel(actors.credentials.user,next),409);assert.equal(state().balance,600);
 });
 await check('real audit503 hides committed refund and SDK sends one attempt',async()=>{
  const next=JSON.parse(fixture('new-order')).trade_no;const before=attempts;fixture('audit-fail');await rejected(sdk.orders.cancel(next),503);fixture('audit-restore');assert.equal(attempts,before+1);assert.equal(state().balance,700);assert.equal(state().order_statuses.at(-1),2);await rejected(cancel(actors.credentials.user,next),409);assert.equal(state().balance,700);
 });
 await check('strict isolated native capture can record cancellation without a new writer',async()=>{
  const next=JSON.parse(fixture('new-order')).trade_no;fixture('capture','on');assert.deepEqual(await cancel(actors.credentials.user,next),{ok:true});fixture('capture','off');assert.equal(state().balance,800);assert.equal(state().capture_events,1);assert.equal(fixture('capture-check'),'valid');
 });
 await check('current credential revocation refuses pending cancellation without mutation',async()=>{
  const next=JSON.parse(fixture('new-order')).trade_no;fixture('mutate','revoke');await rejected(cancel(actors.credentials.user,next),401);assert.equal(state().balance,800);assert.equal(state().order_statuses.at(-1),0);
 });
 await check('fixed service diagnostics audit and final temporary credential cleanup remain private',async()=>{
  const log=readFileSync(`${api}/storage/logs/laravel.log`,'utf8');assert.ok(log.includes('ORDER_CANCELLATION_FAILED'));
  for(const secret of['private-hook-argument','private-previous-secret','private-cancel-audit-failure',actors.credentials.user])assert.ok(!log.includes(secret));
  fixture('revoke-all');assert.equal(state().token_count,0);assert.equal(fixture('audit-check'),'safe');assert.equal(nativeCalls,0);
 });
 writeFileSync(`${dir}/result.json`,JSON.stringify({checks,passed:checks.length,...state(),synthetic_only:true,no_native_fallback:true,no_auto_retry:true,public_route_default_off:true,scv2Claim:false},null,2)+'\n');process.stdout.write(`Passed ${checks.length} cancellation checks; private material cleanup follows.\n`);
}finally{for(const child of children)try{process.kill(-child.pid,'SIGTERM')}catch{}await sleep(100);}
