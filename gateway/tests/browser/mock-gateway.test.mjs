import test from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { spawn } from 'node:child_process'
import { build } from 'esbuild'
import { mkdtemp, writeFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { generateKeyPairSync } from 'node:crypto'
import { chromium } from 'playwright'

const TEST_BEARER = 'Bearer fixture-session-abc123'
const SITE = 'fixture-site-key'
const email = 'user@fixture.test'
const password = 'fixture-password-123'
const upstreamRequests = []
const requestId = '12345678-1234-4234-8234-123456789abc'
const upstreamEnvelope = (data, meta) => ({ data, request_id: requestId, ...(meta ? { meta } : {}) })
const upstreamError = message => ({ error: { code: 'REJECTED', message }, request_id: requestId })
const orderFixture = { id: 1, trade_no: 'fixture-order', status: 4, plan_id: 1, period: 'monthly', type: 1, amount_minor: 1200, plan: { id: 1, name: 'Fixture Pro', traffic_limit_bytes: 100 }, paid_at: null, created_at: '2026-10-12T00:00:00+00:00', payment_id: null, balance_amount_minor: 0, discount_amount_minor: 0 }
// GW-304: idempotency-key -> trade_no, mirroring the Laravel semantics the
// ADR specifies (same key + same body replays the first result).
const ordersByKey = new Map()

async function startServer(handler) {
  const server = createServer(handler)
  await new Promise((resolve, reject) => {
    server.once('error', reject)
    server.listen(0, '127.0.0.1', resolve)
  })
  return {
    server,
    port: server.address().port,
    close: () => new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve())),
  }
}
async function choosePort() {
  const { port, close } = await startServer((_req, res) => res.end())
  await close()
  return port
}
async function waitHealth(url, child) {
  for (let i = 0; i < 100; i++) {
    if (child.exitCode !== null) throw new Error('Gateway child exited before health')
    try {
      const response = await fetch(url)
      if (response.ok) return
    } catch {}
    await new Promise(resolve => setTimeout(resolve, 100))
  }
  throw new Error('Gateway did not become ready')
}

test('Chromium theme SDK works through Gateway with strict fake Laravel contracts', { timeout: 120000 }, async t => {
  const upstream = await startServer(async (req, res) => {
    let path = req.url?.split('?')[0] || ''
    upstreamRequests.push(path)
    res.setHeader('Content-Type', 'application/json')
    let status = 200
    let result
    if (path === '/txapi/public/site-config') {
      result = upstreamEnvelope({
        app_name: 'Fixture TXBoard', frontend_theme: 'TXBoard', theme_config: { accent: 'blue' },
        is_captcha: 1, captcha_type: 'turnstile', turnstile_site_key: SITE,
        turnstile_secret_key: 'not-public',
      })
    } else if (path === '/txapi/plans') {
      result = upstreamEnvelope([{ id: 1, name: 'Monthly', content: '', tags: [], traffic_limit_bytes: 1073741824, speed_limit_mbps: null, device_limit: null, capacity_limit: null, reset_traffic_method: null, prices: [{ period: 'monthly', amount_minor: 1200 }], renewable: true }])
    } else if ([
      '/txapi/auth/login', '/txapi/auth/register',
      '/txapi/auth/email-code',
    ].includes(path) && req.method === 'POST') {
      let data = ''
      for await (const chunk of req) data += chunk
      const submitted = JSON.parse(data)
      if (submitted.email !== email || submitted.turnstile_token !== 'fixture-captcha'
        || (path !== '/txapi/auth/email-code' && submitted.password !== password)) {
        status = 422
        result = upstreamError('Invalid fixture test credentials')
      } else {
        result = upstreamEnvelope(path === '/txapi/auth/email-code' ? { ok: true }
          : { auth_data: TEST_BEARER, is_admin: false, secure_path: 'private-admin-route', token: 'legacy-token' })
      }
    } else if (path === '/txapi/orders' && req.method === 'POST') {
      let data = ''
      for await (const chunk of req) data += chunk
      const submitted = JSON.parse(data)
      // The Gateway must forward the client's Idempotency-Key verbatim and
      // add its own hint header; neither is authoritative, both must arrive.
      const idempotencyKey = req.headers['idempotency-key']
      const idempotencyHint = req.headers['x-idempotency-key-hint']
      if (!idempotencyKey || !idempotencyHint) {
        status = 400
        result = upstreamError('Idempotency-Key header is required')
      } else if (req.headers.authorization !== TEST_BEARER) {
        status = 401
        result = upstreamError('Expired fixture session')
      } else if (!(submitted.plan_id === 1 && submitted.period === 'month_price')) {
        status = 422
        result = upstreamError('Invalid fixture order')
      } else {
        // Same key + same body replays the first result; a fresh key creates
        // a new order. The fixture mirrors the Laravel semantics the ADR
        // specifies (GW-302/303), not the Gateway's.
        if (!ordersByKey.has(idempotencyKey)) ordersByKey.set(idempotencyKey, 'fixture-order-' + ordersByKey.size)
        result = upstreamEnvelope({ trade_no: ordersByKey.get(idempotencyKey), status: 0 })
      }
    } else if ([
      '/txapi/me', '/txapi/orders', '/txapi/me/subscription',
      '/txapi/orders/fixture-order/detail', '/txapi/billing/payment-methods',
      '/txapi/notices', '/txapi/me/dashboard-stats', '/txapi/orders/fixture-order',
    ].includes(path)) {
      if (req.headers.authorization !== TEST_BEARER) {
        status = 401
        result = upstreamError('Expired fixture session')
      } else if (path === '/txapi/me') {
        result = upstreamEnvelope({ id: 1, email, telegram_id: null, credential_status: 'provider_managed' })
      } else if (path.endsWith('/dashboard-stats')) {
        result = upstreamEnvelope({ unpaid_orders: 2, open_tickets: 1, invited_users: 7 })
      } else if (path === '/txapi/orders/fixture-order') {
        result = upstreamEnvelope(orderFixture)
      } else if (path.endsWith('/subscription')) {
        result = upstreamEnvelope({
          schema: 'core-entitlement/v1', authorized: true, reason: 'ENTITLEMENT_GRANTED',
          subscription_id: '1', plan_id: '1', quota_bytes: '100', consumed_bytes: '30', remaining_bytes: '70',
          upload_bytes: '10', download_bytes: '20', expires_at: null, next_reset_at: null,
          device_limit: null, speed_limit_mbps: null, token: 'must-never-leak',
          credential_status: 'provider_managed',
        })
      } else if (path.endsWith('/detail')) {
        result = upstreamEnvelope(orderFixture)
      } else if (path.endsWith('/payment-methods')) {
        result = upstreamEnvelope([{ id: 2, name: 'Fixture Card', provider: 'Stripe', icon: null, fee_fixed_minor: 0, fee_percent: 0, config: { secret: 'private-payment-key' } }])
      } else if (path.endsWith('/notices')) {
        result = upstreamEnvelope([{ id: 7, title: 'Fixture Notice', content: '', tags: [], img_url: null, created_at: '2026-10-12T00:00:00+00:00' }], { page: 1, per_page: 5, total: 1, last_page: 1 })
      } else {
        result = upstreamEnvelope([orderFixture], { page: 1, per_page: 100, total: 1, last_page: 1 })
      }
    } else {
      status = 404
      result = upstreamError('Unknown upstream route')
    }
    res.statusCode = status
    res.end(JSON.stringify(result))
  })
  t.after(() => upstream.close())

  const bundle = await build({
    entryPoints: ['packages/theme-sdk/dist/index.js'],
    bundle: true, platform: 'browser', format: 'esm', write: false,
  })
  const sdkSource = bundle.outputFiles[0].contents
  const frontend = await startServer((req, res) => {
    if (req.url === '/sdk.js') {
      res.setHeader('Content-Type', 'text/javascript')
      res.end(sdkSource)
    } else {
      res.setHeader('Content-Type', 'text/html; charset=utf-8')
      res.end('<!doctype html><html><body><h1>Isolated TXBoard theme fixture</h1></body></html>')
    }
  })
  t.after(() => frontend.close())

  const keyDir = await mkdtemp(join(tmpdir(), 'txboard-gateway-hpke-'))
  const keyFile = join(keyDir, 'test-hpke.json')
  const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
  await writeFile(keyFile, JSON.stringify(privateKey.export({ format: 'jwk' })), { mode: 0o600 })
  t.after(() => rm(keyDir, { recursive: true, force: true }))

  const gatewayPort = await choosePort()
  const gateway = spawn(process.execPath, ['apps/gateway/dist/index.js'], {
    env: {
      ...process.env,
      GATEWAY_HOST: '127.0.0.1',
      GATEWAY_PORT: String(gatewayPort),
      TXBOARD_UPSTREAM_URL: 'http://127.0.0.1:' + upstream.port,
      TXBOARD_ALLOW_PRIVATE_HTTP: 'true',
      GATEWAY_ALLOWED_ORIGINS: 'http://127.0.0.1:' + frontend.port,
      GATEWAY_HPKE_MODE: 'optional',
      GATEWAY_HPKE_KEY_FILE: keyFile,
      GATEWAY_REDIS_URL: process.env.GATEWAY_TEST_REDIS_URL || 'redis://127.0.0.1:16379',
      GATEWAY_ACCOUNT_WORKFLOWS_ENABLED: 'true',
      // GW-304: the order-write adaptation layer is opt-in; with the flag the
      // route is the promoted orderWrite policy (bearer + Idempotency-Key),
      // without it the permanent 405 write ban stays in force.
      GATEWAY_ORDER_WRITE_ENABLED: 'true',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  })
  const childOutput = []
  gateway.stdout.on('data', data => childOutput.push(data.toString()))
  gateway.stderr.on('data', data => childOutput.push(data.toString()))
  t.after(async () => {
    gateway.kill('SIGTERM')
    if (gateway.exitCode === null) {
      await Promise.race([
        new Promise(resolve => gateway.once('exit', resolve)),
        new Promise(resolve => setTimeout(resolve, 1500)),
      ])
      if (gateway.exitCode === null) gateway.kill('SIGKILL')
    }
  })
  await waitHealth('http://127.0.0.1:' + gatewayPort + '/healthz', gateway)

  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.GATEWAY_TEST_CHROMIUM_PATH || undefined,
  })
  t.after(() => browser.close())
  const page = await browser.newPage()
  await page.goto('http://127.0.0.1:' + frontend.port)
  let encryptedRequestBody = ''
  page.on('request', req => {
    if (req.url().includes('/secure/auth/login')) encryptedRequestBody = req.postData() || ''
  })
  const result = await page.evaluate(async ({ gatewayPort, email, password }) => {
    const { createTXBoardClient } = await import('/sdk.js')
    let session = null
    const api = createTXBoardClient({
      baseURL: 'http://127.0.0.1:' + gatewayPort + '/gateway/v1',
      getToken: () => session,
    })
    const bootstrap = await api.bootstrap()
    const plans = await api.plans.list()
    const login = await api.auth.login({ email, password, turnstile_token: 'fixture-captcha' })
    session = login.auth_data
    const profile = await api.user.profile()
    const orders = await api.orders.list({ status: 4 })
    const orderPage = await api.orders.page({ status: 4, current: 1, page_size: 100 })
    const subscription = await api.user.subscription()
    const detail = await api.orders.detail('fixture-order')
    const payments = await api.payments.list()
    const notices = await api.notices.list({ current: 1, pageSize: 5 })
    const encryptedApi = createTXBoardClient({
      baseURL: 'http://127.0.0.1:' + gatewayPort + '/gateway/v1',
      encryptedLogin: true,
    })
    const stats = await api.dashboard.stats()
    const orderStatus = await api.orders.status('fixture-order')
    // GW-304: order write through the adaptation layer. The SDK sends the
    // Idempotency-Key header; the Gateway forwards it plus its own hint.
    const orderKey = 'browser-fixture-order-1'
    const created = await api.orders.create(
      { plan_id: 1, period: 'month_price' }, { idempotencyKey: orderKey },
    )
    // Replaying the SAME key returns the SAME order (the fixture upstream
    // mirrors the Laravel idempotency semantics, not the Gateway's).
    const replayed = await api.orders.create(
      { plan_id: 1, period: 'month_price' }, { idempotencyKey: orderKey },
    )
    let missingKeyStatus
    try {
      await fetch('http://127.0.0.1:' + gatewayPort + '/gateway/v1/orders', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: session },
        body: JSON.stringify({ plan_id: 1, period: 'month_price' }),
      }).then(r => { missingKeyStatus = r.status })
    } catch { /* captured below as undefined */ }
    const code = await encryptedApi.auth.sendEmailCode({ email, turnstile_token: 'fixture-captcha' })
    const registered = await encryptedApi.auth.register({ email, password, turnstile_token: 'fixture-captcha' })
    const protectedLogin = await encryptedApi.auth.login({
      email, password, turnstile_token: 'fixture-captcha',
    })
    session = 'invalid-session-123'
    let expiredStatus
    try {
      await api.user.profile()
    } catch (error) {
      expiredStatus = error.status
    }
    let rejectedStatus
    try {
      await api.auth.login({ email, password: 'wrong-password', turnstile_token: 'fixture-captcha' })
    } catch (error) {
      rejectedStatus = error.status
    }
    return {
      siteName: bootstrap.site.name,
      captcha: bootstrap.security.captcha,
      theme: bootstrap.theme,
      plans,
      loginKeys: Object.keys(login).sort(),
      profileEmail: profile.email,
      orderNumber: orders[0]?.trade_no,
      orderState: orders[0]?.status,
      orderPage,
      subscriptionId: subscription.subscription_id,
      remainingBytes: subscription.remaining_bytes,
      leakedSubscriptionToken: JSON.stringify(subscription).includes('must-never-leak'),
      detailNumber: detail.trade_no,
      paymentName: payments[0]?.name,
      leakedPaymentKey: JSON.stringify(payments).includes('private-payment-key'),
      noticeTotal: notices.total,
      encryptedLoginKeys: Object.keys(protectedLogin).sort(),
      registrationKeys: Object.keys(registered).sort(),
      codeSent: code.sent,
      stats,
      orderStatus,
      createdTradeNo: created.trade_no,
      replayedTradeNo: replayed.trade_no,
      missingKeyStatus,
      expiredStatus,
      rejectedStatus,
      persistedAuth: Object.keys(localStorage).some(k => /auth|token/i.test(k)),
    }
  }, { gatewayPort, email, password })
  assert.equal(result.siteName, 'Fixture TXBoard')
  assert.deepEqual(result.captcha, { enabled: true, type: 'turnstile', siteKey: SITE })
  assert.deepEqual(result.theme, { name: 'TXBoard', config: { accent: 'blue' } })
  assert.equal(result.plans[0].month_price, 1200)
  assert.deepEqual(result.loginKeys, ['auth_data'])
  assert.equal(result.profileEmail, email)
  assert.equal(result.orderNumber, 'fixture-order')
  assert.equal(result.orderState, 4)
  assert.equal(result.orderPage.items[0]?.status, 4)
  assert.equal(result.orderPage.total, 1)
  assert.equal(result.orderPage.lastPage, 1)
  assert.equal(result.subscriptionId, '1')
  assert.equal(result.remainingBytes, '70')
  assert.equal(result.leakedSubscriptionToken, false)
  assert.equal(result.detailNumber, 'fixture-order')
  assert.equal(result.paymentName, 'Fixture Card')
  assert.equal(result.leakedPaymentKey, false)
  assert.equal(result.noticeTotal, 1)
  assert.deepEqual(result.encryptedLoginKeys, ['auth_data'])
  assert.deepEqual(result.registrationKeys, ['auth_data'])
  assert.equal(result.codeSent, true)
  assert.deepEqual(result.stats, { unpaidOrders: 2, openTickets: 1, invitedUsers: 7 })
  assert.deepEqual(result.orderStatus, { tradeNo: 'fixture-order', status: 4 })
  // GW-304: the write path. Same key + same body = same order; a missing
  // Idempotency-Key is refused by the Gateway before any upstream call.
  assert.equal(result.createdTradeNo, 'fixture-order-0')
  assert.equal(result.replayedTradeNo, 'fixture-order-0')
  assert.equal(result.missingKeyStatus, 400)
  assert.ok(upstreamRequests.includes('/txapi/orders'))
  assert.ok(encryptedRequestBody.includes('"kid"'))
  assert.ok(encryptedRequestBody.includes('"ct"'))
  assert.ok(!encryptedRequestBody.includes(password))
  assert.ok(!encryptedRequestBody.includes(email))
  assert.equal(result.expiredStatus, 401)
  assert.equal(result.rejectedStatus, 422)
  assert.equal(result.persistedAuth, false)
  assert.ok(upstreamRequests.includes('/txapi/orders'))
  assert.ok(!upstreamRequests.some(path => path.startsWith('/api/v2/')))
  assert.ok(!childOutput.join('').includes(password))
})
