import assert from 'node:assert/strict'
import { readFileSync, openSync, closeSync } from 'node:fs'
import { randomUUID } from 'node:crypto'
import { spawn, spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'
import { createCoreUserClient, createCoreAdminClient } from '../../../packages/core-sdk/dist/index.js'
import { verifyRendererDelivery } from './renderer-delivery.mjs'
import { createSupportTicket, verifyAdminSupport } from './support-workflow.mjs'
import { verifyFinancialWorkflows } from './financial-workflow.mjs'

const root = process.env.TXBOARD_CORE_TEST_ROOT
const scratch = process.env.CORE_BROWSER_INTEGRATION_LOG_DIR
assert.ok(root && scratch)
const sourceHead = readFileSync(`${root}/.core-browser-integration-snapshot`, 'utf8').trim()
assert.match(sourceHead, /^[a-f0-9]{40}$/)
const nodeSource = JSON.parse(readFileSync(`${root}/.node-integration-snapshot`, 'utf8'))
assert.equal(nodeSource.repository, 'ANRCM0/txnode')
assert.match(nodeSource.revision, /^[a-f0-9]{40}$/)
const service = JSON.parse(readFileSync(`${scratch}/browser-user.private.json`, 'utf8'))
const servers = []
const devServers = []
const log = openSync(`${scratch}/core-browser-http.log`, 'a')
const php = spawn('php', ['-S', '127.0.0.1:18081', '-t', 'public', 'public/index.php'], {
  cwd: root, stdio: ['ignore', log, log], env: process.env,
})
let checks = 0
try {
  for (let attempt = 0; ; attempt++) {
    if (php.exitCode !== null) throw new Error('Core exited before readiness')
    try { if ((await fetch('http://127.0.0.1:18081/gateway/v1/bootstrap')).ok) break } catch {}
    if (attempt > 50) throw new Error('Core browser ingress readiness timed out')
    await new Promise(r => setTimeout(r, 100))
  }
  // Exercise each actual Vite configuration against the same real Core HTTP ingress.
  for (const [app, variable] of [['admin', 'VITE_GATEWAY_TARGET'], ['user', 'VITE_GATEWAY_PROXY_TARGET']]) {
    const previous = process.env[variable]
    process.env[variable] = 'http://127.0.0.1:18081'
    let dev
    try {
      const appRoot = fileURLToPath(new URL(`../../../web/${app}/`, import.meta.url))
      dev = await createServer({ root: appRoot, configFile: `${appRoot}/vite.config.ts`,
        server: { host: '127.0.0.1', port: 0, hmr: false }, logLevel: 'error' })
      devServers.push(dev)
      await dev.listen()
    } finally {
      if (previous === undefined) delete process.env[variable]
      else process.env[variable] = previous
    }
    const address = dev.httpServer.address()
    assert.ok(address && typeof address === 'object')
    const devOrigin = `http://127.0.0.1:${address.port}`
    const bootstrapURL = `${devOrigin}/gateway/v1/bootstrap`
    assert.equal((await fetch(bootstrapURL)).status, 200); checks++
    assert.equal((await fetch(bootstrapURL, { headers: { Origin: devOrigin } })).status, 200); checks++
    assert.equal((await fetch(bootstrapURL, { headers: { Origin: devOrigin, 'Sec-Fetch-Site': 'cross-site' } })).status, 403); checks++
    // Even a forged Core target Origin must fail the original dev-origin check.
    for (const origin of ['https://untrusted.example', 'http://127.0.0.1:18081', 'null']) {
      assert.equal((await fetch(bootstrapURL, { headers: { Origin: origin } })).status, 403); checks++
    }
  }
  const baseURL = 'http://127.0.0.1:18081/gateway/v1/core/user'
  const token = service.owner, otherToken = service.outsider
  const client = createCoreUserClient({ baseURL, getToken: () => token })
  const other = createCoreUserClient({ baseURL, getToken: () => otherToken })
  const caps = await client.capabilities()
  const surface = JSON.parse(readFileSync(`${root}/resources/contracts/browser-surfaces.v1.json`, 'utf8'))
  const expectedUser = [...surface.user].sort()
  assert.deepEqual(caps.operations.map(row => row.id).sort(), expectedUser); checks++
  const offers = await client.call('catalog.offers.list', {})
  assert.ok(offers.length > 0); checks++
  const actor = await client.call('identity.account.get', {})
  assert.equal(typeof actor.id, 'string'); checks++
  const key = randomUUID(), input = { offer_id: offers[0].offer_id }
  const lost = createCoreUserClient({ baseURL, getToken: () => token, fetchImpl: async (...args) => {
    const response = await fetch(...args)
    assert.ok(response.ok)
    await response.arrayBuffer()
    throw new Error('Simulated browser response loss after actual Core completion')
  } })
  await assert.rejects(lost.call('commerce.orders.create', input, { idempotencyKey: key }),
    e => e.code === 'RESULT_UNKNOWN' && e.needsStateConfirmation); checks++
  const created = await client.callResult('commerce.orders.create', input, { idempotencyKey: key })
  const replay = await client.callResult('commerce.orders.create', input, { idempotencyKey: key })
  assert.deepEqual(replay.data, created.data); checks++
  const history = await client.callResult('billing.order-payment-attempts.list', { trade_no: created.data.trade_no })
  assert.deepEqual(history.data, []); assert.equal(history.meta.has_unresolved, false); checks++
  await assert.rejects(other.call('billing.order-payment-attempts.list', { trade_no: created.data.trade_no }), e => e.status === 404); checks++
  const events = await client.callResult('commerce.orders.events.list', { trade_no: created.data.trade_no })
  assert.deepEqual(events.data.map(row => row.type), ['created']); assert.equal(events.meta.history_complete, true); checks++
  await assert.rejects(other.call('commerce.orders.get', { trade_no: created.data.trade_no }), e => e.status === 404); checks++
  const orders = await client.callResult('commerce.orders.list', { page: 1, per_page: 1 })
  assert.equal(orders.meta.page, 1); assert.equal(orders.meta.total, 1); checks++
  const recharges = await client.callResult('billing.recharges.list', {})
  assert.equal(recharges.meta.next_after_id, null); checks++
  const usage = await client.callResult('usage.mine.list', {})
  assert.equal(usage.meta.schema, 'native-usage-periods/v1'); checks++
  const wallet = await client.call('billing.wallet.get', {})
  assert.equal(wallet.schema, 'native-wallet/v1'); checks++
  await client.call('commerce.orders.cancel', { trade_no: created.data.trade_no }, { idempotencyKey: randomUUID() }); checks++
  const free = offers.find(offer => offer.price_minor === '0')
  assert.ok(free)
  const freeOrder = await client.call('commerce.orders.create', { offer_id: free.offer_id }, { idempotencyKey: randomUUID() })
  const freeKey = randomUUID()
  const paid = await client.call('commerce.orders.checkout', { trade_no: freeOrder.trade_no }, { idempotencyKey: freeKey })
  assert.equal(paid.type, -1); assert.equal(paid.data, true)
  assert.deepEqual(await client.call('commerce.orders.checkout', { trade_no: freeOrder.trade_no }, { idempotencyKey: freeKey }), paid); checks++
  const renderer = await verifyRendererDelivery(client, other, { root, scratch, actorId: actor.id, token, servers })
  checks += renderer.checks
  const support = await createSupportTicket(client, other)
  checks += support.checks
  const provision = spawnSync('php', ['scripts/tests/core-browser/core-fixture.php', root, 'browser-admin'], { encoding: 'utf8' })
  assert.equal(provision.status, 0, provision.stderr)
  const adminService = JSON.parse(provision.stdout)
  let adminToken, adminPath
  const admin = createCoreAdminClient({ baseURL: 'http://127.0.0.1:18081/gateway/v1/core/admin',
    getToken: () => adminToken, getAdminPath: () => adminPath })
  const expectedAdmin = [...surface.admin].sort()
  assert.deepEqual((await admin.capabilities()).operations.map(row => row.id).sort(), expectedAdmin); checks++
  await assert.rejects(admin.call('identity.admin-session.create', { email: actor.email, password: 'integration-password-2026' },
    { idempotencyKey: randomUUID() }), e => e.status === 403); checks++
  const loggedIn = await admin.call('identity.admin-session.create', { email: adminService.email, password: 'admin-integration-password' }, { idempotencyKey: randomUUID() })
  adminToken = loggedIn.auth_data; adminPath = loggedIn.secure_path
  assert.equal(adminPath, 'sdk_admin_fixture'); checks++
  assert.equal((await admin.call('identity.admin.get', {})).id, adminService.id); checks++
  checks += await verifyAdminSupport(admin, support.ticket, { root, adminId: adminService.id })
  checks += await verifyFinancialWorkflows(client, other, admin, { root, actorId: actor.id, adminId: adminService.id, offers })
  const sessions = await client.callResult('identity.sessions.list', {})
  assert.equal(sessions.meta.has_more, false); assert.ok(sessions.data.length > 0); checks++
  await client.call('identity.sessions.revoke', { session_id: service.secondary }, { idempotencyKey: randomUUID() })
  assert.ok(!(await client.call('identity.sessions.list', {})).some(row => row.id === service.secondary)); checks++
  // The owner revokes the actual current session. A fresh Core read must fail.
  await client.call('identity.session.end', {}, { idempotencyKey: randomUUID() }); checks++
  await assert.rejects(client.call('identity.account.get', {}), e => e.status === 401 && e.code === 'UNAUTHORIZED'); checks++
  const nodes = await admin.callResult('network.nodes.list', { page: 1, per_page: 1 })
  assert.equal(nodes.meta.page, 1); assert.ok(nodes.data.length > 0)
  assert.equal(typeof nodes.data[0].id, 'string'); assert.ok(!('protocol_settings' in nodes.data[0])); checks++
  const machines = await admin.callResult('network.machines.list', {})
  assert.equal(machines.meta.total, 0); checks++
  const site = await admin.call('settings.site.get', {})
  assert.equal(typeof site.site.app_name, 'string'); assert.ok(!('server_token' in site.site)); checks++
  const accounts = await admin.callResult('identity.accounts.list', { email: adminService.email, per_page: 1 })
  assert.equal(accounts.meta.total, 1); assert.equal(accounts.data[0].id, adminService.id); checks++
  assert.ok(!('password' in accounts.data[0])); checks++
  const target = await admin.call('identity.accounts.get', { user_id: actor.id })
  assert.equal(target.id, actor.id); assert.equal(target.credential_status, 'provider_managed'); checks++
  await assert.rejects(admin.call('identity.accounts.get', { user_id: '9223372036854775807' }), e => e.status === 404); checks++
  const nativeOrders = await admin.callResult('commerce.admin-orders.list', { user_id: actor.id, per_page: 1 })
  assert.ok(nativeOrders.meta.total >= 2); assert.equal(nativeOrders.data[0].user_id, actor.id); checks++
  const nativeDetail = await admin.call('commerce.admin-orders.get', { trade_no: nativeOrders.data[0].trade_no })
  assert.equal(nativeDetail.currency, 'CNY'); assert.equal(typeof nativeDetail.amount_minor, 'string'); checks++
  assert.ok(!('purchase_terms_json' in nativeDetail)); checks++
  const newAccount = await admin.call('identity.accounts.create', {
    email: `admin-created-${randomUUID()}@example.test`, password: 'created-integration-password',
  }, { idempotencyKey: randomUUID() })
  assert.equal(typeof newAccount.id, 'string'); assert.ok(!('password' in newAccount)); checks++
  const orderFixture = spawnSync('php', ['scripts/tests/core-browser/core-fixture.php', root, 'admin-order-target', offers[0].offer_id], { encoding: 'utf8' })
  assert.equal(orderFixture.status, 0, orderFixture.stderr)
  const adminOrder = JSON.parse(orderFixture.stdout)
  const closeKey = randomUUID(), closeInput = { trade_no: adminOrder.trade_no, expected_status: 0, reason: 'http_reviewed' }
  const lostAdmin = createCoreAdminClient({ baseURL: 'http://127.0.0.1:18081/gateway/v1/core/admin',
    getToken: () => adminToken, getAdminPath: () => adminPath, fetchImpl: async (...args) => {
      const response = await fetch(...args)
      assert.ok(response.ok); await response.arrayBuffer()
      throw new Error('Simulated lost admin acknowledgement after owner commit')
    } })
  await assert.rejects(lostAdmin.call('commerce.admin-orders.close', closeInput, { idempotencyKey: closeKey }),
    e => e.code === 'RESULT_UNKNOWN' && e.needsStateConfirmation); checks++
  const closed = await admin.call('commerce.admin-orders.close', closeInput, { idempotencyKey: closeKey })
  assert.equal(closed.status, 2)
  assert.deepEqual(await admin.call('commerce.admin-orders.close', closeInput, { idempotencyKey: closeKey }), closed); checks++
  const audit = await admin.callResult('commerce.admin-orders.audit.list', { trade_no: adminOrder.trade_no })
  assert.equal(audit.data.length, 1); assert.equal(audit.data[0].action, 'close')
  assert.equal(audit.data[0].actor_id, adminService.id); checks++
  await assert.rejects(admin.call('commerce.admin-orders.close', { ...closeInput, reason: 'changed' }, { idempotencyKey: closeKey }),
    e => e.status === 409); checks++
  assert.equal(spawnSync('php', ['scripts/tests/core-browser/core-fixture.php', root, 'admin-revoke-orders']).status, 0)
  await assert.rejects(admin.call('commerce.admin-orders.close', closeInput, { idempotencyKey: closeKey }), e => e.status === 403); checks++
  const statusKey = randomUUID(), statusInput = { user_id: newAccount.id, expected_banned: false, banned: true }
  const banned = await admin.call('identity.accounts.status.update', statusInput, { idempotencyKey: statusKey })
  assert.equal(banned.banned, true)
  assert.deepEqual(await admin.call('identity.accounts.status.update', statusInput, { idempotencyKey: statusKey }), banned); checks++
  const noticeKey = randomUUID(), noticeInput = { title: 'SDK original notice', content: 'Integration body', img_url: null, tags: [], popup: false, show: true, sort: 0, audience: 'authenticated', publish_at: null, expire_at: null }
  const notice = await admin.call('content.notices.create', noticeInput, { idempotencyKey: noticeKey })
  assert.equal(typeof notice.id, 'string')
  assert.deepEqual(await admin.call('content.notices.create', noticeInput, { idempotencyKey: noticeKey }), notice); checks++
  await admin.call('content.notices.update', { ...noticeInput, content_id: notice.id, expected_version: '1', title: 'SDK updated notice', content: 'Updated body' }, { idempotencyKey: randomUUID() })
  assert.equal((await admin.call('content.notices.get', { content_id: notice.id })).title, 'SDK updated notice'); checks++
  await admin.call('content.notices.delete', { content_id: notice.id, expected_version: '2' }, { idempotencyKey: randomUUID() })
  await assert.rejects(admin.call('content.notices.get', { content_id: notice.id }), e => e.status === 404); checks++
  const groups = await admin.callResult('network.groups.list', { page: 1, per_page: 1 })
  assert.equal(groups.meta.per_page, 1); assert.equal(typeof groups.data[0].id, 'string'); checks++
  const adminSessions = await admin.callResult('identity.sessions.list', {})
  assert.ok(adminSessions.data.some(row => row.id === adminService.secondary)); checks++
  await admin.call('identity.sessions.revoke', { session_id: adminService.secondary }, { idempotencyKey: randomUUID() })
  assert.ok(!(await admin.call('identity.sessions.list', {})).some(row => row.id === adminService.secondary)); checks++
  assert.equal(spawnSync('php', ['scripts/tests/core-browser/core-fixture.php', root, 'admin-restrict']).status, 0)
  await assert.rejects(admin.call('network.nodes.list', {}), e => e.status === 403); checks++
  assert.equal(spawnSync('php', ['scripts/tests/core-browser/core-fixture.php', root, 'admin-rotate-path']).status, 0)
  await assert.rejects(admin.call('identity.admin.get', {}), e => e.status === 404); checks++
  adminPath = 'sdk_admin_rotated'
  assert.equal((await admin.call('identity.admin.get', {})).id, adminService.id); checks++
  await admin.call('identity.session.end', {}, { idempotencyKey: randomUUID() })
  await assert.rejects(admin.call('identity.admin.get', {}), e => e.status === 401); checks++
  console.log(JSON.stringify({ sourceHead, nodeSource, checks, realCore: true, nativeActivation: true, database: 'mysql',
    coreBrowserIngress: true, viteDevProxySameOrigin: true, browserTransport: 'direct same-process PHP HTTP without service credentials',
    browserSdk: true, originalKeyReplay: true, lostResponseRecovery: true, ownerIsolation: true,
    sessionEnd: true, internalServiceMtls: 'separate retained API acceptance', adminChannel: true,
    adminOrderCloseRecovery: true, adminOrderAudit: true, currentOrderGrantReplay: true, orderPaymentDiscovery: true,
    rendererFormats: renderer.formats.length, rendererFormatIds: renderer.formats,
    rendererSdkProviderWire: true, rendererCliFiles: true, rendererLiveRevocation: true,
    financialWorkflows: true, couponPurchaseBinding: true, giftBatchRecovery: true, reviewedCommissionPayout: true,
    adminAccountMutation: true, adminContentCrud: true, adminWriteKeyReplay: true, actorGrantRevocation: true, adminPathRotation: true }))
} finally {
  for (const dev of devServers.reverse()) await dev.close()
  for (const server of servers.reverse()) await new Promise(r => server.close(r))
  if (php.exitCode === null) { php.kill(); await new Promise(r => php.once('exit', r)) }; closeSync(log)
}
