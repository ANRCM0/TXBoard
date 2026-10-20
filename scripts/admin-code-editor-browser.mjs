import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { createServer } from 'node:http'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'
import { coreOperations } from '@txboard/core-sdk'

const dist = fileURLToPath(new URL('../web/admin/dist/', import.meta.url))
const output = fileURLToPath(new URL('../artifacts/admin-native-wave/', import.meta.url))
await mkdir(output, { recursive: true })
const server = createServer(async (req, res) => {
  try {
    const path = new URL(req.url, 'http://localhost').pathname
    const asset = path.match(/^\/(?:admin|\.txboard-admin)\/assets\/([A-Za-z0-9_.-]+)$/)
    const manifest = /^\/(?:admin|\.txboard-admin)\/editor-assets\.json$/.test(path)
    const file = asset ? 'assets/' + asset[1] : manifest ? 'editor-assets.json' : 'index.html'
    const extension = file.split('.').at(-1)
    const mime = { js: 'text/javascript', css: 'text/css', json: 'application/json', ttf: 'font/ttf', woff: 'font/woff', woff2: 'font/woff2', svg: 'image/svg+xml', png: 'image/png' }[extension] || 'text/html'
    res.writeHead(200, { 'Content-Type': mime,
      'Cache-Control': asset ? 'public, max-age=31536000, immutable' : 'no-cache',
      'Content-Security-Policy': "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; worker-src 'self'; connect-src 'self'; img-src 'self' data:;" })
    res.end(await readFile(dist + file))
  } catch { res.writeHead(404); res.end() }
})
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
const origin = 'http://127.0.0.1:' + server.address().port
const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined })
const formats = ['clash/v1', 'clash-meta/v1', 'sing-box/v1', 'stash/v1', 'surfboard/v1', 'shadowrocket/v1', 'surge-ios/v1', 'surge-mac/v1']
const metadata = { version: '1', requestId: '12345678-1234-4234-8234-123456789abc', coreRequestId: '12345678-1234-4234-8234-123456789abc', coreMeta: {} }
const checks = []
try {
  const context = await browser.newContext({ locale: 'zh-CN', viewport: { width: 1440, height: 1000 } })
  const requests = [], errors = [], workers = []
  let deny = false, blockEditor = false, blockedEditorRequests = 0
  const templates = formats.map(format_id => ({ format_id, source: '{{ .Default }}', version: 'a'.repeat(64), sha256: 'b'.repeat(64), custom: false }))
  const templateWrites = []

  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url())
    if (url.origin !== origin) { errors.push('Remote request: ' + request.url()); return route.abort() }
    requests.push(url.pathname)
    if (blockEditor && /\/editor-[^/]+\.js$/.test(url.pathname)) {
      blockedEditorRequests++
      return route.abort('failed')
    }
    if (!url.pathname.startsWith('/gateway/')) return route.continue()
    const send = data => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, data, meta: metadata }) })
    if (url.pathname.endsWith('/bootstrap')) return send({ security: { captcha: { enabled: false, type: null, siteKey: null } } })
    const operation = url.pathname.split('/operations/')[1], input = request.postDataJSON().input
    if (operation === 'identity.admin-session.create') return send({ auth_data: 'Bearer local-editor-fixture', is_admin: true, secure_path: 'ci_admin' })
    if (operation === 'identity.admin.get') return send({ id: '1', email: 'admin@example.test' })
    if (operation === 'subscription.admin-templates.list') {
      if (deny) return route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ ok: false, error: { code: 'FORBIDDEN', message: 'withheld' }, meta: { version: '1', requestId: metadata.requestId } }) })
      const data = { templates: structuredClone(templates), providers: ['provider-a'] }
      coreOperations[operation].input.parse(input); coreOperations[operation].output.parse(data)
      return send(data)
    }
    if (operation === 'subscription.admin-templates.save' || operation === 'subscription.admin-templates.reset') {
      coreOperations[operation].input.parse(input)
      assert.match(request.headers()['idempotency-key'], /^[0-9a-f-]{36}$/)
      const row = templates.find(value => value.format_id === input.format_id)
      assert.equal(input.expected_version, row.version)
      if (operation.endsWith('.save')) assert.equal(input.provider_id, 'provider-a')
      row.source = operation.endsWith('.reset') ? '' : input.source
      row.custom = row.source !== ''
      row.sha256 = createHash('sha256').update(row.source).digest('hex')
      row.version = createHash('sha256').update(JSON.stringify(row)).digest('hex')
      templateWrites.push({ operation, input, key: request.headers()['idempotency-key'] })
      return send(coreOperations[operation].output.parse({ ok: true }))
    }
    throw new Error('Unexpected editor browser operation: ' + operation)
  })
  const page = await context.newPage()
  page.on('pageerror', error => errors.push(error.message))
  page.on('worker', worker => workers.push(worker.url()))
  await page.goto(origin + '/admin/config/system/subscribe-template')
  await page.getByRole('textbox', { name: '邮箱' }).fill('admin@example.test')
  await page.getByLabel('密码', { exact: true }).fill('fixture-password')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByRole('heading', { name: '订阅模板', exact: true }).waitFor()
  assert.equal(requests.some(path => /\/editor-[^/]+\.js$/.test(path)), false, 'Read view must not load Monaco')
  blockEditor = true
  await page.getByRole('button', { name: '编辑模板', exact: true }).click()
  await page.getByText('本地编辑器加载失败，源码草稿已保留。', { exact: true }).waitFor()
  assert.ok(blockedEditorRequests > 0, 'The browser actually refused a local editor chunk')
  await page.getByRole('button', { name: '使用纯文本编辑', exact: true }).click()
  await page.getByRole('textbox', { name: 'Clash 模板源码', exact: true }).fill('# draft survives failed local asset')
  await page.getByRole('button', { name: '使用代码编辑器', exact: true }).click()
  await page.getByText('本地编辑器加载失败，源码草稿已保留。', { exact: true }).waitFor()
  blockEditor = false
  await page.getByRole('button', { name: '重新加载编辑器', exact: true }).click()
  await page.locator('[data-editor-state="ready"]').waitFor({ timeout: 30000 })
  assert.ok(await page.locator('.monaco-editor').innerText().then(text => text.includes('# draft survives failed local asset')))
  checks.push('A blocked local editor chunk exposes retry; retry preserves the edited plaintext draft')
  await page.locator('.monaco-editor').click({ position: { x: 180, y: 20 } })
  await page.keyboard.press('Control+A'); await page.keyboard.insertText('# local editor draft')
  await page.getByRole('button', { name: '使用纯文本编辑', exact: true }).click()
  assert.equal(await page.getByRole('textbox', { name: 'Clash 模板源码', exact: true }).inputValue(), '# local editor draft')
  checks.push('CSP permits local Monaco initialization; typing and mode switching preserve source')
  await page.getByRole('button', { name: '取消编辑', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '放弃修改', exact: true }).click()
  await page.getByRole('button', { name: '编辑模板', exact: true }).click()
  await page.getByLabel('校验节点', { exact: true }).selectOption('provider-a')
  const plainMode = page.getByRole('button', { name: '使用纯文本编辑', exact: true })
  if (await plainMode.isVisible()) await plainMode.click()
  await page.getByRole('textbox', { name: 'Clash 模板源码', exact: true }).fill('# edited locally\n{{ .Default }}')
  await page.getByRole('button', { name: '校验并保存', exact: true }).click()
  await page.getByText('已启用自定义模板', { exact: true }).waitFor()
  assert.deepEqual(templateWrites[0].input, { format_id: 'clash/v1', expected_version: 'a'.repeat(64), source: '# edited locally\n{{ .Default }}', provider_id: 'provider-a' })
  const savedVersion = templates[0].version
  await page.getByRole('button', { name: '恢复默认模板', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '恢复默认', exact: true }).click()
  await page.getByText('使用节点内置默认模板', { exact: true }).waitFor()
  assert.deepEqual(templateWrites[1].input, { format_id: 'clash/v1', expected_version: savedVersion })
  checks.push('Template save sends the provider, unchanged source and observed version; reset confirms and uses the next version')
  await page.getByRole('button', { name: 'Sing-box', exact: true }).click()
  const jsonWorkerStarted = page.waitForEvent('worker', { predicate: worker => /json\.worker-[^/]+\.js$/.test(worker.url()) })
  await page.getByRole('button', { name: '编辑模板', exact: true }).click()
  await page.locator('[data-editor-state="ready"]').waitFor()
  await page.locator('.monaco-editor').click({ position: { x: 180, y: 20 } })
  await page.keyboard.press('Control+A'); await page.keyboard.insertText('null')
  await jsonWorkerStarted
  assert.ok(workers.some(url => /json\.worker-/.test(url)), 'JSON language worker starts locally')
  // Exercise the production worker factory for languages used by mail and theme source.
  const languageWorkers = Promise.all(['html', 'css'].map(language => page.waitForEvent('worker', {
    predicate: worker => new RegExp(language + '\\.worker-').test(worker.url()),
  })))
  await page.evaluate(() => {
    const environment = globalThis.MonacoEnvironment
    if (!environment?.getWorker) throw new Error('Production worker factory missing')
    globalThis.editorBrowserWorkers = ['html', 'css'].map(label => environment.getWorker('editor-browser-check', label))
  })
  const startedLanguageWorkers = await languageWorkers
  for (const language of ['html', 'css']) {
    const worker = startedLanguageWorkers.find(candidate => new RegExp(language + '\\.worker-').test(candidate.url()))
    assert.ok(worker, language + ' worker starts locally')
    assert.equal(new URL(worker.url()).origin, origin)
    assert.equal(await worker.evaluate(() => typeof self.postMessage), 'function')
  }
  await page.evaluate(() => document.documentElement.dataset.theme = 'dark')
  await page.locator('.monaco-editor.vs-dark').waitFor()
  assert.equal(await page.locator('.monaco-editor').evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(30, 30, 30)')
  await page.screenshot({ path: output + 'subscription-template-editor-dark.png', fullPage: true })
  checks.push('Production HTML/CSS worker factories start local workers under CSP; existing editor changes to dark theme')
  await page.evaluate(() => { for (const worker of globalThis.editorBrowserWorkers) worker.terminate() })
  await page.screenshot({ path: output + 'subscription-template-editor-desktop.png', fullPage: true })
  await page.setViewportSize({ width: 390, height: 844 })
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Editor fits mobile width')
  await page.screenshot({ path: output + 'subscription-template-editor-mobile.png', fullPage: true })
  checks.push('JSON worker loads from local /admin/assets under CSP and template editor fits mobile width')
  deny = true
  await page.getByRole('button', { name: '刷新模板', exact: true }).click()
  await page.getByText('模板访问权限已失效，编辑内容已清空').waitFor()
  assert.equal(await page.locator('.monaco-editor').count(), 0)
  checks.push('Forbidden owner refresh disposes Monaco and clears source')
  assert.deepEqual(errors, [])
  await writeFile(output + 'code-editor-browser-result.json', JSON.stringify({ checks, workers, templateWrites, localResources: [...new Set(requests.filter(path => /\.worker-|editor-|lspLanguageFeatures-/.test(path)))] }, null, 2))
  console.log(JSON.stringify({ checks, workers }, null, 2))
} finally { await browser.close(); await new Promise(resolve => server.close(resolve)) }
