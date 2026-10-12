import test from 'node:test'
import assert from 'node:assert/strict'
import { fork } from 'node:child_process'
import { once } from 'node:events'
import { readFile } from 'node:fs/promises'
import { performance } from 'node:perf_hooks'
import { randomUUID } from 'node:crypto'
import { createSharedOwnerFixture } from './shared-owner.mjs'
import { realFixture, material, request, digest, instruments, encode, failure, pause, keyPrefix, ackProxy } from './fixtures.mjs'
const zero = { exports: 0, imports: 0, encrypts: 0 }
const listen = (child, type) => new Promise((resolve, reject) => {
  const timer = setTimeout(() => done(Error('fixture child deadline')), 7000)
  function done(error, value) { clearTimeout(timer); child.off('message', message); child.off('exit', exit); error ? reject(error) : resolve(value) }
  function message(value) { if (value.type === type) done(undefined, value); else if (value.type === 'failure') done(Error('fixture child rejected')) }
  function exit() { done(Error('fixture child exited early')) }
  child.on('message', message); child.on('exit', exit)
})
function child(t) {
  const process = fork(new URL('./worker.mjs', import.meta.url), [], { stdio: ['ignore', 'ignore', 'ignore', 'ipc'] })
  t.after(async () => {
    if (process.exitCode === null && process.signalCode === null) {
      const exit = once(process, 'exit')
      process.kill('SIGKILL'); await exit
    }
  })
  return process
}
async function initialized(t, mode, context, key, envelope) {
  const process = child(t), ready = listen(process, 'ready')
  process.send({ type: 'init', mode, jwk: key.jwk, envelope, config: { enabled: true, environment: 'testing',
    socketPath: context.path, epoch: context.epoch, runId: context.runId } })
  await ready; return process
}
function count(t) { const result = instruments(); t.after(() => result.close()); return result }
async function fixture(t) { const redis = await realFixture(); t.after(() => redis.close()); return redis }

test('real Redis: 64 independent clients in four workers produce one owner and one response encryption', async t => {
  const redis = await fixture(t), key = await material(), sender = await request(key)
  const children = await Promise.all(Array.from({ length: 4 }, () => initialized(t, 'concurrent', redis, key, sender.envelope)))
  const pending = children.map(process => listen(process, 'result'))
  for (const process of children) process.send({ type: 'go' })
  const results = await Promise.all(pending)
  assert.equal(results.reduce((sum, row) => sum + row.winners, 0), 1)
  assert.equal(results.reduce((sum, row) => sum + row.refused, 0), 63)
  for (const metric of ['exports', 'imports', 'encrypts']) assert.equal(results.reduce((sum, row) => sum + row.counts[metric], 0), metric === 'exports' ? 2 : 1)
  const winner = results.find(row => row.winners === 1)
  assert.deepEqual(await sender.openResponse(winner.response), encode('one fixture response'))
  assert.ok(await redis.client.pTTL(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`) > 65000)
})

test('real Redis: independent fresh requests own separately; postclaim bad response retains tombstone', async t => {
  const redis = await fixture(t), key = await material(), a = await request(key), b = await request(key)
  const owner = await createSharedOwnerFixture(redis.config(key.pair)); t.after(() => owner.close())
  const counter = count(t)
  const [first, second] = await Promise.all([owner.open(a.envelope), owner.open(b.envelope)])
  await assert.rejects(first.seal(encode('invalid'), { kind: 'result', status: 500 }), failure)
  await assert.rejects(owner.open(a.envelope), failure)
  assert.deepEqual(counter.counts, zero)
  await second.seal(encode('valid'), { kind: 'error', status: 422 })
  assert.deepEqual(counter.counts, { exports: 2, imports: 1, encrypts: 1 })
  for (const sender of [a, b]) assert.ok(await redis.client.pTTL(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`) > 65000)
})

test('real Redis: SIGKILL after claim cannot authorize reconstructed response material', async t => {
  const redis = await fixture(t), key = await material(), sender = await request(key)
  const process = await initialized(t, 'crash', redis, key, sender.envelope), claimed = listen(process, 'claimed')
  process.send({ type: 'go' }); assert.deepEqual((await claimed).counts, zero)
  const exit = once(process, 'exit'); process.kill('SIGKILL'); const [, signal] = await exit
  assert.equal(signal, 'SIGKILL')
  const retry = await initialized(t, 'retry', redis, key, sender.envelope), result = listen(retry, 'result')
  retry.send({ type: 'go' }); const row = await result
  assert.equal(row.winners, 0); assert.equal(row.refused, 1); assert.deepEqual(row.counts, zero)
  assert.ok(await redis.client.pTTL(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`) > 65000)
})

for (const mode of ['drop', 'delay', 'drip']) test(`real Redis: executed claim with ${mode} ACK cannot revive after total deadline`, async t => {
  const redis = await fixture(t), proxy = await ackProxy(redis.path, mode); t.after(() => proxy.close())
  const key = await material(), sender = await request(key)
  const owner = await createSharedOwnerFixture({ ...redis.config(key.pair), socketPath: proxy.path }); t.after(() => owner.close())
  const counter = count(t), start = performance.now()
  await assert.rejects(owner.open(sender.envelope), failure)
  assert.ok(performance.now() - start < 750)
  assert.ok(await redis.client.pTTL(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`) > 65000)
  assert.equal(proxy.stats.dropped + proxy.stats.delayed, 1)
  await pause(600)
  await assert.rejects(owner.open(sender.envelope), failure)
  const other = await createSharedOwnerFixture(redis.config(key.pair)); t.after(() => other.close())
  await assert.rejects(other.open(sender.envelope), failure)
  assert.deepEqual(counter.counts, zero)
})

test('real Redis: Node clock disagrees with Redis TIME; no claim or response material', async t => {
  const original = Date.now
  Date.now = () => original() + 2500; t.after(() => { Date.now = original })
  const redis = await fixture(t), key = await material(), sender = await request(key)
  const owner = await createSharedOwnerFixture(redis.config(key.pair)); t.after(() => owner.close())
  const counter = count(t)
  await assert.rejects(owner.open(sender.envelope), failure)
  assert.equal(await redis.client.exists(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`), 0)
  assert.deepEqual(counter.counts, zero)
  await assert.rejects(owner.open(sender.envelope), failure)
})

test('real Redis: clock mismatch after claim refuses pre-seal without exporter/encryption', async t => {
  const original = Date.now
  let offset = 0
  Date.now = () => original() + offset; t.after(() => { Date.now = original })
  const redis = await fixture(t), key = await material(), sender = await request(key)
  const owner = await createSharedOwnerFixture(redis.config(key.pair)); t.after(() => owner.close())
  const counter = count(t), admitted = await owner.open(sender.envelope)
  offset = 2500
  await assert.rejects(admitted.seal(encode('unused'), { kind: 'error', status: 503 }), failure)
  assert.equal(await redis.client.exists(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`), 1)
  assert.deepEqual(counter.counts, zero)
})

test('real Redis: malformed Lua admission cannot create records', async t => {
  const redis = await fixture(t), source = await readFile(new URL('./claim.lua', import.meta.url), 'utf8')
  const base = keyPrefix(redis.epoch), key = `${base}:invalid-fixture-digest`
  const now = Date.now(), valid = [`${redis.epoch}:${redis.runId}`, randomUUID(), String(now), String(now + 60000), String(now)]
  for (const [index, value] of [[2, '1\n'], [2, '01'], [3, '9007199254740992'], [3, String(now + 60001)], [0, 'bad']]) {
    const args = [...valid]; args[index] = value
    assert.equal(await redis.client.eval(source, { keys: [`${base}:epoch`, key], arguments: args }), -4)
    assert.equal(await redis.client.exists(key), 0)
  }
})

test('real Redis: pre-seal known missing owner and epoch change refuse without reacquire', async t => {
  for (const mode of ['missing-owner', 'epoch']) {
    const redis = await fixture(t), key = await material(), sender = await request(key)
    const owner = await createSharedOwnerFixture(redis.config(key.pair)); t.after(() => owner.close())
    const counter = count(t), admitted = await owner.open(sender.envelope)
    const claim = `${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`
    // Deliberate destructive fault only in the private fixture, never a recovery command.
    if (mode === 'missing-owner') await redis.client.del(claim)
    else await redis.client.set(`${keyPrefix(redis.epoch)}:epoch`, 'known-loss')
    await assert.rejects(admitted.seal(encode('refused'), { kind: 'error', status: 503 }), failure)
    await assert.rejects(owner.open(sender.envelope), failure)
    assert.deepEqual(counter.counts, zero)
    if (mode === 'missing-owner') assert.equal(await redis.client.exists(claim), 0)
    else assert.equal(await redis.client.exists(claim), 1)
    counter.close()
  }
})

test('raw non-crypto NX demonstration: lost record allows second winner and is NOT a crypto fence', async t => {
  const redis = await fixture(t), source = await readFile(new URL('./claim.lua', import.meta.url), 'utf8')
  const base = keyPrefix(redis.epoch), key = `${base}:raw-loss-proof-no-encryption`, now = Date.now()
  const parameters = marker => ({ keys: [`${base}:epoch`, key], arguments: [`${redis.epoch}:${redis.runId}`,
    marker, String(now), String(now + 60000), String(Date.now())] })
  const counter = count(t)
  assert.equal(await redis.client.eval(source, parameters(randomUUID())), 1)
  assert.equal(await redis.client.eval(source, parameters(randomUUID())), 0)
  await redis.client.del(key)
  assert.equal(await redis.client.eval(source, parameters(randomUUID())), 1)
  assert.deepEqual(counter.counts, zero)
})

test('real Redis: noeviction OOM fails closed and existing ownership is not released', async t => {
  const redis = await fixture(t), key = await material(), sender = await request(key)
  const owner = await createSharedOwnerFixture(redis.config(key.pair)); t.after(() => owner.close())
  const counter = count(t), admitted = await owner.open(sender.envelope)
  const memory = (await redis.client.info('memory')).split('\r\n').find(line => line.startsWith('used_memory:')).slice(12)
  const previous = await redis.client.configGet('maxmemory')
  await redis.client.configSet('maxmemory', String(Number(memory) - 1))
  try {
    // Claim script performs writes; OOM denies even a duplicate rather than making a new owner.
    await assert.rejects(owner.open(sender.envelope), failure)
    assert.equal(await redis.client.exists(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`), 1)
    await assert.rejects(admitted.seal(encode('unused'), { kind: 'error', status: 503 }), failure)
    assert.deepEqual(counter.counts, zero)
  } finally { await redis.client.configSet('maxmemory', previous.maxmemory) }
})

test('real Redis: changed pinned run ID refuses startup', async t => {
  const redis = await fixture(t), key = await material(), counter = count(t)
  await assert.rejects(createSharedOwnerFixture({ ...redis.config(key.pair), runId: 'f'.repeat(40) }), failure)
  assert.deepEqual(counter.counts, zero)
})

test('real Redis: unavailable server at startup and outage after claim discard all owners', async t => {
  const redis = await fixture(t), key = await material(), sender = await request(key)
  const owner = await createSharedOwnerFixture(redis.config(key.pair)); t.after(() => owner.close())
  const counter = count(t), admitted = await owner.open(sender.envelope)
  assert.equal(await redis.client.exists(`${keyPrefix(redis.epoch)}:${digest(sender.envelope)}`), 1)
  const pid = Number(process.env.SC_OWNER_REDIS_PID)
  assert.ok(Number.isSafeInteger(pid) && pid > 1)
  process.kill(pid, 'SIGTERM'); await pause(150)
  await assert.rejects(admitted.seal(encode('unused'), { kind: 'error', status: 503 }), failure)
  await assert.rejects(createSharedOwnerFixture(redis.config(key.pair)), failure)
  assert.deepEqual(counter.counts, zero)
})
