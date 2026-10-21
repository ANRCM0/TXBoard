import { createHash } from 'node:crypto'

const hash = value => createHash('sha256').update(JSON.stringify(value)).digest('hex')
const formats = ['clash/v1', 'clash-meta/v1', 'sing-box/v1', 'stash/v1', 'surfboard/v1', 'shadowrocket/v1', 'surge-ios/v1', 'surge-mac/v1']

// Synthetic UI transport data. Domain authorization, signatures and ledger tests run against Core separately.
export function nativeAdditionsFixture(state) {
  const templates = formats.map(format_id => ({ format_id, source: '', custom: false, version: hash({ format_id, source: '' }), sha256: createHash('sha256').update('').digest('hex') }))
  state.nativeTemplates = templates
  const cards = () => state.modules.map(module => ({
    id: module.id, name: module.name, version: module.version, description: module.description, author: module.author,
    type: module.type, subtype: module.type === 'plugin' ? 'feature' : null, source: 'user', installed: module.installed,
    enabled: module.enabled, active: module.active, health: module.health, managed: true, protected: false,
    upgrade_available: false, configuration_available: true, presentation_available: module.type === 'plugin',
    revision: hash({ id: module.id, enabled: module.enabled }), fingerprint: hash(module.id), actions: [],
  }))
  const configuration = new Map()
  const receipts = new Map()
  let orderingRevision = hash('node-ordering-initial')
  const check = (condition, code = 'VERSION_CONFLICT') => {
    if (!condition) throw Object.assign(new Error(code), { fixtureStatus: 409, fixtureCode: code })
  }
  const write = (operation, input, key, action) => {
    const digest = hash({ operation, input })
    const previous = receipts.get(key)
    if (previous) { check(previous.digest === digest, 'IDEMPOTENCY_CONFLICT'); return structuredClone(previous.result) }
    const result = action()
    receipts.set(key, { digest, result: structuredClone(result) })
    return result
  }
  return (operation, input, key) => {
    if (operation === 'network.nodes.ordering.get') { const node = state.detailNode ?? state.node; return { revision: orderingRevision, nodes: [{ id: node.id, name: node.name, type: node.type }] } }
    if (operation === 'network.nodes.ordering.replace') return write(operation, input, key, () => { check(input.expected_revision === orderingRevision); check(input.node_ids.length === 1 && input.node_ids[0] === (state.detailNode ?? state.node).id); orderingRevision = hash(input.node_ids); return { ok: true } })
    if (operation === 'platform.module-cards.list') return { modules: cards(), discovery_errors: 0 }
    if (operation === 'platform.module-cards.get') {
      const module = cards().find(row => row.id === input.module_id)
      if (!module) throw new Error('Unknown synthetic module')
      return { module, readme: '# ' + module.name + '\n\nDeclared native module documentation.' }
    }
    if (operation === 'platform.module-configuration.get') {
      const module = cards().find(row => row.id === input.module_id)
      if (!module) throw new Error('Unknown synthetic module')
      return { module_id: module.id, fingerprint: module.fingerprint, revision: hash(configuration.get(module.id) ?? {}),
        schema_json: JSON.stringify({ type: 'object', properties: { title: { type: 'string' } }, additionalProperties: false }),
        configuration_json: JSON.stringify(configuration.get(module.id) ?? {}), credentials: [] }
    }
    if (operation === 'platform.module-configuration.save') return write(operation, input, key, () => {
      const module = cards().find(row => row.id === input.module_id)
      check(module && module.fingerprint === input.fingerprint)
      check(hash(configuration.get(module.id) ?? {}) === input.expected_revision)
      configuration.set(module.id, JSON.parse(input.configuration_json))
      return { module_id: module.id, phase: 'completed', action: 'configure' }
    })
    if (operation === 'platform.module-presentation.get') return { module_id: input.module_id, title: 'Declared module tools', fingerprint: input.fingerprint, entry: 'native-admin/v1', operations: [] }
    if (operation === 'platform.theme-packages.list') return { candidates: [] }
    if (operation === 'subscription.admin-templates.list') return { templates: structuredClone(templates), providers: ['fixture-provider'] }
    if (operation === 'subscription.admin-templates.save' || operation === 'subscription.admin-templates.reset') return write(operation, input, key, () => {
      const row = templates.find(item => item.format_id === input.format_id)
      check(row && row.version === input.expected_version, 'SUBSCRIPTION_TEMPLATE_VERSION_CONFLICT')
      if (operation.endsWith('.save')) check(input.provider_id === 'fixture-provider')
      row.source = operation.endsWith('.reset') ? '' : input.source
      row.custom = row.source !== ''
      row.version = hash({ format_id: row.format_id, source: row.source })
      row.sha256 = createHash('sha256').update(row.source).digest('hex')
      return { ok: true }
    })
    if (operation === 'subscription.admin.amendment.get') {
      const row = state.subscription
      return { user_id: input.user_id, amendment: { subscription_id: row.subscription_id, subscription_version: row.subscription_version,
        terms_id: row.terms_id, usage_period_id: row.usage_period_id, period_version: row.period_version,
        plan_revision_id: row.plan_revision_id, status: state.lifecycle.status === 'suspended' ? 'suspended' : 'active', plan_id: row.plan_id,
        quota_bytes: row.quota_bytes, consumed_bytes: row.consumed_bytes, expires_at: row.expires_at === null ? null : String(row.expires_at),
        speed_limit_mbps: row.speed_limit_mbps === null ? null : String(row.speed_limit_mbps), device_limit: row.device_limit === null ? null : String(row.device_limit), editable: true } }
    }
    if (operation === 'subscription.admin.amendment.history' || operation === 'billing.admin-account-adjustments.list') return { items: [], next_cursor: null }
    if (operation === 'billing.admin-account-balances.get') return { user_id: input.user_id, currency: input.currency,
      wallet_available_minor: '9007199254740993', wallet_reserved_minor: '0', commission_available_minor: '100', commission_reserved_minor: '0', ledger_revision: hash({ user_id: input.user_id, currency: input.currency }), editable: true }
    if (operation === 'billing.admin-customer-policy.get') return { user_id: input.user_id, discount_bps: 0,
      commission_mode: 'inherit', commission_rate_bps: null, policy_version: '0',
      policy_revision: hash({ user_id: input.user_id, policy_version: '0' }), editable: true }
    if (operation === 'usage.admin-settled-entries.list') return { basis: 'settled-billed-with-admin-corrections/v1', items: [], next_cursor: null }
    if (operation === 'billing.admin-customer-policy.history' || operation === 'usage.admin-corrections.list') return { items: [], next_cursor: null }
    if (operation === 'settings.telegram.webhook.inspect') return { configured: false, matches_saved: false, pending_updates: 0, delivery_error: false }
    return undefined
  }
}
