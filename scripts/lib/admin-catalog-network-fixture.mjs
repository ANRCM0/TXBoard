import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { coreOperations } from '@txboard/core-sdk'

const requestId = '12345678-1234-4234-8234-123456789abc'
const stamp = 1792368000
const planId = '9007199254740993'
const revisionId = '9007199254740995'
const digest = value => createHash('sha256').update(JSON.stringify(value)).digest('hex')

export async function catalogNetworkFixture(browser, viewport) {
  const context = await browser.newContext({ locale: 'zh-CN', viewport })
  const group = { id: '7', name: 'Native group', users_count: 0, plans_count: 1, server_count: 1,
    created_at: stamp, updated_at: stamp }
  const routes = [
    { id: '9007199254740997', remarks: 'Route Alpha', match: ['domain:example.test'], action: 'direct',
      action_value: null, enabled: true, sort: 10, server_count: 1, created_at: stamp, updated_at: stamp },
    { id: '9007199254740999', remarks: 'Route Beta', match: ['domain:blocked.test'], action: 'block',
      action_value: null, enabled: true, sort: 20, server_count: 1, created_at: stamp, updated_at: stamp },
  ]
  const rule = { id: '41', name: 'Observed domains', match_type: 'domain_suffix', match_value: 'example.test', enabled: true }
  const event = { id: '51', server_id: '9007199254740993', user_id: '12', target: 'example.test',
    target_ip: '192.0.2.2', source_ip: '192.0.2.3', matched: true, created_at: stamp }
  const node = { id: event.server_id, name: 'Native node', type: 'vless', host: '192.0.2.1', port: '443',
    server_port: null, parent_id: null, machine_id: null, show: true, enabled: true, sort: 1, u: '0', d: '0' }
  const terms = { name: 'Authoritative plan', content: 'Frozen publication body', tags: ['frozen-tag'], quota_gib: 7,
    group_id: group.id, speed_limit_mbps: 10, device_limit: 3, reset_method: 2, currency: 'CNY',
    prices_minor: { monthly: '9007199254740993' } }
  const originalRevision = { revision_id: revisionId, revision_no: '1', content_hash: digest(terms), published_at: stamp, terms }
  const offer = { offer_id: '31', plan_id: planId, revision_id: revisionId, name: terms.name,
    offer_code: 'monthly', price_minor: terms.prices_minor.monthly, currency: 'CNY', status: 'published',
    show: true, sell: true, renew: true, capacity_limit: '7', row_version: '3' }
  const state = { calls: [], errors: [], pageErrors: [], forbiddenRequests: [], groupLost: true, groupEffects: 0,
    catalogDenied: false, networkDenied: false, group, routes, rule, event, node,
    planId, originalRevision: structuredClone(originalRevision), currentRevision: structuredClone(originalRevision), offers: [offer] }
  const receipts = new Map()
  function summary(revision) {
    return { plan_id: planId, revision_id: revision.revision_id, revision_no: revision.revision_no,
      name: revision.terms.name, currency: revision.terms.currency, published_at: revision.published_at }
  }
  await context.route('**/gateway/v1/**', async route => {
    const request = route.request(), url = new URL(request.url())
    const json = (status, value) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(value) })
    try {
      if (url.pathname === '/gateway/v1/bootstrap') return json(200, { ok: true,
        data: { security: { captcha: { enabled: false, type: null, siteKey: null } } },
        meta: { version: '1', requestId, coreRequestId: requestId, coreMeta: {} } })
      const operation = url.pathname.split('/operations/')[1]
      assert.ok(Object.hasOwn(coreOperations, operation), 'Only current Core operations are admitted')
      assert.equal(request.method(), 'POST')
      const spec = coreOperations[operation], input = spec.input.parse(request.postDataJSON().input)
      const key = request.headers()['idempotency-key']
      state.calls.push({ operation, input: structuredClone(input), key })
      const send = (data, meta = {}) => {
        spec.output.parse(data); spec.meta.parse(meta)
        return json(200, { ok: true, data, meta: { version: '1', requestId, coreRequestId: requestId, coreMeta: meta } })
      }
      const list = rows => send(rows, { page: input.page ?? 1, per_page: input.per_page ?? 20,
        total: rows.length, last_page: 1 })
      const denied = () => json(403, { ok: false, error: { code: 'FORBIDDEN', message: 'withheld' }, meta: { version: '1', requestId } })
      if (operation === 'identity.admin-session.create') {
        assert.equal(input.email, 'admin@example.test'); assert.match(key, /^[0-9a-f-]{36}$/)
        return send({ auth_data: 'Bearer 1|catalog-network-fixture', is_admin: true, secure_path: 'ci_admin' })
      }
      assert.equal(request.headers().authorization, 'Bearer 1|catalog-network-fixture')
      assert.equal(request.headers()['x-core-admin-path'], 'ci_admin')
      if (spec.idempotency !== 'read') assert.match(key, /^[0-9a-f-]{36}$/)
      if (operation === 'identity.admin.get') return send({ id: '20', email: 'admin@example.test' })
      if (operation.startsWith('catalog.') && state.catalogDenied) return denied()
      if (operation.startsWith('network.') && state.networkDenied) return denied()
      if (operation === 'catalog.admin-plans.list') {
        const { revision_id: current_revision_id, ...row } = summary(state.currentRevision)
        return list([{ ...row, current_revision_id }])
      }
      if (operation === 'catalog.admin-plans.get') {
        assert.equal(input.plan_id, planId)
        const revision = input.revision_id === revisionId ? state.originalRevision : state.currentRevision
        return send({ plan_id: planId, current_revision_id: state.currentRevision.revision_id,
          revision, offers: state.offers.filter(row => row.revision_id === revision.revision_id) })
      }
      if (operation === 'catalog.admin-plans.history.list') {
        assert.equal(input.plan_id, planId)
        return list(state.currentRevision.revision_id === revisionId ? [summary(state.originalRevision)]
          : [summary(state.currentRevision), summary(state.originalRevision)])
      }
      if (operation === 'catalog.admin-offers.list') return list(state.offers.filter(row =>
        (!input.plan_id || row.plan_id === input.plan_id) && (!input.status || row.status === input.status)))
      if (operation === 'catalog.plans.publish') {
        assert.equal(input.plan_id, planId)
        assert.equal(input.expected_revision_id, revisionId)
        assert.equal(input.content, terms.content)
        assert.deepEqual(input.tags, terms.tags)
        assert.equal(input.quota_gib, terms.quota_gib)
        const receiptKey = operation + ':' + key
        if (!receipts.has(receiptKey)) {
          assert.equal(state.currentRevision.revision_id, revisionId)
          const nextId = String(BigInt(revisionId) + 1n)
          const nextTerms = { name: input.name, content: input.content ?? null, tags: input.tags ?? [],
            quota_gib: input.quota_gib, group_id: input.group_id ?? null,
            speed_limit_mbps: input.speed_limit_mbps ?? null, device_limit: input.device_limit ?? null,
            reset_method: input.reset_method, currency: input.currency, prices_minor: input.prices_minor }
          state.currentRevision = { revision_id: nextId, revision_no: '2', content_hash: digest(nextTerms),
            published_at: stamp + 1, terms: nextTerms }
          state.offers[0].status = 'superseded'; state.offers[0].row_version = '4'
          const nextOffer = { ...offer, offer_id: '32', revision_id: nextId, name: input.name,
            price_minor: input.prices_minor.monthly, capacity_limit: input.capacity_limit == null ? null : String(input.capacity_limit),
            row_version: '1', status: 'published' }
          state.offers.push(nextOffer)
          receipts.set(receiptKey, { schema: 'native-plan-publication/v1', plan_id: planId,
            publication: { schema: 'plan-publication/v1', plan_id: planId, revision_id: nextId, revision_no: '2',
              publication_key: 'fixture-publication', content_hash: state.currentRevision.content_hash,
              offers: [{ offer_id: nextOffer.offer_id, offer_code: 'monthly', price_minor: nextOffer.price_minor, currency: input.currency }] } })
        }
        return send(receipts.get(receiptKey))
      }
      if (operation === 'catalog.offers.availability.update') {
        const target = state.offers.find(row => row.offer_id === input.offer_id)
        assert.ok(target); assert.equal(target.row_version, input.expected_version)
        Object.assign(target, { show: input.show, sell: input.sell, renew: input.renew,
          capacity_limit: input.capacity_limit === null ? null : String(input.capacity_limit), row_version: String(BigInt(target.row_version) + 1n) })
        return send({ offer_id: target.offer_id, status: target.status, row_version: target.row_version,
          show: input.show, sell: input.sell, renew: input.renew, capacity_limit: target.capacity_limit })
      }
      if (operation === 'network.groups.names.get') return send({ groups: input.group_ids.filter(id => id === group.id).map(id => ({ id, name: group.name })) })
      if (operation === 'network.groups.list') return list([group])
      if (operation === 'network.groups.save') {
        assert.equal(input.id, group.id)
        const receiptKey = operation + ':' + key
        if (!receipts.has(receiptKey)) {
          group.name = input.name; state.groupEffects++
          receipts.set(receiptKey, { id: group.id, ok: true })
        }
        if (state.groupLost) { state.groupLost = false; return route.abort('failed') }
        return send(receipts.get(receiptKey))
      }
      if (operation === 'network.routes.list') return send([...routes].sort((a, b) => a.sort - b.sort))
      if (operation === 'network.routes.ordering.get') return send({ revision: digest([...routes].sort((a, b) => a.sort - b.sort).map(row => row.id)), route_ids: [...routes].sort((a, b) => a.sort - b.sort).map(row => row.id) })
      if (operation === 'network.routes.ordering.replace') {
        assert.equal(input.expected_revision, digest([...routes].sort((a, b) => a.sort - b.sort).map(row => row.id)))
        assert.deepEqual([...input.route_ids].sort(), routes.map(row => row.id).sort())
        input.route_ids.forEach((id, index) => { routes.find(row => row.id === id).sort = index + 1 })
        return send({ ok: true })
      }
      if (operation === 'network.routes.sort') {
        assert.deepEqual([...input.items.map(row => row.id)].sort(), routes.map(row => row.id).sort())
        for (const row of input.items) routes.find(current => current.id === row.id).sort = row.sort
        return send({ ok: true })
      }
      if (operation === 'network.nodes.list') return list([node])
      if (operation === 'network.routes.simulate') {
        assert.equal(input.node_id, node.id)
        return send({ node: { id: node.id, name: node.name }, target: input.target, authoritative: false,
          warning: 'Custom routes take precedence; this result describes the panel route layer.',
          match: { layer: 'panel', id: routes[0].id, remarks: routes[0].remarks, action: 'direct', action_value: null, pattern: input.target },
          evaluated_routes: [{ id: routes[0].id, remarks: routes[0].remarks, matched: true, matched_pattern: input.target,
            action: 'direct', action_value: null }], unresolved_patterns: ['geoip:fixture'] })
      }
      if (operation === 'network.access-audit.rules.list') return send([rule])
      if (operation === 'network.access-audit.rules.save') {
        assert.equal(input.id, rule.id); Object.assign(rule, input); return send({ id: rule.id, ok: true })
      }
      if (operation === 'network.access-audit.events.list') return list(!input.keyword || event.target.includes(input.keyword) ? [event] : [])
      throw new Error('Unexpected catalog/network operation: ' + operation)
    } catch (error) {
      state.errors.push(error.stack ?? String(error))
      await json(500, { ok: false, error: { code: 'FIXTURE_ASSERTION_FAILED', message: 'See fixture errors' }, meta: { version: '1', requestId } })
    }
  })
  const page = await context.newPage()
  page.setDefaultTimeout(15000)
  page.on('pageerror', error => state.pageErrors.push(error.message))
  page.on('request', request => {
    if (/\/(?:txapi|plugin)\//.test(new URL(request.url()).pathname)) state.forbiddenRequests.push(request.url())
  })
  return { context, page, state }
}
