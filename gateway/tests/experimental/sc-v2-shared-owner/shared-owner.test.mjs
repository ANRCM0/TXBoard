import test from 'node:test'
import assert from 'node:assert/strict'
import { performance } from 'node:perf_hooks'
import { createSharedOwnerFixture, SharedOwnerFailure } from './shared-owner.mjs'
import { fakeRedis, material, request, encode, digest, instruments, failure, pause, keyPrefix } from './fixtures.mjs'

async function fixture(t) {
  const redis = await fakeRedis(), key = await material()
  t.after(() => redis.close())
  const owner = await createSharedOwnerFixture(redis.config(key.pair))
  t.after(() => owner.close())
  return { redis, key, owner }
}
function counters(t) { const count = instruments(); t.after(() => count.close()); return count }
const zero = { exports: 0, imports: 0, encrypts: 0 }

test('default off, strict enable/environment/config and no raw claim API', async () => {
  for (const enabled of [undefined, '', false, 'false']) assert.equal(await createSharedOwnerFixture({ enabled }), undefined)
  for (const enabled of ['TRUE', ' false ', '1', 1, null]) await assert.rejects(createSharedOwnerFixture({ enabled }), failure)
  await assert.rejects(createSharedOwnerFixture({ enabled: true, environment: 'production' }), failure)
  await assert.rejects(createSharedOwnerFixture({ enabled: true, environment: 'testing', digest: 'self-selected' }), failure)
  let accessed = false
  await assert.rejects(createSharedOwnerFixture({ get enabled() { accessed = true; return true } }), failure)
  assert.equal(accessed, false)
})

test('late startup crypto Promise after total setup deadline cannot connect or revive factory', async t => {
  const redis = await fakeRedis(), key = await material(), original = crypto.subtle.exportKey
  t.after(() => redis.close()); t.after(() => { crypto.subtle.exportKey = original })
  crypto.subtle.exportKey = async function (...args) {
    const result = await original.apply(this, args); await pause(3300); return result
  }
  await assert.rejects(createSharedOwnerFixture(redis.config(key.pair)), failure)
  await pause(500)
  assert.equal(redis.stats.commands, 0); assert.equal(redis.stats.claims, 0)
})

test('only a verified fixed operation can claim; canonical/extra/accessor/symbol input fails before Redis', async t => {
  const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
  for (const change of [
    { ...sender.envelope, digest: 'forged', expires_at: 0 },
    { ...sender.envelope, ct: sender.envelope.ct + '\n' },
    { ...sender.envelope, metadata: { ...sender.envelope.metadata, extra: true } },
    { ...sender.envelope, metadata: { ...sender.envelope.metadata, [Symbol('extra')]: true } },
  ]) await assert.rejects(owner.open(change), failure)
  let invoked = false
  const getter = { ...sender.envelope }
  Object.defineProperty(getter, 'ct', { enumerable: true, get() { invoked = true; return sender.envelope.ct } })
  await assert.rejects(owner.open(getter), failure)
  assert.equal(invoked, false); assert.equal(redis.stats.claims, 0); assert.deepEqual(count.counts, zero)
  count.close()
  for (const patch of [{ operation: 'user.profile.get' }, { domain: 'admin', path: '/gateway/v2/channel/admin' },
    { operation_version: '2' }, { origin: 'https://other.example.test' }, { kid: 'a'.repeat(64) }]) {
    const wrong = await request(key, patch)
    await assert.rejects(owner.open(wrong.envelope), failure)
  }
  assert.equal(redis.stats.claims, 0)
})

test('bad HPKE tag cannot claim or export a response', async t => {
  const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
  const ct = Buffer.from(sender.envelope.ct, 'base64url'); ct[0] ^= 1
  await assert.rejects(owner.open({ ...sender.envelope, ct: ct.toString('base64url') }), failure)
  assert.equal(redis.stats.claims, 0); assert.deepEqual(count.counts, zero)
})

test('only first owner can export and encrypt, including duplicate errors', async t => {
  const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
  const outcomes = await Promise.allSettled(Array.from({ length: 8 }, () => owner.open(sender.envelope)))
  assert.equal(outcomes.filter(row => row.status === 'fulfilled').length, 1)
  assert.deepEqual(count.counts, zero)
  const winner = outcomes.find(row => row.status === 'fulfilled').value
  assert.deepEqual(Object.keys(winner), ['plaintext', 'seal']); assert.equal(Object.isFrozen(winner), true)
  const response = await winner.seal(encode('response'), { kind: 'error', status: 403 })
  assert.deepEqual(count.counts, { exports: 2, imports: 1, encrypts: 1 })
  assert.deepEqual(await sender.openResponse(response), encode('response'))
  assert.deepEqual(winner.plaintext, encode('fixture request'))
  await assert.rejects(winner.seal(encode('again'), { kind: 'error', status: 500 }), failure)
  assert.equal(redis.keys.size, 2)
})

test('one-shot wrapper claims before Proxy reentry or getter access and retains tombstones', async t => {
  const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
  const first = await owner.open(sender.envelope)
  let nested
  const binding = new Proxy({ kind: 'result', status: 200 }, { getPrototypeOf(target) {
    nested = first.seal(encode('loser'), { kind: 'error', status: 500 }); void nested.catch(() => {})
    return Reflect.getPrototypeOf(target)
  } })
  await first.seal(encode('winner'), binding)
  await assert.rejects(nested, failure)
  assert.deepEqual(count.counts, { exports: 2, imports: 1, encrypts: 1 })
  assert.ok(redis.keys.has(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`))
})

test('reserve capacity before Proxy reflection reentry: at most 128 authenticated contexts/claims', async t => {
  const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
  const original = crypto.subtle.deriveBits
  let opens = 0, reflections = 0
  const pending = []
  crypto.subtle.deriveBits = function (...args) { if (args[0]?.name === 'ECDH') opens++; return original.apply(this, args) }
  t.after(() => { crypto.subtle.deriveBits = original })
  const input = new Proxy(sender.envelope, { getPrototypeOf(target) {
    reflections++
    if (reflections <= 200) {
      const result = owner.open(input); void result.catch(() => {}); pending.push(result)
    }
    return Reflect.getPrototypeOf(target)
  } })
  const first = owner.open(input); void first.catch(() => {}); pending.push(first)
  const results = await Promise.allSettled(pending)
  assert.equal(reflections, 128); assert.equal(opens, 128); assert.equal(redis.stats.claims, 128)
  assert.equal(results.filter(row => row.status === 'fulfilled').length, 1)
  assert.equal(results.filter(row => row.status === 'rejected').length, 128)
  assert.deepEqual(count.counts, zero)
})

test('close while HPKE digest awaits returns no holder/plaintext and performs no claim/export', async t => {
  const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
  const original = crypto.subtle.digest, originalDecrypt = crypto.subtle.decrypt, internalPlaintexts = []
  let announce, resume
  const reached = new Promise(resolve => { announce = resolve })
  const barrier = new Promise(resolve => { resume = resolve })
  crypto.subtle.digest = async function (...args) {
    const result = await original.apply(this, args); announce(); await barrier; return result
  }
  crypto.subtle.decrypt = async function (...args) {
    const result = await originalDecrypt.apply(this, args); internalPlaintexts.push(new Uint8Array(result)); return result
  }
  t.after(() => { crypto.subtle.digest = original; crypto.subtle.decrypt = originalDecrypt; resume() })
  const opening = owner.open(sender.envelope); void opening.catch(() => {})
  await reached; owner.close(); resume()
  await assert.rejects(opening, failure)
  assert.equal(redis.stats.claims, 0); assert.deepEqual(count.counts, zero)
  assert.equal(internalPlaintexts.length, 1)
  assert.ok(internalPlaintexts[0].every(value => value === 0))
  await assert.rejects(owner.open(sender.envelope), failure)
})

test('snapshot envelope, nonzero-offset Buffer, binding before Redis await and preserve returned bytes', async t => {
  const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
  const mutable = { ...sender.envelope, metadata: { ...sender.envelope.metadata } }
  const pending = owner.open(mutable); mutable.metadata.operation = 'user.profile.get'; mutable.ct = 'invalid'
  const winner = await pending
  redis.behavior.verifyDelay = 30
  const backing = Buffer.from('beforeVALUEafter'), input = backing.subarray(6, 11)
  const binding = Object.assign(Object.create(null), { kind: 'result', status: 200 })
  const sealing = winner.seal(input, binding); backing.fill(0); binding.status = 201
  const response = await sealing
  assert.equal(response.status, 200); assert.deepEqual(count.counts, { exports: 2, imports: 1, encrypts: 1 })
  assert.deepEqual(await sender.openResponse(response), encode('VALUE'))
  assert.deepEqual(winner.plaintext, encode('fixture request'))
})

test('postclaim invalid, accessor, shared, detached, and oversized response attempts consume without export', async t => {
  const { redis, key, owner } = await fixture(t)
  let getterInvoked = false
  const detachedBuffer = new ArrayBuffer(1), detached = new Uint8Array(detachedBuffer)
  structuredClone(detachedBuffer, { transfer: [detachedBuffer] })
  const cases = [
    [encode('wrong kind'), { kind: 'result', status: 500 }],
    [encode('getter'), { get kind() { getterInvoked = true; return 'result' }, status: 200 }],
    [new Uint8Array(new SharedArrayBuffer(1)), { kind: 'result', status: 200 }],
    [detached, { kind: 'result', status: 200 }],
    [new Uint8Array(3057), { kind: 'result', status: 200 }],
  ]
  for (const [bytes, binding] of cases) {
    const sender = await request(key), count = counters(t), winner = await owner.open(sender.envelope)
    await assert.rejects(winner.seal(bytes, binding), failure)
    await assert.rejects(winner.seal(encode('retry'), { kind: 'error', status: 500 }), failure)
    await assert.rejects(owner.open(sender.envelope), failure)
    assert.deepEqual(count.counts, zero)
    assert.ok(redis.keys.has(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`)); count.close()
  }
  assert.equal(getterInvoked, false)
})

test('malformed or unexpected first-owner ACK quarantines, no later reply or request can revive', async t => {
  for (const reply of ['$-1\r\n', '+OK\r\n', ':2\r\n']) {
    const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
    redis.behavior.claimReply = reply
    await assert.rejects(owner.open(sender.envelope), failure)
    const claims = redis.stats.claims
    await assert.rejects(owner.open(sender.envelope), failure)
    assert.equal(redis.stats.claims, claims); assert.equal(claims, 1); assert.deepEqual(count.counts, zero)
    assert.ok(redis.keys.has(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`)); count.close()
  }
})

test('total command deadline consumes ambiguous claim and late synthetic Promise/reply cannot revive', async t => {
  const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
  redis.behavior.claimDelay = 850
  const start = performance.now()
  await assert.rejects(owner.open(sender.envelope), failure)
  assert.ok(performance.now() - start < 750)
  assert.ok(redis.keys.has(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`))
  await pause(500)
  await assert.rejects(owner.open(sender.envelope), failure)
  assert.equal(redis.stats.claims, 1); assert.deepEqual(count.counts, zero)
})

test('verify timeout, missing owner and epoch/clock error all consume with no response export', async t => {
  for (const value of ['timeout', -5, -1, -2]) {
    const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
    const winner = await owner.open(sender.envelope)
    if (value === 'timeout') redis.behavior.verifyDelay = 850
    else redis.behavior.verifyReply = `:${value}\r\n`
    await assert.rejects(winner.seal(encode('unused'), { kind: 'result', status: 200 }), failure)
    await assert.rejects(winner.seal(encode('error'), { kind: 'error', status: 503 }), failure)
    assert.deepEqual(count.counts, zero)
    assert.ok(redis.keys.has(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`)); count.close()
  }
})

test('wall-clock drift and monotonic regression close factory before any claim', async t => {
  const originalWall = Date.now, originalSteady = performance.now
  let wallOffset = 0, steadyOffset = 0
  Date.now = () => originalWall() + wallOffset
  Object.defineProperty(performance, 'now', { configurable: true, value: () => originalSteady.call(performance) + steadyOffset })
  t.after(() => { Date.now = originalWall; Object.defineProperty(performance, 'now', { configurable: true, value: originalSteady }) })
  for (const phase of ['wall', 'monotonic']) {
    wallOffset = 0; steadyOffset = 0
    const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
    if (phase === 'wall') wallOffset = -2000
    else steadyOffset = -2000
    await assert.rejects(owner.open(sender.envelope), failure)
    assert.equal(redis.stats.claims, 0); assert.deepEqual(count.counts, zero); count.close()
  }
})

test('expired-before-admission and expired postclaim attempts never release the record', async t => {
  const original = Date.now
  let offset = 0
  Date.now = () => original() + offset; t.after(() => { Date.now = original })
  const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
  const winner = await owner.open(sender.envelope)
  offset = 61000
  await assert.rejects(winner.seal(encode('unused'), { kind: 'error', status: 500 }), failure)
  assert.equal(redis.stats.verifies, 0); assert.deepEqual(count.counts, zero)
  assert.ok(redis.keys.has(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`))
  offset = 0
  await assert.rejects(winner.seal(encode('retry'), { kind: 'error', status: 500 }), failure)
})

test('crypto failure/completion after expiry consumes already valid winner and retains claim', async t => {
  const originalWall = Date.now, originalEncrypt = crypto.subtle.encrypt
  let offset = 0
  Date.now = () => originalWall() + offset
  t.after(() => { Date.now = originalWall; crypto.subtle.encrypt = originalEncrypt })
  const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
  const instrumentedEncrypt = crypto.subtle.encrypt
  const winner = await owner.open(sender.envelope)
  crypto.subtle.encrypt = async function (...args) { const result = await instrumentedEncrypt.apply(this, args); offset = 61000; return result }
  await assert.rejects(winner.seal(encode('refused late output'), { kind: 'result', status: 200 }), failure)
  assert.deepEqual(count.counts, { exports: 2, imports: 1, encrypts: 1 })
  assert.ok(redis.keys.has(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`))
  offset = 0
  await assert.rejects(winner.seal(encode('second'), { kind: 'error', status: 500 }), failure)
})

test('actual expiry refuses before claim and releases unused local holder without deleting tombstone', async t => {
  const { redis, key, owner } = await fixture(t)
  const now = Math.floor(Date.now() / 1000), sender = await request(key, { issued_at: now, expires_at: now + 2 })
  const counter = counters(t), winner = await owner.open(sender.envelope)
  await pause(Math.max(0, (now + 2) * 1000 - Date.now()) + 30)
  const claims = redis.stats.claims
  await assert.rejects(owner.open(sender.envelope), failure)
  assert.equal(redis.stats.claims, claims)
  await assert.rejects(winner.seal(encode('unused'), { kind: 'error', status: 503 }), failure)
  assert.equal(redis.stats.verifies, 0); assert.deepEqual(counter.counts, zero)
  assert.ok(redis.keys.has(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`))
})

test('crypto import failure after claim cannot retry/export another response or release tombstone', async t => {
  const { redis, key, owner } = await fixture(t), sender = await request(key), counter = counters(t)
  const winner = await owner.open(sender.envelope), original = crypto.subtle.importKey
  crypto.subtle.importKey = function (...args) {
    if (args[0] === 'raw' && args[2] === 'AES-GCM' && args[4][0] === 'encrypt') return Promise.reject(Error('native private error'))
    return original.apply(this, args)
  }
  try {
    await assert.rejects(winner.seal(encode('secret'), { kind: 'result', status: 200 }), failure)
    assert.equal(counter.counts.exports, 1); assert.equal(counter.counts.encrypts, 0)
    await assert.rejects(winner.seal(encode('retry'), { kind: 'error', status: 503 }), failure)
    await assert.rejects(owner.open(sender.envelope), failure)
    assert.equal(counter.counts.exports, 1); assert.equal(counter.counts.encrypts, 0)
    assert.ok(redis.keys.has(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`))
  } finally { crypto.subtle.importKey = original }
})

test('close discards all owners without deleting claims; failure contains no native cause or payload', async t => {
  const { redis, key, owner } = await fixture(t), sender = await request(key), count = counters(t)
  const winner = await owner.open(sender.envelope)
  owner.close()
  await assert.rejects(winner.seal(encode('private input'), { kind: 'error', status: 500 }), error => {
    assert.ok(error instanceof SharedOwnerFailure); assert.equal(error.message, 'SC_OWNER_INVALID'); assert.equal(error.cause, undefined)
    assert.equal(String(error).includes('private input'), false); return true
  })
  assert.deepEqual(count.counts, zero)
  assert.ok(redis.keys.has(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`))
})
