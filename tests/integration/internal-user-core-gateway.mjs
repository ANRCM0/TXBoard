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

const api = process.env.INTERNAL_CORE_API, dir = process.env.INTERNAL_FIXTURE_DIR
assert.ok(api && dir)
const snapshot = JSON.parse(readFileSync(`${api}/../internal-snapshot.json`))
assert.equal(snapshot.committed_core_head, '60e6ecae545078e7e771aa73c429828a557b0e27')
const actors = JSON.parse(readFileSync(`${dir}/actors.json`))
const pki = JSON.parse(readFileSync(`${dir}/public-evidence.json`))
const processes = [], servers = [], checks = [], nativeCalls = []
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
  await check('nonselected status retains Native path', async () => {
    const before=nativeCalls.length; await call(internal,orderPath+'/status')
    assert.deepEqual(nativeCalls.slice(before),['/txapi/orders/'+actors.trade_no])
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
  const wrongCA=gateway(gatewayConfig('bad-client'),19584), revoked=gateway(gatewayConfig('revoked'),19585)
  await check('actual untrusted client CA TLS handshake rejects without Native fallback', async () => {
    const before=nativeCalls.length; await call(wrongCA,'/user/profile',actors.credentials.user,502); assert.equal(nativeCalls.length,before)
  })
  await check('actual CRL-revoked client handshake rejects without Native fallback', () => call(revoked,'/user/profile',actors.credentials.user,502))
  await check('wrong trusted server CA is rejected by actual client verification', async () => {
    const port=gateway(gatewayConfig('user',{ca_path:`${dir}/bad-ca.pem`}),19586)
    await call(port,'/user/profile',actors.credentials.user,502)
  })
  await check('actual server DNS SAN mismatch is rejected while CA remains trusted', async () => {
    const ext=`${dir}/wrong-server.ext`
    writeFileSync(ext,'basicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:visitor.internal\n')
    execFileSync('openssl',['req','-new','-newkey','rsa:2048','-nodes','-keyout',`${dir}/wrong-server.key`,'-out',`${dir}/wrong-server.csr`,'-subj','/CN=wrong-server'],{stdio:'ignore'})
    execFileSync('openssl',['ca','-batch','-config',`${dir}/ca.cnf`,'-in',`${dir}/wrong-server.csr`,'-out',`${dir}/wrong-server.pem`,'-extfile',ext,'-notext'],{stdio:'ignore'})
    const wrongServer=https.createServer({key:readFileSync(`${dir}/wrong-server.key`),cert:readFileSync(`${dir}/wrong-server.pem`),
      ca:readFileSync(`${dir}/ca.pem`),requestCert:true,rejectUnauthorized:true,minVersion:'TLSv1.3',maxVersion:'TLSv1.3'},(_req,res)=>res.end('{}'))
    wrongServer.on('tlsClientError',()=>{}); await new Promise(resolve=>wrongServer.listen(19445,'127.0.0.1',resolve)); servers.push(wrongServer)
    const port=gateway(gatewayConfig('user',{origin:'https://127.0.0.1:19445'}),19587)
    await call(port,'/user/profile',actors.credentials.user,502)
  })
  await check('Core default disabled remains authoritative without fallback', async () => {
    stop(core); await sleep(200); core=child('php',['-S','127.0.0.1:19081','-t','public','public/index.php'],'core-http',{CORE_INTERNAL_READONLY_ENABLED:'false'})
    await ready(); const before=nativeCalls.length; await call(internal,'/user/profile',actors.credentials.user,404); assert.equal(nativeCalls.length,before)
  })
  await check('Core production 404 gate remains authoritative', async () => {
    stop(core); await sleep(200); core=child('php',['-S','127.0.0.1:19081','-t','public','public/index.php'],'core-http',{APP_ENV:'production',CORE_INTERNAL_READONLY_ENABLED:'true'})
    await ready(); await call(internal,'/user/profile',actors.credentials.user,404)
  })
  await check('revoked Core actor session fails after server restarts', async () => {
    stop(core); await sleep(200); core=child('php',['-S','127.0.0.1:19081','-t','public','public/index.php'],'core-http')
    await ready(); fixture('mutate','user','revoke'); await call(internal,'/user/profile',actors.credentials.user,401)
  })
  await check('TLS service offline never falls back to plaintext Native', async () => {
    stop(boundary); await sleep(200); const before=nativeCalls.length; await call(internal,'/user/profile',actors.credentials.other,502)
    assert.equal(nativeCalls.length,before)
  })
  const log=readFileSync(`${dir}/gateway-requests.log`,'utf8')
  for(const secret of [...Object.values(actors.credentials),'PRIVATE KEY','private-subscribe-token'])assert.ok(!log.includes(secret))
  const hashes={}
  for(const file of ['apps/gateway/src/services/internal-user-client.ts','apps/gateway/src/adapters/internal-user-v2.ts','apps/gateway/src/config/internal-user.ts',
    'tests/integration/internal-user-core-gateway.mjs','tests/integration/internal-user-core-fixture.php'])hashes[file]=createHash('sha256').update(readFileSync(file)).digest('hex')
  const report={current_only:true,phase:'B5.5',production_acceptance:false,passed:checks.length,checks,
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
