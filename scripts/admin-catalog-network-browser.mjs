import assert from 'node:assert/strict'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { createServer } from 'node:http'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'
import { catalogNetworkFixture } from './lib/admin-catalog-network-fixture.mjs'

const root = fileURLToPath(new URL('../', import.meta.url))
const output = path.join(root, 'artifacts/admin-catalog-network-browser')
const dist = path.join(root, 'web/admin/dist')
await mkdir(output, { recursive: true })
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
const routes = [
  ['套餐管理', '/finance/plan', '套餐与发布'],
  ['权限组管理', '/server/group', '分组管理'],
  ['路由管理', '/server/route', '路由管理'],
  ['访问审计', '/server/access-audit', '访问审计'],
]
let browser
function passed(name) { checks.push(name); console.log('PASS ' + name) }
async function login(page) {
  await page.goto(origin + '/admin/finance/plan')
  await page.getByRole('heading', { name: '管理员登录', exact: true }).waitFor()
  await page.getByRole('textbox', { name: '邮箱', exact: true }).fill('admin@example.test')
  await page.getByLabel('密码', { exact: true }).fill('admin-password')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.locator('.native-management-page .page-header').getByRole('heading', { name: '套餐与发布', exact: true }).waitFor()
}
async function navigate(page, name, mobile = false) {
  if (mobile) await page.getByRole('button', { name: '打开导航', exact: true }).click()
  const link = page.getByRole('link', { name, exact: true })
  await link.waitFor(); assert.doesNotMatch(await link.innerText(), /待开放/); await link.click()
  const [, pathname, heading] = routes.find(row => row[0] === name)
  await page.waitForURL(url => url.pathname === '/admin' + pathname)
  await page.locator('.native-management-page .page-header').getByRole('heading', { name: heading, exact: true }).waitFor()
}
function verifyFixture(state) {
  assert.deepEqual(state.errors, [])
  assert.deepEqual(state.pageErrors, [])
  assert.deepEqual(state.forbiddenRequests, [])
}
try {
  browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined })
  const desktop = await catalogNetworkFixture(browser, { width: 1440, height: 1000 })
  fixtures.push(desktop)
  const { page, state } = desktop
  await login(page)
  const expand = page.getByRole('button', { name: '展开侧边栏', exact: true })
  if (await expand.count()) await expand.click()
  for (const [name] of routes) await navigate(page, name)
  passed('four current catalog and network routes expose active desktop navigation')

  await navigate(page, '权限组管理')
  await page.getByRole('row').filter({ hasText: 'Native group' }).getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('分组名称', { exact: true }).fill('Reviewed group')
  await page.getByRole('button', { name: '保存分组', exact: true }).click()
  await page.getByRole('button', { name: '确认原分组操作', exact: true }).waitFor()
  await navigate(page, '套餐管理')
  await navigate(page, '权限组管理')
  await page.getByRole('button', { name: '确认原分组操作', exact: true }).click()
  await page.getByRole('button', { name: '确认原分组操作', exact: true }).waitFor({ state: 'detached' })
  const repeated = state.calls.filter(call => call.operation === 'network.groups.save')
  assert.equal(repeated.length, 2); assert.equal(repeated[0].key, repeated[1].key)
  assert.deepEqual(repeated[0].input, repeated[1].input); assert.equal(state.groupEffects, 1)
  await page.getByRole('row').filter({ hasText: 'Reviewed group' }).waitFor()
  passed('unknown group update survives navigation and recovers its original input and key exactly once')

  await navigate(page, '路由管理')
  await page.getByRole('button', { name: '编辑完整路由排序', exact: true }).click()
  const sorting = page.locator('.native-management-page section.list-card').filter({ has: page.getByRole('heading', { name: '完整路由排序', exact: true }) })
  await sorting.getByRole('button', { name: '下移', exact: true }).first().click()
  await page.getByRole('button', { name: '保存完整路由排序', exact: true }).click()
  await page.getByRole('button', { name: '保存完整路由排序', exact: true }).waitFor({ state: 'detached' })
  const sort = state.calls.find(call => call.operation === 'network.routes.sort')
  assert.deepEqual(sort.input.items.map(row => row.id), [state.routes[1].id, state.routes[0].id])
  assert.equal(sort.input.items.length, state.routes.length)
  const writesBeforeSimulation = state.calls.filter(call => call.key).length
  await page.getByRole('combobox', { name: /^已读取节点/ }).selectOption(state.node.id)
  await page.getByLabel('目标', { exact: true }).fill('example.test')
  await page.getByRole('button', { name: '运行只读模拟', exact: true }).click()
  await page.getByText(/请求目标 example.test/).waitFor()
  await page.getByText(/非权威参考结果/).waitFor()
  assert.equal(state.calls.filter(call => call.key).length, writesBeforeSimulation)
  await page.getByRole('list', { name: '未解析路由规则', exact: true }).getByText('geoip:fixture', { exact: true }).waitFor()
  await page.getByLabel('目标', { exact: true }).fill('different.test')
  await page.getByText(/请求目标 example.test/).waitFor({ state: 'detached' })
  await page.screenshot({ path: path.join(output, 'routes-desktop.png'), fullPage: true })
  passed('complete route ordering retains exact IDs and read-only simulation binds to the selected node and target')

  await navigate(page, '访问审计')
  await page.getByRole('row').filter({ hasText: 'Observed domains' }).getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('审计规则匹配内容', { exact: true }).fill('reviewed.test')
  await page.getByRole('button', { name: '保存审计规则', exact: true }).click()
  await page.getByRole('row').filter({ hasText: 'reviewed.test' }).waitFor()
  await page.getByLabel('节点 ID', { exact: true }).fill(state.event.server_id)
  await page.getByLabel('用户 ID', { exact: true }).fill(state.event.user_id)
  await page.getByRole('combobox', { name: /^规则命中/ }).selectOption('yes')
  await page.getByLabel('目标关键字', { exact: true }).fill('example.test')
  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/operations/network.access-audit.events.list')
      && response.request().postDataJSON().input.keyword === 'example.test' && response.status() === 200),
    page.getByRole('button', { name: '筛选审计事件', exact: true }).click(),
  ])
  const filter = state.calls.filter(call => call.operation === 'network.access-audit.events.list').at(-1).input
  assert.deepEqual(filter, { page: 1, per_page: 20, server_id: state.event.server_id, user_id: state.event.user_id, matched: true, keyword: 'example.test' })
  passed('audit rule editing and event filters use the controlled exact-ID contracts')

  await navigate(page, '套餐管理')
  await page.getByRole('button', { name: '编辑当前套餐', exact: true }).click()
  await page.locator('#catalog-name').waitFor()
  assert.equal(await page.locator('#catalog-content').inputValue(), state.originalRevision.terms.content)
  assert.equal(await page.locator('#catalog-price-monthly').inputValue(), '9007199254740993')
  assert.equal(await page.locator('#catalog-quota').inputValue(), '7')
  await page.locator('#catalog-name').fill('Revised authoritative plan')
  await page.locator('#catalog-price-monthly').fill('777')
  await page.locator('#catalog-capacity-choice').selectOption('limited')
  await page.locator('#catalog-capacity').fill('9')
  await page.getByRole('button', { name: '发布完整条款', exact: true }).click()
  await page.locator('section[aria-label="已发布套餐"]').getByRole('row').filter({ hasText: 'Revised authoritative plan' }).waitFor()
  const published = state.calls.find(call => call.operation === 'catalog.plans.publish').input
  assert.equal(published.plan_id, state.planId); assert.equal(published.expected_revision_id, state.originalRevision.revision_id)
  assert.equal(published.capacity_limit, 9); assert.equal(state.originalRevision.terms.prices_minor.monthly, '9007199254740993')
  const history = page.locator('section[aria-label="套餐修订历史"]')
  await history.getByRole('row').filter({ hasText: state.originalRevision.revision_id }).getByRole('button', { name: '查看此修订', exact: true }).click()
  await page.locator('section[aria-label="套餐完整条款"]').getByText('月付：9007199254740993 最小单位 CNY', { exact: true }).waitFor()
  assert.equal(await page.locator('section[aria-label="套餐发布编辑器"]').count(), 0)
  passed('new publication uses authoritative full terms and the observed revision while historical terms remain unchanged')

  const offers = page.locator('section[aria-label="套餐 Offer"]')
  await offers.getByRole('button', { name: '调整可用性', exact: true }).click()
  await page.locator('#catalog-availability-sell').uncheck()
  await page.locator('#catalog-availability-capacity').fill('0')
  await page.getByRole('button', { name: '保存可用性', exact: true }).click()
  await offers.getByText(/不新购/).waitFor()
  const availability = state.calls.find(call => call.operation === 'catalog.offers.availability.update').input
  assert.equal(availability.expected_version, '1'); assert.equal(availability.capacity_limit, 0)
  state.catalogDenied = true
  await page.getByRole('button', { name: '刷新', exact: true }).click()
  await page.getByText('无法确认套餐管理权限，已隐藏数据。请刷新确认权限。', { exact: true }).waitFor()
  assert.equal(await page.locator('.native-management-page table').count(), 0)
  assert.equal(await page.locator('section[aria-label="套餐完整条款"]').count(), 0)
  assert.ok(!page.url().includes('sign-in'))
  passed('Offer changes carry its observed version and explicit zero capacity; permission loss hides all catalog data')
  verifyFixture(state)

  const mobile = await catalogNetworkFixture(browser, { width: 390, height: 844 })
  fixtures.push(mobile); await login(mobile.page)
  for (const [name] of routes) {
    await navigate(mobile.page, name, true)
    await mobile.page.locator('.native-management-page table').first().waitFor()
    assert.equal(await mobile.page.locator('.admin-sidebar.is-open').count(), 0)
    assert.ok(await mobile.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), name + ' must fit viewport')
    const tables = await mobile.page.locator('.native-management-page .table-wrap').evaluateAll(elements => elements.map(element => ({
      viewport: element.clientWidth, content: element.scrollWidth, table: element.querySelector('table').getBoundingClientRect().width,
    })))
    assert.ok(tables.length > 0 && tables.every(table => table.content > table.viewport && table.table >= 920), name + ' tables must retain readable columns in a horizontal scroll region')
    await mobile.page.screenshot({ path: path.join(output, name + '-mobile.png'), fullPage: true })
  }
  verifyFixture(mobile.state)
  passed('all four catalog and network pages retain bounded mobile layouts and navigation')
  await writeFile(path.join(output, 'result.json'), JSON.stringify({ passed: true, checks,
    frontend: 'built main-repository admin', transport: 'strict synthetic Gateway fixtures',
    schema: 'current generated Core input/output/meta schemas',
    realBackendAcceptance: 'separate API, MySQL, and actual Core image Actions; this fixture does not claim live Core UI integration' }, null, 2) + '\n')
} catch (error) {
  const pages = []
  for (const [index, fixture] of fixtures.entries()) {
    const diagnostics = await Promise.allSettled([
      fixture.page.content().then(html => writeFile(path.join(output, 'failure-' + index + '.html'), html)),
      fixture.page.screenshot({ path: path.join(output, 'failure-' + index + '.png'), fullPage: true, timeout: 5000 }),
    ])
    pages.push({ url: fixture.page.url(), diagnostics: diagnostics.map(result => result.status) })
  }
  await writeFile(path.join(output, 'failure.json'), JSON.stringify({ passed: false, error: error.stack ?? String(error),
    checks, pages, fixtures: fixtures.map(fixture => ({ errors: fixture.state.errors, pageErrors: fixture.state.pageErrors,
      calls: fixture.state.calls.map(({ operation, input }) => ({ operation, input })) })) }, null, 2) + '\n')
  throw error
} finally {
  for (const fixture of fixtures) await fixture.context.close()
  if (browser) await browser.close()
  await new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve()))
}
