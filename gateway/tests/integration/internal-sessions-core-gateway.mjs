import assert from 'node:assert/strict'
import https from 'node:https'
import { createHash, generateKeyPairSync } from 'node:crypto'
import { execFileSync, spawn } from 'node:child_process'
import { closeSync, openSync, readFileSync, readdirSync, unlinkSync, rmSync, writeFileSync } from 'node:fs'
import { setTimeout as sleep } from 'node:timers/promises'
import { createClient } from 'redis'
import { serve } from '@hono/node-server'
import { createGatewayApp } from '../../apps/gateway/dist/app.js'
import { loadConfig } from '../../apps/gateway/dist/config/env.js'
import { loadCryptoService } from '../../apps/gateway/dist/services/crypto.js'
import { RedisSecurity } from '../../apps/gateway/dist/services/redis-security.js'
import { createTXBoardClient } from '../../packages/theme-sdk/dist/index.js'
import { encryptForOperation } from '../../packages/theme-sdk/dist/crypto.js'

const api = process.env.INTERNAL_CORE_API, dir = process.env.INTERNAL_FIXTURE_DIR
assert.ok(api && dir)
const manifest = JSON.parse(readFileSync(`${api}/../manifest.json`, 'utf8'))
assert.equal(manifest.base, process.env.INTERNAL_EXPECTED_CORE_COMMIT)
const actors = JSON.parse(readFileSync(`${dir}/actors.json`, 'utf8'))
const processes = [], servers = [], checks = [], nativeCalls = [], tlsVersions = new Set()
const sha = value => createHash('sha256').update(value).digest('hex')
function child(command, args, label, env = {}) {
  const log = openSync(`${dir}/${label}.log`, 'a', 0o600)
  const proc = spawn(command, args, { cwd: api, env: { ...process.env, ...env }, detached: true, stdio: ['ignore', log, log] })
  closeSync(log); processes.push(proc); return proc
}
const stop = proc => { try { process.kill(-proc.pid, 'SIGTERM') } catch {} }
async function ready() {
  for (let i = 0; i < 60; i++) { try { if ((await fetch('http://127.0.0.1:19081/api/health')).ok) return } catch {} await sleep(100) }
  throw new Error('Core fixture did not become ready')
}
const fixture = (file, action) => JSON.parse(execFileSync('php', [file, api, action], { env: process.env, encoding: 'utf8' }))
const decorate = action => fixture(`${api}/../scripts/tests/internal-sessions/fixture.php`, action)
const bound = action => fixture('tests/integration/internal-sessions-fixture.php', action)
const mutate = (...args) => execFileSync('php', [`${api}/internal-readonly-fixtures/fixture.php`, ...args], { env: process.env })
async function check(name, run) { await run(); checks.push(name); process.stdout.write(`ok ${checks.length}: ${name}\n`) }
const settings = { TXBOARD_UPSTREAM_URL: 'http://127.0.0.1:19081', TXBOARD_ALLOW_PRIVATE_HTTP: 'true', APP_ENV: 'testing' }
function config(enabled = true) {
  const file = `${dir}/gateway-sessions.json`
  writeFileSync(file, JSON.stringify({ origin: 'https://127.0.0.1:19443', servername: 'internal-core.local',
    ca_path: `${dir}/ca.pem`, crl_path: `${dir}/ca.crl`, cert_path: `${dir}/user.pem`, key_path: `${dir}/user.key` }))
  return loadConfig({ ...settings, GATEWAY_INTERNAL_USER_ENABLED: 'true', GATEWAY_INTERNAL_USER_CONFIG_FILE: file,
    GATEWAY_INTERNAL_SESSIONS_ENABLED: enabled ? 'true' : 'false' })
}
function directTls(operation, token, cert = 'user') {
  const body = JSON.stringify({ contract_version: '1', request_id: '00000000-0000-4000-8000-000000000000',
    deadline_at: Math.floor(Date.now() / 1000) + 5, input: {} })
  return new Promise((resolve, reject) => {
    const req = https.request({ hostname: '127.0.0.1', port: 19443, servername: 'internal-core.local',
      ca: readFileSync(`${dir}/ca.pem`), cert: readFileSync(`${dir}/${cert}.pem`), key: readFileSync(`${dir}/${cert}.key`),
      minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3', agent: false, method: 'POST', path: '/txapi/internal/v1/operations/' + operation,
      headers: { authorization: token, 'content-type': 'application/json', 'content-length': Buffer.byteLength(body) } }, response => {
      tlsVersions.add(response.socket.getProtocol()); const chunks = []
      response.on('data', chunk => chunks.push(chunk)); response.on('end', () => resolve({ status: response.statusCode, body: JSON.parse(Buffer.concat(chunks)) }))
    }); req.setTimeout(5000, () => req.destroy(new Error('Fixture TLS timeout'))); req.on('error', reject); req.end(body)
  })
}
let core, safety, adminRedis
try {
  const remaining = decorate('remaining').credential
  core = child('php', ['-S', '127.0.0.1:19081', '-t', 'public', 'public/index.php'], 'core-sessions-gateway')
  child('node', [`${api}/internal-readonly-fixtures/tls-boundary.mjs`, `${dir}/boundary.json`], 'boundary-sessions-gateway')
  await ready()
  safety = await RedisSecurity.connect(process.env.GATEWAY_REDIS_URL)
  adminRedis = createClient({ url: process.env.GATEWAY_REDIS_URL }); adminRedis.on('error', () => {}); await adminRedis.connect()
  const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
  writeFileSync(`${dir}/hpke.json`, JSON.stringify(privateKey.export({ format: 'jwk' })), { mode: 0o600 })
  const crypto = await loadCryptoService({ APP_ENV: 'testing', GATEWAY_HPKE_MODE: 'optional',
    GATEWAY_HPKE_KEY_FILE: `${dir}/hpke.json`, GATEWAY_INTERNAL_SESSIONS_ENABLED: 'true' }, safety)
  const native = async () => { nativeCalls.push('refused'); throw new Error('Native transport must never be called') }
  const app = createGatewayApp(config(), native, crypto, { limiter: safety })
  const server = serve({ fetch: app.fetch, hostname: '127.0.0.1', port: 19338 }); servers.push(server)
  const baseURL = 'http://127.0.0.1:19338/gateway/v1'
  const sdkFor = token => createTXBoardClient({ baseURL, getToken: () => token, maxRetries: 0 })
  const sdk = sdkFor(actors.credentials.user), other = sdkFor(actors.credentials.other)
  const list = () => sdk.auth.sessions.list()
  const post = (operation, sealed, token = actors.credentials.user) => fetch(`${baseURL}/secure/auth/${operation}`, {
    method: 'POST', headers: { authorization: token, 'content-type': 'application/json' }, body: JSON.stringify(sealed) })
  let currentId, expiredId
  await check('independent default-off routes and plaintext mutations are absent without Native calls', async () => {
    const closed = createGatewayApp(config(false), native, crypto, { limiter: safety })
    assert.equal((await closed.request('/gateway/v1/user/sessions', { headers: { authorization: actors.credentials.user } })).status, 404)
    assert.equal((await closed.request('/gateway/v1/secure/auth/session-logout', { method: 'POST', headers: { authorization: actors.credentials.user } })).status, 404)
    assert.equal((await fetch(`${baseURL}/auth/session-logout`, { method: 'POST' })).status, 404)
  })
  await check('real SDK Gateway TLS1.3 Core owner list has bounded decimal metadata and no credentials', async () => {
    const result = await list(); assert.equal(result.items.length, 3)
    currentId = result.items.find(row => row.name === 'internal-fixture-user').id
    expiredId = result.items.find(row => row.name === 'expired').id
    const raw = await fetch(`${baseURL}/user/sessions`, { headers: { authorization: actors.credentials.user } })
    assert.ok(raw.headers.get('cache-control').includes('no-store'))
    for (const row of result.items) {
      assert.match(row.id, /^[1-9][0-9]{0,17}$/)
      assert.deepEqual(Object.keys(row).sort(), ['id', 'name', 'abilities', 'last_used_at', 'created_at', 'updated_at', 'expires_at'].sort())
    }
    for (const secret of Object.values(actors.credentials)) assert.ok(!JSON.stringify(result).includes(secret))
    assert.equal((await directTls('user.session.list', actors.credentials.user)).status, 200)
    assert.equal((await directTls('user.session.list', actors.credentials.user, 'admin')).status, 403)
  })
  await check('actual User authorization refuses admin, expired, banned and bare Core before mutation', async () => {
    await assert.rejects(() => sdkFor(actors.credentials.admin).auth.logout(), error => error.status === 403)
    await assert.rejects(() => sdkFor(actors.credentials.expired).auth.sessions.list(), error => error.status === 401)
    mutate('mutate', 'user', 'ban')
    try { await assert.rejects(() => sdk.auth.logout(), error => error.status === 403) } finally { mutate('mutate', 'user', 'unban') }
    const response = await fetch('http://127.0.0.1:19081/txapi/internal/v1/operations/user.session.logout', {
      method: 'POST', headers: { authorization: actors.credentials.user, 'content-type': 'application/json' },
      body: JSON.stringify({ contract_version: '1', request_id: '00000000-0000-4000-8000-000000000000',
        deadline_at: Math.floor(Date.now() / 1000) + 5, input: {} }) })
    assert.equal(response.status, 401); assert.equal(decorate('state').user_sessions, 3)
  })
  await check('real 101-row owner list is refused and exactly 100 rows remain accepted without truncation', async () => {
    bound('bound-101')
    try {
      await assert.rejects(list, error => error.status === 409)
      bound('bound-100'); assert.equal((await list()).items.length, 100)
    } finally { bound('bound-clean') }
    assert.equal(decorate('state').user_sessions, 3)
  })
  await check('foreign owner revoke retains native 404 and preserves both users sessions', async () => {
    const foreignId = (await other.auth.sessions.list()).items[0].id
    await assert.rejects(() => sdk.auth.sessions.revoke(foreignId), error => error.status === 404)
    assert.equal((await other.auth.sessions.list()).items.length, 1); assert.equal((await list()).items.length, 3)
  })
  await check('actual encrypted revoke succeeds once, replay and wrong AAD reject, repeated native ID is 404', async () => {
    const sealed = await encryptForOperation(crypto.publicKey(), { session_id: expiredId }, 'session-revoke')
    assert.equal((await post('session-revoke', sealed)).status, 200)
    assert.equal((await post('session-revoke', sealed)).status, 409)
    assert.equal((await post('session-revoke', await encryptForOperation(crypto.publicKey(), {}, 'session-logout'))).status, 400)
    assert.equal((await post('session-logout', await encryptForOperation(crypto.publicKey(), { owner_id: '1' }, 'session-logout'))).status, 400)
    await assert.rejects(() => sdk.auth.sessions.revoke(expiredId), error => error.status === 404)
    assert.equal((await list()).items.length, 2)
  })
  await check('SQL delete failure remains private, with the same native current credential usable', async () => {
    decorate('delete-reject')
    try { await assert.rejects(() => sdk.auth.logout(), error => error.status === 502 && !error.message.includes('fixture-private')) }
    finally { decorate('delete-allow') }
    assert.equal((await list()).items.length, 2)
  })
  await check('real Redis credential and IP budgets fail closed and retain case-sensitive credential keys', async () => {
    const key = 'txbgw:v1:rate:sessions:' + sha(actors.credentials.user)
    await adminRedis.set(key, '30', { PX: 60000 })
    await assert.rejects(list, error => error.status === 429); await adminRedis.del(key)
    const ipKey = 'txbgw:v1:rateip:sessions:' + sha('0.0.0.0')
    await adminRedis.set(ipKey, '60', { PX: 60000 })
    await assert.rejects(list, error => error.status === 429); await adminRedis.del(ipKey)
    await safety.check('sessions', 'Bearer CaseFixture', 'fixture-ip')
    await safety.check('sessions', 'Bearer casefixture', 'fixture-ip')
    assert.equal(await adminRedis.get('txbgw:v1:rate:sessions:' + sha('Bearer CaseFixture')), '1')
    assert.equal(await adminRedis.get('txbgw:v1:rate:sessions:' + sha('Bearer casefixture')), '1')
    assert.equal((await list()).items.length, 2)
  })
  await check('audit failure conceals committed logout and another actual credential confirms deletion without retry', async () => {
    decorate('audit-reject')
    try { await assert.rejects(() => sdk.auth.logout(), error => error.status === 503 && error.needsStateConfirmation === true) }
    finally { decorate('audit-allow') }
    await assert.rejects(list, error => error.status === 401)
    const result = await sdkFor(remaining).auth.sessions.list(); assert.equal(result.items.length, 1)
    assert.ok(!result.items.some(row => row.id === currentId)); assert.equal(decorate('state').user_sessions, 1)
  })
  await check('successful final logout keeps SDK token caller-owned and leaves other user untouched', async () => {
    assert.deepEqual(await sdkFor(remaining).auth.logout(), { ok: true })
    await assert.rejects(() => sdkFor(remaining).auth.sessions.list(), error => error.status === 401)
    assert.equal(decorate('state').user_sessions, 0); assert.equal(decorate('state').other_sessions, 1)
  })
  await check('disabled Core gate and offline TLS cannot reach Native fallback', async () => {
    stop(core); await sleep(200)
    core = child('php', ['-S', '127.0.0.1:19081', '-t', 'public', 'public/index.php'], 'core-sessions-disabled', { CORE_INTERNAL_SESSIONS_ENABLED: 'false' })
    await ready(); await assert.rejects(() => other.auth.sessions.list(), error => error.status === 404)
    stop(processes[1]); await sleep(200)
    await assert.rejects(() => other.auth.sessions.list(), error => error.status === 502 && error.needsStateConfirmation === true)
    assert.equal(nativeCalls.length, 0)
  })
  await check('closed Redis safety store refuses before any business transport', async () => {
    await safety.close(); await assert.rejects(() => other.auth.sessions.list(), error => error.status === 503)
    assert.equal(nativeCalls.length, 0)
  })
  const logs = readdirSync(dir).filter(name => name.endsWith('.log')).map(name => readFileSync(`${dir}/${name}`, 'utf8')).join('\n')
  for (const secret of [remaining, ...Object.values(actors.credentials), 'PRIVATE KEY', 'fixture-private-session-sql-error']) assert.ok(!logs.includes(secret))
  writeFileSync(`${dir}/result.json`, JSON.stringify({ phase: 'B5.14', production_acceptance: false, passed: checks.length, checks,
    core_base: manifest.base, observed_tls_protocols: [...tlsVersions], redis_database: Number(process.env.INTERNAL_GATEWAY_REDIS_DB), native_fallback_calls: nativeCalls.length,
    final_state: decorate('state'), audit: JSON.parse(mutate('audit-check')) }, null, 2))
  process.stdout.write(`PASS ${checks.length} real session Gateway/Core checks\n`)
} finally {
  for (const server of servers) server.close()
  if (safety) { try { await safety.close() } catch {} }
  if (adminRedis) adminRedis.destroy()
  for (const proc of processes) stop(proc)
  await sleep(200)
  rmSync(`${dir}/newcerts`, { recursive: true, force: true })
  for (const file of readdirSync(dir)) if (!file.endsWith('.log') && !['result.json', 'public-evidence.json'].includes(file)) {
    try { unlinkSync(`${dir}/${file}`) } catch {}
  }
}
