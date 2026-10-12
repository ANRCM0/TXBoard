import { createServer } from 'node:http'
import { chromium } from 'playwright'
import { build } from 'esbuild'
import { readFile } from 'node:fs/promises'
import { strict as assert } from 'node:assert'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('../', import.meta.url))
const vector = JSON.parse(await readFile(new URL('../packages/theme-sdk/test/vectors/sc-v2-codec.json', import.meta.url), 'utf8'))
const bundle = await build({
  absWorkingDir: root, entryPoints: ['packages/theme-sdk/src/experimental/sc-v2-codec.ts'],
  bundle: true, format: 'esm', platform: 'browser', write: false,
})
const source = bundle.outputFiles[0].text
const server = createServer((request, response) => {
  response.setHeader('Cache-Control', 'no-store')
  response.setHeader('Content-Type', request.url === '/codec.js' ? 'text/javascript' : 'text/html')
  response.end(request.url === '/codec.js' ? source : '<!doctype html><title>Local metadata codec fixture</title>')
})
await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve) })
let browser
try {
  browser = await chromium.launch({ headless: true, executablePath: process.env.GATEWAY_TEST_CHROMIUM_PATH || undefined })
  const page = await browser.newPage()
  await page.goto(`http://127.0.0.1:${server.address().port}`)
  const actual = await page.evaluate(async vector => {
    const c = await import('/codec.js')
    const hex = bytes => Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')
    const bytes = text => Uint8Array.from(text.match(/../g), pair => Number.parseInt(pair, 16))
    const digestInput = c.requestDigestInput(vector.metadata, bytes(vector.enc_hex), bytes(vector.ciphertext_hex))
    const digest = c.toBase64url(new Uint8Array(await crypto.subtle.digest('SHA-256', digestInput)))
    let rejected = 0
    for (const metadata of [
      { ...vector.metadata, origin: 'https://gateway.example.test:443' },
      { ...vector.metadata, nonce: vector.metadata.nonce.slice(0, -1) + 'B' },
      { ...vector.metadata, operation: 'user.资料.get' },
      { ...vector.metadata, path: '/gateway/v2/channel/admin' },
      { ...vector.metadata, expires_at: Number.MAX_SAFE_INTEGER + 1 },
    ]) {
      try { c.requestAad(metadata) } catch (error) { if (error.message === 'SC_CODEC_INVALID') rejected++ }
    }
    return {
      request_aad_hex: hex(c.requestAad(vector.metadata)), response_aad_hex: hex(c.responseAad(vector.metadata, vector.binding)),
      request_digest_input_hex: hex(digestInput), request_digest: digest,
      response_key_context_hex: hex(c.responseExporterContext(digest, 'key')),
      response_nonce_context_hex: hex(c.responseExporterContext(digest, 'nonce')), rejected,
      browser: navigator.userAgent,
    }
  }, vector)
  for (const field of ['request_aad_hex', 'response_aad_hex', 'request_digest_input_hex', 'response_key_context_hex', 'response_nonce_context_hex']) {
    assert.equal(actual[field], vector[field], field)
  }
  assert.equal(actual.request_digest, vector.binding.request_digest)
  assert.equal(actual.rejected, 5)
  console.log(JSON.stringify({ result: 'passed', positive_checks: 6, rejection_checks: 5, browser: actual.browser, scope: 'metadata codec only; no encrypted operation or business endpoint' }))
} finally {
  await browser?.close()
  await new Promise(resolve => server.close(resolve))
}
