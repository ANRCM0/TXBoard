import assert from 'node:assert/strict'
import https from 'node:https'
import { readFileSync, writeFileSync, openSync, closeSync, readdirSync, unlinkSync, rmSync, appendFileSync } from 'node:fs'
import { spawn, execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { setTimeout as sleep } from 'node:timers/promises'
import { serve } from '@hono/node-server'
import { createGatewayApp } from '../../apps/gateway/dist/app.js'
import { loadConfig } from '../../apps/gateway/dist/config/env.js'
import { createLogger } from '../../apps/gateway/dist/middleware/observability.js'
import { invalidateCache } from '../../apps/gateway/dist/middleware/cache.js'

const api = process.env.INTERNAL_CORE_API, dir = process.env.INTERNAL_FIXTURE_DIR
assert.ok(api && dir)
const snapshot = JSON.parse(readFileSync(`${api}/../internal-snapshot.json`))
const expectedCoreCommit = process.env.INTERNAL_EXPECTED_CORE_COMMIT
assert.match(expectedCoreCommit || '', /^[0-9a-f]{40}$/, 'An explicit fixed Core commit is required')
assert.equal(snapshot.committed_core_head, expectedCoreCommit)
const actors = JSON.parse(readFileSync(`${dir}/actors.json`))
const pki = JSON.parse(readFileSync(`${dir}/public-evidence.json`))
const processes = [], servers = [], checks = [], nativeCalls = []
const loginSecrets = []
let core, boundary
const fixture = (...args) => execFileSync('php', [`${api}/internal-readonly-fixtures/fixture.php`, ...args], { env: process.env })
const decorate = (action) => execFileSync('php', ['tests/integration/internal-user-core-fixture.php', api, action], { env: process.env })
function child(command, args, label, env = {}) {
  const log = openSync(`${dir}/${label}.log`, 'a', 0o600)
  const result = spawn(command, args, { cwd: api, env: { ...process.env, ...env }, detached: true, stdio: ['ignore', log, log] })
  closeSync(log); processes.push(result); return result
}
function stop(process) { if (process) { try { globalThis.process.kill(-process.pid, 'SIGTERM') } catch {} } }
const serverConfig = { TXBOARD_UPSTREAM_URL: 'http://127.0.0.1:19081', TXBOARD_ALLOW_PRIVATE_HTTP: 'true', APP_ENV: 'testing' }
function gatewayConfig(cert = 'user', overrides = {}) {
  const path = `${dir}/gateway-${cert}.json`
  writeFileSync(path, JSON.stringify({ origin: 'https://127.0.0.1:19443', servername: 'internal-core.local',
    ca_path: `${dir}/ca.pem`, crl_path: `${dir}/ca.crl`, cert_path: `${dir}/${cert}.pem`, key_path: `${dir}/${cert}.key`, ...overrides }))
  return loadConfig({ ...serverConfig, GATEWAY_INTERNAL_USER_ENABLED: 'true', GATEWAY_INTERNAL_USER_CONFIG_FILE: path })
}
const nativeFetcher = async (url, init) => { nativeCalls.push(new URL(url).pathname); return fetch(url, init) }
const logger = createLogger({ level: 'info', sink: line => appendFileSync(`${dir}/gateway-requests.log`, line+'\n') })
function gateway(config, port) {
  const app = createGatewayApp(config, nativeFetcher, undefined, {}, { logger })
  const server = serve({ fetch: app.fetch, hostname: '127.0.0.1', port }); servers.push(server); return port
}
async function call(port, path, token = actors.credentials.user, expected = 200, headers = {}) {
  // Multiple fixture apps share the process cache. Force each boundary assertion to reach its selected upstream.
  if (['/bootstrap','/theme/config','/plans','/catalog/payment-methods'].includes(path.split('?')[0])) invalidateCache()
  const response = await fetch(`http://127.0.0.1:${port}/gateway/v1${path}`, {
    headers: { ...(token ? { authorization: token } : {}), ...headers }, signal: AbortSignal.timeout(7000) })
  const json = await response.json(); assert.equal(response.status, expected, `${path}: expected ${expected}, got ${response.status} ${json.error?.code ?? ''}`)
  const text = JSON.stringify(json)
  for (const secret of [...Object.values(actors.credentials), 'private-subscribe-token', 'must-never-return-callback', 'must-never-return-provider-secret', 'PRIVATE KEY']) {
    assert.ok(!text.includes(secret))
  }
  return json
}
async function check(name, run) { await run(); checks.push(name); process.stdout.write(`ok ${checks.length}: ${name}\n`) }
async function accountLogin(port, email = 'internal-user@example.test', password = 'fixture-password-only', expected = 200, headers = {}) {
  const response = await fetch(`http://127.0.0.1:${port}/gateway/v1/auth/login`, {method:'POST',
    headers:{'content-type':'application/json',...headers},body:JSON.stringify({email,password}),signal:AbortSignal.timeout(7000)})
  const result = await response.json()
  assert.equal(response.status,expected,`login expected${expected}, got${response.status} ${result.error?.code??''}`)
  if(expected===200){assert.deepEqual(Object.keys(result.data),['auth_data']);loginSecrets.push(result.data.auth_data)}
  for(const secret of ['private-subscribe-token','secure_path',password,email])assert.ok(!JSON.stringify(result).includes(secret))
  return result
}
async function createOrder(port, token, key, body, expected) {
  const response=await fetch(`http://127.0.0.1:${port}/gateway/v1/orders`,{method:'POST',headers:{authorization:token,
    'content-type':'application/json','idempotency-key':key},body:JSON.stringify(body),signal:AbortSignal.timeout(7000)})
  const result=await response.json();assert.equal(response.status,expected,`write expected${expected}, got${response.status} ${result.error?.code??''}`)
  for(const secret of [...Object.values(actors.credentials),'PRIVATE KEY','private-subscribe-token',key])assert.ok(!JSON.stringify(result).includes(secret))
  return result
}
function directTlsOperation(operation, headers = {}, cert = 'user', rawInput = '{}') {
  const tls = gatewayConfig(cert).internalUser
  const body=Buffer.from('{"contract_version":"1","request_id":"00000000-0000-4000-8000-000000000000","deadline_at":'+(Math.floor(Date.now()/1000)+5)+',"input":'+rawInput+'}')
  return new Promise((resolve,reject)=>{
    const request=https.request({hostname:'127.0.0.1',port:19443,servername:tls.servername,
      ca:tls.ca,crl:tls.crl,cert:tls.cert,key:tls.key,rejectUnauthorized:true,minVersion:'TLSv1.3',maxVersion:'TLSv1.3',agent:false,
      method:'POST',path:'/txapi/internal/v1/operations/'+operation,
      headers:{'content-type':'application/json','content-length':body.length,...headers}},reply=>{
        const output=[];reply.on('data',v=>output.push(v));reply.on('end',()=>resolve({status:reply.statusCode,body:JSON.parse(Buffer.concat(output))}))
    });request.setTimeout(5000,()=>request.destroy(new Error('Fixture TLS timeout')));request.on('error',reject);request.end(body)
  })
}
async function ready() {
  for (let i=0;i<40;i++) { try { const r=await fetch('http://127.0.0.1:19081/txapi/public/site-config'); if (r.ok) return } catch {} await sleep(100) }
  throw new Error('Core fixture failed readiness')
}
async function boundaryReady(port) {
  for (let i=0;i<40;i++) { try { await call(port, '/user/profile'); return } catch {} await sleep(100) }
  throw new Error('TLS boundary failed readiness')
}
try {
  decorate('decorate')
  decorate('list-orders')
  decorate('display-reads')
  decorate('notice-reads')
  core = child('php', ['-S', '127.0.0.1:19081', '-t', 'public', 'public/index.php'], 'core-http')
  boundary = child('node', [`${api}/internal-readonly-fixtures/tls-boundary.mjs`, `${dir}/boundary.json`], 'tls-boundary')
  await ready()
  // Exercise actual Core login issuance; no shared/service admin token is used.
  const login = await fetch('http://127.0.0.1:19081/txapi/auth/login', { method:'POST', headers:{'content-type':'application/json'},
    body:JSON.stringify({ email:'internal-user@example.test', password:'fixture-password-only' }) })
  assert.equal(login.status, 200); actors.credentials.user=(await login.json()).data.auth_data
  const native = gateway(loadConfig(serverConfig), 19582), internal = gateway(gatewayConfig(), 19583)
  await boundaryReady(internal)
  await check('default Native profile has no Internal transport requirement', async () => {
    const before=nativeCalls.length; await call(native,'/user/profile'); assert.equal(nativeCalls.length,before+1)
  })
  await check('real mTLS Internal profile equals Native in every frozen display field', async () => {
    const before=nativeCalls.length; const viaNative=await call(native,'/user/profile'); const viaInternal=await call(internal,'/user/profile')
    assert.deepEqual(viaInternal.data,viaNative.data); assert.equal(viaInternal.data.telegram_id,-1234567)
    assert.equal(nativeCalls.length,before+1)
  })
  const orderPath='/orders/'+actors.trade_no
  await check('real full-detail Internal equals Native including payment wallet discount plan traffic status4', async () => {
    const before=nativeCalls.length; const viaNative=await call(native,orderPath); const viaInternal=await call(internal,orderPath)
    assert.deepEqual(viaInternal.data,viaNative.data)
    assert.equal(viaInternal.data.status,4); assert.equal(viaInternal.data.payment_id,7)
    assert.equal(viaInternal.data.balance_amount,300); assert.equal(viaInternal.data.discount_amount,150)
    assert.equal(viaInternal.data.plan.transfer_enable,1); assert.equal(nativeCalls.length,before+1)
  })
  await check('other user profile reflects actual actor, never supplied actor header', async () => {
    const value=await call(internal,'/user/profile',actors.credentials.other,200,{'x-actor-id':'1','x-client-cert':'admin-gateway.internal','x-service-identity':'admin-gateway'})
    assert.equal(value.data.email,'internal-other@example.test')
  })
  await check('foreign order remains Core 404 and does not call Native fallback', async () => {
    const before=nativeCalls.length; await call(internal,orderPath,actors.credentials.other,404); assert.equal(nativeCalls.length,before)
  })
  await check('missing and expired actor reject with no Native fallback', async () => {
    const before=nativeCalls.length; await call(internal,'/user/profile',null,401); await call(internal,'/user/profile',actors.credentials.expired,401)
    assert.equal(nativeCalls.length,before)
  })
  await check('admin actor cannot use user Internal service', () => call(internal,'/user/profile',actors.credentials.admin,403))
  await check('Core ban immediately rejects same user session', async () => {
    fixture('mutate','user','ban'); await call(internal,'/user/profile',actors.credentials.user,403)
    fixture('mutate','user','unban'); await call(internal,'/user/profile')
  })
  await check('unsafe decimal bytes are rejected without precision loss or fallback', async () => {
    const before=nativeCalls.length; decorate('overflow'); await call(internal,'/user/profile',actors.credentials.user,502)
    assert.equal(nativeCalls.length,before); decorate('restore-values')
  })
  await check('real bounded Internal list equals Native array and page with original filters and missing plan', async () => {
    for (const path of ['/orders?current=1&page_size=2','/orders/page?current=1&page_size=2',
                        '/orders/page?current=2&page_size=2','/orders/page?current=3&page_size=2',
                        '/orders/page?status=4&current=1&page_size=1','/orders/page?status=0&current=1&page_size=2']) {
      const before=nativeCalls.length
      const viaNative=await call(native,path), viaInternal=await call(internal,path)
      assert.deepEqual(viaInternal.data,viaNative.data); assert.equal(nativeCalls.length,before+1)
      assert.ok(!JSON.stringify(viaInternal).includes('internal-list-foreign'))
    }
  })
  await check('Internal list preserves empty foreign-user scope and rejects Admin actor without fallback', async () => {
    const before=nativeCalls.length
    assert.deepEqual((await call(internal,'/orders',actors.credentials.other)).data,[])
    await call(internal,'/orders',actors.credentials.admin,403); assert.equal(nativeCalls.length,before)
  })
  await check('unknown duplicate and out-of-bounds list queries refuse before either transport', async () => {
    const before=nativeCalls.length
    for(const query of ['current=0','current=10000','page_size=101','status=5','current=1&current=2','user_id=1']) {
      await call(internal,'/orders?'+query,actors.credentials.user,400)
    }
    assert.equal(nativeCalls.length,before)
  })
  await check('real display reads equal Native stats and enabled ordered payment methods', async () => {
    for(const path of ['/dashboard/stats','/payments']) {
      const before=nativeCalls.length, viaNative=await call(native,path), viaInternal=await call(internal,path)
      assert.deepEqual(viaInternal.data,viaNative.data); assert.equal(nativeCalls.length,before+1)
      if(path==='/dashboard/stats') assert.deepEqual(viaInternal.data,{unpaidOrders:1,openTickets:1,invitedUsers:1})
      else { assert.deepEqual(viaInternal.data.map(row=>row.name),['Zero method','Fixed method']); assert.equal(viaInternal.data[1].handlingFeeFixed,17) }
    }
  })
  await check('display reads keep actual user scope, protected payment methods and no fallback on actor rejection', async () => {
    const before=nativeCalls.length
    assert.deepEqual((await call(internal,'/dashboard/stats',actors.credentials.other)).data,{unpaidOrders:0,openTickets:0,invitedUsers:0})
    for(const path of ['/dashboard/stats','/payments']) {
      await call(internal,path,null,401); await call(internal,path,actors.credentials.admin,403)
      await call(internal,path,actors.credentials.expired,401)
    }
    assert.equal(nativeCalls.length,before)
  })
  await check('real subscription summary equals Native and excludes private URL and credentials', async () => {
    const before=nativeCalls.length, viaNative=await call(native,'/user/subscription/summary'), viaInternal=await call(internal,'/user/subscription/summary')
    assert.deepEqual(viaInternal.data,viaNative.data); assert.equal(viaInternal.data.planName,'Internal Read Plan')
    assert.equal(nativeCalls.length,before+1); assert.ok(!JSON.stringify(viaInternal).includes('subscribe_url'))
    assert.equal((await call(internal,'/user/subscription/summary',actors.credentials.other)).data.planId,null)
  })
  await check('real visible notice pages equal Native while retaining sort content and tags', async () => {
    for(const path of ['/notices?current=1&pageSize=2','/notices?current=2&pageSize=2','/notices?current=3&pageSize=2']) {
      const before=nativeCalls.length, viaNative=await call(native,path), viaInternal=await call(internal,path)
      assert.deepEqual(viaInternal.data,viaNative.data); assert.equal(nativeCalls.length,before+1)
      assert.ok(!JSON.stringify(viaInternal).includes('Hidden'))
    }
  })
  await check('content reads reject missing expired and Admin actors with no Native fallback', async () => {
    const before=nativeCalls.length
    for(const path of ['/user/subscription/summary','/notices']) {
      await call(internal,path,null,401); await call(internal,path,actors.credentials.admin,403)
      await call(internal,path,actors.credentials.expired,401)
    }
    assert.equal(nativeCalls.length,before)
  })
  await check('notice query bounds duplicate and unknown keys refuse before either transport', async () => {
    const before=nativeCalls.length
    for(const query of ['current=0','pageSize=101','current=1&current=2','status=0','user_id=1']) await call(internal,'/notices?'+query,actors.credentials.user,400)
    assert.equal(nativeCalls.length,before)
  })
  await check('missing current summary plan retains Core409 through actual TLS without fallback', async () => {
    const before=nativeCalls.length;decorate('summary-plan-missing')
    await call(internal,'/user/subscription/summary',actors.credentials.user,409)
    assert.equal(nativeCalls.length,before);decorate('summary-plan-restore')
  })
  await check('oversize visible notice refuses before release through actual TLS without fallback', async () => {
    const before=nativeCalls.length;decorate('notice-oversize')
    await call(internal,'/notices',actors.credentials.user,502)
    assert.equal(nativeCalls.length,before);decorate('notice-restore')
  })
  await check('current status equals Native over TLS and retains missing-plan reads and ownership', async () => {
    const before=nativeCalls.length, viaNative=await call(native,orderPath+'/status'), viaInternal=await call(internal,orderPath+'/status')
    assert.deepEqual(viaInternal.data,viaNative.data);assert.equal(nativeCalls.length,before+1)
    const statusPath='/orders/internal-list-missing-plan/status'
    assert.deepEqual((await call(internal,statusPath)).data,{tradeNo:'internal-list-missing-plan',status:4})
    const unchanged=nativeCalls.length;await call(internal,orderPath+'/status',actors.credentials.other,404)
    await call(internal,orderPath+'/status',actors.credentials.admin,403);assert.equal(nativeCalls.length,unchanged)
  })
  const publicInternal=gateway({...gatewayConfig(),internalPublicReadEnabled:true},19589)
  await check('public Internal default disabled refuses while public default uses Native',async()=>{
    const before=nativeCalls.length
    await call(publicInternal,'/bootstrap',null,404)
    await call(publicInternal,'/plans',null,404)
    await call(internal,'/bootstrap',null)
    assert.equal(nativeCalls.length,before+1)
  })
  stop(core);await sleep(200);core=child('php',['-S','127.0.0.1:19081','-t','public','public/index.php'],'core-http',
    {CORE_INTERNAL_PUBLIC_READ_ENABLED:'true'})
  await ready();fixture('rate-reset');decorate('public-prepare')
  await check('anonymous site theme captcha and exact plan display equal Native through actual TLS',async()=>{
    for(const path of ['/bootstrap','/theme/config','/plans']){
      const before=nativeCalls.length,viaNative=await call(native,path,null),viaInternal=await call(publicInternal,path,null)
      assert.deepEqual(viaInternal.data,viaNative.data);assert.equal(nativeCalls.length,before+1)
      if(path==='/plans'){
        assert.deepEqual(viaInternal.data.map(row=>row.name),['Internal Read Plan','Public second'])
        assert.equal(viaInternal.data[1].month_price,317)
      }
    }
  })
  await check('visitor credentials are never forwarded for public reads and cannot grant protected access',async()=>{
    const before=nativeCalls.length
    await call(publicInternal,'/theme/config',actors.credentials.admin)
    await call(publicInternal,'/plans','Bearer not-a-valid-actor')
    await call(publicInternal,'/user/profile',null,401)
    await call(publicInternal,'/payments',null,401)
    await call(publicInternal,'/catalog/payment-methods',null,404)
    assert.equal(nativeCalls.length,before)
  })
  await check('existing public cache retains display TTL without another Internal or Native request',async()=>{
    invalidateCache();const before=nativeCalls.length,auditBefore=JSON.parse(decorate('public-audit')).public_audit_rows
    const first=await fetch(`http://127.0.0.1:${publicInternal}/gateway/v1/plans`),second=await fetch(`http://127.0.0.1:${publicInternal}/gateway/v1/plans`)
    assert.equal(first.status,200);assert.equal(second.status,200)
    assert.equal(first.headers.get('x-cache'),'MISS');assert.equal(second.headers.get('x-cache'),'HIT')
    assert.deepEqual((await first.json()).data,(await second.json()).data);assert.equal(nativeCalls.length,before)
    assert.equal(JSON.parse(decorate('public-audit')).public_audit_rows,auditBefore+1)
    invalidateCache()
  })
  await check('public numeric overflow and response cap reject without Native fallback',async()=>{
    const before=nativeCalls.length;decorate('public-overflow')
    try{await call(publicInternal,'/plans',null,502)}finally{decorate('public-restore')}
    decorate('public-oversize')
    try{await call(publicInternal,'/plans',null,502)}finally{decorate('public-restore')}
    assert.equal(nativeCalls.length,before)
  })
  await check('anonymous technical audit uses marker zero and service rate is still enforced',async()=>{
    assert.deepEqual(JSON.parse(decorate('public-audit')).actor_ids,[0])
    fixture('rate-fill');const before=nativeCalls.length
    try{await call(publicInternal,'/theme/config',null,429)}finally{fixture('rate-reset')}
    assert.equal(nativeCalls.length,before)
  })
  await check('actual TLS public headers and closed input cannot weaken protected operation identity',async()=>{
    for(const authorization of ['',actors.credentials.user]){
      assert.equal((await directTlsOperation('public.site-config.get',{authorization})).status,400)
    }
    assert.equal((await directTlsOperation('user.profile.get')).status,400)
    assert.equal((await directTlsOperation('public.site-config.get',{'x-actor-id':actors.user_id,'x-service-identity':'admin-gateway'})).status,200)
    assert.equal((await directTlsOperation('public.plan.list',{},'user','{"actor_id":"1"}')).status,400)
    assert.equal((await directTlsOperation('public.plan.list',{},'user','{"field":1,"field":2}')).status,400)
  })
  const loginInternal=gateway({...gatewayConfig(),internalLoginEnabled:true},19590)
  await check('login has an independent default-off Core gate and no Native fallback',async()=>{
    const before=nativeCalls.length,state=JSON.parse(decorate('login-state'))
    await accountLogin(loginInternal,undefined,undefined,404)
    assert.deepEqual(JSON.parse(decorate('login-state')),state);assert.equal(nativeCalls.length,before)
  })
  stop(core);await sleep(200);core=child('php',['-S','127.0.0.1:19081','-t','public','public/index.php'],'core-http',
    {CORE_INTERNAL_PUBLIC_READ_ENABLED:'true',CORE_INTERNAL_LOGIN_ENABLED:'true',CORE_SHADOW_ISOLATED_COPY:'true',CACHE_DRIVER:'redis'})
  await ready();fixture('rate-reset');decorate('login-rate-reset')
  await check('actual enabled CAPTCHA refuses login before token issuance without supplier call',async()=>{
    const state=JSON.parse(decorate('login-state')),before=nativeCalls.length
    await accountLogin(loginInternal,undefined,undefined,400)
    assert.deepEqual(JSON.parse(decorate('login-state')),state);assert.equal(nativeCalls.length,before)
  })
  decorate('login-prepare');decorate('login-rate-reset')
  let knownLoginToken
  await check('actual TLS login issues one native hashed year session and preserves protected identity',async()=>{
    const before=nativeCalls.length,state=JSON.parse(decorate('login-state'))
    await accountLogin(loginInternal,undefined,undefined,403,{'x-forwarded-for':'198.51.100.9'})
    assert.deepEqual(JSON.parse(decorate('login-state')),state);assert.equal(nativeCalls.length,before)
    knownLoginToken=(await accountLogin(loginInternal,undefined,undefined,200,{authorization:actors.credentials.admin})).data.auth_data
    const after=JSON.parse(decorate('login-state'))
    assert.equal(after.user_tokens,state.user_tokens+1);assert.equal(after.last_year_ttl,true);assert.deepEqual(after.last_abilities,['*'])
    assert.equal((await call(internal,'/user/profile',knownLoginToken)).data.email,'internal-user@example.test')
    const admin=(await accountLogin(loginInternal,'internal-admin@example.test')).data.auth_data
    await call(internal,'/user/profile',admin,403);assert.equal(nativeCalls.length,before)
  })
  await check('wrong unknown and banned credentials keep native rejection without token or Native call',async()=>{
    decorate('login-rate-reset');const state=JSON.parse(decorate('login-state')),before=nativeCalls.length
    await accountLogin(loginInternal,undefined,'incorrect-fixture-password',401)
    await accountLogin(loginInternal,'nobody-login@example.test',undefined,401)
    fixture('mutate','user','ban')
    try{await accountLogin(loginInternal,undefined,undefined,401)}finally{fixture('mutate','user','unban')}
    assert.deepEqual(JSON.parse(decorate('login-state')),state);assert.equal(nativeCalls.length,before)
  })
  await check('actual TLS absent Authorization and exact input keep native FormRequest validation',async()=>{
    decorate('login-rate-reset');const state=JSON.parse(decorate('login-state'))
    const input=JSON.stringify({email:'internal-user@example.test',password:'fixture-password-only'})
    for(const authorization of ['',actors.credentials.user])assert.equal((await directTlsOperation('auth.login',{authorization},'user',input)).status,400)
    assert.equal((await directTlsOperation('auth.login',{},'user','{"email":"not-email","password":"long-enough"}')).status,422)
    assert.equal((await directTlsOperation('auth.login',{},'user','{"email":"internal-user@example.test","password":"short"}')).status,422)
    assert.equal((await directTlsOperation('auth.login',{},'user','{"email":"x","email":"y","password":"long-enough"}')).status,400)
    assert.deepEqual(JSON.parse(decorate('login-state')),state)
  })
  await check('Native and Internal login share the real Redis observed-IP ten-attempt budget',async()=>{
    decorate('login-rate-reset');const before=nativeCalls.length,state=JSON.parse(decorate('login-state'))
    for(let i=0;i<10;i++){
      if(i%2===0)await accountLogin(loginInternal,'no-such-budget@example.test',undefined,401)
      else{
        const response=await fetch('http://127.0.0.1:19081/txapi/auth/login',{method:'POST',headers:{'content-type':'application/json'},
          body:JSON.stringify({email:'no-such-budget@example.test',password:'fixture-password-only'})})
        assert.equal(response.status,401);await response.arrayBuffer()
      }
    }
    await accountLogin(loginInternal,'no-such-budget@example.test',undefined,429)
    assert.deepEqual(JSON.parse(decorate('login-state')),state);assert.equal(nativeCalls.length,before);decorate('login-rate-reset')
  })
  await check('service budget login rejection is infrastructure failure without burning credential outcome',async()=>{
    fixture('rate-fill');const state=JSON.parse(decorate('login-state'))
    try{await accountLogin(loginInternal,undefined,undefined,503)}finally{fixture('rate-reset')}
    assert.deepEqual(JSON.parse(decorate('login-state')),state)
  })
  await check('audit failure after login leaves an unknown hashed session and native session revoke cleans it',async()=>{
    const state=JSON.parse(decorate('login-state'));decorate('audit-write-fail')
    try{await accountLogin(loginInternal,undefined,undefined,503)}finally{decorate('audit-write-restore')}
    const after=JSON.parse(decorate('login-state'));assert.equal(after.user_tokens,state.user_tokens+1)
    const revoke=await fetch('http://127.0.0.1:19081/txapi/auth/sessions/'+after.last_id,{method:'DELETE',headers:{authorization:knownLoginToken}})
    assert.equal(revoke.status,200);await revoke.arrayBuffer()
    assert.equal(JSON.parse(decorate('login-state')).user_tokens,state.user_tokens)
    decorate('login-rate-reset')
  })
  await check('login opt-in refuses typos missing transport production and account-workflow conflict',async()=>{
    for(const value of ['TRUE','1',' true '])assert.throws(()=>loadConfig({...serverConfig,GATEWAY_INTERNAL_LOGIN_ENABLED:value}))
    assert.throws(()=>loadConfig({...serverConfig,GATEWAY_INTERNAL_LOGIN_ENABLED:'true'}))
    assert.throws(()=>loadConfig({...serverConfig,APP_ENV:'production',GATEWAY_INTERNAL_LOGIN_ENABLED:'true'}))
    assert.throws(()=>createGatewayApp({...gatewayConfig(),internalLoginEnabled:true},nativeFetcher,undefined,{accountWorkflows:true}))
  })
  const writing=gateway({...gatewayConfig(),orderWriteEnabled:true},19588)
  const purchase={plan_id:1,period:'month_price'},businessKey='fixture-original-key'
  await check('Core write default disabled and Gateway write ban refuse without Native fallback',async()=>{
    const before=nativeCalls.length
    await createOrder(internal,actors.credentials.user,businessKey,purchase,405)
    await createOrder(writing,actors.credentials.user,businessKey,purchase,404)
    assert.equal(nativeCalls.length,before)
  })
  stop(core);await sleep(200);core=child('php',['-S','127.0.0.1:19081','-t','public','public/index.php'],'core-http',
    {CORE_INTERNAL_ORDER_WRITE_ENABLED:'true',CORE_SHADOW_ISOLATED_COPY:'true',CORE_INTERNAL_PUBLIC_READ_ENABLED:'true'})
  await ready();fixture('rate-reset');decorate('write-prepare')
  let createdTrade
  await check('actual TLS order creation reserves wallet once and same-key replay returns original trade',async()=>{
    const before=nativeCalls.length
    const created=await createOrder(writing,actors.credentials.user,businessKey,purchase,201);createdTrade=created.data.trade_no
    const copies=await Promise.all(Array.from({length:5},()=>createOrder(writing,actors.credentials.user,businessKey,purchase,201)))
    for(const result of copies)assert.equal(result.data.trade_no,createdTrade)
    assert.deepEqual(JSON.parse(decorate('write-state')),{balance:0,trade_no:createdTrade,wallet_reserved:300,provider_due:900,claims:1})
    assert.equal(nativeCalls.length,before)
  })
  await check('write identity and business conflict rejects retain Core authority without fallback',async()=>{
    const before=nativeCalls.length
    const conflict=await createOrder(writing,actors.credentials.user,businessKey,{...purchase,coupon_code:'different-private-coupon'},409)
    assert.equal(conflict.error.code,'IDEMPOTENCY_CONFLICT')
    await createOrder(writing,actors.credentials.admin,businessKey,purchase,403)
    await createOrder(writing,actors.credentials.expired,businessKey,purchase,401)
    assert.equal(JSON.parse(decorate('write-state')).claims,1);assert.equal(nativeCalls.length,before)
  })
  await check('expired business key replacement immediately replays new successful claim without a second debit',async()=>{
    decorate('write-expire');const before=nativeCalls.length
    const replaced=await createOrder(writing,actors.credentials.user,businessKey,purchase,201)
    assert.notEqual(replaced.data.trade_no,createdTrade)
    assert.equal((await createOrder(writing,actors.credentials.user,businessKey,purchase,201)).data.trade_no,replaced.data.trade_no)
    assert.deepEqual(JSON.parse(decorate('write-state')),{balance:0,trade_no:replaced.data.trade_no,wallet_reserved:300,provider_due:900,claims:1})
    assert.equal(nativeCalls.length,before)
  })
  await check('audit failure after business commit returns unknown result and same key recovers original order',async()=>{
    const key='fixture-audit-unknown',before=nativeCalls.length
    decorate('audit-write-fail')
    try{await createOrder(writing,actors.credentials.other,key,purchase,503)}finally{decorate('audit-write-restore')}
    const committed=JSON.parse(decorate('write-other-state'));assert.equal(committed.orders,1);assert.equal(committed.claims,1)
    const recovered=await createOrder(writing,actors.credentials.other,key,purchase,201)
    assert.equal(recovered.data.trade_no,committed.trade_no)
    assert.equal((await createOrder(writing,actors.credentials.other,key,purchase,201)).data.trade_no,recovered.data.trade_no)
    assert.equal(nativeCalls.length,before)
  })
  for (const cert of ['admin','wrong-san','multi-san','expired']) {
    await check(`startup rejects ${cert} client identity`, () => assert.throws(()=>gatewayConfig(cert),/Invalid local\/testing Internal User configuration/))
  }
  await check('startup rejects nonHTTPS/visitor server identity/production flag configuration', () => {
    assert.throws(()=>gatewayConfig('user',{origin:'http://127.0.0.1:19081'}))
    assert.throws(()=>gatewayConfig('user',{servername:'visitor.internal'}))
    assert.throws(()=>loadConfig({...serverConfig,APP_ENV:'production',GATEWAY_INTERNAL_USER_ENABLED:'true',GATEWAY_INTERNAL_USER_CONFIG_FILE:`${dir}/gateway-user.json`}))
  })
  await check('startup rejects mistyped opt-in duplicate config keys and noncanonical HTTPS origins', () => {
    const file=`${dir}/gateway-user.json`
    const original=readFileSync(file,'utf8')
    for(const flag of ['1','TRUE',' true','false '])assert.throws(()=>loadConfig({...serverConfig,GATEWAY_INTERNAL_USER_ENABLED:flag,GATEWAY_INTERNAL_USER_CONFIG_FILE:file}))
    for(const origin of ['https://127.0.0.1:19443/',' https://127.0.0.1:19443','https://127.0.0.1:19443/a/..','https://INTERNAL-core.local:19443']) {
      assert.throws(()=>gatewayConfig('user',{origin}))
    }
    writeFileSync(file,original.replace('{','{"origin":"https://127.0.0.1:19443",'))
    assert.throws(()=>loadConfig({...serverConfig,GATEWAY_INTERNAL_USER_ENABLED:'true',GATEWAY_INTERNAL_USER_CONFIG_FILE:file}))
    writeFileSync(file,original.replace('"origin":','"\\u006frigin":"https://127.0.0.1:19443","origin":'))
    assert.throws(()=>loadConfig({...serverConfig,GATEWAY_INTERNAL_USER_ENABLED:'true',GATEWAY_INTERNAL_USER_CONFIG_FILE:file}))
    writeFileSync(file,original)
  })
  await check('public transport opt-in rejects typos missing transport and production configuration',()=>{
    const file=`${dir}/gateway-user.json`
    for(const flag of ['1','TRUE',' true','false '])assert.throws(()=>loadConfig({...serverConfig,GATEWAY_INTERNAL_PUBLIC_READ_ENABLED:flag}))
    assert.throws(()=>loadConfig({...serverConfig,GATEWAY_INTERNAL_PUBLIC_READ_ENABLED:'true'}))
    assert.throws(()=>loadConfig({...serverConfig,APP_ENV:'production',GATEWAY_INTERNAL_PUBLIC_READ_ENABLED:'true',GATEWAY_INTERNAL_USER_ENABLED:'true',GATEWAY_INTERNAL_USER_CONFIG_FILE:file}))
    assert.equal(loadConfig({...serverConfig,GATEWAY_INTERNAL_PUBLIC_READ_ENABLED:'false'}).internalPublicReadEnabled,false)
  })
  const wrongCA=gateway({...gatewayConfig('bad-client'),internalPublicReadEnabled:true},19584), revoked=gateway({...gatewayConfig('revoked'),internalPublicReadEnabled:true},19585)
  await check('actual untrusted client CA TLS handshake rejects without Native fallback', async () => {
    const before=nativeCalls.length; await call(wrongCA,'/user/profile',actors.credentials.user,502)
    await call(wrongCA,'/theme/config',null,502);assert.equal(nativeCalls.length,before)
  })
  await check('actual CRL-revoked client handshake rejects without Native fallback', async () => {
    await call(revoked,'/user/profile',actors.credentials.user,502);await call(revoked,'/theme/config',null,502)
  })
  await check('wrong trusted server CA is rejected by actual client verification', async () => {
    const port=gateway({...gatewayConfig('user',{ca_path:`${dir}/bad-ca.pem`}),internalPublicReadEnabled:true},19586)
    await call(port,'/user/profile',actors.credentials.user,502)
    await call(port,'/theme/config',null,502)
  })
  await check('actual server DNS SAN mismatch is rejected while CA remains trusted', async () => {
    const ext=`${dir}/wrong-server.ext`
    writeFileSync(ext,'basicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:visitor.internal\n')
    execFileSync('openssl',['req','-new','-newkey','rsa:2048','-nodes','-keyout',`${dir}/wrong-server.key`,'-out',`${dir}/wrong-server.csr`,'-subj','/CN=wrong-server'],{stdio:'ignore'})
    execFileSync('openssl',['ca','-batch','-config',`${dir}/ca.cnf`,'-in',`${dir}/wrong-server.csr`,'-out',`${dir}/wrong-server.pem`,'-extfile',ext,'-notext'],{stdio:'ignore'})
    const wrongServer=https.createServer({key:readFileSync(`${dir}/wrong-server.key`),cert:readFileSync(`${dir}/wrong-server.pem`),
      ca:readFileSync(`${dir}/ca.pem`),requestCert:true,rejectUnauthorized:true,minVersion:'TLSv1.3',maxVersion:'TLSv1.3'},(_req,res)=>res.end('{}'))
    wrongServer.on('tlsClientError',()=>{}); await new Promise(resolve=>wrongServer.listen(19445,'127.0.0.1',resolve)); servers.push(wrongServer)
    const port=gateway({...gatewayConfig('user',{origin:'https://127.0.0.1:19445'}),internalPublicReadEnabled:true},19587)
    await call(port,'/user/profile',actors.credentials.user,502)
    await call(port,'/theme/config',null,502)
  })
  await check('Core default disabled remains authoritative without fallback', async () => {
    stop(core); await sleep(200); core=child('php',['-S','127.0.0.1:19081','-t','public','public/index.php'],'core-http',{CORE_INTERNAL_READONLY_ENABLED:'false',CORE_INTERNAL_PUBLIC_READ_ENABLED:'true',CORE_INTERNAL_LOGIN_ENABLED:'true',CORE_SHADOW_ISOLATED_COPY:'true'})
    await ready(); const before=nativeCalls.length; await call(internal,'/user/profile',actors.credentials.user,404)
    await call(publicInternal,'/theme/config',null,404);await accountLogin(loginInternal,undefined,undefined,404);assert.equal(nativeCalls.length,before)
  })
  await check('Core production 404 gate remains authoritative', async () => {
    stop(core); await sleep(200); core=child('php',['-S','127.0.0.1:19081','-t','public','public/index.php'],'core-http',{APP_ENV:'production',CORE_INTERNAL_READONLY_ENABLED:'true',CORE_INTERNAL_PUBLIC_READ_ENABLED:'true',CORE_INTERNAL_LOGIN_ENABLED:'true',CORE_SHADOW_ISOLATED_COPY:'true'})
    await ready(); await call(internal,'/user/profile',actors.credentials.user,404)
    await call(publicInternal,'/theme/config',null,404)
    await accountLogin(loginInternal,undefined,undefined,404)
  })
  await check('revoked Core actor session fails after server restarts', async () => {
    stop(core); await sleep(200); core=child('php',['-S','127.0.0.1:19081','-t','public','public/index.php'],'core-http')
    await ready(); fixture('mutate','user','revoke'); await call(internal,'/user/profile',actors.credentials.user,401)
  })
  await check('TLS service offline never falls back to plaintext Native', async () => {
    stop(boundary); await sleep(200); const before=nativeCalls.length; await call(internal,'/user/profile',actors.credentials.other,502)
    await call(publicInternal,'/theme/config',null,502)
    await accountLogin(loginInternal,undefined,undefined,502)
    assert.equal(nativeCalls.length,before)
  })
  const log=readFileSync(`${dir}/gateway-requests.log`,'utf8')
  for(const file of readdirSync(dir).filter(name=>name.endsWith('.log'))){
    const contents=readFileSync(`${dir}/${file}`,'utf8')
    for(const secret of [...Object.values(actors.credentials),...loginSecrets,'fixture-password-only','incorrect-fixture-password','PRIVATE KEY','private-subscribe-token'])assert.ok(!contents.includes(secret))
  }
  const hashes={}
  for(const file of ['apps/gateway/src/services/internal-user-client.ts','apps/gateway/src/adapters/internal-user-v2.ts','apps/gateway/src/config/internal-user.ts',
    'tests/integration/internal-user-core-gateway.mjs','tests/integration/internal-user-core-fixture.php'])hashes[file]=createHash('sha256').update(readFileSync(file)).digest('hex')
  const report={current_only:true,phase:'B5.9',production_acceptance:false,passed:checks.length,checks,
    committed_core_head:snapshot.committed_core_head,committed_gateway_head:JSON.parse(readFileSync('internal-user-snapshot.json')).committed_gateway_head,
    core_slice_sha256:snapshot.slice_sha256,gateway_source_sha256:hashes,database:fixture('database-version').toString(),
    runtime:{node:process.version,php:execFileSync('php',['-r','echo PHP_VERSION;']).toString()},tls:'TLSv1.3',
    audit:JSON.parse(fixture('audit-check')),pki,cleanup:'Services stopped and temporary keys/credentials/trust removed in finally.'}
  writeFileSync(`${dir}/result.json`,JSON.stringify(report,null,2)); process.stdout.write(`PASS ${checks.length} real Core/Gateway Internal checks\n`)
} finally {
  for(const server of servers) { server.closeAllConnections?.(); server.close() }
  for(const process of processes)stop(process)
  rmSync(`${dir}/newcerts`,{recursive:true,force:true})
  for(const file of readdirSync(dir))if(!file.endsWith('.log')&&!['result.json','public-evidence.json'].includes(file))try{unlinkSync(`${dir}/${file}`)}catch{}
}
