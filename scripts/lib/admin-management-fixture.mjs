import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { coreOperations } from '@txboard/core-sdk'
import { catalogNetworkFixture } from './admin-catalog-network-fixture.mjs'

const requestId = '12345678-1234-4234-8234-123456789abc'
const stamp = 1792368000
const metadata = JSON.parse(await readFile(new URL('../../contracts/core-api/admin-settings.v1.json', import.meta.url), 'utf8'))
const managed = /^(?:audit\.logs\.|platform\.(?:modules\.|queue\.)|settings\.configuration\.|notifications\.mail-templates\.|billing\.admin-payment-|network\.(?:nodes\.get$|nodes\.batch-[a-z-]+$|machines\.)|identity\.accounts\.get$|identity\.accounts\.status\.update$|subscription\.lifecycle\.reset$)/

/** Built admin UI with schema-checked synthetic browser ingress; no live Core acceptance is implied. */
export async function managementFixture(browser, viewport) {
  const fixture = await catalogNetworkFixture(browser, viewport)
  const { context, state } = fixture
  const fields = Object.entries(metadata.fields)
  const settings = {
    version: 'a'.repeat(64),
    values: Object.fromEntries(fields.filter(([, field]) => !field.write_only).map(([name, field]) => [name, structuredClone(field.default)])),
    credentials: fields.filter(([, field]) => field.write_only).map(([name]) => ({ name, configured: true })),
  }
  const modules = ['plugin', 'theme'].map(type => ({ id: 'fixture-' + type, name: 'Observed ' + type,
    version: '1.0.0', description: 'Read-only installed module', author: 'Fixture', type, source: 'local',
    installed: true, enabled: true, active: type === 'theme' ? true : null, health: 'healthy',
    capabilities: [], compatibility: { txboard: '^0.2.0' } }))
  const machine = { id: '9007199254740997', name: 'Observed machine', notes: 'Machine metadata only',
    is_active: true, image_channel: 'stable', servers_count: 1, last_seen_at: stamp, created_at: stamp, updated_at: stamp }
  const node = { ...state.node, machine_id: machine.id, group_ids: ['7'], route_ids: [], tags: ['observed'], created_at: stamp, updated_at: stamp }
  let accountBanned = false
  const subscription = { schema: 'core-entitlement/v1', authorized: true, reason: 'ENTITLEMENT_GRANTED',
    user_id: '12', subscription_id: '9007199254740999', subscription_version: '9007199254740993',
    plan_id: state.planId, terms_id: '81', terms_version: '1', plan_revision_id: state.originalRevision.revision_id,
    usage_period_id: '82', period_terms_id: '81', period_version: '1', group_id: '7', speed_limit_mbps: null,
    device_limit: null, expires_at: null, next_reset_at: null, quota_bytes: '1073741824', consumed_bytes: '123',
    remaining_bytes: '1073741701', upload_bytes: '100', download_bytes: '23' }
  const payment = { id: '91', name: 'Observed payment', provider: 'fixturepay', icon: null, enabled: true,
    available: true, version: 'b'.repeat(64), sort: 0, fee_fixed_minor: '0', fee_percent: '0' }
  const failure = { id: '101', connection: 'redis', queue: 'default', job: 'ObservedJob', failed_at: '2026-10-19T00:00:00Z' }
  Object.assign(state, { denied: new Set(), settings, modules, machine, detailNode: node, subscription, payment,
    originalSettingsVersion: settings.version, originalPaymentVersion: payment.version, originalSubscriptionVersion: subscription.subscription_version })
  await context.route('**/gateway/v1/core/admin/operations/**', async route => {
    const request = route.request(), operation = new URL(request.url()).pathname.split('/operations/')[1]
    if (!managed.test(operation)) return route.fallback()
    const json = (status, body) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
    try {
      assert.ok(Object.hasOwn(coreOperations, operation), 'Only generated operations are accepted')
      assert.equal(request.method(), 'POST')
      assert.equal(request.headers().authorization, 'Bearer 1|catalog-network-fixture')
      assert.equal(request.headers()['x-core-admin-path'], 'ci_admin')
      const spec = coreOperations[operation], input = spec.input.parse(request.postDataJSON().input)
      const key = request.headers()['idempotency-key']
      if (spec.idempotency !== 'read') assert.match(key, /^[0-9a-f-]{36}$/)
      state.calls.push({ operation, input: structuredClone(input), key })
      const send = (data, meta = {}) => {
        spec.output.parse(data); spec.meta.parse(meta)
        return json(200, { ok: true, data, meta: { version: '1', requestId, coreRequestId: requestId, coreMeta: meta } })
      }
      const list = rows => send(rows, { page: input.page ?? 1, per_page: input.per_page ?? 20, total: rows.length, last_page: 1 })
      if ([...state.denied].some(prefix => operation.startsWith(prefix))) {
        return json(403, { ok: false, error: { code: 'FORBIDDEN', message: 'withheld' }, meta: { version: '1', requestId } })
      }
      if (operation === 'audit.logs.list') return list([{ id: '111', admin_id: '20', action: 'observed.admin.change', method: 'POST', uri: '/controlled-operation', ip: '192.0.2.1', created_at: stamp, admin: { id: '20', email: 'admin@example.test' } }])
      if (operation === 'platform.modules.list') return send({ modules, summary: { total: modules.length, discovery_errors: 0 } })
      if (operation === 'platform.modules.get') { const row = modules.find(row => row.id === input.module_id); assert.ok(row); return send(row) }
      if (operation === 'platform.modules.operations.list') return send({ module_id: input.module_id, operations: ['enable', 'disable'] })
      if (operation === 'platform.queue.snapshot.get') return send({ status: 'running', connection: 'redis', processes: 1,
        recent_jobs: 2, jobs_per_minute: 1, wait_seconds: 0, longest_wait_queue: 'default', pending_jobs: 0,
        observed_at: '2026-10-19T00:00:00Z', failed_last_7_days: 1, failed_jobs_available: true })
      if (operation === 'platform.queue.failures.list') return send([{ ...failure, message: 'REDACTED' }])
      if (operation === 'platform.queue.failures.get') { assert.equal(input.failure_id, failure.id); return send(failure) }
      if (operation === 'settings.configuration.get') return send(settings)
      if (operation === 'settings.configuration.update') {
        assert.equal(input.expected_version, settings.version)
        for (const [name, value] of Object.entries(input.values)) {
          assert.ok(metadata.fields[name])
          if (!metadata.fields[name].write_only) settings.values[name] = value
        }
        settings.version = settings.version === 'a'.repeat(64) ? 'c'.repeat(64) : 'd'.repeat(64)
        return send({ ok: true })
      }
      if (operation === 'notifications.mail-templates.list') return send([{ name: 'verify', label: 'Verification template', customized: false, subject: 'Verify account', updated_at: null }])
      if (operation === 'notifications.mail-templates.get') return send({ name: input.name, label: 'Verification template',
        required_vars: ['code'], optional_vars: [], customized: false, subject: 'Verify account', content: 'Your verification code is {{code}}' })
      if (operation === 'billing.admin-payment-methods.list') return list([payment])
      if (operation === 'billing.admin-payment-providers.list') return send([{ provider: payment.provider, plugin_code: 'fixture-payment', version: '1.0.0', currencies: ['CNY'], external_refund: false }])
      if (operation === 'billing.admin-payment-methods.form') {
        assert.equal(input.provider, payment.provider)
        return send({ provider: payment.provider, fields: [
          { name: 'merchant_id', label: 'Merchant ID', kind: 'text', value: null, configured: true },
          { name: 'merchant_key', label: 'Merchant key', kind: 'password', value: null, configured: true },
        ] })
      }
      if (operation === 'billing.admin-payment-methods.save') {
        assert.equal(input.payment_id, payment.id); assert.equal(input.expected_version, payment.version)
        payment.name = input.name; payment.version = 'e'.repeat(64)
        return send({ id: payment.id })
      }
      if (operation === 'network.nodes.get') { assert.equal(input.node_id, node.id); return send(node) }
      if (operation === 'network.nodes.batch-update') {
        assert.ok(input.ids.includes(node.id))
        if (input.enabled !== undefined) node.enabled = input.enabled
        if (input.show !== undefined) node.show = input.show
        if ('machine_id' in input) node.machine_id = input.machine_id
        return send({ ok: true })
      }
      if (operation === 'network.nodes.batch-delete' || operation === 'network.nodes.batch-traffic-reset') {
        assert.ok(input.ids.includes(node.id))
        return send({ ok: true })
      }
      if (operation === 'network.machines.get') { assert.equal(input.machine_id, machine.id); return send(machine) }
      if (operation === 'network.machines.list') {
        const { notes, ...summary } = machine
        return list([summary])
      }
      if (operation === 'network.machines.nodes.list') {
        assert.equal(input.machine_id, machine.id)
        const { id, name, type, host, port, show, enabled, sort } = node
        return list([{ id, name, type, host, port: String(port), show, enabled, sort }])
      }
      if (operation === 'identity.accounts.get') {
        assert.equal(input.user_id, subscription.user_id)
        return send({ id: subscription.user_id, email: 'subscription@example.test', banned: accountBanned, is_admin: false,
          created_at: stamp, last_login_at: null, subscription, credential_status: 'provider_managed' })
      }
      if (operation === 'identity.accounts.status.update') {
        assert.equal(input.user_id, subscription.user_id)
        assert.equal(input.expected_banned, accountBanned)
        accountBanned = input.banned
        return send({ id: subscription.user_id, banned: accountBanned })
      }
      if (operation === 'subscription.lifecycle.reset') {
        assert.equal(input.subscription_id, subscription.subscription_id)
        assert.equal(input.expected_version, subscription.subscription_version)
        const previous = subscription.usage_period_id
        subscription.subscription_version = String(BigInt(subscription.subscription_version) + 1n)
        subscription.usage_period_id = '83'; subscription.consumed_bytes = '0'; subscription.remaining_bytes = subscription.quota_bytes
        subscription.upload_bytes = '0'; subscription.download_bytes = '0'
        return send({ schema: 'core-subscription-operation/v1', user_id: subscription.user_id,
          subscription_id: subscription.subscription_id, terms_id: subscription.terms_id, usage_period_id: subscription.usage_period_id,
          subscription_version: subscription.subscription_version, period_version: '1', status: 'active', expires_at: null,
          applied_at: stamp, transition_id: '84', previous_period_id: previous, quota_adjustment_id: null })
      }
      throw new Error('Unexpected management fixture operation: ' + operation)
    } catch (error) {
      state.errors.push(error.stack ?? String(error))
      return json(500, { ok: false, error: { code: 'FIXTURE_ASSERTION_FAILED', message: 'See fixture errors' }, meta: { version: '1', requestId } })
    }
  })
  return fixture
}
