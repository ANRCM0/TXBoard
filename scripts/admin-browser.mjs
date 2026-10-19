import assert from 'node:assert/strict'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { createServer } from 'node:http'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const root = fileURLToPath(new URL('../', import.meta.url))
const output = fileURLToPath(new URL('../artifacts/admin-browser/', import.meta.url))
await mkdir(output, { recursive: true })
// Serve one compiled asset tree at multiple runtime mounts, as the Core entry
// route does. The build-time asset base must never become the router basename.
const dist = path.join(root, 'web/admin/dist')
const server = createServer(async (request, response) => {
  try {
    const pathname = new URL(request.url, 'http://localhost').pathname
    const asset = pathname.match(/^\/(?:admin|\.txboard-admin)\/assets\/([A-Za-z0-9_.-]+)$/)
    if (asset) {
      const bytes = await readFile(path.join(dist, 'assets', asset[1]))
      response.writeHead(200, { 'Content-Type': asset[1].endsWith('.css') ? 'text/css' : 'text/javascript' })
      return response.end(bytes)
    }
    if (/^\/(?:admin|rotated_admin)(?:\/.*)?$/.test(pathname)) {
      response.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'private, no-store' })
      return response.end(await readFile(path.join(dist, 'index.html')))
    }
    response.writeHead(404); response.end()
  } catch { response.writeHead(404); response.end() }
})
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
const address = server.address()
const origin = 'http://127.0.0.1:' + address.port
const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined })
const checks = []
const requestId = '12345678-1234-4234-8234-123456789abc'
const node = { id: '9007199254740993', name: 'Tokyo-A', type: 'vless', host: '2001:db8::1', port: '443',
  server_port: null, parent_id: null, machine_id: '9', show: true, enabled: true, sort: null, u: '9007199254740999', d: '0' }
const metadata = { version: '1', requestId, coreRequestId: requestId, coreMeta: {} }
const account = { id: '9007199254740995', email: 'alice@example.test', banned: false, is_admin: false,
  created_at: 1792245600, last_login_at: null }
const order = { id: '7', user_id: account.id, trade_no: 'ORDER-A', plan_id: '8', period: 'monthly',
  type: 1, status: 0, amount_minor: '9007199254740993', currency: 'USD', plan: { id: '8', name: 'Frozen Plus' },
  created_at: '2026-10-18T00:00:00Z', paid_at: null, purchase_terms_version: 'tx-core.purchase-terms/v2' }
function passed(name) { checks.push(name); console.log('PASS ' + name) }
function session(id, name) {
  return { id, name, abilities: ['admin'], created_at: '2026-10-18T08:00:00Z',
    last_used_at: null, updated_at: null, expires_at: null }
}
async function fixture(viewport) {
  const context = await browser.newContext({ viewport })
  const state = { nodesStatus: 200, logoutFailure: false, revokeLost: true, malformedCaptcha: false,
    sessions: [session('1', 'Current session'), session('2', 'Secondary session')], operations: [], revokeKeys: [],
    pageErrors: [], forbiddenRequests: [], reads: [], accountsStatus: 200, ordersStatus: 200,
    eventStage: 0, eventFailure: false, eventsStatus: 200 }
  await context.route('**/gateway/v1/**', async route => {
    const req = route.request()
    const url = new URL(req.url())
    const send = (data, coreMeta = {}) => route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ ok: true, data, meta: { ...metadata, coreMeta } }) })
    const fail = status => route.fulfill({ status, contentType: 'application/json',
      body: JSON.stringify({ ok: false, error: { code: status === 401 ? 'UNAUTHORIZED' : 'FORBIDDEN', message: 'withheld' },
        meta: { version: '1', requestId } }) })
    if (url.pathname === '/gateway/v1/bootstrap') return send({ security: {
      captcha: state.malformedCaptcha ? { enabled: true, type: null, siteKey: null } : { enabled: false, type: null, siteKey: null },
    } })
    const operation = url.pathname.split('/operations/')[1]
    assert.ok(operation, 'Only pinned operations are used')
    state.operations.push(operation)
    const input = req.postDataJSON().input
    state.reads.push({ operation, input })
    if (operation === 'identity.admin-session.create') {
      assert.equal(input.email, 'admin@example.test')
      assert.match(req.headers()['idempotency-key'], /^[0-9a-f-]{36}$/)
      return send({ auth_data: 'Bearer 1|browser-admin-fixture', is_admin: true, secure_path: 'ci_admin' })
    }
    assert.equal(req.headers().authorization, 'Bearer 1|browser-admin-fixture')
    assert.equal(req.headers()['x-core-admin-path'], 'ci_admin')
    if (operation === 'identity.admin.get') return send({ id: '9007199254740993', email: 'admin@example.test' })
    if (operation === 'identity.accounts.list') {
      if (state.accountsStatus !== 200) return fail(state.accountsStatus)
      return send([{ ...account, email: input.page === 2 ? 'bob@example.test' : account.email }],
        { page: input.page, per_page: 20, total: input.email ? 1 : 21, last_page: input.email ? 1 : 2 })
    }
    if (operation === 'identity.accounts.get') return send({ ...account, credential_status: 'provider_managed',
      subscription: { schema: 'core-entitlement/v1', authorized: false, reason: 'ENTITLEMENT_MISSING', user_id: account.id } })
    if (operation === 'commerce.admin-orders.list') {
      if (state.ordersStatus !== 200) return fail(state.ordersStatus)
      return send([{ ...order, trade_no: input.page === 2 ? 'ORDER-B' : order.trade_no }],
        { page: input.page, per_page: 20, total: input.trade_no ? 1 : 21, last_page: input.trade_no ? 1 : 2 })
    }
    if (operation === 'commerce.admin-orders.audit.list') return send([], { next_after_id: '0', has_more: false })
    if (operation === 'commerce.admin-orders.get') return send({ ...order, trade_no: input.trade_no,
      status: state.eventStage === 2 ? 3 : state.eventStage === 1 ? 1 : 0 })
    if (operation === 'commerce.admin-orders.events.list') {
      if (state.eventsStatus !== 200) return fail(state.eventsStatus)
      if (state.eventFailure) { state.eventFailure = false; return route.abort('failed') }
      const rows = [
        { sequence: '1', type: 'created', status: 0, occurred_at: order.created_at },
        ...(state.eventStage >= 1 ? [{ sequence: '2', type: 'payment_accepted', status: 1, occurred_at: order.created_at }] : []),
        ...(state.eventStage >= 2 ? [{ sequence: '3', type: 'fulfilled', status: 3, occurred_at: order.created_at }] : []),
      ].filter(event => BigInt(event.sequence) > BigInt(input.after_sequence ?? '0'))
      const page = rows.slice(0, 1)
      return send(page, { next_after_sequence: page.at(-1)?.sequence ?? input.after_sequence ?? '0',
        has_more: rows.length > page.length, history_complete: true })
    }
    if (operation === 'network.nodes.list') {
      if (state.nodesStatus !== 200) return fail(state.nodesStatus)
      return send([{ ...node, name: input.page === 2 ? 'Osaka-B' : 'Tokyo-A' }],
        { page: input.page, per_page: 20, total: 21, last_page: 2 })
    }
    if (operation === 'network.machines.list') return send([{ id: '9', name: 'Tokyo host',
      is_active: true, image_channel: 'dev', last_seen_at: null, servers_count: 1,
      created_at: null, updated_at: null }], { page: input.page, per_page: 20, total: 1, last_page: 1 })
    if (operation === 'settings.site.get') return send({ site: { app_name: 'TXBoard',
      app_description: 'Subscription services', app_url: 'https://example.test', logo: null, tos_url: null,
      currency: 'CNY', stop_register: 0 } })
    if (operation === 'identity.sessions.list') return send(state.sessions, { has_more: false })
    if (operation === 'identity.sessions.revoke') {
      state.revokeKeys.push(req.headers()['idempotency-key'])
      if (state.revokeLost) { state.revokeLost = false; return route.abort('failed') }
      state.sessions = state.sessions.filter(row => row.id !== input.session_id)
      return send({ ok: true })
    }
    if (operation === 'identity.session.end') {
      if (state.logoutFailure) return route.abort('failed')
      return send({ ok: true })
    }
    throw new Error('Unexpected management operation: ' + operation)
  })
  const page = await context.newPage()
  page.on('pageerror', error => state.pageErrors.push(error.message))
  page.on('request', req => {
    if (/\/(?:txapi|plugin)\//.test(new URL(req.url()).pathname)) state.forbiddenRequests.push(req.url())
  })
  return { context, page, state }
}
async function login(page, target = '/admin/') {
  await page.goto(origin + target)
  await page.getByRole('heading', { name: '管理员登录' }).waitFor()
  await page.getByRole('textbox', { name: '邮箱' }).fill('admin@example.test')
  await page.getByLabel('密码', { exact: true }).fill('admin-password')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.waitForURL(url => !url.pathname.endsWith('sign-in'))
}
async function nav(page, name) {
  await page.getByRole('link', { name: new RegExp('^' + name + '(?:\\s*待开放)?$') }).click()
}
try {
  const desktop = await fixture({ width: 1440, height: 960 })
  const { page, state } = desktop
  await login(page, '/admin/server/manage')
  await page.getByText('Tokyo-A', { exact: true }).waitFor()
  assert.match(await page.locator('table').innerText(), /9007199254740999/)
  assert.match(await page.locator('table').innerText(), /\[2001:db8::1\]:443/)
  passed('desktop login, protected deep link and exact decimal inventory')
  await page.locator('[data-sonner-toast]').waitFor({ state: 'detached' })
  await page.screenshot({ path: output + '/nodes-desktop.png', fullPage: true })
  await page.getByRole('button', { name: '下一页', exact: true }).click()
  await page.getByText('Osaka-B', { exact: true }).waitFor()
  passed('server pagination')
  state.nodesStatus = 403
  await page.getByRole('button', { name: '刷新', exact: true }).click()
  await page.getByRole('alert').filter({ hasText: '没有此操作的权限' }).waitFor()
  assert.equal(await page.locator('table').count(), 0)
  assert.ok(!page.url().includes('sign-in'))
  passed('permission revocation removes stale rows without ending the session')
  await page.getByRole('button', { name: '展开侧边栏', exact: true }).click()
  await nav(page, '服务器管理')
  await page.getByText('Tokyo host', { exact: true }).waitFor()
  assert.match(await page.locator('table').innerText(), /未上报/)
  passed('machine projection and unknown heartbeat')
  await nav(page, '系统配置')
  await page.locator('section[aria-label="站点配置"]').waitFor()
  assert.match(await page.locator('dl').innerText(), /CNY/)
  assert.equal(await page.getByRole('button', { name: '保存', exact: true }).count(), 0)
  passed('read-only settings')
  await nav(page, '登录会话')
  const secondary = page.getByRole('row').filter({ hasText: 'Secondary session' })
  await secondary.getByRole('button', { name: '撤销', exact: true }).click()
  assert.equal(state.revokeKeys.length, 0)
  await page.getByRole('dialog').getByRole('button', { name: '撤销', exact: true }).click()
  await page.getByText(/再次操作将沿用原请求标识/).waitFor()
  await secondary.getByRole('button', { name: '撤销', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '撤销', exact: true }).click()
  await secondary.waitFor({ state: 'detached' })
  assert.equal(state.revokeKeys.length, 2)
  assert.equal(state.revokeKeys[0], state.revokeKeys[1])
  passed('confirmation and original-key recovery after a lost revocation response')
  await nav(page, '用户管理')
  await page.getByRole('link', { name: account.email, exact: true }).waitFor()
  assert.match(await page.locator('table').innerText(), new RegExp(account.id))
  await page.getByRole('button', { name: '下一页', exact: true }).click()
  await page.getByRole('link', { name: 'bob@example.test', exact: true }).waitFor()
  await page.goBack()
  await page.getByRole('link', { name: account.email, exact: true }).waitFor()
  await page.getByRole('textbox', { name: '用户邮箱', exact: true }).fill(account.email)
  await page.getByLabel('账户状态', { exact: true }).selectOption('0')
  await page.getByRole('button', { name: '查询', exact: true }).click()
  await page.waitForURL(url => url.searchParams.get('email') === account.email && url.searchParams.get('banned') === '0')
  await page.waitForFunction(() => document.querySelector('.native-pagination')?.textContent.includes('共 1 项'))
  assert.deepEqual(state.reads.filter(row => row.operation === 'identity.accounts.list').at(-1).input,
    { page: 1, per_page: 20, email: account.email, banned: false })
  await page.locator('[data-sonner-toast]').first().waitFor({ state: 'detached' })
  await page.screenshot({ path: output + '/users-desktop.png', fullPage: true })
  passed('user filters, pagination, browser back and exact string identifiers')
  await page.getByRole('link', { name: account.email, exact: true }).click()
  await page.getByRole('heading', { name: '用户详情', exact: true }).waitFor()
  await page.getByText('当前没有可用订阅。', { exact: true }).waitFor()
  assert.match(await page.locator('section[aria-label="账户信息"]').innerText(), new RegExp(account.id))
  assert.doesNotMatch(await page.locator('section[aria-label="账户信息"]').innerText(), /余额|佣金|密码/)
  await page.getByRole('link', { name: '查看该用户订单', exact: true }).click()
  await page.getByRole('button', { name: 'ORDER-A', exact: true }).waitFor()
  assert.equal(new URL(page.url()).searchParams.get('user_id'), account.id)
  assert.deepEqual(state.reads.filter(row => row.operation === 'commerce.admin-orders.list').at(-1).input,
    { page: 1, per_page: 20, user_id: account.id })
  passed('native user detail and linked account order filter')
  await page.getByRole('button', { name: '下一页', exact: true }).click()
  await page.getByRole('button', { name: 'ORDER-B', exact: true }).waitFor()
  assert.equal(new URL(page.url()).searchParams.get('user_id'), account.id)
  await page.goBack()
  await page.getByRole('button', { name: 'ORDER-A', exact: true }).waitFor()
  await page.getByRole('textbox', { name: '订单号', exact: true }).fill('ORDER-A')
  await page.getByLabel('订单状态', { exact: true }).selectOption('0')
  await page.getByRole('button', { name: '查询', exact: true }).click()
  await page.waitForURL(url => url.searchParams.get('trade_no') === 'ORDER-A')
  await page.waitForFunction(() => document.querySelector('.native-pagination')?.textContent.includes('共 1 项'))
  assert.deepEqual(state.reads.filter(row => row.operation === 'commerce.admin-orders.list').at(-1).input,
    { page: 1, per_page: 20, user_id: account.id, trade_no: 'ORDER-A', status: 0 })
  await page.getByRole('button', { name: 'ORDER-A', exact: true }).click()
  const detail = page.getByRole('dialog', { name: '订单详情', exact: true })
  await detail.getByText('90071992547409.93 USD', { exact: true }).waitFor()
  await detail.getByText('Frozen Plus', { exact: true }).waitFor()
  const timeline = detail.getByRole('region', { name: '订单状态时间线' })
  await timeline.getByText('订单已创建', { exact: true }).waitFor()
  state.eventFailure = true
  await page.evaluate(() => window.dispatchEvent(new Event('online')))
  await timeline.getByRole('alert').waitFor()
  state.eventStage = 2
  await timeline.getByRole('button', { name: '重新加载时间线' }).click()
  await timeline.getByText('履约已完成', { exact: true }).waitFor()
  assert.equal(await timeline.locator('li').count(), 3)
  assert.deepEqual(state.reads.filter(row => row.operation === 'commerce.admin-orders.events.list').slice(-3)
    .map(row => row.input.after_sequence), ['1', '1', '2'])
  passed('durable timeline resumes the accepted cursor after a lost response and drains pages without duplicates')
  await page.screenshot({ path: output + '/order-detail-desktop.png', fullPage: true })
  await detail.locator('.modal-header').getByRole('button', { name: '关闭', exact: true }).click()
  assert.equal(await page.getByRole('button', { name: /标记付款|创建订单|取消订单/ }).count(), 0)
  passed('order filters, pagination and frozen purchase detail with exact currency and money')
  state.ordersStatus = 403
  await page.getByRole('button', { name: '刷新', exact: true }).click()
  await page.getByRole('alert').filter({ hasText: '没有此操作的权限' }).waitFor()
  assert.equal(await page.locator('table').count(), 0)
  assert.ok(!page.url().includes('sign-in'))
  await nav(page, '用户管理')
  await page.getByRole('link', { name: account.email, exact: true }).waitFor()
  state.accountsStatus = 403
  await page.getByRole('button', { name: '刷新', exact: true }).click()
  await page.getByRole('alert').filter({ hasText: '没有此操作的权限' }).waitFor()
  assert.equal(await page.locator('table').count(), 0)
  passed('resource permission withdrawal removes user and order rows while preserving login')
  await nav(page, '审计日志')
  await page.getByText('此功能暂未开放', { exact: true }).waitFor()
  passed('dormant pages stay explicit and do not call old HTTP routes')
  state.logoutFailure = true
  await page.getByRole('button', { name: '退出登录', exact: true }).click()
  await page.getByRole('heading', { name: '管理员登录' }).waitFor()
  await page.getByText(/服务端退出结果未确认/).waitFor()
  assert.deepEqual(await page.evaluate(() => Object.keys(localStorage).filter(key => /token|secure_path/i.test(key))), [])
  assert.deepEqual(state.pageErrors, [])
  assert.deepEqual(state.forbiddenRequests, [])
  passed('unknown logout clears local identity and reports missing server confirmation')
  await desktop.context.close()

  const mobile = await fixture({ width: 390, height: 844 })
  mobile.state.malformedCaptcha = true
  await mobile.page.goto(origin + '/admin/sign-in')
  await mobile.page.getByRole('alert').filter({ hasText: '验证码配置加载失败' }).waitFor()
  assert.equal(await mobile.page.getByRole('button', { name: '登录', exact: true }).isDisabled(), true)
  passed('malformed CAPTCHA configuration blocks login')
  await mobile.page.screenshot({ path: output + '/login-mobile.png', fullPage: true })
  mobile.state.malformedCaptcha = false
  await login(mobile.page, '/admin/server/manage')
  await mobile.page.getByText('Tokyo-A', { exact: true }).waitFor()
  assert.ok(await mobile.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  await mobile.page.screenshot({ path: output + '/nodes-mobile.png', fullPage: true })
  await mobile.page.getByRole('button', { name: '打开导航', exact: true }).click()
  await mobile.page.getByRole('dialog', { name: '管理导航' }).waitFor()
  await mobile.page.waitForFunction(() => {
    const panel = document.querySelector('.admin-sidebar.is-open')
    return panel && Math.abs(panel.getBoundingClientRect().left) < 1
  })
  await mobile.page.screenshot({ path: output + '/navigation-mobile.png', fullPage: true })
  await nav(mobile.page, '服务器管理')
  await mobile.page.getByText('Tokyo host', { exact: true }).waitFor()
  assert.equal(await mobile.page.locator('.admin-sidebar.is-open').count(), 0)
  await mobile.page.getByRole('button', { name: '打开导航', exact: true }).click()
  await nav(mobile.page, '节点管理')
  await mobile.page.getByText('Tokyo-A', { exact: true }).waitFor()
  passed('mobile layout, bounded table scroll and accessible navigation')
  await mobile.page.getByRole('button', { name: '打开导航', exact: true }).click()
  await nav(mobile.page, '用户管理')
  await mobile.page.getByRole('link', { name: account.email, exact: true }).waitFor()
  assert.ok(await mobile.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  await mobile.page.screenshot({ path: output + '/users-mobile.png', fullPage: true })
  await mobile.page.getByRole('link', { name: account.email, exact: true }).click()
  await mobile.page.getByText('当前没有可用订阅。', { exact: true }).waitFor()
  await mobile.page.getByRole('link', { name: '查看该用户订单', exact: true }).click()
  await mobile.page.getByRole('button', { name: 'ORDER-A', exact: true }).waitFor()
  assert.ok(await mobile.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  await mobile.page.screenshot({ path: output + '/orders-mobile.png', fullPage: true })
  const table = mobile.page.locator('.native-read-list .table-wrap')
  assert.ok(await table.evaluate(element => element.scrollWidth > element.clientWidth))
  await table.evaluate(element => { element.scrollLeft = element.scrollWidth })
  assert.ok(await table.evaluate(element => {
    const last = element.querySelector('tbody td:last-child').getBoundingClientRect()
    const bounds = element.getBoundingClientRect()
    return last.right <= bounds.right + 1 && last.left >= bounds.left
  }))
  await mobile.page.screenshot({ path: output + '/orders-mobile-scrolled.png', fullPage: true })
  await table.evaluate(element => { element.scrollLeft = 0 })
  passed('mobile resource tables scroll to complete fields without compressing exact values')
  await mobile.page.getByRole('button', { name: 'ORDER-A', exact: true }).click()
  await mobile.page.getByRole('dialog', { name: '订单详情', exact: true })
    .getByText('90071992547409.93 USD', { exact: true }).waitFor()
  assert.ok(await mobile.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  await mobile.page.screenshot({ path: output + '/order-detail-mobile.png', fullPage: true })
  await mobile.page.getByRole('dialog', { name: '订单详情', exact: true }).locator('.modal-header')
    .getByRole('button', { name: '关闭', exact: true }).click()
  assert.equal(mobile.state.operations.some(id => /admin-orders\.(create|cancel)|accounts\.(update|delete)/.test(id)), false)
  passed('mobile user, linked orders and readable bounded order dialog')
  await mobile.page.getByRole('button', { name: '打开导航', exact: true }).click()
  await nav(mobile.page, '节点管理')
  await mobile.page.getByText('Tokyo-A', { exact: true }).waitFor()
  mobile.state.nodesStatus = 401
  await mobile.page.getByRole('button', { name: '刷新', exact: true }).click()
  await mobile.page.getByRole('heading', { name: '管理员登录' }).waitFor()
  await mobile.page.reload()
  await mobile.page.getByRole('heading', { name: '管理员登录' }).waitFor()
  assert.deepEqual(mobile.state.pageErrors, [])
  assert.deepEqual(mobile.state.forbiddenRequests, [])
  passed('expired identity redirects and refresh does not restore a token')
  await mobile.context.close()
  const rotated = await fixture({ width: 1280, height: 800 })
  await login(rotated.page, '/rotated_admin/server/manage')
  await rotated.page.getByText('Tokyo-A', { exact: true }).waitFor()
  assert.ok(new URL(rotated.page.url()).pathname.startsWith('/rotated_admin/'))
  await rotated.page.reload()
  await rotated.page.getByRole('heading', { name: '管理员登录' }).waitFor()
  assert.ok(new URL(rotated.page.url()).pathname.startsWith('/rotated_admin/'))
  assert.deepEqual(rotated.state.pageErrors, [])
  await rotated.context.close()
  passed('one compiled bundle supports runtime admin mounts independently from its static asset namespace')
  await writeFile(output + '/result.json', JSON.stringify({ passed: true, checks,
    frontend: 'built same-repository management UI', transport: 'synthetic Gateway responses',
    realCoreAcceptance: 'separate Core Actions run; no combined production UI acceptance' }, null, 2) + '\n')
} finally {
  await browser.close()
  await new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve()))
}
