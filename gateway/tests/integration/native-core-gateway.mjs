import assert from 'node:assert/strict'
import { generateKeyPairSync, randomUUID } from 'node:crypto'
import { spawn, spawnSync } from 'node:child_process'
import { readFileSync, openSync, closeSync, appendFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { serve } from '@hono/node-server'
import { createGatewayApp } from '../../apps/gateway/dist/app.js'
import { loadConfig } from '../../apps/gateway/dist/config/env.js'
import { CryptoService } from '../../apps/gateway/dist/services/crypto.js'
import { RedisSecurity } from '../../apps/gateway/dist/services/redis-security.js'
import { createLogger } from '../../apps/gateway/dist/middleware/observability.js'
import { encryptForOperation } from '../../packages/theme-sdk/dist/crypto.js'

const coreRoot = resolve(process.env.TXBOARD_CORE_TEST_ROOT || '')
const sourceHead = readFileSync(coreRoot + '/.gateway-integration-snapshot', 'utf8').trim()
assert.match(sourceHead, /^[a-f0-9]{40}$/)
const envText = readFileSync(coreRoot + '/.env', 'utf8')
for (const line of ['DB_DATABASE=txboard_gateway_test', 'APP_ENV=testing', 'MAIL_MAILER=log',
  'MAIL_DRIVER=log', 'CORE_INSTALLATION_MODE=native', 'CORE_INSTALLATION_EPOCH=2']) {
  assert.ok(envText.split(/\r?\n/).includes(line), `Isolated native config required: ${line}`)
}
const logDir = process.env.GATEWAY_INTEGRATION_LOG_DIR
assert.ok(logDir)
const seeded = JSON.parse(readFileSync(logDir + '/fixture.json', 'utf8'))
assert.equal(seeded.native_activation, true)
assert.equal(seeded.authority_epoch, '2')
function fixture(action, ...args) {
  const run = spawnSync('php', ['tests/integration/core-fixture.php', coreRoot, action, ...args.map(String)],
    { encoding: 'utf8', timeout: 30000 })
  assert.equal(run.status, 0, run.stderr || 'Core fixture failed')
  return JSON.parse(run.stdout)
}
const core = 'http://127.0.0.1:18081'
const gateway = 'http://127.0.0.1:18082'
const writable = 'http://127.0.0.1:18083'
const prefix = '/gateway/v1'
const phpLog = openSync(logDir + '/core-http.log', 'a')
let php, redis
const servers = []
try {
  php = spawn('php', ['-S', '127.0.0.1:18081', '-t', 'public', 'public/index.php'],
    { cwd: coreRoot, stdio: ['ignore', phpLog, phpLog] })
  redis = await RedisSecurity.connect(process.env.GATEWAY_TEST_REDIS_URL)
  const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
  const crypto = await CryptoService.create(privateKey.export({ format: 'jwk' }), redis, true)
  const config = loadConfig({ TXBOARD_UPSTREAM_URL: core, TXBOARD_ALLOW_PRIVATE_HTTP: 'true' })
  const reached = []
  let expectedPurchase
  // Observe the real transport without substituting responses or saving credentials.
  const realFetcher = async (url, init) => {
    if (init.method === 'POST' && new URL(url).pathname === '/txapi/orders') {
      assert.ok(expectedPurchase)
      const headers = new Headers(init.headers)
      assert.ok(headers.get('Authorization') === expectedPurchase.token, 'Original user Bearer must reach Core')
      assert.equal(headers.get('Idempotency-Key'), expectedPurchase.key)
      assert.deepEqual(JSON.parse(init.body), expectedPurchase.body)
    }
    reached.push({ path: new URL(url).pathname, method: init.method })
    return fetch(url, init)
  }
  const features = { accountWorkflows: true, limiter: redis }
  const logger = createLogger({ level: 'info', sink: line => appendFileSync(logDir + '/gateway-requests.log', line + '\n') })
  const readApp = createGatewayApp(config, realFetcher, crypto, features, { logger })
  const writeApp = createGatewayApp({ ...config, orderWriteEnabled: true }, realFetcher, crypto, features, { logger })
  servers.push(serve({ fetch: readApp.fetch, hostname: '127.0.0.1', port: 18082 }))
  servers.push(serve({ fetch: writeApp.fetch, hostname: '127.0.0.1', port: 18083 }))
  let checks = 0
  async function call(base, path, { token, body, method = body ? 'POST' : 'GET', key } = {}, expected = 200) {
    const response = await fetch(base + path, { method, headers: {
      Accept: 'application/json', ...(token ? { Authorization: token } : {}),
      ...(body ? { 'Content-Type': 'application/json' } : {}), ...(key ? { 'Idempotency-Key': key } : {}),
    }, body: body ? JSON.stringify(body) : undefined, signal: AbortSignal.timeout(15000) })
    const json = await response.json()
    assert.equal(response.status, expected, `${path}: ${json.error?.code ?? 'Unexpected HTTP status'}`)
    checks++
    return json
  }
  for (let attempt = 0; ; attempt++) {
    if (php.exitCode !== null) throw new Error('PHP integration server exited before readiness')
    try { const ready = await fetch(core + '/txapi/public/site-config'); if (ready.ok) break } catch {}
    if (attempt >= 50) throw new Error('PHP server unavailable')
    await new Promise(r => setTimeout(r, 100))
  }
  const suffix = randomUUID().replaceAll('-', '').slice(0, 12)
  const emailA = `gw-a-${suffix}@example.test`, emailB = `gw-b-${suffix}@example.test`
  const password = 'integration-password-2026'
  const encrypted = await encryptForOperation(crypto.publicKey(), { email: emailA, password }, 'register')
  const registered = await call(gateway, prefix + '/secure/auth/register', { body: encrypted })
  assert.deepEqual(Object.keys(registered.data), ['auth_data'])
  const tokenA = registered.data.auth_data
  const issued = await call(core, '/txapi/auth/register', { body: { email: emailB, password } }, 201)
  const tokenB = issued.data.auth_data
  const profileA = await call(gateway, prefix + '/user/profile', { token: tokenA })
  const profileB = await call(gateway, prefix + '/user/profile', { token: tokenB })
  assert.equal(profileA.data.email, emailA); assert.equal(profileB.data.email, emailB)
  assert.notEqual(profileA.data.id, profileB.data.id)
  assert.ok(!('uuid' in profileA.data))
  const bootstrap = await call(gateway, prefix + '/bootstrap')
  assert.equal(bootstrap.data.site.name, 'Gateway integration')
  assert.ok(!bootstrap.data.capabilities.includes('orders.create'))
  const writeBootstrap = await call(writable, prefix + '/bootstrap')
  assert.ok(writeBootstrap.data.capabilities.includes('orders.create'))
  const plans = await call(gateway, prefix + '/plans')
  const monthly = plans.data.find(p => p.plan_id === seeded.plan_id && p.offer_code === 'monthly')
  const quarterly = plans.data.find(p => p.plan_id === seeded.plan_id && p.offer_code === 'quarterly')
  assert.ok(monthly && quarterly)
  assert.equal(monthly.price_minor, '1200')
  assert.equal(quarterly.price_minor, '3600')
  assert.equal(monthly.currency, 'CNY')
  assert.match(monthly.publication_hash, /^[a-f0-9]{64}$/)
  assert.equal(monthly.quota_bytes, '10737418240')
  assert.ok(!('month_price' in monthly) && !('id' in monthly))
  // Change the mutable draft before purchase. The current publication is immutable.
  fixture('edit-plan', seeded.plan_id)
  const coreOffers = await call(core, '/txapi/plans')
  assert.equal(coreOffers.data.find(p => p.offer_id === monthly.offer_id).price_minor, '1200')
  const orderBody = { offer_id: monthly.offer_id }
  let before = reached.length
  await call(gateway, prefix + '/orders', { token: tokenA, body: orderBody, key: 'disabled-' + suffix }, 405)
  assert.equal(reached.length, before)
  for (const invalidBody of [
    { plan_id: seeded.plan_id, period: 'monthly' }, { offer_id: Number(monthly.offer_id) },
    { ...orderBody, price_minor: '1' }, { ...orderBody, user_id: profileB.data.id },
    { ...orderBody, coupon_code: 'anything' },
  ]) {
    before = reached.length
    await call(writable, prefix + '/orders', { token: tokenA, body: invalidBody, key: 'invalid-' + suffix }, 400)
    assert.equal(reached.length, before)
  }
  before = reached.length
  await call(writable, prefix + '/orders', { body: orderBody, key: 'missing-auth-' + suffix }, 401)
  await call(writable, prefix + '/orders', { token: tokenA, body: orderBody }, 400)
  assert.equal(reached.length, before)
  const key = 'native-order-' + suffix
  expectedPurchase = { token: tokenA, key, body: orderBody }
  const created = await call(writable, prefix + '/orders', { token: tokenA, body: orderBody, key }, 201)
  assert.deepEqual(Object.keys(created.data), ['trade_no'])
  const replay = await call(writable, prefix + '/orders', { token: tokenA, body: orderBody, key }, 201)
  assert.equal(created.data.trade_no, replay.data.trade_no)
  expectedPurchase = { token: tokenA, key, body: { offer_id: quarterly.offer_id } }
  const conflict = await call(writable, prefix + '/orders',
    { token: tokenA, body: { offer_id: quarterly.offer_id }, key }, 409)
  assert.equal(conflict.error.code, 'IDEMPOTENCY_CONFLICT')
  const trade = created.data.trade_no
  const detail = await call(gateway, prefix + '/orders/' + trade, { token: tokenA })
  assert.equal(detail.data.total_amount, 1200)
  const status = await call(gateway, prefix + '/orders/' + trade + '/status', { token: tokenA })
  assert.deepEqual(status.data, { tradeNo: trade, status: 0 })
  for (const tail of ['', '/status']) {
    await call(gateway, prefix + '/orders/' + trade + tail, { token: tokenB }, 404)
    await call(gateway, prefix + '/orders/' + trade + tail, { token: 'Bearer invalid-session-value' }, 401)
  }
  const own = await call(gateway, prefix + '/orders?status=0', { token: tokenA })
  assert.equal(own.data.filter(order => order.trade_no === trade).length, 1)
  const other = await call(gateway, prefix + '/orders', { token: tokenB })
  assert.equal(other.data.length, 0)
  // Historical order display/pagination remains supported without inventing native entitlement.
  const history = fixture('history', profileA.data.id, seeded.plan_id)
  const first = await call(gateway, prefix + '/orders/page?status=4&current=1&page_size=100', { token: tokenA })
  const second = await call(gateway, prefix + '/orders/page?status=4&current=2&page_size=100', { token: tokenA })
  assert.equal(first.data.total, 101); assert.equal(first.data.lastPage, 2)
  assert.equal(first.data.items.length, 100); assert.equal(second.data.items.length, 1)
  assert.equal(new Set([...first.data.items, ...second.data.items].map(order => order.trade_no)).size, 101)
  const arrayPage = await call(gateway, prefix + '/orders?status=4&current=2&page_size=100', { token: tokenA })
  assert.deepEqual(arrayPage.data, second.data.items)
  const historyDetail = await call(gateway, prefix + '/orders/' + history.trade_no, { token: tokenA })
  assert.equal(historyDetail.data.status, 4)
  await call(gateway, prefix + '/orders/' + history.trade_no, { token: tokenB }, 404)
  const mixed = await call(gateway, prefix + '/orders/page', { token: tokenA })
  assert.equal(mixed.data.total, 102)
  for (const path of ['/user/profile', '/orders', '/orders/page']) {
    before = reached.length
    await call(gateway, prefix + path, {}, 401)
    assert.equal(reached.length, before)
  }
  const purchases = reached.filter(item => item.method === 'POST' && item.path === '/txapi/orders')
  assert.equal(purchases.length, 3, 'Only create, explicit replay and explicit conflict reach Core; no automatic retry')
  await call(core, '/txapi/auth/logout', { token: tokenA, method: 'POST' })
  await call(gateway, prefix + '/user/profile', { token: tokenA }, 401)
  assert.ok(reached.every(item => item.path.startsWith('/txapi/')))
  console.log(JSON.stringify({ result: 'passed', checks, core_head: sourceHead,
    database: 'txboard_gateway_test', database_version: seeded.database_version, real_http: true,
    native_activation: true, authority_epoch: '2', immutable_publication_price: true,
    discounted_history_orders: history.count, purchase_upstream_calls: purchases.length,
    operations: [...new Set(reached.map(item => `${item.method} ${item.path.replace(/^\/txapi\/orders\/[^/]+/, '/txapi/orders/{tradeNo}')}`))],
    limitations: ['Catalog/create/read acceptance only; no payment or fulfillment claim',
      'Historical rows verify read pagination only', 'No native invites/knowledge acceptance',
      'Ephemeral CI PKI and Provider; no production deployment or merchant acceptance'],
  }, null, 2))
} finally {
  await Promise.all(servers.map(server => new Promise(resolveClose => server.close(resolveClose))))
  try { await redis?.close() } finally { php?.kill('SIGTERM'); closeSync(phpLog) }
}
