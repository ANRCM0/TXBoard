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
assert.match(envText, /^DB_DATABASE=txboard_gateway_test$/m)
assert.match(envText, /^APP_ENV=testing$/m)
assert.match(envText, /^MAIL_MAILER=log$/m)
assert.match(envText, /^MAIL_DRIVER=log$/m)
const logDir = process.env.GATEWAY_INTEGRATION_LOG_DIR
assert.ok(logDir)
function fixture(action, ...args) {
  const run = spawnSync('php', ['tests/integration/core-fixture.php', coreRoot, action, ...args.map(String)], { encoding: 'utf8' })
  assert.equal(run.status, 0, run.stderr || 'Core fixture failed')
  return JSON.parse(run.stdout)
}
const seeded = fixture('seed')
const core = 'http://127.0.0.1:18081'
const gateway = 'http://127.0.0.1:18082'
const writable = 'http://127.0.0.1:18083'
const phpLog = openSync(logDir + '/core-http.log', 'a')
let php, redis
const servers = []
try {
php = spawn('php', ['-S', '127.0.0.1:18081', '-t', 'public', 'public/index.php'], { cwd: coreRoot, stdio: ['ignore', phpLog, phpLog] })
redis = await RedisSecurity.connect(process.env.GATEWAY_TEST_REDIS_URL)
const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
const crypto = await CryptoService.create(privateKey.export({ format: 'jwk' }), redis, true)
const config = loadConfig({ TXBOARD_UPSTREAM_URL: core, TXBOARD_ALLOW_PRIVATE_HTTP: 'true', GATEWAY_PAYMENT_CATALOG_ENABLED: 'true' })
const reached = []
const realFetcher = async (url, init) => { reached.push({ path: new URL(url).pathname, method: init.method }); return fetch(url, init) }
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
  assert.equal(response.status, expected, `${path}: ${JSON.stringify(json)}`)
  checks++
  return json
}
const prefix = '/gateway/v1'
const suffix = randomUUID().replaceAll('-', '').slice(0, 12)
const emailA = `gw-a-${suffix}@example.test`, emailB = `gw-b-${suffix}@example.test`
const password = 'integration-password-2026'
  for (let attempt = 0; ; attempt++) {
    if (php.exitCode !== null) throw new Error('PHP integration server exited before readiness')
    try { const ready = await fetch(core + '/txapi/public/site-config'); if (ready.ok) break } catch {}
    if (attempt >= 30) throw new Error('PHP server unavailable')
    await new Promise(r => setTimeout(r, 100))
  }
  const encrypted = await encryptForOperation(crypto.publicKey(), { email: emailA, password }, 'register')
  const registered = await call(gateway, prefix + '/secure/auth/register', { body: encrypted })
  assert.deepEqual(Object.keys(registered.data), ['auth_data'])
  const issued = await call(core, '/txapi/auth/register', { body: { email: emailB, password } }, 201)
  const tokenB = issued.data.auth_data
  await call(gateway, prefix + '/auth/login', { body: { email: emailA, password: 'wrong-password' } }, 401)
  fixture('captcha-on')
  const captchaFailure = await call(gateway, prefix + '/auth/login', { body: { email: emailA, password } }, 400)
  assert.equal(captchaFailure.error.code, 'UPSTREAM_ERROR')
  fixture('captcha-off')
  const emailRequest = await encryptForOperation(crypto.publicKey(), { email: `gw-mail-${suffix}@example.test` }, 'email-code')
  const sent = await call(gateway, prefix + '/secure/auth/email-code', { body: emailRequest })
  assert.deepEqual(sent.data, { sent: true })
  const logged = await call(gateway, prefix + '/auth/login', { body: { email: emailA, password } })
  const tokenA = logged.data.auth_data
  assert.deepEqual(Object.keys(logged.data), ['auth_data'])
  await call(core, '/txapi/invites', {}, 401)
  const invitesA = await call(core, '/txapi/invites', { token: tokenA })
  assert.ok(Array.isArray(invitesA.data.codes))
  await call(core, '/txapi/invites', { token: tokenA, method: 'POST' }, 201)
  const invitesCreated = await call(core, '/txapi/invites', { token: tokenA })
  const invitesB = await call(core, '/txapi/invites', { token: tokenB })
  assert.equal(invitesCreated.data.codes.length, 1); assert.equal(invitesB.data.codes.length, 0)
  const bootstrap = await call(gateway, prefix + '/bootstrap')
  assert.equal(bootstrap.data.site.name, 'Gateway integration')
  assert.ok(!bootstrap.data.capabilities.includes('catalog.payment-methods'))
  assert.ok(!bootstrap.data.capabilities.includes('orders.create'))
  const theme = await call(gateway, prefix + '/theme/config'); assert.equal(typeof theme.data.config, 'object')
  const plans = await call(gateway, prefix + '/plans')
  assert.equal(plans.data.find(p => p.id === seeded.plan_id).month_price, 1200)
  const beforeCatalog = reached.length
  await call(gateway, prefix + '/catalog/payment-methods', {}, 404)
  assert.equal(reached.length, beforeCatalog)
  const profileA = await call(gateway, prefix + '/user/profile', { token: tokenA })
  const profileB = await call(gateway, prefix + '/user/profile', { token: tokenB })
  assert.equal(profileA.data.email, emailA); assert.equal(profileB.data.email, emailB)
  assert.notEqual(profileA.data.id, profileB.data.id)
  assert.ok(!('uuid' in profileA.data))
  const history = fixture('history', profileA.data.id, seeded.plan_id)
  const discounted = await call(gateway, prefix + '/orders?status=4', { token: tokenA })
  assert.equal(discounted.data.length, 100)
  assert.ok(discounted.data.every(order => order.status === 4))
  const historyPage1 = await call(gateway, prefix + '/orders/page?status=4&current=1&page_size=100', { token: tokenA })
  const historyPage2 = await call(gateway, prefix + '/orders/page?status=4&current=2&page_size=100', { token: tokenA })
  assert.equal(historyPage1.data.total, 101); assert.equal(historyPage1.data.lastPage, 2)
  assert.equal(historyPage2.data.items.length, 1)
  assert.equal(new Set([...historyPage1.data.items, ...historyPage2.data.items].map(order => order.trade_no)).size, 101)
  const arrayPage2 = await call(gateway, prefix + '/orders?status=4&current=2&page_size=100', { token: tokenA })
  assert.deepEqual(arrayPage2.data, historyPage2.data.items)
  const emptyHistory = await call(gateway, prefix + '/orders/page?status=4', { token: tokenB })
  assert.equal(emptyHistory.data.total, 0)
  const historyStatus = await call(gateway, prefix + '/orders/' + history.trade_no + '/status', { token: tokenA })
  assert.equal(historyStatus.data.status, 4)
  const historyDetail = await call(gateway, prefix + '/orders/' + history.trade_no, { token: tokenA })
  assert.equal(historyDetail.data.status, 4)
  await call(gateway, prefix + '/orders/' + history.trade_no, { token: tokenB }, 404)
  await call(gateway, prefix + '/orders/' + history.trade_no + '/status', { token: tokenB }, 404)
  const subscription = await call(gateway, prefix + '/user/subscription/summary', { token: tokenA })
  assert.equal(subscription.data.planId, null)
  assert.ok(!JSON.stringify(subscription).includes('subscribe_url'))
  for (const path of ['/user/profile', '/user/subscription/summary', '/orders', '/orders/page', '/payments', '/notices', '/dashboard/stats']) {
    const before = reached.length
    await call(gateway, prefix + path, {}, 401); assert.equal(reached.length, before)
    await call(gateway, prefix + path, { token: 'Bearer invalid-session-value' }, 401)
  }
  await call(core, '/txapi/billing/payment-methods', {}, 401)
  const methods = await call(gateway, prefix + '/payments', { token: tokenA })
  assert.equal(methods.data.find(p => p.id === seeded.payment_id).handlingFeeFixed, 20)
  assert.ok(!JSON.stringify(methods).includes('secret_key'))
  const notices = await call(gateway, prefix + '/notices?current=1&pageSize=100', { token: tokenA })
  assert.ok(notices.data.data.some(n => n.id === seeded.notice_id))
  assert.ok(!JSON.stringify(notices).includes('hidden-notice-secret'))
  const beforeWrite = reached.length
  await call(gateway, prefix + '/orders', { token: tokenA, body: { plan_id: seeded.plan_id, period: 'month_price' }, key: 'disabled-' + suffix }, 405)
  assert.equal(reached.length, beforeWrite)
  await call(writable, prefix + '/orders', { body: { plan_id: seeded.plan_id, period: 'month_price' }, key: 'missing-auth-' + suffix }, 401)
  await call(writable, prefix + '/orders', { token: tokenA, body: { plan_id: seeded.plan_id, period: 'month_price' } }, 400)
  const orderKey = 'order-' + suffix
  const orderBody = { plan_id: seeded.plan_id, period: 'month_price' }
  const created = await call(writable, prefix + '/orders', { token: tokenA, body: orderBody, key: orderKey }, 201)
  const replay = await call(writable, prefix + '/orders', { token: tokenA, body: orderBody, key: orderKey }, 201)
  assert.equal(created.data.trade_no, replay.data.trade_no)
  const conflict = await call(writable, prefix + '/orders', { token: tokenA, body: { ...orderBody, period: 'quarter_price' }, key: orderKey }, 409)
  assert.equal(conflict.error.code, 'IDEMPOTENCY_CONFLICT')
  const trade = created.data.trade_no
  const listA = await call(gateway, prefix + '/orders?status=0', { token: tokenA })
  const mixedHistory = await call(gateway, prefix + '/orders', { token: tokenA })
  assert.equal(mixedHistory.data.length, 100)
  assert.ok(mixedHistory.data.some(order => order.status === 4))
  assert.ok(mixedHistory.data.some(order => order.trade_no === trade && order.status === 0))
  const mixedPage = await call(gateway, prefix + '/orders/page', { token: tokenA })
  assert.equal(mixedPage.data.total, 102); assert.equal(mixedPage.data.lastPage, 2)
  const listB = await call(gateway, prefix + '/orders', { token: tokenB })
  assert.equal(listA.data.filter(o => o.trade_no === trade).length, 1)
  assert.ok(!listB.data.some(o => o.trade_no === trade))
  const detail = await call(gateway, prefix + '/orders/' + trade, { token: tokenA })
  assert.equal(detail.data.total_amount, 1200)
  const status = await call(gateway, prefix + '/orders/' + trade + '/status', { token: tokenA })
  assert.deepEqual(status.data, { tradeNo: trade, status: 0 })
  for (const tail of ['', '/status']) {
    await call(gateway, prefix + '/orders/' + trade + tail, { token: tokenB }, 404)
    await call(gateway, prefix + '/orders/' + trade + tail, { token: 'Bearer invalid-session-value' }, 401)
  }
  const stats = await call(gateway, prefix + '/dashboard/stats', { token: tokenA })
  assert.equal(stats.data.unpaidOrders, 1)
  await call(core, '/txapi/knowledge/' + seeded.article_id, {}, 401)
  const article = await call(core, '/txapi/knowledge/' + seeded.article_id, { token: tokenA })
  assert.ok(!article.data.body.includes('paid-fixture-secret'))
  fixture('activate-subscription', profileB.data.id, seeded.plan_id)
  const subscribed = await call(gateway, prefix + '/user/subscription/summary', { token: tokenB })
  assert.equal(subscribed.data.planId, seeded.plan_id)
  assert.equal(subscribed.data.transferEnable, 10 * 1073741824)
  assert.equal(subscribed.data.upload, 123); assert.equal(subscribed.data.download, 456)
  assert.equal(subscribed.data.deviceLimit, 2); assert.equal(subscribed.data.speedLimit, 100)
  assert.ok(!JSON.stringify(subscribed.data).includes('subscribe_url'))
  const paidArticle = await call(core, '/txapi/knowledge/' + seeded.article_id, { token: tokenB })
  assert.ok(paidArticle.data.body.includes('paid-fixture-secret'))
  await call(gateway, prefix + '/invites', { token: tokenA }, 404)
  await call(gateway, prefix + '/knowledge', { token: tokenA }, 404)
  await call(gateway, prefix + '/auth/login', { body: { email: emailA, password } }, 429)
  await call(core, '/txapi/auth/logout', { token: tokenA, method: 'POST' })
  await call(gateway, prefix + '/user/profile', { token: tokenA }, 401)
  assert.ok(reached.every(item => item.path.startsWith('/txapi/')))
  console.log(JSON.stringify({ result: 'passed', checks, core_head: sourceHead, database: 'txboard_gateway_test', database_version: seeded.database_version, real_http: true, discounted_history_orders: history.count, operations: [...new Set(reached.map(item => `${item.method} ${item.path.replace(/^\/txapi\/orders\/[^/]+/, '/txapi/orders/{tradeNo}')}`))], limitations: ['B5/B6 not complete', 'No anonymous payment catalog', 'Invites/knowledge Core-only checks', 'Unpaged list returns first 100; use orders.page for complete traversal', 'No production deployment'] }, null, 2))
} finally {
  try { fixture('captcha-off') } catch { /* Resource cleanup still must run. */ }
  await Promise.all(servers.map(server => new Promise(resolveClose => server.close(resolveClose))))
  try { await redis?.close() } finally { php?.kill('SIGTERM'); closeSync(phpLog) }
}
