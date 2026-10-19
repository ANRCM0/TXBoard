import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { coreOperations } from '@txboard/core-sdk'

const requestId = '12345678-1234-4234-8234-123456789abc'
const stamp = '2026-10-19T00:00:00Z'
const epoch = 1792368000
export const markup = '<img src=x onerror="window.fixtureInjected=true"><script>window.fixtureInjected=true</script>'
export const attachmentBytes = Buffer.from('Private support attachment\n', 'utf8')
export const attachmentId = '11111111-1111-4111-8111-111111111111'
const hash = bytes => createHash('sha256').update(bytes).digest('hex')

export async function workflowFixture(browser, viewport) {
  const context = await browser.newContext({ viewport })
  const ticket = { id: '9007199254740993', user_id: '9007199254740995', subject: 'Private connection issue',
    level: 1, status: 0, reply_status: 0, row_version: '7', created_at: stamp, updated_at: stamp, assigned_admin_id: null }
  const coupon = { id: '9007199254740997', code: 'BROWSER01', name: 'Recovery coupon', currency: 'CNY',
    discount_kind: 'fixed', value: '100', minimum_minor: '0', total_limit: 2, user_limit: 1,
    starts_at: null, expires_at: null, offer_ids: [], enabled: true, row_version: '11', used_total: 0,
    usage_policy: 'retained-on-order-create/v1', created_at: epoch, updated_at: epoch }
  const common = { id: '15', title: markup, sort: 0, row_version: '3', audience: 'authenticated',
    publish_at: null, expire_at: null, created_at: epoch, updated_at: epoch, show: false }
  const notice = { ...common, img_url: null, tags: [], popup: false }
  const knowledge = { ...common, id: '16', category: 'Connection', language: 'zh-CN' }
  const gift = { card_id: '22222222-2222-4222-8222-222222222222', issuer_id: ticket.user_id,
    recipient_id: null, amount_minor: '9007199254740993', currency: 'CNY', state: 'reserved', row_version: '5',
    expires_at: epoch + 86400, created_at: epoch, closed_at: null }
  const batch = { batch_id: '33333333-3333-4333-8333-333333333333', issuer_id: ticket.user_id,
    label: 'Current batch', quantity: 1, amount_minor: gift.amount_minor, total_minor: gift.amount_minor,
    currency: 'CNY', expires_at: gift.expires_at, created_at: epoch }
  const payout = { id: '44444444-4444-4444-8444-444444444444', user_id: ticket.user_id,
    state: 'requested', row_version: '9', amount_minor: '1200', currency: 'CNY', destination: 'native-wallet',
    actor_id: null, reason: null, created_at: epoch, closed_at: null }
  const policy = { enabled: true, rate_bps: 1000, hold_seconds: 86400,
    refund_policy: 'net-before-vesting-retained-after/v1', row_version: '2' }
  const state = { calls: [], errors: [], pageErrors: [], forbiddenRequests: [], giftDenied: false,
    couponLost: true, couponEffects: 0, ticket, coupon, gift, batch, payout, attachment: null,
    messages: [{ id: '1', author_kind: 'user', message: 'Please inspect this connection.', created_at: stamp, attachments: [] }] }
  const receipts = new Map()
  await context.route('**/gateway/v1/**', async route => {
    const request = route.request(), url = new URL(request.url())
    const json = (status, body) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
    try {
      if (url.pathname === '/gateway/v1/bootstrap') return json(200, { ok: true,
        data: { security: { captcha: { enabled: false, type: null, siteKey: null } } },
        meta: { version: '1', requestId, coreRequestId: requestId, coreMeta: {} } })
      const operation = url.pathname.split('/operations/')[1]
      assert.ok(Object.hasOwn(coreOperations, operation), 'Only current pinned Core operations are admitted')
      assert.equal(request.method(), 'POST')
      const body = request.postDataJSON()
      const spec = coreOperations[operation], input = spec.input.parse(body.input)
      const key = request.headers()['idempotency-key']
      state.calls.push({ operation, input: structuredClone(input), key })
      const send = (data, meta = {}) => {
        spec.output.parse(data); spec.meta.parse(meta)
        return json(200, { ok: true, data, meta: { version: '1', requestId, coreRequestId: requestId, coreMeta: meta } })
      }
      const list = rows => send(rows, { page: input.page ?? 1, per_page: input.per_page ?? 20, total: rows.length, last_page: 1 })
      const cursor = rows => send(rows, { next_after_id: rows.at(-1)?.id ?? '0', has_more: false })
      if (operation === 'identity.admin-session.create') {
        assert.equal(input.email, 'admin@example.test'); assert.match(key, /^[0-9a-f-]{36}$/)
        return send({ auth_data: 'Bearer 1|workflow-fixture', is_admin: true, secure_path: 'ci_admin' })
      }
      assert.equal(request.headers().authorization, 'Bearer 1|workflow-fixture')
      assert.equal(request.headers()['x-core-admin-path'], 'ci_admin')
      if (operation === 'identity.admin.get') return send({ id: '20', email: 'admin@example.test' })
      if (spec.idempotency !== 'read') assert.match(key, /^[0-9a-f-]{36}$/)
      if (operation === 'billing.coupons.list') return list([coupon])
      if (operation === 'billing.coupons.disable') {
        assert.deepEqual(input, { coupon_id: coupon.id, expected_version: '11' })
        if (!receipts.has(key)) {
          assert.equal(coupon.row_version, '11'); coupon.enabled = false; coupon.row_version = '12'; state.couponEffects++
          receipts.set(key, { id: coupon.id, row_version: '12', enabled: false })
        }
        if (state.couponLost) { state.couponLost = false; return route.abort('failed') }
        return send(receipts.get(key))
      }
      if (operation === 'support.admin-tickets.list') return list([ticket])
      if (operation === 'support.admin-tickets.get') { assert.equal(input.ticket_id, ticket.id); return send(ticket, { current_admin_id: '20' }) }
      if (operation === 'support.admin-tickets.messages.list') return cursor(input.after_id === '0' ? state.messages : [])
      if (operation === 'support.admin-tickets.audit.list' || operation === 'content.audit.list') return cursor([])
      if (operation === 'support.admin-tickets.claim') {
        assert.deepEqual(input, { ticket_id: ticket.id, expected_version: '7' })
        ticket.assigned_admin_id = '20'; ticket.row_version = '8'
        return send({ ticket_id: ticket.id, assigned_admin_id: '20', row_version: '8' })
      }
      if (operation === 'support.admin-tickets.attachments.begin') {
        assert.equal(input.ticket_id, ticket.id); assert.equal(input.size_bytes, String(attachmentBytes.length))
        assert.equal(input.sha256, hash(attachmentBytes)); assert.equal(input.media_type, 'text/plain')
        state.attachment = { id: attachmentId, ticket_id: ticket.id, message_id: null, filename: input.filename,
          media_type: input.media_type, size_bytes: input.size_bytes, sha256: input.sha256, status: 'uploading',
          received_bytes: '0', next_chunk_index: 0, created_at: stamp, expires_at: '2026-10-20T00:00:00Z' }
        return send(state.attachment)
      }
      if (operation === 'support.admin-tickets.attachments.append') {
        assert.equal(input.attachment_id, attachmentId); assert.equal(input.chunk_index, 0)
        assert.deepEqual(Buffer.from(input.content_base64, 'base64'), attachmentBytes)
        Object.assign(state.attachment, { received_bytes: String(attachmentBytes.length), next_chunk_index: 1 })
        return send(state.attachment)
      }
      if (operation === 'support.admin-tickets.attachments.finish') {
        assert.equal(input.attachment_id, attachmentId); state.attachment.status = 'ready'; return send(state.attachment)
      }
      if (operation === 'support.admin-tickets.attachments.get') { assert.equal(input.attachment_id, attachmentId); return send(state.attachment) }
      if (operation === 'support.admin-tickets.reply') {
        assert.deepEqual(input, { ticket_id: ticket.id, expected_version: '8', message: 'Verified response', attachment_ids: [attachmentId] })
        assert.equal(state.attachment.status, 'ready')
        Object.assign(state.attachment, { status: 'attached', message_id: '2' })
        state.messages.push({ id: '2', author_kind: 'admin', message: input.message, created_at: stamp, attachments: [{ ...state.attachment }] })
        ticket.row_version = '9'; ticket.reply_status = 1
        return send({ ticket_id: ticket.id, message_id: '2', row_version: '9', status: 0 })
      }
      if (operation === 'content.notices.list') return list([notice])
      if (operation === 'content.knowledge.list') return list([knowledge])
      if (operation === 'content.notices.get') return send({ ...notice, content: markup })
      if (operation === 'content.knowledge.get') return send({ ...knowledge, body: markup })
      if (operation.startsWith('billing.admin-gift-')) {
        if (state.giftDenied) return json(403, { ok: false, error: { code: 'FORBIDDEN', message: 'withheld' }, meta: { version: '1', requestId } })
        if (operation === 'billing.admin-gift-cards.list') return list([gift])
        if (operation === 'billing.admin-gift-batches.list') return list([batch])
        if (operation === 'billing.admin-gift-batches.get') { assert.equal(input.batch_id, batch.batch_id); return send({ batch, cards: [gift] }) }
      }
      if (operation === 'billing.admin-commissions.policy.get') return send(policy)
      if (operation === 'billing.admin-commissions.rewards.list') return list([])
      if (operation === 'billing.admin-commissions.payouts.list') return list([payout])
      if (operation === 'billing.admin-commissions.payouts.approve') {
        assert.deepEqual(input, { payout_id: payout.id, expected_version: '9', reason: 'reviewed' })
        Object.assign(payout, { state: 'approved', row_version: '10', actor_id: '20', reason: input.reason, closed_at: epoch })
        return send(payout)
      }
      throw new Error('Unexpected workflow operation: ' + operation)
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
