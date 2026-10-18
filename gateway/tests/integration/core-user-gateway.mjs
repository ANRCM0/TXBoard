import assert from 'node:assert/strict'
import { readFileSync, openSync, closeSync } from 'node:fs'
import { randomBytes, randomUUID } from 'node:crypto'
import { createServer } from 'node:https'
import { spawn, spawnSync } from 'node:child_process'
import { resolve } from 'node:path'
import { serve } from '@hono/node-server'
import { createGatewayApp } from '../../apps/gateway/dist/app.js'
import { loadConfig } from '../../apps/gateway/dist/config/env.js'
import { createCoreUserClient } from '../../packages/core-sdk/dist/index.js'

const root = process.env.TXBOARD_CORE_TEST_ROOT
const scratch = process.env.GATEWAY_INTEGRATION_LOG_DIR
assert.ok(root && scratch)
const sourceHead = readFileSync(`${root}/.gateway-integration-snapshot`, 'utf8').trim()
const service = JSON.parse(readFileSync(`${scratch}/user-service.private.json`, 'utf8'))
const nonce = randomBytes(32).toString('hex')
const servers = []
const log = openSync(`${scratch}/core-user-http.log`, 'a')
const php = spawn('php', ['-S', '127.0.0.1:18081', '-t', 'public', resolve('tests/integration/core-user-router.php')], {
  cwd: root, stdio: ['ignore', log, log], env: { ...process.env, CORE_USER_PROXY_NONCE: nonce },
})
let checks = 0
try {
  for (let attempt = 0; ; attempt++) {
    if (php.exitCode !== null) throw new Error('Core exited before readiness')
    try { if ((await fetch('http://127.0.0.1:18081/txapi/public/site-config')).ok) break } catch {}
    if (attempt > 50) throw new Error('Core readiness timed out')
    await new Promise(r => setTimeout(r, 100))
  }
  const proxy = createServer({ key: readFileSync(`${scratch}/server.key`), cert: readFileSync(`${scratch}/server.pem`),
    ca: readFileSync(`${scratch}/ca.pem`), requestCert: true, rejectUnauthorized: true }, async (req, res) => {
    try {
      assert.ok(req.socket.authorized)
      const peer = req.socket.getPeerCertificate()
      const headers = { ...req.headers }
      for (const key of Object.keys(headers)) if (key.startsWith('x-core-tls-') || key.startsWith('x-ci-') || ['host', 'connection', 'transfer-encoding'].includes(key)) delete headers[key]
      headers['X-Core-Tls-Verified'] = 'SUCCESS'
      headers['X-Core-Tls-Certificate-Sha256'] = peer.fingerprint256.replaceAll(':', '').toLowerCase()
      headers['X-CI-Tls-Nonce'] = nonce
      const chunks = []
      for await (const chunk of req) chunks.push(chunk)
      const response = await fetch(`http://127.0.0.1:18081${req.url}`, { method: req.method, headers,
        body: Buffer.concat(chunks), redirect: 'error', signal: AbortSignal.timeout(15000) })
      res.writeHead(response.status, { 'content-type': response.headers.get('content-type') || 'application/json' })
      res.end(Buffer.from(await response.arrayBuffer()))
    } catch { res.writeHead(502); res.end() }
  })
  await new Promise(r => proxy.listen(18087, '127.0.0.1', r)); servers.push(proxy)
  const config = loadConfig({ TXBOARD_UPSTREAM_URL: 'https://127.0.0.1:18087',
    GATEWAY_CORE_USER_ENABLED: 'true', GATEWAY_CORE_USER_OPERATIONS_ENABLED: 'true', GATEWAY_ORDER_WRITE_ENABLED: 'true',
    GATEWAY_CORE_USER_SERVICE_ID: service.service_id, GATEWAY_CORE_USER_CREDENTIAL: service.credential,
    GATEWAY_CORE_USER_EPOCH: service.epoch, GATEWAY_CORE_USER_CA_FILE: `${scratch}/ca.pem`,
    GATEWAY_CORE_USER_CERT_FILE: `${scratch}/core.pem`, GATEWAY_CORE_USER_KEY_FILE: `${scratch}/core.key`,
  })
  servers.push(serve({ fetch: createGatewayApp(config).fetch, hostname: '127.0.0.1', port: 18088 }))
  const baseURL = 'http://127.0.0.1:18088/gateway/v1/core/user'
  async function register() {
    const response = await fetch('http://127.0.0.1:18081/txapi/auth/register', { method: 'POST', headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ email: `gw-user-${randomUUID()}@example.test`, password: 'integration-password-2026' }) })
    assert.equal(response.status, 201)
    return (await response.json()).data.auth_data
  }
  const token = await register(), otherToken = await register()
  const client = createCoreUserClient({ baseURL, getToken: () => token })
  const other = createCoreUserClient({ baseURL, getToken: () => otherToken })
  const caps = await client.capabilities()
  assert.equal(caps.operations.length, 20); checks++
  const offers = await client.call('catalog.offers.list', {})
  assert.ok(offers.length > 0); checks++
  const actor = await client.call('identity.account.get', {})
  assert.equal(typeof actor.id, 'string'); checks++
  const key = randomUUID(), input = { offer_id: offers[0].offer_id }
  const created = await client.callResult('commerce.orders.create', input, { idempotencyKey: key })
  const replay = await client.callResult('commerce.orders.create', input, { idempotencyKey: key })
  assert.deepEqual(replay.data, created.data); checks++
  await assert.rejects(other.call('commerce.orders.get', { trade_no: created.data.trade_no }), e => e.status === 404); checks++
  const orders = await client.callResult('commerce.orders.list', { page: 1, per_page: 1 })
  assert.equal(orders.meta.page, 1); assert.equal(orders.meta.total, 1); checks++
  const recharges = await client.callResult('billing.recharges.list', {})
  assert.equal(recharges.meta.next_after_id, null); checks++
  const usage = await client.callResult('usage.mine.list', {})
  assert.equal(usage.meta.schema, 'native-usage-periods/v1'); checks++
  const wallet = await client.call('billing.wallet.get', {})
  assert.equal(wallet.schema, 'native-wallet/v1'); checks++
  await client.call('commerce.orders.cancel', { trade_no: created.data.trade_no }, { idempotencyKey: randomUUID() }); checks++
  const sessions = await client.callResult('identity.sessions.list', {})
  assert.equal(sessions.meta.has_more, false); assert.ok(sessions.data.length > 0); checks++
  // The owner revokes the actual current session. A fresh Core read must fail.
  await client.call('identity.session.end', {}, { idempotencyKey: randomUUID() }); checks++
  await assert.rejects(client.call('identity.account.get', {}), e => e.status === 401 && e.code === 'UNAUTHORIZED'); checks++
  const restrict = spawnSync('php', ['tests/integration/core-fixture.php', root, 'restrict-user-service'], { encoding: 'utf8' })
  assert.equal(restrict.status, 0, restrict.stderr)
  const reduced = await other.capabilities()
  assert.deepEqual(reduced.operations.map(row => row.id), ['catalog.offers.list']); checks++
  await assert.rejects(other.call('billing.wallet.get', {}), e => e.status === 403); checks++
  console.log(JSON.stringify({ sourceHead, checks, realCore: true, nativeActivation: true, database: 'mysql',
    gatewayMtls: true, tlsTermination: 'isolated CI adapter to loopback PHP development server',
    browserSdk: true, originalKeyReplay: true, ownerIsolation: true, sessionEnd: true, serviceGrantRevocation: true }))
} finally {
  for (const server of servers.reverse()) await new Promise(r => server.close(r))
  php.kill(); await new Promise(r => php.once('exit', r)); closeSync(log)
}
