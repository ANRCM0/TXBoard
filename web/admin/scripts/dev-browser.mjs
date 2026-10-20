import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'
import { createServer } from 'vite'

// Actions-only regression: a successful production bundle does not exercise
// the dev optimizer or the SDK/form dependencies loaded in the browser.
const root = fileURLToPath(new URL('../', import.meta.url))
const output = fileURLToPath(new URL('../../../artifacts/admin-dev-browser/', import.meta.url))
await mkdir(output, { recursive: true })
const server = await createServer({
  root,
  configFile: fileURLToPath(new URL('../vite.config.ts', import.meta.url)),
  mode: 'development',
  cacheDir: output + '/vite-cache',
  optimizeDeps: { force: true },
  server: { host: '127.0.0.1', port: 0, strictPort: true, open: false },
  clearScreen: false,
})
let browser
try {
  await server.listen()
  const address = server.httpServer.address()
  assert.ok(address && typeof address !== 'string')
  const origin = 'http://127.0.0.1:' + address.port
  browser = await chromium.launch({ headless: true,
    executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined })
  const context = await browser.newContext({ locale: 'zh-CN', viewport: { width: 1280, height: 800 } })
  const page = await context.newPage()
  const errors = []
  page.on('pageerror', error => errors.push(error.message))
  await context.route('**/gateway/v1/bootstrap', route => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ ok: true, data: { security: {
      captcha: { enabled: false, type: null, siteKey: null },
    } } }),
  }))
  await page.goto(origin + '/admin/')
  await page.getByRole('heading', { name: '管理员登录', exact: true }).waitFor()
  // Exercise the form's Zod 3 resolver after the SDK's Zod 4 schemas load.
  // Invalid fixture input stays in the browser and never attempts a login.
  await page.getByRole('textbox', { name: '邮箱' }).fill('invalid')
  await page.getByLabel('密码', { exact: true }).fill('bad')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByText('请输入有效邮箱', { exact: true }).waitFor()
  await page.getByText('密码至少需要 8 个字符', { exact: true }).waitFor()
  assert.deepEqual(errors, [])
  await page.screenshot({ path: output + '/login-dev.png', fullPage: true })
  await writeFile(output + '/result.json', JSON.stringify({ passed: true,
    frontend: 'actual Vite development server with fresh dependency optimization',
    transport: 'synthetic public bootstrap; no authenticated backend',
    checks: ['SDK schema initialization', 'protected route redirects to rendered login', 'form validation'],
  }, null, 2) + '\n')
  console.log('PASS actual management dev server renders login and validates form without runtime errors')
} finally {
  await browser?.close()
  await server.close()
}
