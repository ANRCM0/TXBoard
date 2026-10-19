import assert from 'node:assert/strict'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { createServer } from 'node:http'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'
import { attachmentBytes, attachmentId, markup, workflowFixture } from './lib/admin-workflows-fixture.mjs'

const root = fileURLToPath(new URL('../', import.meta.url))
const output = path.join(root, 'artifacts/admin-workflows-browser')
await mkdir(output, { recursive: true })
const dist = path.join(root, 'web/admin/dist')
const server = createServer(async (request, response) => {
  try {
    const pathname = new URL(request.url, 'http://localhost').pathname
    const asset = pathname.match(/^\/(?:admin|\.txboard-admin)\/assets\/([A-Za-z0-9_.-]+)$/)
    if (asset) {
      response.writeHead(200, { 'Content-Type': asset[1].endsWith('.css') ? 'text/css' : 'text/javascript' })
      return response.end(await readFile(path.join(dist, 'assets', asset[1])))
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
let browser
const routes = [
  ['工单管理', '/user/ticket', '工单管理'],
  ['公告管理', '/config/notice', '公告管理'],
  ['知识库管理', '/config/knowledge', '知识库管理'],
  ['优惠券', '/finance/coupon', '优惠券管理'],
  ['礼品卡', '/finance/gift-card', '礼品卡管理'],
  ['佣金管理', '/finance/commission', '邀请与佣金'],
]
function passed(name) { checks.push(name); console.log('PASS ' + name) }
async function login(page) {
  await page.goto(origin + '/admin/user/ticket')
  await page.getByRole('heading', { name: '管理员登录', exact: true }).waitFor()
  await page.getByRole('textbox', { name: '邮箱', exact: true }).fill('admin@example.test')
  await page.getByLabel('密码', { exact: true }).fill('admin-password')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByRole('heading', { name: '工单管理', exact: true }).waitFor()
}
async function navigate(page, name, mobile = false) {
  if (mobile) await page.getByRole('button', { name: '打开导航', exact: true }).click()
  const link = page.getByRole('link', { name, exact: true })
  await link.waitFor()
  assert.doesNotMatch(await link.innerText(), /待开放/)
  await link.click()
  const [, pathname, heading] = routes.find(row => row[0] === name)
  await page.waitForURL(url => url.pathname === '/admin' + pathname)
  await page.getByRole('heading', { name: heading, exact: true }).waitFor()
  assert.equal(await page.getByText('此功能暂未开放', { exact: true }).count(), 0)
}
function validateFixture(state) {
  assert.deepEqual(state.errors, [], 'Strict Core browser-ingress fixtures must not fail schema or operation assertions')
  assert.deepEqual(state.pageErrors, [])
  assert.deepEqual(state.forbiddenRequests, [])
}
try {
  browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined })
  const desktop = await workflowFixture(browser, { width: 1440, height: 1000 })
  fixtures.push(desktop)
  const { page, state } = desktop
  await login(page)
  // Desktop starts with a compact rail; expand before matching navigation labels.
  const expand = page.getByRole('button', { name: '展开侧边栏', exact: true })
  if (await expand.count()) await expand.click()
  for (const [name] of routes) await navigate(page, name)
  passed('all six desktop routes navigate to active management pages')

  await navigate(page, '优惠券')
  const couponRow = page.getByRole('row').filter({ hasText: 'Recovery coupon' })
  await couponRow.getByRole('button', { name: '停用', exact: true }).click()
  await page.getByRole('button', { name: '使用原操作确认结果', exact: true }).waitFor()
  const first = state.calls.filter(call => call.operation === 'billing.coupons.disable')
  assert.equal(first.length, 1)
  await navigate(page, '知识库管理')
  await navigate(page, '优惠券')
  await page.getByRole('button', { name: '使用原操作确认结果', exact: true }).click()
  await page.getByRole('button', { name: '使用原操作确认结果', exact: true }).waitFor({ state: 'detached' })
  await couponRow.getByText('已停用', { exact: true }).waitFor()
  const repeated = state.calls.filter(call => call.operation === 'billing.coupons.disable')
  assert.equal(repeated.length, 2)
  assert.equal(repeated[0].key, repeated[1].key)
  assert.deepEqual(repeated[0].input, repeated[1].input)
  assert.equal(repeated[1].input.expected_version, '11')
  assert.equal(state.couponEffects, 1)
  passed('coupon unknown outcome survives navigation and recovers original key, input and version exactly once')

  await navigate(page, '工单管理')
  await page.getByRole('row').filter({ hasText: 'Private connection issue' }).getByRole('button', { name: '查看', exact: true }).click()
  await page.getByRole('button', { name: '认领给我', exact: true }).click()
  await page.getByRole('button', { name: '释放我的工单', exact: true }).waitFor()
  assert.equal(state.ticket.row_version, '8')
  await page.getByLabel('选择附件', { exact: true }).setInputFiles({ name: 'private-note.txt', mimeType: 'text/plain', buffer: attachmentBytes })
  await page.getByText('private-note.txt · 待随回复发送', { exact: true }).waitFor()
  await page.getByLabel('回复（最多 10 KB）', { exact: true }).fill('Verified response')
  await page.getByRole('button', { name: '发送回复', exact: true }).click()
  await page.locator('.native-ticket-messages').getByText('Verified response', { exact: true }).waitFor()
  await page.getByRole('list', { name: '已发送附件', exact: true }).getByText('private-note.txt · ' + attachmentBytes.length + ' 字节', { exact: true }).waitFor()
  assert.equal(state.attachment.status, 'attached')
  assert.deepEqual(state.calls.filter(call => call.operation === 'support.admin-tickets.reply').map(call => call.input.attachment_ids), [[attachmentId]])
  assert.deepEqual(state.calls.filter(call => /attachments\.(begin|append|finish)$/.test(call.operation)).map(call => call.operation),
    ['support.admin-tickets.attachments.begin', 'support.admin-tickets.attachments.append', 'support.admin-tickets.attachments.finish'])
  await page.screenshot({ path: path.join(output, 'ticket-desktop.png'), fullPage: true })
  passed('ticket claim uses current version and chunked private attachment binds to a versioned reply')

  for (const name of ['公告管理', '知识库管理']) {
    await navigate(page, name)
    await page.getByRole('row').filter({ hasText: markup }).getByRole('button', { name: '编辑', exact: true }).click()
    const editor = page.getByLabel('正文', { exact: true })
    await editor.waitFor()
    await page.waitForFunction(expected => document.querySelector('textarea')?.value === expected, markup)
    assert.equal(await editor.inputValue(), markup)
    assert.equal(await page.evaluate(() => window.fixtureInjected === true), false)
    assert.equal(await page.locator('.native-management-page img[src="x"], .native-management-page script').count(), 0)
  }
  passed('notice and knowledge hostile markup remains inert text in rows and content editors')

  await navigate(page, '礼品卡')
  await page.getByRole('button', { name: '查看逐卡状态', exact: true }).click()
  const batchTable = page.getByRole('region', { name: '批次内卡片', exact: true }).getByRole('table')
  await batchTable.getByText('待兑换', { exact: true }).waitFor()
  assert.match(await batchTable.innerText(), /9007199254740993 分 CNY/)
  Object.assign(state.gift, { state: 'redeemed', recipient_id: '31', row_version: '6', closed_at: 1792368001 })
  await page.getByRole('button', { name: '刷新', exact: true }).click()
  await batchTable.getByText('已兑换', { exact: true }).waitFor()
  assert.equal(await batchTable.getByRole('button', { name: '撤销并退回发行人', exact: true }).isDisabled(), true)
  state.giftDenied = true
  await page.getByRole('button', { name: '刷新', exact: true }).click()
  await page.getByRole('alert').filter({ hasText: '无法确认礼品卡管理权限，已隐藏数据。' }).waitFor()
  assert.equal(await page.locator('.native-management-page table').count(), 0)
  assert.equal(await page.getByText('Current batch', { exact: true }).count(), 0)
  assert.equal(await page.getByText(state.gift.card_id, { exact: true }).count(), 0)
  assert.ok(!page.url().includes('sign-in'))
  passed('gift batches show current per-card state and permission loss clears cached cards and details')

  await navigate(page, '佣金管理')
  await page.getByRole('button', { name: '批准转入钱包', exact: true }).click()
  await page.getByRole('row').filter({ hasText: state.payout.id }).getByText('已转入钱包', { exact: true }).waitFor()
  assert.equal(state.calls.filter(call => call.operation === 'billing.admin-commissions.payouts.approve').length, 1)
  assert.equal(await page.getByRole('button', { name: '批准转入钱包', exact: true }).count(), 0)
  passed('commission approval submits current payout version and removes further decision actions')
  validateFixture(state)

  const mobile = await workflowFixture(browser, { width: 390, height: 844 })
  fixtures.push(mobile)
  await login(mobile.page)
  for (const [name] of routes) {
    await navigate(mobile.page, name, true)
    assert.equal(await mobile.page.locator('.admin-sidebar.is-open').count(), 0)
    const table = mobile.page.locator('.native-management-page table').first()
    await table.waitFor()
    assert.ok(await mobile.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), name + ' must not overflow viewport')
    await mobile.page.screenshot({ path: path.join(output, name + '-mobile.png'), fullPage: true })
  }
  validateFixture(mobile.state)
  passed('all six mobile routes expose active navigation and bounded management content')
  await writeFile(path.join(output, 'result.json'), JSON.stringify({ passed: true, checks,
    frontend: 'built admin in the main TXBoard repository', transport: 'strict synthetic Core browser-ingress operation fixtures',
    schema: 'current generated Core SDK input/output/meta schemas validated for every operation',
    realCoreAcceptance: 'separate backend Actions evidence; this script does not claim live Core or production acceptance',
    userFrontend: 'deferred', operations: [...new Set(fixtures.flatMap(fixture => fixture.state.calls.map(call => call.operation)))] }, null, 2) + '\n')
} catch (error) {
  const diagnostics = []
  for (const [index, fixture] of fixtures.entries()) {
    const diagnostic = { fixture: index, url: fixture.page.url(), files: [], errors: [] }
    if (!fixture.page.isClosed()) {
      // Keep the failing DOM and rendered state together; a timeout alone cannot
      // distinguish an absent editor from a user-content-dependent label.
      const captures = await Promise.allSettled([
        fixture.page.content().then(html => writeFile(path.join(output, `failure-${index}.html`), html)),
        fixture.page.screenshot({ path: path.join(output, `failure-${index}.png`), fullPage: true, timeout: 5000 }),
        fixture.page.locator('label').allTextContents().then(labels => { diagnostic.labels = labels }),
      ])
      for (const [capture, result] of captures.entries()) {
        if (result.status === 'rejected') diagnostic.errors.push(String(result.reason))
        else if (capture < 2) diagnostic.files.push(`failure-${index}.${capture === 0 ? 'html' : 'png'}`)
      }
    }
    diagnostics.push(diagnostic)
  }
  await writeFile(path.join(output, 'failure.json'), JSON.stringify({ passed: false, message: error.stack ?? String(error), checks,
    diagnostics,
    fixtures: fixtures.map(fixture => ({ errors: fixture.state.errors, pageErrors: fixture.state.pageErrors,
      calls: fixture.state.calls.map(({ operation, input }) => ({ operation, input })) })) }, null, 2) + '\n')
  throw error
} finally {
  for (const fixture of fixtures) await fixture.context.close()
  if (browser) await browser.close()
  await new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve()))
}
