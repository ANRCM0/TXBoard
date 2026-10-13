/** Local static browser harness only. There is no encrypted HTTP operation handler. */
import { createServer } from 'node:http'
import { chromium } from 'playwright'
import { build } from 'esbuild'
import { readFile } from 'node:fs/promises'
import { strict as assert } from 'node:assert'
import {createSaltedExperimentalRequest as createExperimentalRequest,openSaltedExperimentalRequest as openExperimentalRequest} from '../packages/theme-sdk/dist/experimental/sc-v2-response-salt.js'
import { toBase64url } from '../packages/theme-sdk/dist/experimental/sc-v2-codec.js'

const vector = JSON.parse(await readFile(new URL('../packages/theme-sdk/test/vectors/sc-v2-response-salt.json', import.meta.url), 'utf8'))
const keyFixture = JSON.parse(await readFile(new URL('../packages/theme-sdk/test/vectors/hpke-kat.json', import.meta.url), 'utf8'))
const bundle = await build({ entryPoints: ['packages/theme-sdk/src/experimental/sc-v2-response-salt.ts'], bundle: true,
  format: 'esm', platform: 'browser', write: false })
const server = createServer((request, response) => {
  response.setHeader('Cache-Control', 'no-store')
  response.setHeader('Content-Type', request.url === '/experiment.js' ? 'text/javascript' : 'text/html')
  response.end(request.url === '/experiment.js' ? bundle.outputFiles[0].text : '<!doctype html><title>Offline cryptography fixture</title>')
})
await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve) })
let browser
try {
  browser = await chromium.launch({ headless: true, executablePath: process.env.GATEWAY_TEST_CHROMIUM_PATH || undefined })
  const page = await browser.newPage()
  await page.goto(`http://127.0.0.1:${server.address().port}`)
  const reference = await page.evaluate(async ({ vector, keyFixture }) => {
    const c = await import('/experiment.js')
    const bytes = hex => Uint8Array.from(hex.match(/../g) ?? [], pair => Number.parseInt(pair, 16))
    const hex = data => Array.from(data, byte => byte.toString(16).padStart(2, '0')).join('')
    const originalRandom = crypto.getRandomValues.bind(crypto)
    const originalClock = Date.now
    Date.now = () => vector.envelope.metadata.issued_at * 1000
    try {
      const { d, ...publicJwk } = keyFixture.recipientJwk
      const algorithm = { name: 'ECDH', namedCurve: 'P-256' }
      const pair = { privateKey: await crypto.subtle.importKey('jwk', keyFixture.recipientJwk, algorithm, false, ['deriveBits']),
        publicKey: await crypto.subtle.importKey('jwk', publicJwk, algorithm, true, []) }
      const recipient = await c.openSaltedExperimentalRequest(pair, vector.envelope)
      crypto.getRandomValues = buffer => {buffer.set(Uint8Array.from(atob(vector.response.response_nonce.replace(/-/g,'+').replace(/_/g,'/')),c=>c.charCodeAt(0)));return buffer}
      const response = await recipient.sealResponse(bytes(vector.response_plaintext_hex), { kind: 'error', status: 403 })
      crypto.getRandomValues = originalRandom
      let rejected = 0
      try { await recipient.sealResponse(new Uint8Array(), { kind: 'result', status: 200 }) } catch (error) { if (error.message === 'SC_EXPERIMENT_INVALID') rejected++ }
      for (const patch of [{ operation: 'user.order.get' }, { domain: 'admin', path: '/gateway/v2/channel/admin' }]) {
        try { await c.openSaltedExperimentalRequest(pair, { ...vector.envelope, metadata: { ...vector.envelope.metadata, ...patch } }) }
        catch (error) { if (error.message === 'SC_EXPERIMENT_INVALID') rejected++ }
      }
      // Test the reviewed odd-y, nonextractable-key compatibility case.
      const b64 = data => btoa(String.fromCharCode(...data)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
      const jwk = { kty: 'EC', crv: 'P-256', x: b64(bytes('6b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c296')),
        y: b64(bytes('4fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5')), d: b64(bytes('0'.repeat(63)+'1')) }
      const { d: _publicFixture, ...publicOdd } = jwk
      const odd = { privateKey: await crypto.subtle.importKey('jwk', jwk, algorithm, false, ['deriveBits']),
        publicKey: await crypto.subtle.importKey('jwk', publicOdd, algorithm, true, []) }
      const sender = await c.createSaltedExperimentalRequest(odd.publicKey, vector.envelope.metadata, new Uint8Array([1]))
      const opened = await c.openSaltedExperimentalRequest(odd, sender.envelope)
      return { plaintext: hex(recipient.plaintext), response, rejected, odd_y: hex(opened.plaintext), browser: navigator.userAgent }
    } finally { Date.now = originalClock;crypto.getRandomValues = originalRandom }
  }, { vector, keyFixture })
  assert.equal(reference.plaintext, vector.request_plaintext_hex)
  assert.deepEqual(reference.response, vector.response)
  assert.equal(reference.rejected, 3); assert.equal(reference.odd_y, '01')

  // Browser owns its private key in memory; only the public key crosses roles.
  const browserPublic = await page.evaluate(async () => {
    globalThis.fixturePair = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits'])
    const raw = new Uint8Array(await crypto.subtle.exportKey('raw', fixturePair.publicKey))
    return Array.from(raw)
  })
  const browserKey = await crypto.subtle.importKey('raw', new Uint8Array(browserPublic), { name: 'ECDH', namedCurve: 'P-256' }, true, [])
  let crossChecks = 0
  for (const text of ['', '中文🚀\u0000']) {
    const issued_at = Math.floor(Date.now() / 1000)
    const metadata = { ...vector.envelope.metadata, issued_at, expires_at: issued_at + 60,
      request_id: crypto.randomUUID(), nonce: toBase64url(crypto.getRandomValues(new Uint8Array(32))) }
    const sender = await createExperimentalRequest(browserKey, metadata, new TextEncoder().encode(text))
    const browserResponse = await page.evaluate(async envelope => {
      const c = await import('/experiment.js')
      const recipient = await c.openSaltedExperimentalRequest(fixturePair, envelope)
      const response = await recipient.sealResponse(recipient.plaintext, { kind: 'result', status: 200 })
      return { response, plaintext: Array.from(recipient.plaintext) }
    }, sender.envelope)
    assert.deepEqual(new Uint8Array(browserResponse.plaintext), new TextEncoder().encode(text))
    assert.equal(new TextDecoder().decode(await sender.openResponse(browserResponse.response)), text)
    crossChecks++
  }
  const nodePair = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits'])
  const nodePublic = Array.from(new Uint8Array(await crypto.subtle.exportKey('raw', nodePair.publicKey)))
  for (const size of [0, 3056]) {
    const issued_at = Math.floor(Date.now() / 1000)
    const metadata = { ...vector.envelope.metadata, issued_at, expires_at: issued_at + 60,
      request_id: crypto.randomUUID(), nonce: toBase64url(crypto.getRandomValues(new Uint8Array(32))) }
    const envelope = await page.evaluate(async ({ nodePublic, metadata, size }) => {
      const c = await import('/experiment.js')
      const key = await crypto.subtle.importKey('raw', new Uint8Array(nodePublic), { name: 'ECDH', namedCurve: 'P-256' }, true, [])
      globalThis.fixtureSender = await c.createSaltedExperimentalRequest(key, metadata, new Uint8Array(size).fill(173))
      return fixtureSender.envelope
    }, { nodePublic, metadata, size })
    const recipient = await openExperimentalRequest(nodePair, envelope)
    assert.deepEqual(recipient.plaintext, new Uint8Array(size).fill(173))
    const response = await recipient.sealResponse(recipient.plaintext, { kind: 'result', status: 200 })
    const opened = await page.evaluate(async response => Array.from(await fixtureSender.openResponse(response)), response)
    assert.deepEqual(new Uint8Array(opened), new Uint8Array(size).fill(173)); crossChecks++
  }
  console.log(JSON.stringify({ result: 'passed', deterministic_request_response_checks: 2, rejection_checks: reference.rejected,
    odd_y_check: 1, cross_role_roundtrips: crossChecks, browser: reference.browser,
    scope: 'offline primitive fixture; no encrypted HTTP operation, shared replay, actor authorization or production acceptance' }))
} finally {
  await browser?.close()
  await new Promise(resolve => server.close(resolve))
}
