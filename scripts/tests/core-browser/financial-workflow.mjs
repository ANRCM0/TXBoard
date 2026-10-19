import assert from 'node:assert/strict'
import { randomUUID } from 'node:crypto'
import { spawnSync } from 'node:child_process'

export async function verifyFinancialWorkflows(owner, buyer, admin, { root, actorId, adminId, offers }) {
  const fixture = (action, ...args) => {
    const run = spawnSync('php', ['scripts/tests/core-browser/core-fixture.php', root, action, ...args], { encoding: 'utf8', timeout: 30000 })
    assert.equal(run.status, 0, run.stderr || `Financial fixture failed: ${action}`)
    return JSON.parse(run.stdout)
  }
  let checks = 0
  const buyerId = (await buyer.call('identity.account.get', {})).id
  fixture('financial-fund', actorId, buyerId)
  const couponInput = { code: 'HTTP-SAVE01', name: 'SDK frozen discount', currency: 'CNY', discount_kind: 'fixed',
    value: '200', minimum_minor: '1000', total_limit: 2, user_limit: 1, starts_at: null, expires_at: null, offer_ids: [] }
  const couponKey = randomUUID()
  const coupon = await admin.call('billing.coupons.create', couponInput, { idempotencyKey: couponKey })
  assert.deepEqual(await admin.call('billing.coupons.create', couponInput, { idempotencyKey: couponKey }), coupon); checks++
  const couponPage = await admin.callResult('billing.coupons.list', { page: 1, per_page: 1 })
  assert.equal(couponPage.meta.total, 1); assert.equal(couponPage.data[0].used_total, 0); checks++
  await admin.call('billing.admin-commissions.policy.update', { enabled: true, rate_bps: 1000, hold_seconds: 0,
    refund_policy: 'net-before-vesting-retained-after/v1', expected_version: '0' }, { idempotencyKey: randomUUID() })
  const referral = await owner.call('identity.referrals.code.create', {}, { idempotencyKey: randomUUID() })
  const binding = await buyer.call('identity.referrals.bind', { invite_code: referral.invite_code }, { idempotencyKey: randomUUID() })
  assert.equal(binding.sponsor_id, actorId); checks++
  const offer = offers.find(row => row.offer_code === 'monthly')
  assert.equal(offer.price_minor, '1200')
  const orderKey = randomUUID(), orderInput = { offer_id: offer.offer_id, coupon_code: couponInput.code }
  const order = await buyer.call('commerce.orders.create', orderInput, { idempotencyKey: orderKey })
  assert.equal(order.amount_minor, '1000')
  const orderDetail = await buyer.call('commerce.orders.get', { trade_no: order.trade_no })
  assert.equal(orderDetail.coupon_discount_minor, '200'); checks++
  await admin.call('billing.coupons.disable', { coupon_id: coupon.id, expected_version: '1' }, { idempotencyKey: randomUUID() })
  assert.deepEqual(await buyer.call('commerce.orders.create', orderInput, { idempotencyKey: orderKey }), order); checks++
  const paid = await buyer.call('commerce.orders.checkout', { trade_no: order.trade_no, wallet: true }, { idempotencyKey: randomUUID() })
  assert.equal(paid.type, -1); checks++
  fixture('financial-recover')
  const rewards = await owner.call('billing.commissions.rewards.list', {})
  assert.equal(rewards.length, 1); assert.equal(rewards[0].state, 'accrued'); assert.equal(rewards[0].amount_minor, '100'); checks++
  assert.equal((await owner.call('billing.commissions.balance.get', {})).available_minor, '100')
  const payout = await owner.call('billing.commissions.payouts.request', { currency: 'CNY', amount_minor: '100' }, { idempotencyKey: randomUUID() })
  assert.equal(payout.state, 'requested'); checks++
  const decisionInput = { payout_id: payout.id, expected_version: '1', reason: 'http_reviewed' }, decisionKey = randomUUID()
  const approved = await admin.call('billing.admin-commissions.payouts.approve', decisionInput, { idempotencyKey: decisionKey })
  assert.equal(approved.state, 'approved')
  assert.deepEqual(await admin.call('billing.admin-commissions.payouts.approve', decisionInput, { idempotencyKey: decisionKey }), approved); checks++
  await assert.rejects(admin.call('billing.admin-commissions.payouts.reject', decisionInput,
    { idempotencyKey: randomUUID() }), error => error.status === 409); checks++
  const payoutPage = await admin.callResult('billing.admin-commissions.payouts.list', { page: 1, per_page: 1 })
  assert.equal(payoutPage.meta.total, 1); assert.equal(payoutPage.data[0].row_version, '2'); checks++
  const batchKey = randomUUID(), batchInput = { label: 'Actual SDK batch', quantity: 2, currency: 'CNY',
    amount_minor: '100', expires_at: Math.floor(Date.now() / 1000) + 3600 }
  const batch = await owner.call('billing.gift-batches.issue', batchInput, { idempotencyKey: batchKey })
  assert.equal(batch.batch.total_minor, '200'); checks++
  const redeemKey = randomUUID(), redeemInput = { code: batch.cards[0].code }
  const redeemed = await buyer.call('billing.gift-cards.redeem', redeemInput, { idempotencyKey: redeemKey })
  assert.equal(redeemed.state, 'redeemed')
  assert.deepEqual(await buyer.call('billing.gift-cards.redeem', redeemInput, { idempotencyKey: redeemKey }), redeemed); checks++
  await admin.call('billing.admin-gift-cards.revoke', { card_id: batch.cards[1].card_id, expected_version: '1' }, { idempotencyKey: randomUUID() })
  const current = await owner.call('billing.gift-batches.issue', batchInput, { idempotencyKey: batchKey })
  assert.deepEqual(current.cards.map(row => row.state), ['redeemed', 'revoked'])
  assert.deepEqual(current.cards.map(row => row.code), batch.cards.map(row => row.code)); checks++
  const detail = await owner.call('billing.gift-batches.get', { batch_id: batch.batch.batch_id })
  assert.ok(detail.cards.every(row => !('code' in row)))
  await assert.rejects(buyer.call('billing.gift-batches.get', { batch_id: batch.batch.batch_id }), error => error.status === 404); checks++
  fixture('financial-revoke', adminId)
  await assert.rejects(admin.call('billing.coupons.create', couponInput, { idempotencyKey: couponKey }), error => error.status === 403)
  await assert.rejects(admin.call('billing.admin-commissions.payouts.approve', decisionInput, { idempotencyKey: decisionKey }), error => error.status === 403); checks++
  return checks
}
