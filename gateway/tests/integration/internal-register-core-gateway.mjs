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
assert.equal(process.env.DB_DATABASE, 'txboard_internal_test')
const snapshot = JSON.parse(readFileSync(`${api}/../manifest.json`, 'utf8'))
const expectedCoreCommit = process.env.INTERNAL_EXPECTED_CORE_COMMIT
assert.match(expectedCoreCommit || '', /^[0-9a-f]{40}$/, 'An explicit fixed Core commit is required')
assert.equal(snapshot.base, expectedCoreCommit)
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
  const configPath = `${dir}/gateway-registration.json`
  writeFileSync(configPath, JSON.stringify({
    origin: 'https://127.0.0.1:19443', servername: 'internal-core.local',
    ca_path: `${dir}/ca.pem`, crl_path: `${dir}/ca.crl`,
    cert_path: `${dir}/user.pem`, key_path: `${dir}/user.key`,
  }))
  return loadConfig({
    ...serverConfig,
    GATEWAY_INTERNAL_USER_ENABLED: 'true',
    GATEWAY_INTERNAL_USER_CONFIG_FILE: configPath,
    GATEWAY_INTERNAL_REGISTER_ENABLED: enabled ? 'true' : 'false',
  })
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
      method: 'POST', path: '/txapi/internal/v1/operations/auth.register',
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

let core, boundary, gatewayServer, redis
try {
  decorate('registration-prepare')
  core = child('php', ['-S', '127.0.0.1:19081', '-t', 'public', `${process.cwd()}/tests/integration/internal-email-router.php`], 'core-registration')
  boundary = child('node', [`${api}/internal-readonly-fixtures/tls-boundary.mjs`, `${dir}/boundary.json`], 'boundary-registration')
  await ready('http://127.0.0.1:19081/txapi/public/site-config')
  const redisUrl = process.env.GATEWAY_REDIS_URL
  const redisDatabase = Number(process.env.INTERNAL_REGISTRATION_REDIS_DB ?? '22')
  assert.ok(Number.isInteger(redisDatabase) && redisDatabase >= 0 && redisDatabase <= 99)
  assert.equal(new URL(redisUrl).pathname, '/' + redisDatabase)
  redis = await RedisSecurity.connect(redisUrl)
  const key = generateKeyPairSync('ec', { namedCurve: 'prime256v1' }).privateKey.export({ format: 'jwk' })
  writeFileSync(`${dir}/hpke.jwk`, JSON.stringify(key), { mode: 0o600 })
  const crypto = await loadCryptoService({ APP_ENV: 'testing', GATEWAY_HPKE_MODE: 'optional',
    GATEWAY_HPKE_KEY_FILE: `${dir}/hpke.jwk`, GATEWAY_INTERNAL_REGISTER_ENABLED: 'true' }, redis)
  const config = gatewayConfig()
  const logger = createLogger({ level: 'info', sink: line => appendFileSync(`${dir}/gateway-registration.log`, line + '\n') })
  const nativeFetcher = (url, init) => { nativeCalls.push(new URL(url).pathname); return fetch(url, init) }
  const app = createGatewayApp(config, nativeFetcher, crypto, { limiter: redis }, { logger })
  gatewayServer = serve({ fetch: app.fetch, hostname: '127.0.0.1', port: 19591 })
  await ready('http://127.0.0.1:19591/healthz')
  const sdk = createTXBoardClient({ baseURL: 'http://127.0.0.1:19591/gateway/v1', timeoutMs: 5000, maxRetries: 0,
    getToken: () => { throw new Error('Registration must not request a visitor token') },
    fetchImpl: async (url, options) => {
      sdkRequests.push({ path: new URL(url).pathname, body: options?.body,
        authorization: new Headers(options?.headers).get('authorization') })
      return fetch(url, options)
    } })
  const input = { email: 'registered-owner@example.test', password: 'fixture-registration-password',
    invite_code: 'fixture-registration-invite', email_code: '123456' }
  const before = decorate('registration-state')
  await check('SDK HPKE to real TLS1.3 Core creates the native invited trial account and session', async () => {
    const result = await sdk.auth.register(input)
    assert.deepEqual(Object.keys(result), ['auth_data'])
    assert.match(result.auth_data, /^Bearer [A-Za-z0-9|._-]+$/)
    assert.deepEqual(sdkRequests.map(r => r.path), ['/gateway/v1/crypto/key', '/gateway/v1/secure/auth/register'])
    for (const secret of Object.values(input)) assert.ok(!sdkRequests[1].body.includes(secret))
    assert.ok(sdkRequests.every(r => r.authorization === null))
    const state = decorate('registration-state')
    for (const flag of ['owner_exists', 'password_matches', 'invite_matches', 'invite_used', 'trial_matches', 'audit_actors_guest']) assert.equal(state[flag], true)
    assert.equal(state.owner_sessions, 1); assert.equal(state.verification_code_present, false)
    assert.equal(state.business.users, before.business.users + 1)
    assert.equal(state.business.orders, before.business.orders)
    assert.equal(state.audit_rows, before.audit_rows + 1)
    assert.equal(nativeCalls.length, 0)
  })
  await check('real Redis nonce replay rejects the captured ciphertext before another registration', async () => {
    const state = decorate('registration-state')
    const response = await fetch('http://127.0.0.1:19591/gateway/v1/secure/auth/register', {
      method: 'POST', headers: { 'content-type': 'application/json' }, body: sdkRequests[1].body })
    assert.equal(response.status, 409); await response.arrayBuffer()
    assert.deepEqual(decorate('registration-state'), state)
  })
  await check('native duplicate account rejects a deliberately new request without another token', async () => {
    decorate('registration-duplicate-code')
    await assert.rejects(() => sdk.auth.register(input), error => error?.status === 409)
    assert.equal(decorate('registration-state').owner_sessions, 1)
    assert.equal(nativeCalls.length, 0)
  })
  await check('real HPKE operation binding and unknown role fail before Core execution', async () => {
    const state = decorate('registration-state')
    for (const [operation, body] of [['email-code', input], ['register', { ...input, role: 'admin' }]]) {
      const sealed = await encryptForOperation(crypto.publicKey(), body, operation)
      const response = await fetch('http://127.0.0.1:19591/gateway/v1/secure/auth/register', {
        method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(sealed) })
      assert.equal(response.status, 400); await response.arrayBuffer()
    }
    assert.deepEqual(decorate('registration-state'), state)
  })
  await check('real mTLS rejects admin service and explicit visitor actor, strict Core JSON rejects duplicate keys', async () => {
    const raw = JSON.stringify(input)
    assert.equal((await directTls(config, raw, {}, 'admin')).status, 403)
    assert.equal((await directTls(config, raw, { authorization: 'Bearer visitor' })).status, 400)
    assert.equal((await directTls(config, raw.replace(/}$/, ',"password":"duplicate"}'))).status, 400)
    assert.equal((await directTls(config, JSON.stringify({ email: 'outside@example.com', password: input.password }))).status, 400)
    assert.deepEqual([...observedTls], ['TLSv1.3'])
  })
  await check('independent flags keep email-code and plaintext registration absent', async () => {
    for (const path of ['/gateway/v1/secure/auth/email-code', '/gateway/v1/auth/register']) {
      assert.equal((await app.request(path, { method: 'POST' })).status, 404)
    }
    const disabled = createGatewayApp(gatewayConfig(false), nativeFetcher, crypto, { limiter: redis })
    assert.equal((await disabled.request('/gateway/v1/secure/auth/register', { method: 'POST' })).status, 404)
    assert.equal(nativeCalls.length, 0)
  })
  await check('audit failure hides a committed synthetic account and token, SDK exposes an unknown outcome', async () => {
    decorate('registration-open-invite'); decorate('registration-audit-reject')
    const count = sdkRequests.length
    try {
      await assert.rejects(() => sdk.auth.register({ email: 'unknown-result@example.test', password: input.password, email_code: '123456' }),
        error => error?.status === 503 && error.needsStateConfirmation === true)
    } finally { decorate('registration-audit-allow') }
    const state = decorate('registration-state')
    assert.equal(state.unknown_exists, true); assert.equal(state.unknown_sessions, 1)
    assert.equal(sdkRequests.length, count + 2); assert.equal(nativeCalls.length, 0)
  })
  await check('native registration stop still rejects before another account is created', async () => {
    decorate('registration-stop')
    const state = decorate('registration-state')
    await assert.rejects(() => sdk.auth.register({ email: 'stopped@example.test', password: input.password }), error => error?.status === 422)
    assert.deepEqual(decorate('registration-state').business, state.business)
  })
  await check('offline TLS boundary produces one unknown outcome without native fallback', async () => {
    stop(boundary); await sleep(200)
    const count = sdkRequests.length
    await assert.rejects(() => sdk.auth.register({ email: 'offline@example.test', password: input.password }),
      error => error?.status === 502 && error.needsStateConfirmation === true)
    assert.equal(sdkRequests.length, count + 2); assert.equal(nativeCalls.length, 0)
  })
  const logText = readdirSync(dir).filter(name => name.endsWith('.log')).map(name => readFileSync(`${dir}/${name}`, 'utf8')).join('\n')
  for (const secret of [...Object.values(actors.credentials), ...Object.values(input), 'unknown-result@example.test', 'PRIVATE KEY']) {
    assert.ok(!logText.includes(secret), `Secret leaked to local logs`)
  }
  writeFileSync(`${dir}/result.json`, JSON.stringify({ current_only: true, phase: 'B5.11', production_acceptance: false,
    passed: checks.length, checks, core_base: expectedCoreCommit, observed_tls_protocols: [...observedTls],
    redis_database: redisDatabase, native_fallback_calls: nativeCalls.length, final_state: decorate('registration-state'),
    cleanup: 'Fixture processes stopped and credentials removed.' }, null, 2))
  process.stdout.write(`PASS ${checks.length} registration Core/Gateway checks\n`)
} finally {
  gatewayServer?.close?.()
  if (redis) await redis.close()
  for (const process of processes) stop(process)
  await sleep(200)
  try { rmSync(`${dir}/newcerts`, { recursive: true, force: true }) } catch {}
  for (const file of readdirSync(dir)) {
    if (!file.endsWith('.log') && !['result.json', 'public-evidence.json'].includes(file)) {
      try { unlinkSync(`${dir}/${file}`) } catch {}
    }
  }
}
