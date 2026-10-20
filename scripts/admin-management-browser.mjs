import assert from 'node:assert/strict'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { createServer } from 'node:http'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'
import { managementFixture } from './lib/admin-management-fixture.mjs'

const root = fileURLToPath(new URL('../', import.meta.url))
const output = path.join(root, 'artifacts/admin-management-browser')
const dist = path.join(root, 'web/admin/dist')
await mkdir(output, { recursive: true })
const server = createServer(async (request, response) => {
  try {
    const pathname = new URL(request.url, 'http://localhost').pathname
    const asset = pathname.match(/^\/(?:admin|\.txboard-admin)\/assets\/([A-Za-z0-9_.-]+)$/)
    if (asset) {
      const mime = { js: 'text/javascript', css: 'text/css', ttf: 'font/ttf', woff: 'font/woff', woff2: 'font/woff2', svg: 'image/svg+xml', png: 'image/png' }
      response.writeHead(200, { 'Content-Type': mime[path.extname(asset[1]).slice(1)] || 'application/octet-stream' })
      return response.end(await readFile(path.join(dist, 'assets', asset[1])))
    }
    if (/^\/(?:admin|\.txboard-admin)\/editor-assets\.json$/.test(pathname)) {
      response.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' })
      return response.end(await readFile(path.join(dist, 'editor-assets.json')))
    }
    if (/^\/admin(?:\/.*)?$/.test(pathname)) {
      response.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'private, no-store' })
      return response.end(await readFile(path.join(dist, 'index.html')))
    }
    response.writeHead(404); response.end()
  } catch { response.writeHead(404); response.end() }
})
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
const origin = 'http://127.0.0.1:' + server.address().port
const checks = [], fixtures = []
const routes = [
  ['/system/audit-log', '管理审计', 'audit.logs.list'],
  ['/system/modules', '模块清单', 'platform.module-cards.list'],
  ['/config/plugin', '插件管理', 'platform.module-cards.list'],
  ['/config/theme', '主题管理', 'platform.module-cards.list'],
  ['/system/queue', '队列监控', 'platform.queue.snapshot.get'],
  ['/config/system', '站点设置', 'settings.configuration.get'],
  ['/config/system/safe', '安全设置', 'settings.configuration.get'],
  ['/config/system/features', '功能设置', 'settings.configuration.get'],
  ['/config/system/subscribe', '订阅设置', 'settings.configuration.get'],
  ['/config/server', '节点设置', 'settings.configuration.get'],
  ['/config/email', '邮件设置', 'settings.configuration.get'],
  ['/config/telegram', 'Telegram 设置', 'settings.configuration.get'],
  ['/config/app', '客户端设置', 'settings.configuration.get'],
  ['/config/appearance', '后台外观', 'settings.configuration.get'],
  ['/config/system/subscribe-template', '订阅模板', 'subscription.admin-templates.list'],
  ['/config/email/templates', '邮件模板', 'notifications.mail-templates.get'],
  ['/config/payment', '支付配置', 'billing.admin-payment-methods.list'],
  ['/server/manage', '节点管理', 'network.nodes.list'],
  ['/server/node/9007199254740993', '节点详情', 'network.nodes.get'],
  ['/server/machine/9007199254740997', '服务器详情', 'network.machines.get'],
  ['/user/12', '用户详情', 'identity.accounts.get'],
  ['/user/traffic-reset', '订阅流量重置', null],
]
let browser
const passed = name => { checks.push(name); console.log('PASS ' + name) }
const writes = state => state.calls.filter(call => call.key && !call.operation.startsWith('identity.admin-session.'))
function verify(state) {
  assert.deepEqual(state.errors, []); assert.deepEqual(state.pageErrors, []); assert.deepEqual(state.forbiddenRequests, [])
}
async function open(fixture, pathname) {
  const { page, state } = fixture
  const [, title, operation] = routes.find(row => row[0] === pathname)
  await page.goto(origin + '/admin' + pathname)
  // Administrator credentials are memory-only, so hard route loads must log in again.
  await page.getByRole('heading', { name: '管理员登录', exact: true }).waitFor()
  await page.getByRole('textbox', { name: '邮箱', exact: true }).fill('admin@example.test')
  await page.getByLabel('密码', { exact: true }).fill('admin-password')
  const loaded = operation ? page.waitForResponse(response => response.url().endsWith('/operations/' + operation) && response.status() === 200) : Promise.resolve()
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.waitForURL(url => url.pathname === '/admin' + pathname)
  await page.locator('.native-management-page .page-header').getByRole('heading', { name: title, exact: true }).waitFor()
  await loaded
  await page.waitForFunction(() => document.querySelectorAll('.native-management-page .query-feedback[role="status"]').length === 0)
  verify(state)
}
async function screenshot(page, name) { await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true }) }
try {
  browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined })
  const desktop = await managementFixture(browser, { width: 1440, height: 1000 })
  fixtures.push(desktop)
  const { page, state } = desktop
  page.on('dialog', dialog => dialog.accept())
  for (const [pathname] of routes) await open(desktop, pathname)
  passed('all management paths load built native pages through generated Core contracts')

  await open(desktop, '/system/audit-log')
  await page.getByLabel('管理员 ID', { exact: true }).fill('9007199254740993')
  const auditResponse = page.waitForResponse(response => response.url().endsWith('/operations/audit.logs.list') && response.request().postDataJSON().input.admin_id === '9007199254740993')
  await page.getByRole('button', { name: '查询', exact: true }).click(); await auditResponse
  assert.equal(state.calls.filter(call => call.operation === 'audit.logs.list').at(-1).input.admin_id, '9007199254740993')
  await open(desktop, '/system/modules')
  await page.locator('.module-card').filter({ hasText: 'Observed plugin' }).getByRole('button', { name: '文档', exact: true }).click()
  await page.getByRole('dialog').getByText('Declared native module documentation.', { exact: false }).waitFor()
  await page.getByRole('dialog').getByRole('button', { name: '关闭', exact: true }).last().click()
  const beforeModules = writes(state).length
  state.denied.add('platform.module-')
  await page.locator('.native-management-page .page-header').getByRole('button', { name: '刷新', exact: true }).click()
  await page.getByText('无法确认模块权限，已隐藏清单、详情和配置草稿。可重新读取权限后恢复原请求。', { exact: true }).waitFor()
  assert.equal(await page.locator('.module-card').count(), 0)
  assert.equal(writes(state).length, beforeModules); state.denied.clear()
  passed('audit filters preserve large IDs and module permission revocation removes cards and dialogs without lifecycle writes')

  await open(desktop, '/config/system')
  await page.getByRole('button', { name: '开始编辑', exact: true }).click()
  const beforeSettings = writes(state).length
  await page.locator('#configuration-app_name').fill('Reviewed site')
  assert.equal(writes(state).length, beforeSettings)
  await page.getByRole('button', { name: '保存当前分类', exact: true }).click()
  await page.getByText('设置已保存', { exact: true }).waitFor()
  const save = state.calls.find(call => call.operation === 'settings.configuration.update')
  assert.deepEqual(save.input, { expected_version: state.originalSettingsVersion, values: { app_name: 'Reviewed site' } })
  await page.getByRole('button', { name: '开始编辑', exact: true }).click()
  await page.locator('#configuration-app_name').fill('Unsaved private draft')
  state.denied.add('settings.configuration.')
  await page.getByRole('button', { name: '刷新设置', exact: true }).click()
  await page.getByText('当前无法确认设置访问权限，已清空表单并隐藏数据', { exact: true }).waitFor()
  assert.equal(await page.locator('#configuration-app_name').count(), 0)
  assert.ok(!(await page.locator('.native-management-page').innerText()).includes('Unsaved private draft'))
  state.denied.clear()
  passed('settings save only changed fields with the observed CAS version and revoke cached drafts after 403')

  await open(desktop, '/config/system/safe')
  await page.getByRole('button', { name: '开始编辑', exact: true }).click()
  assert.equal(await page.locator('#configuration-captcha_type').count(), 0)
  await page.locator('.ui-switch').filter({ has: page.locator('#configuration-captcha_enable') }).click()
  assert.equal(await page.locator('#configuration-captcha_enable').isChecked(), true)
  for (const [provider, secret] of [['recaptcha', 'recaptcha_key'], ['recaptcha-v3', 'recaptcha_v3_secret_key'], ['turnstile', 'turnstile_secret_key']]) {
    await page.locator('#configuration-captcha_type').selectOption(provider)
    assert.equal(await page.locator('#configuration-' + secret).inputValue(), '')
    assert.equal(await page.locator('#configuration-' + secret).getAttribute('type'), 'password')
    assert.equal(await page.locator('#configuration-' + (secret === 'recaptcha_key' ? 'turnstile_secret_key' : 'recaptcha_key')).count(), 0)
  }
  await page.locator('#configuration-turnstile_site_key').fill('synthetic-public-site-key')
  await page.locator('#configuration-turnstile_secret_key').fill('synthetic-new-secret')
  const observed = state.settings.version
  await page.getByRole('button', { name: '保存当前分类', exact: true }).click()
  await page.getByText('设置已保存', { exact: true }).waitFor()
  const secretSave = state.calls.filter(call => call.operation === 'settings.configuration.update').at(-1)
  assert.deepEqual(secretSave.input, { expected_version: observed, values: { captcha_enable: true, captcha_type: 'turnstile', turnstile_site_key: 'synthetic-public-site-key', turnstile_secret_key: 'synthetic-new-secret' } })
  await page.getByRole('button', { name: '开始编辑', exact: true }).click()
  assert.equal(await page.locator('#configuration-turnstile_secret_key').inputValue(), '')
  assert.ok(!(await page.content()).includes('synthetic-new-secret'))
  await screenshot(page, 'security-desktop')
  passed('configured credentials are password blanks and new secrets are write-only dirty patches')

  await open(desktop, '/config/appearance')
  const appearanceVersion = state.settings.version
  await page.getByLabel('侧栏表面风格', { exact: true }).selectOption('contrast')
  await page.getByLabel('强调色', { exact: true }).selectOption('green')
  await page.getByRole('button', { name: '保存外观', exact: true }).click()
  await page.getByText('后台外观已保存', { exact: true }).waitFor()
  const appearanceSave = state.calls.filter(call => call.operation === 'settings.configuration.update').at(-1)
  assert.deepEqual(appearanceSave.input, { expected_version: appearanceVersion, values: { admin_sidebar_style: 'contrast', admin_color_scheme: 'green' } })
  passed('persistent appearance saves only changed fields using the observed settings version')

  await page.getByRole('combobox', { name: '语言', exact: true }).selectOption('en-US')
  await page.waitForFunction(() => document.documentElement.lang === 'en-US')
  assert.ok(!(await page.locator('.page-header').innerText()).includes('后台外观'))
  await page.getByRole('combobox').filter({ has: page.locator('option[value="ru-RU"]') }).selectOption('ru-RU')
  await page.waitForFunction(() => document.documentElement.lang === 'ru-RU')
  assert.ok(!(await page.locator('.page-header').innerText()).includes('后台外观'))
  await page.getByRole('combobox').filter({ has: page.locator('option[value="zh-CN"]') }).selectOption('zh-CN')
  await page.getByRole('heading', { name: '后台外观', exact: true }).waitFor()
  passed('language switching translates the active page and shell without reloading or losing the selected settings')

  await open(desktop, '/config/payment')
  await page.getByRole('button', { name: '配置支付方式', exact: true }).click()
  await page.getByLabel(/^Merchant ID\s*已配置$/).waitFor()
  assert.equal(await page.getByLabel(/^Merchant ID\s*已配置$/).inputValue(), '')
  assert.equal(await page.getByLabel(/^Merchant key\s*已配置$/).inputValue(), '')
  const beforePayment = state.calls.filter(call => call.operation === 'billing.admin-payment-methods.save').length
  await page.getByLabel('名称', { exact: true }).fill('Reviewed payment')
  assert.equal(state.calls.filter(call => call.operation === 'billing.admin-payment-methods.save').length, beforePayment)
  await page.getByRole('button', { name: '保存支付配置', exact: true }).click()
  await page.getByRole('row').filter({ hasText: 'Reviewed payment' }).waitFor()
  const paymentSave = state.calls.find(call => call.operation === 'billing.admin-payment-methods.save').input
  assert.equal(paymentSave.payment_id, state.payment.id); assert.equal(paymentSave.expected_version, state.originalPaymentVersion)
  assert.deepEqual(paymentSave.configuration, [])
  await screenshot(page, 'payment-desktop')
  passed('merchant values remain blank and payment metadata saves only on explicit submission with its observed version')

  await open(desktop, '/server/node/9007199254740993')
  assert.deepEqual(state.calls.filter(call => call.operation === 'network.nodes.get').at(-1).input, { node_id: '9007199254740993' })
  await page.getByRole('heading', { name: 'Native node', exact: true }).waitFor()
  const traffic = page.getByRole('region', { name: '节点流量', exact: true })
  await traffic.getByRole('group', { name: '节点总流量交互图表', exact: true }).waitFor()
  const trafficResponse = page.waitForResponse(response => response.url().endsWith('/operations/network.nodes.traffic-history.get')
    && response.request().postDataJSON().input.days === 7)
  await traffic.getByRole('button', { name: '7 天', exact: true }).click()
  await trafficResponse
  assert.ok(state.calls.some(call => call.operation === 'network.nodes.traffic-history.get'
    && call.input.days === 7 && JSON.stringify(call.input.node_ids) === JSON.stringify([state.node.id])))
  passed('node detail loads its own daily traffic history and switches the range')
  state.denied.add('network.nodes.get')
  await page.getByRole('button', { name: '刷新节点', exact: true }).click()
  await page.getByText('当前账号没有此操作的权限，已隐藏资源数据。', { exact: true }).waitFor()
  assert.equal(await page.locator('.native-management-page').getByRole('heading', { name: 'Native node', exact: true }).count(), 0)
  assert.equal(await page.getByRole('button', { name: '删除节点', exact: true }).count(), 0); state.denied.clear()
  await open(desktop, '/server/manage')
  await page.getByRole('row').filter({ hasText: 'Native node' }).getByRole('checkbox').check()
  await page.getByRole('button', { name: '批量启用', exact: true }).click()
  await page.waitForFunction(() => !document.querySelector('.ui-bulk-bar'))
  const batch = state.calls.filter(call => call.operation === 'network.nodes.batch-update').at(-1)
  assert.deepEqual(batch.input, { ids: [state.node.id], enabled: true })
  passed('current-page node selection sends one bounded batch update and clears the selection')
  await page.getByRole('row').filter({ hasText: 'Native node' }).getByRole('checkbox').check()
  await page.getByRole('button', { name: '绑定服务器', exact: true }).click()
  const bindingDialog = page.getByRole('dialog', { name: '批量绑定服务器', exact: true })
  await bindingDialog.getByLabel('服务器', { exact: true }).selectOption(state.machine.id)
  await bindingDialog.getByRole('button', { name: '保存服务器归属', exact: true }).click()
  await page.waitForFunction(() => !document.querySelector('.ui-bulk-bar'))
  const binding = state.calls.filter(call => call.operation === 'network.nodes.batch-update').at(-1)
  assert.deepEqual(binding.input, { ids: [state.node.id], machine_id: state.machine.id })
  passed('batch machine binding uses a selector and sends the chosen machine ID')
  await open(desktop, '/user/12')
  const lifecycle = page.getByRole('region', { name: '订阅生命周期', exact: true })
  await lifecycle.getByText('生效中', { exact: true }).first().waitFor()
  await lifecycle.getByRole('button', { name: '暂停订阅', exact: true }).click()
  await page.getByRole('dialog', { name: '暂停订阅', exact: true }).getByLabel('暂停原因（可选）', { exact: true }).fill('review')
  await page.getByRole('dialog', { name: '暂停订阅', exact: true }).getByRole('button', { name: '确认暂停', exact: true }).click()
  await lifecycle.getByText('已暂停', { exact: true }).first().waitFor()
  const suspend = state.calls.filter(call => call.operation === 'subscription.lifecycle.suspend').at(-1)
  assert.deepEqual(suspend.input, { subscription_id: state.subscription.subscription_id,
    expected_version: state.originalSubscriptionVersion, reason: 'review' })
  await lifecycle.getByRole('button', { name: '恢复订阅', exact: true }).click()
  await page.getByRole('dialog', { name: '恢复订阅', exact: true }).getByRole('button', { name: '恢复订阅', exact: true }).click()
  await lifecycle.getByText('生效中', { exact: true }).first().waitFor()
  const resume = state.calls.filter(call => call.operation === 'subscription.lifecycle.resume').at(-1)
  assert.deepEqual(resume.input, { subscription_id: state.subscription.subscription_id,
    expected_version: String(BigInt(state.originalSubscriptionVersion) + 1n) })
  passed('subscription lifecycle suspends with a reason and resumes with the observed next version')
  await page.getByRole('region', { name: '最近订单', exact: true }).getByRole('link', { name: 'USER-ORDER-A', exact: true }).waitFor()
  await page.getByRole('button', { name: '封禁用户', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '封禁用户', exact: true }).click()
  await page.getByRole('button', { name: '解除封禁', exact: true }).waitFor()
  const status = state.calls.filter(call => call.operation === 'identity.accounts.status.update').at(-1)
  assert.deepEqual(status.input, { user_id: '12', expected_banned: false, banned: true })
  passed('user status changes only after confirmation and uses the observed ban state')
  await open(desktop, '/server/machine/9007199254740997')
  assert.deepEqual(state.calls.filter(call => call.operation === 'network.machines.get').at(-1).input, { machine_id: '9007199254740997' })
  await page.getByRole('link', { name: /Native node/ }).waitFor()
  assert.equal(await page.getByLabel('备注', { exact: true }).inputValue(), 'Machine metadata only')
  passed('node and machine detail use exact direct-read IDs and node revocation removes cached detail and commands')

  await open(desktop, '/user/traffic-reset')
  await page.getByLabel('用户 ID', { exact: true }).fill('12')
  await page.getByRole('button', { name: '读取当前订阅', exact: true }).click()
  await page.locator('.native-management-page').getByRole('heading', { name: 'subscription@example.test', exact: true }).waitFor()
  const beforeResetVersion = state.subscription.subscription_version
  const afterResetVersion = String(BigInt(beforeResetVersion) + 1n)
  await page.getByRole('button', { name: '重置订阅流量周期', exact: true }).click()
  await page.getByText('查看订阅标识与原始用量', { exact: true }).click()
  await page.getByText(afterResetVersion, { exact: true }).waitFor()
  const reset = state.calls.find(call => call.operation === 'subscription.lifecycle.reset').input
  assert.deepEqual(reset, { subscription_id: state.subscription.subscription_id, expected_version: beforeResetVersion })
  passed('subscription reset confirms the native account projection and submits the observed full-precision subscription version')
  verify(state)

  const mobile = await managementFixture(browser, { width: 390, height: 844 })
  fixtures.push(mobile)
  for (const [pathname, title] of routes) {
    await open(mobile, pathname)
    if (pathname === '/config/system' || pathname === '/config/system/safe') {
      await mobile.page.getByRole('button', { name: '开始编辑', exact: true }).click()
      await mobile.page.getByRole('button', { name: '保存当前分类', exact: true }).waitFor()
    } else if (pathname === '/config/payment') {
      await mobile.page.getByRole('button', { name: '配置支付方式', exact: true }).click()
      await mobile.page.getByLabel(/^Merchant key\s*已配置$/).waitFor()
    } else if (pathname === '/config/email/templates') {
      await mobile.page.getByRole('button', { name: '编辑源码', exact: true }).click()
      await mobile.page.getByLabel('模板源码', { exact: true }).waitFor()
    } else if (pathname === '/user/traffic-reset') {
      await mobile.page.getByLabel('用户 ID', { exact: true }).fill('12')
      await mobile.page.getByRole('button', { name: '读取当前订阅', exact: true }).click()
      await mobile.page.locator('.native-management-page').getByRole('heading', { name: 'subscription@example.test', exact: true }).waitFor()
    }
    assert.ok(await mobile.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), title + ' must fit mobile viewport')
    assert.ok(await mobile.page.locator('.native-management-page h2').evaluateAll(headings =>
      headings.every(heading => heading.scrollWidth <= heading.clientWidth + 1)), title + ' must show complete resource headings')
    assert.equal(await mobile.page.locator('.admin-sidebar.is-open').count(), 0)
    await screenshot(mobile.page, pathname.replace(/^\//, '').replaceAll('/', '-') + '-mobile')
  }
  verify(mobile.state)
  passed('all management paths retain bounded mobile layouts')
  await writeFile(path.join(output, 'result.json'), JSON.stringify({ passed: true, checks,
    frontend: 'built main-repository admin', transport: 'strict synthetic Core browser-ingress fixtures',
    schema: 'current generated Core input/output/meta schemas',
    realBackendAcceptance: 'separate API, MySQL, and actual Core image Actions; these synthetic fixtures do not claim live Core UI integration' }, null, 2) + '\n')
} catch (error) {
  const pages = []
  for (const [index, fixture] of fixtures.entries()) {
    const diagnostics = await Promise.allSettled([
      fixture.page.screenshot({ path: path.join(output, 'failure-' + index + '.png'), fullPage: true, timeout: 5000 }),
    ])
    pages.push({ url: fixture.page.url(), diagnostics: diagnostics.map(result => result.status) })
  }
  await writeFile(path.join(output, 'failure.json'), JSON.stringify({ passed: false, error: error.stack ?? String(error), checks, pages,
    fixtures: fixtures.map(fixture => ({ errors: fixture.state.errors, pageErrors: fixture.state.pageErrors,
      operations: fixture.state.calls.map(({ operation }) => operation) })) }, null, 2) + '\n')
  throw error
} finally {
  for (const fixture of fixtures) await fixture.context.close()
  if (browser) await browser.close()
  await new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve()))
}
