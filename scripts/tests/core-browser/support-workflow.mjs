import assert from 'node:assert/strict'
import { createHash, randomUUID } from 'node:crypto'
import { spawnSync } from 'node:child_process'

export async function createSupportTicket(owner, outsider) {
  const input = { subject: 'Actual SDK support request', level: 1, message: 'Private support body' }
  const key = randomUUID(), ticket = await owner.call('support.tickets.create', input, { idempotencyKey: key })
  assert.deepEqual(await owner.call('support.tickets.create', input, { idempotencyKey: key }), ticket)
  await assert.rejects(owner.call('support.tickets.create', { ...input, subject: 'Changed intent' },
    { idempotencyKey: key }), e => e.status === 409)
  await assert.rejects(outsider.call('support.tickets.get', { ticket_id: ticket.ticket_id }), e => e.status === 404)
  const own = await owner.call('support.tickets.get', { ticket_id: ticket.ticket_id })
  assert.ok(!('assigned_admin_id' in own))
  const messages = await owner.callResult('support.tickets.messages.list', { ticket_id: ticket.ticket_id, limit: 1 })
  assert.equal(messages.data.length, 1); assert.equal(messages.meta.has_more, false)
  assert.equal(messages.data[0].author_kind, 'user')
  return { ticket, checks: 5 }
}

export async function verifyAdminSupport(admin, created, { root, adminId }) {
  const ticketId = created.ticket_id
  const claimInput = { ticket_id: ticketId, expected_version: '1' }, claimKey = randomUUID()
  const claim = await admin.call('support.admin-tickets.claim', claimInput, { idempotencyKey: claimKey })
  assert.equal(claim.row_version, '2'); assert.equal(claim.assigned_admin_id, adminId)
  assert.deepEqual(await admin.call('support.admin-tickets.claim', claimInput, { idempotencyKey: claimKey }), claim)
  const detail = await admin.callResult('support.admin-tickets.get', { ticket_id: ticketId })
  assert.equal(detail.meta.current_admin_id, adminId)
  const mine = await admin.call('support.admin-tickets.list', { assignment: 'mine' })
  assert.ok(mine.some(row => row.id === ticketId))
  const content = Buffer.from('Actual private attachment evidence.\n')
  const file = await admin.call('support.admin-tickets.attachments.begin', {
    ticket_id: ticketId, filename: 'sdk-evidence.txt', media_type: 'text/plain', size_bytes: String(content.length),
    sha256: createHash('sha256').update(content).digest('hex'),
  }, { idempotencyKey: randomUUID() })
  const append = { attachment_id: file.id, chunk_index: 0, content_base64: content.toString('base64') }, appendKey = randomUUID()
  await admin.call('support.admin-tickets.attachments.append', append, { idempotencyKey: appendKey })
  await admin.call('support.admin-tickets.attachments.append', append, { idempotencyKey: appendKey })
  const ready = await admin.call('support.admin-tickets.attachments.finish', { attachment_id: file.id }, { idempotencyKey: randomUUID() })
  assert.equal(ready.status, 'ready')
  const replyKey = randomUUID(), replyInput = { ticket_id: ticketId, expected_version: '2',
    message: 'Reviewed support reply', attachment_ids: [file.id] }
  const replied = await admin.call('support.admin-tickets.reply', replyInput, { idempotencyKey: replyKey })
  assert.equal(replied.row_version, '3')
  assert.deepEqual(await admin.call('support.admin-tickets.reply', replyInput, { idempotencyKey: replyKey }), replied)
  const attached = await admin.call('support.admin-tickets.attachments.get', { attachment_id: file.id })
  assert.equal(attached.status, 'attached'); assert.equal(attached.message_id, replied.message_id)
  const downloaded = await admin.call('support.admin-tickets.attachments.download', { attachment_id: file.id, chunk_index: 0 })
  assert.equal(createHash('sha256').update(Buffer.from(downloaded.content_base64, 'base64')).digest('hex'),
    createHash('sha256').update(content).digest('hex'))
  await admin.call('support.admin-tickets.unassign', { ticket_id: ticketId, expected_version: '3' }, { idempotencyKey: randomUUID() })
  const closed = await admin.call('support.admin-tickets.close', { ticket_id: ticketId, expected_version: '4' }, { idempotencyKey: randomUUID() })
  assert.equal(closed.row_version, '5'); assert.equal(closed.status, 1)
  const first = await admin.callResult('support.admin-tickets.audit.list', { ticket_id: ticketId, limit: 2 })
  assert.deepEqual(first.data.map(row => row.action), ['create', 'claim']); assert.equal(first.meta.has_more, true)
  const next = await admin.callResult('support.admin-tickets.audit.list', {
    ticket_id: ticketId, limit: 3, after_id: first.meta.next_after_id,
  })
  assert.deepEqual(next.data.map(row => row.action), ['reply', 'unassign', 'close']); assert.equal(next.meta.has_more, false)
  assert.equal(spawnSync('php', ['scripts/tests/core-browser/core-fixture.php', root, 'admin-revoke-support']).status, 0)
  await assert.rejects(admin.call('support.admin-tickets.claim', claimInput, { idempotencyKey: claimKey }), e => e.status === 403)
  return 13
}
