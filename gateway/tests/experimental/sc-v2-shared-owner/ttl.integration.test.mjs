import test from 'node:test'
import assert from 'node:assert/strict'
import { createSharedOwnerFixture } from './shared-owner.mjs'
import { realFixture, material, request, digest, keyPrefix, pause, failure, instruments, encode } from './fixtures.mjs'

test('real 70-second tombstone survives accepted interval; expiry never permits a replay after TTL deletion', { timeout: 80000 }, async t => {
  const redis = await realFixture(); t.after(() => redis.close())
  const key = await material(), now = Math.floor(Date.now() / 1000)
  const sender = await request(key, { issued_at: now + 4, expires_at: now + 60 })
  const owner = await createSharedOwnerFixture(redis.config(key.pair)); t.after(() => owner.close())
  const counter = instruments(); t.after(() => counter.close())
  const winner = await owner.open(sender.envelope), claim = `${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`
  const initial = await redis.client.pTTL(claim)
  assert.ok(initial > 69000 && initial <= 70000)
  await assert.rejects(owner.open(sender.envelope), failure)
  await assert.rejects(winner.seal(encode('invalid'), { kind: 'result', status: 500 }), failure)
  let elapsed = 0
  while (await redis.client.pTTL(claim) > 0) {
    await pause(1000); elapsed++
    if (elapsed % 10 === 0) console.log(`TTL_WAIT_SECONDS ${elapsed}`)
    if (elapsed === 50) {
      assert.ok(await redis.client.pTTL(claim) > 15000)
      await assert.rejects(owner.open(sender.envelope), failure)
    }
    assert.ok(elapsed <= 72)
  }
  assert.equal(await redis.client.pTTL(claim), -2)
  await assert.rejects(owner.open(sender.envelope), failure)
  assert.deepEqual(counter.counts, { exports: 0, imports: 0, encrypts: 0 })
})
