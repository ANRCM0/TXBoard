import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile } from 'node:fs/promises'
import { chromium } from 'playwright'
import { hostDocument } from '../../../tools/module-host/document.mjs'

// Only the explicit native image CI fixture supplies this private session file.
const input = JSON.parse(await readFile(process.argv[2], 'utf8'))
const host = createServer(async (request, response) => {
  if (!['/', '/host.js'].includes(request.url)) { response.writeHead(404).end(); return }
  response.setHeader('Content-Type', request.url === '/' ? 'text/html' : 'text/javascript')
  response.end(request.url === '/' ? await hostDocument() : await readFile(new URL('../../../tools/module-host/dist/host.js', import.meta.url)))
})
let browser
try {
  await new Promise(resolve => host.listen(4317, '127.0.0.1', resolve))
  browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()
  await page.goto('http://127.0.0.1:4317')
  await page.locator('#origin').fill(input.origin)
  await page.locator('#admin-path').fill(input.adminPath)
  await page.locator('#token').fill(input.token.replace(/^Bearer /, ''))
  await page.locator('#connect button').click()
  const frame = page.frameLocator('#surface iframe')
  await frame.locator('#refresh').click()
  await frame.locator('#result').filter({ hasText: input.version }).waitFor()
  assert.equal(await frame.locator('#label').textContent(), 'Native image portal')
  assert.equal((await page.locator('#surface iframe').getAttribute('srcdoc')).includes(input.token), false)
  await page.locator('#configuration input').fill('Live SDK configuration')
  await page.locator('#configuration button').click()
  await page.locator('#status').filter({ hasText: 'Configuration saved' }).waitFor()
  await frame.locator('#refresh').click()
  await frame.locator('#label').filter({ hasText: 'Live SDK configuration' }).waitFor()
  await page.setViewportSize({ width: 390, height: 844 })
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true)
  console.log('Actual Core image and Chromium module host SDK call/configuration passed')
} finally {
  await browser?.close()
  await new Promise(resolve => host.close(resolve))
}
