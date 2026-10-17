import test from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile } from 'node:fs/promises'
import { chromium } from 'playwright'
import { hostDocument } from '../../apps/module-host/document.mjs'

const token = 'module-browser-fixture-secret'
const empty = { type: 'object', properties: {}, required: [], additionalProperties: false }
const operation = { actor: 'admin', permission: 'extensions.module_portal.view', idempotency: 'read', input: empty,
  output: { type: 'object', properties: { label: { type: 'string', minLength: 1, maxLength: 80 } }, required: ['label'], additionalProperties: false } }
const descriptor = { schema: 'module-presentation/v1', module: 'module_portal', version: '1.0.0', fingerprint: 'a'.repeat(64),
  actor: 'admin', title: 'Fixture module', entry: 'ui/index.html', assets: ['ui/index.html', 'ui/page.js', 'ui/page.css', 'ui/badge.svg'],
  operations: { 'status.get': operation }, configuration_schema: {
    type: 'object', properties: { label: { type: 'string', minLength: 1, maxLength: 80 } }, required: ['label'], additionalProperties: false },
  configuration: { label: 'Original' }, configuration_version: '1' }

async function server(handler) {
  const value = createServer(handler)
  await new Promise(resolve => value.listen(0, '127.0.0.1', resolve))
  return { url: `http://127.0.0.1:${value.address().port}`, close: () => new Promise(resolve => value.close(resolve)) }
}

test('actual minimal host loads declared assets and enforces opaque Bridge, retry and revocation boundaries', { timeout: 120000 }, async t => {
  let hostOrigin, admission = null, unsafeAsset = false, loseConfigurationAck = true, label = 'Original'
  const calls = [], configurations = [], leaks = []
  const core = await server(async (request, response) => {
    response.setHeader('Access-Control-Allow-Origin', hostOrigin)
    response.setHeader('Access-Control-Allow-Headers', 'Authorization,Content-Type,Idempotency-Key,X-Core-Module-Fingerprint')
    response.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS')
    if (request.method === 'OPTIONS') { response.writeHead(204).end(); return }
    if (request.url.startsWith('/leak')) { leaks.push(request.url); response.end(); return }
    assert.equal(request.headers.authorization, `Bearer ${token}`)
    const path = request.url.replace('/txapi/admin/acceptance/extensions/module_portal/', '')
    calls.push({ path, fingerprint: request.headers['x-core-module-fingerprint'] })
    response.setHeader('Content-Type', 'application/json')
    if (path !== 'presentation') assert.equal(request.headers['x-core-module-fingerprint'], descriptor.fingerprint)
    if (admission) { response.writeHead(admission === 'MODULE_PERMISSION_DENIED' ? 403 : 409).end(JSON.stringify({ error: { code: admission } })); return }
    if (path === 'presentation') { response.end(JSON.stringify({ data: { ...descriptor, configuration: { label } } })); return }
    if (path.startsWith('resources/')) {
      const assets = {
        'ui/index.html': `<!doctype html><html><head><link rel="stylesheet" href="page.css"></head><body><h1>Extension</h1><img alt="badge" src="${unsafeAsset ? core.url + '/leak' : 'badge.svg'}"><p id="isolation"></p><p id="result"></p><button id="call">Call</button><button id="unknown">Unknown</button><button id="smuggle">Smuggle</button><script src="page.js"></script></body></html>`,
        'ui/page.css': 'h1{color:rgb(12, 34, 56)} body{background-image:url(badge.svg)}',
        'ui/badge.svg': '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect width="8" height="8" fill="green"/></svg>',
        'ui/page.js': `let serial=0; try{parent.document.querySelector('#token');document.querySelector('#isolation').textContent='escaped'}catch{document.querySelector('#isolation').textContent='isolated'}
          function call(operation,input={},extra={}){parent.postMessage({channel:'core-module/v1',id:String(++serial),operation,input,...extra},'*')}
          onmessage=event=>{if(event.data.channel==='core-module/v1')document.querySelector('#result').textContent=event.data.error||event.data.data.label}
          document.querySelector('#call').onclick=()=>call('status.get');
          document.querySelector('#unknown').onclick=()=>call('unknown.get');
          document.querySelector('#smuggle').onclick=()=>call('status.get',{}, {actor:'admin',Authorization:'forged',url:'https://private'});
          call('status.get');`,
      }
      response.setHeader('Content-Type', path.endsWith('.js') ? 'text/javascript' : path.endsWith('.css') ? 'text/css' : path.endsWith('.svg') ? 'image/svg+xml' : 'text/html')
      response.end(assets[path.slice('resources/'.length)])
      return
    }
    let raw = ''
    for await (const chunk of request) raw += chunk
    if (path === 'configuration') {
      const configuration = JSON.parse(raw)
      configurations.push({ raw, key: request.headers['idempotency-key'] })
      label = configuration.configuration.label
      if (loseConfigurationAck) {
        loseConfigurationAck = false
        response.writeHead(200, { 'Content-Length': '1000' })
        response.write('{"data":')
        setTimeout(() => response.destroy(), 10)
        return
      }
      response.end(JSON.stringify({ data: { deployment_id: request.headers['idempotency-key'], module: descriptor.module,
        action: 'configure', phase: 'completed', package_version: descriptor.version } }))
      return
    }
    assert.equal(path, 'operations/status.get')
    assert.deepEqual(JSON.parse(raw), { input: {} })
    response.end(JSON.stringify({ data: { label } }))
  })
  t.after(core.close)
  const host = await server(async (request, response) => {
    if (!['/', '/host.js'].includes(request.url)) { response.writeHead(404).end(); return }
    response.setHeader('Content-Type', request.url === '/' ? 'text/html' : 'text/javascript')
    response.end(request.url === '/' ? await hostDocument() : await readFile(new URL('../../apps/module-host/dist/host.js', import.meta.url)))
  })
  hostOrigin = host.url
  t.after(host.close)
  const browser = await chromium.launch({ headless: true })
  t.after(() => browser.close())
  const page = await browser.newPage({ viewport: { width: 1000, height: 850 } })
  const diagnostics = []
  page.on('pageerror', error => diagnostics.push(error.message))
  page.on('console', message => { if (message.type() === 'error') diagnostics.push(message.text().slice(0, 500)) })
  const waitStatus = async text => {
    try { await page.locator('#status').filter({ hasText: text }).waitFor() }
    catch (error) { throw new Error(`${error.message}; host=${await page.locator('#status').textContent()}; configuration_requests=${configurations.length}; browser=${JSON.stringify(diagnostics)}`) }
  }
  await page.goto(host.url)
  await page.locator('#origin').fill(core.url)
  await page.locator('#admin-path').fill('acceptance')
  await page.locator('#token').fill(token)
  const connect = async () => {
    await page.locator('#connect button').click()
    try { await page.frameLocator('#surface iframe').locator('#result').filter({ hasText: label }).waitFor() }
    catch (error) { throw new Error(`${error.message}; host=${await page.locator('#status').textContent()}; browser=${JSON.stringify(diagnostics)}`) }
  }
  await connect()
  let frame = page.frameLocator('#surface iframe')
  assert.equal(await frame.locator('#isolation').textContent(), 'isolated')
  assert.equal(await frame.locator('h1').evaluate(element => getComputedStyle(element).color), 'rgb(12, 34, 56)')
  assert.equal(await frame.locator('img').evaluate(element => element.complete && element.naturalWidth === 8), true)
  assert.equal(await page.locator('#surface iframe').getAttribute('sandbox'), 'allow-scripts')
  assert.equal((await page.locator('#surface iframe').getAttribute('srcdoc')).includes(token), false)
  assert.equal(await page.locator('#navigation').textContent(), 'Fixture module')
  const operationCount = () => calls.filter(call => call.path.startsWith('operations/')).length
  const before = operationCount()
  await frame.locator('#unknown').click()
  await frame.locator('#result').filter({ hasText: 'MODULE_OPERATION_NOT_FOUND' }).waitFor()
  assert.equal(operationCount(), before)
  await frame.locator('#smuggle').click()
  await page.evaluate(() => window.postMessage({ channel: 'core-module/v1', id: 'forged', operation: 'status.get', input: {} }, '*'))
  await frame.locator('#call').click()
  await frame.locator('#result').filter({ hasText: label }).waitFor()
  assert.equal(operationCount(), before + 1)

  const input = page.locator('#configuration input')
  await input.fill('Retry value')
  await page.locator('#configuration button').click()
  await waitStatus('MODULE_CONFIGURATION_UNCONFIRMED')
  await input.fill('Different value')
  await page.locator('#configuration button').click()
  await waitStatus('MODULE_CONFIGURATION_RECOVERY_REQUIRED')
  assert.equal(configurations.length, 1)
  await input.fill('Retry value')
  await page.locator('#configuration button').click()
  await waitStatus('Configuration saved')
  assert.equal(configurations.length, 2)
  assert.deepEqual(configurations[0], configurations[1])
  assert.match(configurations[0].key, /^[0-9a-f-]{36}$/)

  for (const code of ['MODULE_PERMISSION_DENIED', 'MODULE_VERSION_CHANGED', 'MODULE_NOT_AVAILABLE']) {
    admission = code
    await frame.locator('#call').click()
    await waitStatus(code)
    await page.locator('#surface iframe').waitFor({ state: 'detached' })
    assert.equal(await page.locator('#navigation').isVisible(), false)
    admission = null
    await connect()
    frame = page.frameLocator('#surface iframe')
  }
  unsafeAsset = true
  await page.locator('#connect button').click()
  await waitStatus('MODULE_RESOURCE_NOT_DECLARED')
  assert.equal(await page.locator('#surface iframe').count(), 0)
  assert.deepEqual(leaks, [])
})
