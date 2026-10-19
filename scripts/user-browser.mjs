import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'
import { preview } from 'vite'

const root = fileURLToPath(new URL('../', import.meta.url))
const output = root + '/artifacts/user-browser'
await mkdir(output, { recursive: true })
const server = await preview({ root: root + '/web/user', configFile: false, preview: { host: '127.0.0.1', port: 0 } })
const origin = 'http://127.0.0.1:' + server.httpServer.address().port
const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined })
const checks = []
const passed = name => { checks.push(name); console.log('PASS ' + name) }
const requestId = '12345678-1234-4234-8234-123456789abc'
const amount = '9007199254740993'
const order = { id: '7', trade_no: 'ORDER-A', plan_id: '8', period: 'monthly', type: 1, status: 0,
  amount_minor: amount, currency: 'USD', plan: { id: '8', name: 'Frozen Plus' },
  created_at: '2026-10-18T00:00:00Z', paid_at: null, purchase_terms_version: 'tx-core.purchase-terms/v2' }
const offer = { offer_id: '9', plan_id: '8', plan_revision_id: '1', name: 'Frozen Plus', content: 'Published offer',
  tags: [], offer_code: 'monthly', purchase_kind: 'subscription', currency: 'USD', price_minor: amount,
  currency_minor_exponent: 2, quota_bytes: amount, group_id: null, speed_limit_mbps: null, device_limit: null,
  sell: true, renew: true, availability_version: '1', publication_hash: 'a'.repeat(64) }
const attempt = { attempt_id: '11', status: 'pending', intent_type: 'purchase', order_id: '7', recharge_id: null,
  payment_id: '1', amount_minor: amount, currency: 'USD' }
async function fixture(viewport) {
  const context = await browser.newContext({ viewport })
  const state = { captcha: false, lostCreate: true, denied: 0, advance: false, checkout: false, queried: false,
    calls: [], keys: [], pageErrors: [], forbidden: [], failures: [] }
  await context.route('**/*', async route => {
    const req = route.request(), url = new URL(req.url())
    if (url.origin !== origin || /^\/(txapi|api|plugin)(\/|$)/.test(url.pathname)) {
      state.forbidden.push(url.pathname); return route.abort()
    }
    if (!url.pathname.startsWith('/gateway/')) return route.continue()
    const send = (data, coreMeta = {}) => route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ ok: true, data, meta: { version: '1', requestId, coreRequestId: requestId, coreMeta } }) })
    const fail = status => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({
      ok: false, error: { code: status === 401 ? 'UNAUTHORIZED' : 'FORBIDDEN', message: 'withheld' }, meta: { version: '1', requestId } }) })
    if (url.pathname === '/gateway/v1/bootstrap') return send({ security: { captcha: { enabled: state.captcha, type: null, siteKey: null } } })
    try {
      const operation = url.pathname.split('/operations/')[1]
      assert.ok(operation, 'Only pinned operations are used')
      const input = req.postDataJSON().input
      state.calls.push({ operation, input })
      if (operation === 'identity.session.create') {
        assert.equal(input.email, 'alice@example.test')
        assert.match(req.headers()['idempotency-key'], /^[0-9a-f-]{36}$/)
        return send({ auth_data: 'Bearer 1|browser-user-fixture' })
      }
      if (operation === 'catalog.offers.list') { assert.equal(req.headers().authorization, undefined); return send([offer]) }
      assert.equal(req.headers().authorization, 'Bearer 1|browser-user-fixture')
      assert.equal(req.headers()['x-core-admin-path'], undefined)
      if (operation === 'identity.account.get') {
        if (state.denied) return fail(state.denied)
        return send({ id: '9007199254740995', email: 'alice@example.test', telegram_id: null, credential_status: 'provider_managed',
          subscription: { schema: 'core-entitlement/v1', authorized: false, reason: 'ENTITLEMENT_MISSING', user_id: '9007199254740995' } })
      }
      if (operation === 'commerce.orders.create') {
        state.keys.push(req.headers()['idempotency-key'])
        if (state.lostCreate) { state.lostCreate = false; return route.abort('failed') }
        return send({ id: order.id, trade_no: order.trade_no, status: 0, amount_minor: amount, currency: 'USD', purchase_terms_version: order.purchase_terms_version })
      }
      if (operation === 'commerce.orders.get') return send({ ...order, status: state.advance ? 3 : 0 })
      if (operation === 'commerce.orders.list') return send([order], { page: input.page, per_page: 20, total: 21, last_page: 2 })
      if (operation === 'billing.payment-methods.list') return send([{ id: '1', name: 'Fixture Pay', payment: 'fixture', icon: null, currency: 'USD', handling_fee_fixed: '0', handling_fee_percent: '0', external_refund_supported: false }])
      if (operation === 'billing.order-payment-attempts.list') {
        assert.equal(input.trade_no, order.trade_no)
        const { order_id, recharge_id, payment_id, ...summary } = attempt
        return send(state.checkout ? [{ ...summary, status: state.queried ? 'succeeded' : 'pending',
          created_at: order.created_at, updated_at: order.created_at }] : [],
          { next_before_id: state.checkout ? attempt.attempt_id : null, has_more: false, has_unresolved: state.checkout && !state.queried })
      }
      if (operation === 'commerce.orders.checkout') {
        state.checkout = true
        return send({ type: 1, data: 'https://payments.example.test/checkout', attempt })
      }
      if (operation === 'billing.payment-attempts.get' || operation === 'billing.payment-attempts.query') {
        if (operation.endsWith('.query')) state.queried = true
        const { order_id, recharge_id, payment_id, ...summary } = attempt
        return send({ ...summary, status: operation.endsWith('.query') ? 'succeeded' : 'pending' })
      }
      if (operation === 'commerce.orders.events.list') {
        const sequence = input.after_sequence === '0' ? '1' : state.advance ? '3' : '2'
        const type = sequence === '1' ? 'created' : sequence === '2' ? 'payment_accepted' : 'fulfilled'
        return send(input.after_sequence === sequence ? [] : [{ sequence, type, status: sequence === '1' ? 0 : sequence === '2' ? 1 : 3, occurred_at: order.created_at }],
          { next_after_sequence: sequence, has_more: sequence === '1', history_complete: true })
      }
      if (operation === 'identity.session.end') return send({ ok: true })
      throw new Error('Unexpected user operation: ' + operation)
    } catch (error) { state.failures.push(error.message); return route.abort('failed') }
  })
  const page = await context.newPage()
  page.on('pageerror', error => state.pageErrors.push(error.message))
  return { context, page, state }
}
async function login(page) {
  await page.goto(origin + '/#/login')
  await page.getByRole('textbox', { name: '邮箱', exact: true }).fill('alice@example.test')
  await page.getByLabel('密码', { exact: true }).fill('password123')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByRole('heading', { name: '账户概览', exact: true }).waitFor()
}
try {
  const desktop = await fixture({ width: 1440, height: 1000 })
  const { page, state } = desktop
  await login(page)
  await page.getByText('账户 ID：9007199254740995', { exact: true }).waitFor()
  assert.deepEqual(await page.evaluate(() => [localStorage.length, sessionStorage.length]), [0, 0])
  passed('memory-only user login and exact account identifiers')
  await page.getByRole('link', { name: '购买套餐', exact: true }).click()
  await page.getByText('monthly · 90071992547409.93 USD', { exact: true }).waitFor()
  await page.getByRole('button', { name: '创建订单', exact: true }).click()
  await page.getByRole('heading', { name: '操作等待确认' }).waitFor()
  assert.equal(await page.getByRole('button', { name: '创建订单', exact: true }).isDisabled(), true)
  await page.getByRole('button', { name: '使用原操作确认结果' }).click()
  await page.getByRole('heading', { name: '订单详情', exact: true }).waitFor()
  assert.equal(state.keys.length, 2); assert.equal(state.keys[0], state.keys[1])
  await page.getByText('金额：90071992547409.93 USD', { exact: true }).waitFor()
  passed('published offer purchase and lost-response recovery retain the original idempotency key')
  await page.getByText('订单已创建', { exact: false }).waitFor()
  await page.getByText('支付已确认', { exact: false }).waitFor()
  assert.ok(state.calls.some(call => call.operation === 'commerce.orders.events.list' && call.input.after_sequence === '1'))
  await page.getByRole('combobox').selectOption('1')
  await page.getByRole('button', { name: '确认并发起支付' }).click()
  const payment = page.getByRole('link', { name: '打开支付页面' })
  await payment.waitFor()
  assert.equal(await payment.getAttribute('href'), 'https://payments.example.test/checkout')
  assert.equal(await payment.getAttribute('rel'), 'noopener noreferrer')
  await page.getByRole('img', { name: '支付链接二维码' }).waitFor()
  await page.screenshot({ path: output + '/order-desktop.png', fullPage: true })
  passed('payment checkout displays a validated HTTPS link and QR without opening the channel')
  assert.equal(await page.getByRole('button', { name: '确认并发起支付' }).isDisabled(), true)
  await page.getByRole('button', { name: '重新读取当前页面', exact: true }).click()
  await page.getByRole('combobox', { name: '选择支付尝试' }).waitFor()
  assert.equal(await page.getByRole('button', { name: '确认并发起支付' }).isDisabled(), true)
  assert.equal(state.calls.filter(call => call.operation === 'commerce.orders.checkout').length, 1)
  passed('re-entering an order discovers pending payments and prevents a second checkout')
  await page.getByRole('button', { name: '向渠道确认支付结果' }).click()
  await page.getByText('支付尝试 11：succeeded · 90071992547409.93 USD', { exact: true }).waitFor()
  passed('payment query preserves exact amount and shows the confirmed attempt status')
  state.advance = true
  await page.evaluate(() => window.dispatchEvent(new Event('online')))
  await page.getByText('履约已完成', { exact: false }).first().waitFor()
  passed('timeline drains pages and reconnects from the retained cursor')
  await page.getByRole('link', { name: '账户概览', exact: true }).click()
  state.denied = 403
  await page.getByRole('button', { name: '重新读取当前页面' }).click()
  await page.getByText('当前账号没有此操作的权限。', { exact: true }).waitFor()
  assert.equal(await page.getByRole('heading', { name: 'TXBoard 用户登录' }).count(), 0)
  state.denied = 401
  await page.getByRole('button', { name: '重新读取当前页面' }).click()
  await page.getByRole('heading', { name: 'TXBoard 用户登录' }).waitFor()
  await page.reload()
  await page.getByRole('heading', { name: 'TXBoard 用户登录' }).waitFor()
  passed('403 preserves identity while 401 and reload clear user facts and credentials')
  assert.deepEqual(state.failures, []); assert.deepEqual(state.forbidden, []); assert.deepEqual(state.pageErrors, [])
  await desktop.context.close()
  const mobile = await fixture({ width: 390, height: 844 })
  mobile.state.captcha = true
  await mobile.page.goto(origin + '/#/login')
  await mobile.page.getByText('此站点要求验证码，当前用户入口暂不支持该验证码配置，请联系管理员。', { exact: true }).waitFor()
  assert.equal(await mobile.page.getByRole('button', { name: '登录', exact: true }).isDisabled(), true)
  mobile.state.captcha = false
  await mobile.page.reload()
  await login(mobile.page)
  assert.ok(await mobile.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  await mobile.page.screenshot({ path: output + '/account-mobile.png', fullPage: true })
  await mobile.page.getByRole('button', { name: '退出登录', exact: true }).click()
  await mobile.page.getByRole('heading', { name: 'TXBoard 用户登录' }).waitFor()
  assert.deepEqual(mobile.state.failures, []); assert.deepEqual(mobile.state.forbidden, []); assert.deepEqual(mobile.state.pageErrors, [])
  await mobile.context.close()
  passed('unsupported captcha fails closed, mobile account layout fits, and explicit logout clears identity')
  await writeFile(output + '/result.json', JSON.stringify({ passed: true, checks, frontend: 'built same-repository user UI',
    transport: 'synthetic Gateway responses', realCoreAcceptance: 'separate Core Actions run; no combined production UI acceptance' }, null, 2) + '\n')
} finally {
  await browser.close()
  await server.close()
}
