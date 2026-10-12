import assert from 'node:assert/strict'
import https from 'node:https'
import { generateKeyPairSync } from 'node:crypto'
import { execFileSync, spawn } from 'node:child_process'
import { appendFileSync, openSync, closeSync, readFileSync, readdirSync, unlinkSync, rmSync, writeFileSync } from 'node:fs'
import { setTimeout as sleep } from 'node:timers/promises'
import { serve } from '@hono/node-server'
import { createGatewayApp } from '../../apps/gateway/dist/app.js'
import { loadConfig } from '../../apps/gateway/dist/config/env.js'
import { createLogger } from '../../apps/gateway/dist/middleware/observability.js'
import { loadCryptoService } from '../../apps/gateway/dist/services/crypto.js'
import { RedisSecurity } from '../../apps/gateway/dist/services/redis-security.js'
import { createTXBoardClient } from '../../packages/theme-sdk/dist/index.js'
import { encryptForOperation } from '../../packages/theme-sdk/dist/crypto.js'

const api = process.env.INTERNAL_CORE_API
const dir = process.env.INTERNAL_FIXTURE_DIR
assert.ok(api && dir)
const snapshot = JSON.parse(readFileSync(`${api}/../internal-snapshot.json`, 'utf8'))
assert.equal(snapshot.committed_core_head, 'e33ba63e9a7c3aa97fde02c41ba5dc76d230f3cf')
const actors = JSON.parse(readFileSync(`${dir}/actors.json`, 'utf8'))
const processes = []
const servers = []
const checks = []
const nativeCalls = []
const sdkRequests = []
const observedTls = new Set()
const decorate = action => JSON.parse(execFileSync('php', [
  'tests/integration/internal-user-core-fixture.php', api, action,
], { env: process.env, encoding: 'utf8' }))

function child(command, args, label, env = {}) {
  const log = openSync(`${dir}/${label}.log`, 'a', 0o600)
  const childProcess = spawn(command, args, {
    cwd: api, env: { ...processEnv(), ...env }, detached: true,
    stdio: ['ignore', log, log],
  })
  closeSync(log)
  processes.push(childProcess)
  return childProcess
}

function processEnv() {
  return { ...process.env, INTERNAL_CORE_API: api, INTERNAL_FIXTURE_DIR: dir }
}

function stop(process) {
  if (process) {
    try { globalThis.process.kill(-process.pid, 'SIGTERM') } catch { /* already stopped */ }
  }
}

async function ready(url) {
  for (let attempt = 0; attempt < 60; attempt++) {
    try {
      const response = await fetch(url)
      if (response.ok) return
    } catch { /* wait for the fixture process */ }
    await sleep(100)
  }
  throw new Error(`Fixture readiness failed: ${url}`)
}

async function check(name, run) {
  await run()
  checks.push(name)
  process.stdout.write(`ok ${checks.length}: ${name}\n`)
}

const serverConfig = {
  TXBOARD_UPSTREAM_URL: 'http://127.0.0.1:19081',
  TXBOARD_ALLOW_PRIVATE_HTTP: 'true',
  APP_ENV: 'testing',
}

function gatewayConfig(enabled = true) {
  const configPath = `${dir}/gateway-email.json`
  writeFileSync(configPath, JSON.stringify({
    origin: 'https://127.0.0.1:19443', servername: 'internal-core.local',
    ca_path: `${dir}/ca.pem`, crl_path: `${dir}/ca.crl`,
    cert_path: `${dir}/user.pem`, key_path: `${dir}/user.key`,
  }))
  return loadConfig({
    ...serverConfig,
    GATEWAY_INTERNAL_USER_ENABLED: 'true',
    GATEWAY_INTERNAL_USER_CONFIG_FILE: configPath,
    GATEWAY_INTERNAL_EMAIL_CODE_ENABLED: enabled ? 'true' : 'false',
  })
}

function witnesses() {
  const files = readdirSync(dir).filter(name => /^email-witness-[0-9a-f]{32}\.json$/.test(name))
  return files.map(name => JSON.parse(readFileSync(`${dir}/${name}`, 'utf8')))
}

function directTls(config, rawInput, headers = {}, cert = 'user') {
  const tls = config.internalUser
  const body = Buffer.from(JSON.stringify({ contract_version: '1',
    request_id: '00000000-0000-4000-8000-000000000000', deadline_at: Math.floor(Date.now() / 1000) + 5,
  }).replace(/}$/, ',"input":' + rawInput + '}'))
  return new Promise((resolve, reject) => {
    const request = https.request({ hostname: '127.0.0.1', port: 19443, servername: tls.servername,
      ca: tls.ca, crl: tls.crl, cert: readFileSync(`${dir}/${cert}.pem`), key: readFileSync(`${dir}/${cert}.key`),
      rejectUnauthorized: true, minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3', agent: false,
      method: 'POST', path: '/txapi/internal/v1/operations/auth.email-code.send',
      headers: { 'content-type': 'application/json', 'content-length': body.length, ...headers },
    }, response => {
      observedTls.add(response.socket.getProtocol())
      const chunks = []
      response.on('data', chunk => chunks.push(chunk))
      response.on('end', () => resolve({ status: response.statusCode, body: JSON.parse(Buffer.concat(chunks)) }))
    })
    request.setTimeout(5000, () => request.destroy(new Error('Local email TLS timeout')))
    request.on('error', reject)
    request.end(body)
  })
}

let core
let boundary
let gatewayServer
let redis
let successState
try {
  // The router runs the real Laravel HTTP kernel and ArrayTransport; it is not a
  // mail fake or a separate endpoint.
  decorate('email-prepare')
  core = child('php', [
    '-S', '127.0.0.1:19081', '-t', 'public',
    `${process.cwd()}/tests/integration/internal-email-router.php`,
  ], 'core-email-router')
  boundary = child('node', [
    `${api}/internal-readonly-fixtures/tls-boundary.mjs`, `${dir}/boundary.json`,
  ], 'tls-boundary-email')
  await ready('http://127.0.0.1:19081/txapi/public/site-config')

  const redisUrl = process.env.GATEWAY_REDIS_URL
    || `redis://127.0.0.1:${process.env.REDIS_PORT || '16380'}/22`
  assert.match(redisUrl, /\/22$/)
  redis = await RedisSecurity.connect(redisUrl)
  const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
  const keyFile = `${dir}/email-hpke-private.json`
  writeFileSync(keyFile, JSON.stringify(privateKey.export({ format: 'jwk' })), { mode: 0o600 })
  const crypto = await loadCryptoService({ APP_ENV: 'testing', GATEWAY_HPKE_MODE: 'optional',
    GATEWAY_HPKE_KEY_FILE: keyFile, GATEWAY_INTERNAL_EMAIL_CODE_ENABLED: 'true' }, redis)
  assert.ok(crypto)
  const config = gatewayConfig(true)
  const logger = createLogger({ level: 'info', sink: line => appendFileSync(`${dir}/gateway-email.log`, line + '\n') })
  const nativeFetcher = async (url, init) => { nativeCalls.push(new URL(url).pathname); return fetch(url, init) }
  const app = createGatewayApp(config, nativeFetcher, crypto, { limiter: redis }, { logger })
  gatewayServer = serve({ fetch: app.fetch, hostname: '127.0.0.1', port: 19591 })
  await ready('http://127.0.0.1:19591/healthz')

  const sdk = createTXBoardClient({
    baseURL: 'http://127.0.0.1:19591/gateway/v1', fetchImpl: async (url, options) => {
      sdkRequests.push({ path: new URL(url).pathname, body: options?.body,
        authorization: new Headers(options?.headers).get('authorization') })
      return fetch(url, options)
    },
    getToken: () => { throw new Error('Email must not request a visitor token') },
    timeoutMs: 5000, maxRetries: 0,
  })
  const before = decorate('email-state')
  await check('real SDK HPKE request crosses Gateway mTLS and returns the closed email result', async () => {
    assert.deepEqual(await sdk.auth.sendEmailCode({ email: 'internal-user@example.test' }), { sent: true })
    assert.deepEqual(sdkRequests.map(request => request.path), ['/gateway/v1/crypto/key', '/gateway/v1/secure/auth/email-code'])
    assert.ok(!sdkRequests[1].body.includes('internal-user@example.test'))
    assert.ok(sdkRequests.every(request => request.authorization === null))
    assert.equal(nativeCalls.length, 0)
    const after = decorate('email-state')
    successState = after
    assert.equal(after.mail_rows, before.mail_rows + 1)
    assert.equal(after.audit_rows, before.audit_rows + 1)
    assert.deepEqual(after.audit_actor_ids, [0])
    assert.equal(after.code_present, true)
    assert.ok(after.code_ttl > 0 && after.code_ttl <= 300)
    assert.ok(after.cooldown_ttl > 0 && after.cooldown_ttl <= 60)
    assert.deepEqual(after.business, before.business)
    assert.deepEqual(witnesses(), [{
      local_message_count: 1, body_code_matches: true,
      cache_code_matches: true, actual_array_transport: true,
    }])
  })

  await check('real Redis22 rejects the captured HPKE replay before mail or another audit', async () => {
    const state = decorate('email-state')
    const response = await fetch('http://127.0.0.1:19591/gateway/v1/secure/auth/email-code', {
      method: 'POST', headers: { 'content-type': 'application/json' }, body: sdkRequests[1].body,
    })
    assert.equal(response.status, 409)
    await response.arrayBuffer()
    assert.equal(witnesses().length, 1)
    const after = decorate('email-state')
    assert.equal(after.mail_rows, state.mail_rows); assert.equal(after.audit_rows, state.audit_rows)
    assert.equal(nativeCalls.length, 0)
  })

  await check('operation-bound HPKE refuses registration AAD at the email route with zero Core mail', async () => {
    const wrong = await encryptForOperation(crypto.publicKey(), { email: 'wrong-aad@example.test' }, 'register')
    const response = await fetch('http://127.0.0.1:19591/gateway/v1/secure/auth/email-code', {
      method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(wrong),
    })
    assert.equal(response.status, 400); await response.arrayBuffer()
    assert.equal(witnesses().length, 1); assert.equal(nativeCalls.length, 0)
  })

  await check('Core recipient policy refusal is preserved without a mail witness or Native fallback', async () => {
    decorate('email-whitelist')
    const countBefore = witnesses().length
    await assert.rejects(
      () => sdk.auth.sendEmailCode({ email: 'blocked@outside.test' }),
      error => error && error.status === 400,
    )
    assert.equal(witnesses().length, countBefore)
    assert.equal(nativeCalls.length, 0)
    decorate('email-prepare')
  })

  await check('native whitelist still rejects an unregistered synthetic-domain recipient', async () => {
    decorate('email-whitelist')
    try { assert.equal((await directTls(config, '{"email":"unregistered@example.test"}')).status, 400) }
    finally { decorate('email-prepare') }
    assert.equal(witnesses().length, 1)
  })

  await check('existing Core cooldown prevents a second actual message', async () => {
    assert.equal((await directTls(config, '{"email":"internal-user@example.test"}')).status, 400)
    assert.equal(witnesses().length, 1)
    assert.deepEqual(decorate('email-state').business, before.business)
  })

  await check('real mTLS boundary binds absence of actor and keeps Admin service forbidden', async () => {
    const input = '{"email":"actor-refusal@example.test"}'
    for (const authorization of ['', actors.credentials.user]) {
      assert.equal((await directTls(config, input, { authorization })).status, 400)
    }
    assert.equal((await directTls(config, input, {}, 'admin')).status, 403)
    assert.deepEqual([...observedTls], ['TLSv1.3'])
    assert.equal(witnesses().length, 1)
  })

  await check('exact raw JSON and original FormRequest reject widened duplicate and invalid inputs', async () => {
    assert.equal((await directTls(config, '{"email":"a@example.test","code":"123456"}')).status, 400)
    assert.equal((await directTls(config, '{"email":"a@example.test","email":"b@example.test"}')).status, 400)
    assert.equal((await directTls(config, '{"email":"bad"}')).status, 422)
    assert.equal(witnesses().length, 1)
  })

  await check('exhausted service budget refuses before sending any local mail', async () => {
    execFileSync('php', [`${api}/internal-readonly-fixtures/fixture.php`, 'rate-fill'], { env: process.env })
    try { assert.equal((await directTls(config, '{"email":"service-budget@example.test"}')).status, 429) }
    finally { execFileSync('php', [`${api}/internal-readonly-fixtures/fixture.php`, 'rate-reset'], { env: process.env }) }
    assert.equal(witnesses().length, 1)
  })

  await check('default-off mode leaves the encrypted email route absent', async () => {
    const disabled = gatewayConfig(false)
    const disabledApp = createGatewayApp(disabled, nativeFetcher, crypto, { limiter: redis }, { logger })
    const response = await disabledApp.request('/gateway/v1/secure/auth/email-code', { method: 'POST' })
    assert.equal(response.status, 404)
    assert.equal(nativeCalls.length, 0)
  })

  await check('email-only mode does not expose registration or plaintext email routes', async () => {
    for (const path of ['/gateway/v1/secure/auth/register', '/gateway/v1/auth/email-code']) {
      const response = await fetch('http://127.0.0.1:19591' + path, { method: 'POST' })
      assert.equal(response.status, 404); await response.arrayBuffer()
    }
    assert.equal(nativeCalls.length, 0)
  })

  await check('audit failure after native mail execution surfaces unknown result without SDK retry', async () => {
    decorate('login-rate-reset')
    const prior = decorate('email-state'), sentBefore = sdkRequests.length
    decorate('audit-write-fail')
    try {
      await assert.rejects(() => sdk.auth.sendEmailCode({ email: 'unknown-result@example.test' }),
        error => error?.status === 503 && error.needsStateConfirmation === true)
    } finally { decorate('audit-write-restore') }
    assert.equal(sdkRequests.length, sentBefore + 2)
    const after = decorate('email-state')
    assert.equal(after.mail_rows, prior.mail_rows + 1)
    assert.equal(after.audit_rows, prior.audit_rows)
    assert.deepEqual(after.business, prior.business)
    assert.equal(witnesses().length, 2)
    assert.ok(witnesses().every(row => row.actual_array_transport && row.body_code_matches && row.cache_code_matches))
    assert.equal(nativeCalls.length, 0)
  })

  await check('email opt-in remains local/testing-only and does not enable account workflows', async () => {
    assert.throws(() => loadConfig({ ...serverConfig, APP_ENV: 'production',
      GATEWAY_INTERNAL_EMAIL_CODE_ENABLED: 'true',
      GATEWAY_INTERNAL_USER_ENABLED: 'true',
      GATEWAY_INTERNAL_USER_CONFIG_FILE: `${dir}/gateway-email.json`,
    }))
    assert.throws(() => createGatewayApp({ ...config, internalEmailCodeEnabled: true }, fetch, crypto,
      { limiter: redis, accountWorkflows: true }, { logger }))
  })

  await check('Native and Internal email share the actual observed-IP ten-attempt throttle', async () => {
    decorate('login-rate-reset')
    for (let attempt = 0; attempt < 10; attempt++) {
      if (attempt % 2 === 0) {
        assert.equal((await directTls(config, '{"email":"bad"}')).status, 422)
      } else {
        const response = await fetch('http://127.0.0.1:19081/txapi/auth/email-code', {
          method: 'POST', headers: { 'content-type': 'application/json' }, body: '{"email":"bad"}',
        })
        assert.equal(response.status, 422); await response.arrayBuffer()
      }
    }
    assert.equal((await directTls(config, '{"email":"bad"}')).status, 429)
    assert.equal(witnesses().length, 2)
    decorate('login-rate-reset')
  })

  await check('offline mTLS service returns one unknown failure and never falls back to Native', async () => {
    stop(boundary); await sleep(200)
    const requestsBefore = sdkRequests.length
    await assert.rejects(() => sdk.auth.sendEmailCode({ email: 'offline@example.test' }),
      error => error?.status === 502 && error.needsStateConfirmation === true)
    assert.equal(sdkRequests.length, requestsBefore + 2)
    assert.equal(nativeCalls.length, 0); assert.equal(witnesses().length, 2)
  })

  const logText = readdirSync(dir).filter(name => name.endsWith('.log'))
    .map(name => readFileSync(`${dir}/${name}`, 'utf8')).join('\n')
  for (const secret of [...Object.values(actors.credentials), 'internal-user@example.test', 'blocked@outside.test',
    'unregistered@example.test', 'unknown-result@example.test', 'offline@example.test', 'fixture-password-only', 'PRIVATE KEY']) {
    assert.ok(!logText.includes(secret), `secret leaked to local logs: ${secret}`)
  }
  const state = decorate('email-state')
  const result = {
    current_only: true, phase: 'B5.10', production_acceptance: false,
    passed: checks.length, checks, committed_core_head: snapshot.committed_core_head,
    committed_gateway_head: JSON.parse(readFileSync('internal-user-snapshot.json', 'utf8')).committed_gateway_head,
    redis_database: 22, witnesses: witnesses(), first_success_state: successState,
    final_business: state.business, observed_tls_protocols: [...observedTls], native_fallback_calls: nativeCalls.length,
    cleanup: 'Processes stopped and fixture credentials removed.',
  }
  writeFileSync(`${dir}/result.json`, JSON.stringify(result, null, 2))
  process.stdout.write(`PASS ${checks.length} email Core/Gateway checks\n`)
} finally {
  gatewayServer?.close?.()
  if (redis) await redis.close()
  for (const server of servers) server.close?.()
  for (const process of processes) stop(process)
  await sleep(200)
  try { rmSync(`${dir}/newcerts`, { recursive: true, force: true }) } catch {}
  for (const file of readdirSync(dir)) {
    if (!file.endsWith('.log') && !['result.json', 'public-evidence.json'].includes(file)) {
      try { unlinkSync(`${dir}/${file}`) } catch {}
    }
  }
}
