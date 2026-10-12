/** Local/testing fixture only. Continuous primary/no-loss assumption is NOT a crypto fence. */
import { readFileSync } from 'node:fs'
import { createHash, randomUUID } from 'node:crypto'
import { performance } from 'node:perf_hooks'
import { createClient } from 'redis'
import { openExperimentalRequest } from '../../../packages/theme-sdk/dist/experimental/sc-v2-crypto.js'
import { requestAad, requestDigestInput, fromBase64url, toBase64url, responseAad,
  validateTimeWindow } from '../../../packages/theme-sdk/dist/experimental/sc-v2-codec.js'

export class SharedOwnerFailure extends Error {
  constructor() { super('SC_OWNER_INVALID'); this.name = 'SharedOwnerFailure' }
}
const fail = () => new SharedOwnerFailure()
const claimScript = readFileSync(new URL('./claim.lua', import.meta.url), 'utf8')
const verifyScript = readFileSync(new URL('./verify-owner.lua', import.meta.url), 'utf8')
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
const fields = ['version', 'mode', 'domain', 'kid', 'operation', 'operation_version',
  'request_id', 'issued_at', 'expires_at', 'nonce', 'origin', 'path']
const prefix = epoch => `txbgw:lab:sc-owner:1:${epoch}`
const typedArray = Object.getPrototypeOf(Uint8Array.prototype)
const bufferOf = Object.getOwnPropertyDescriptor(typedArray, 'buffer').get
const lengthOf = Object.getOwnPropertyDescriptor(typedArray, 'byteLength').get
const offsetOf = Object.getOwnPropertyDescriptor(typedArray, 'byteOffset').get
const setBytes = Uint8Array.prototype.set
function requireValue(value) { if (!value) throw fail() }
function record(value, keys, optional = []) {
  requireValue(value !== null && typeof value === 'object')
  const prototype = Object.getPrototypeOf(value)
  requireValue(prototype === Object.prototype || prototype === null)
  const descriptors = Object.getOwnPropertyDescriptors(value), actual = Reflect.ownKeys(descriptors)
  requireValue(actual.every(key => typeof key === 'string' && (keys.includes(key) || optional.includes(key)))
    && keys.every(key => Object.hasOwn(descriptors, key)))
  const result = Object.create(null)
  for (const key of actual) {
    const descriptor = descriptors[key]
    requireValue(Object.hasOwn(descriptor, 'value') && descriptor.enumerable)
    result[key] = descriptor.value
  }
  return result
}
function bytes(value, max = 3056) {
  requireValue(value instanceof Uint8Array)
  const buffer = bufferOf.call(value), length = lengthOf.call(value), offset = offsetOf.call(value)
  requireValue(buffer instanceof ArrayBuffer && length <= max)
  const view = new Uint8Array(buffer, offset, length), result = new Uint8Array(length)
  setBytes.call(result, view)
  return result
}
function snapshotEnvelope(input, kid) {
  const row = record(input, ['metadata', 'enc', 'ct']), metadata = record(row.metadata, fields)
  requestAad(metadata)
  requireValue(metadata.kid === kid && metadata.domain === 'user' && metadata.operation === 'lab.echo'
    && metadata.operation_version === '1' && metadata.origin === 'https://sc-owner.example.test'
    && metadata.path === '/gateway/v2/channel/user'
    && Number.isSafeInteger(metadata.issued_at * 1000) && Number.isSafeInteger(metadata.expires_at * 1000))
  requireValue(typeof row.ct === 'string' && row.ct.length >= 22 && row.ct.length <= 4096)
  const enc = fromBase64url(row.enc, 65), ct = fromBase64url(row.ct, Math.floor(row.ct.length * 3 / 4))
  requireValue(ct.length >= 16 && ct.length <= 3072)
  requestDigestInput(metadata, enc, ct)
  return Object.freeze({ metadata: Object.freeze(metadata), enc: row.enc, ct: row.ct })
}
function digest(envelope) {
  const enc = fromBase64url(envelope.enc, 65)
  const ct = fromBase64url(envelope.ct, Math.floor(envelope.ct.length * 3 / 4))
  return createHash('sha256').update(requestDigestInput(envelope.metadata, enc, ct)).digest('base64url')
}
function runId(info) {
  requireValue(typeof info === 'string' && info.length <= 32768)
  const rows = info.split('\r\n').filter(row => row.startsWith('run_id:'))
  requireValue(rows.length === 1)
  const id = rows[0].slice(7)
  requireValue(id.length === 40 && !/[^0-9a-f]/.test(id))
  return id
}

/** No public raw digest, lifetime, claim receipt, Redis command or crypto context API. */
export async function createSharedOwnerFixture(input = {}) {
  let client, phase = 'starting'
  const holders = new Set()
  const discard = holder => {
    holder.seal = undefined
    if (holder.timer) clearTimeout(holder.timer)
    holder.timer = undefined
    holders.delete(holder)
  }
  const quarantine = () => {
    phase = 'closed'
    for (const holder of holders) discard(holder)
    try { if (client?.isOpen) client.destroy() } catch { /* Never emit native errors. */ }
  }
  try {
    const config = record(input, [], ['enabled', 'environment', 'socketPath', 'epoch', 'runId', 'keyPair'])
    if (config.enabled === undefined || config.enabled === '' || config.enabled === false || config.enabled === 'false') return undefined
    requireValue(config.enabled === true || config.enabled === 'true')
    requireValue(config.environment === 'local' || config.environment === 'testing')
    requireValue(typeof config.socketPath === 'string' && config.socketPath.length <= 240
      && (config.socketPath.startsWith('/tmp/txboard-sc-owner.') || config.socketPath.startsWith('\\\\.\\pipe\\txboard-sc-owner-')))
    requireValue(typeof config.epoch === 'string' && config.epoch.length === 36 && uuid.test(config.epoch)
      && typeof config.runId === 'string' && config.runId.length === 40 && !/[^0-9a-f]/.test(config.runId))
    const pair = record(config.keyPair, ['privateKey', 'publicKey'])
    requireValue(pair.privateKey instanceof CryptoKey && pair.publicKey instanceof CryptoKey
      && pair.privateKey.type === 'private' && pair.publicKey.type === 'public'
      && pair.privateKey.algorithm.name === 'ECDH' && pair.publicKey.algorithm.name === 'ECDH'
      && pair.privateKey.algorithm.namedCurve === 'P-256' && pair.publicKey.algorithm.namedCurve === 'P-256'
      && pair.privateKey.usages.includes('deriveBits'))
    const wall = Date.now.bind(Date), steady = performance.now.bind(performance)
    const wallAnchor = wall(), steadyAnchor = steady()
    const clock = () => {
      const now = wall(), elapsed = steady() - steadyAnchor
      if (!Number.isSafeInteger(now) || now <= 0 || !Number.isFinite(elapsed) || elapsed < 0
        || Math.abs(now - wallAnchor - elapsed) > 1000) { quarantine(); throw fail() }
      return now
    }
    const ready = () => {
      if (phase !== 'ready' || !client.isReady) { quarantine(); throw fail() }
      clock()
    }
    const bounded = (task, ms) => new Promise((resolve, reject) => {
      const start = steady()
      let settled = false
      const failure = () => {
        if (settled) return
        settled = true; clearTimeout(timer); quarantine(); reject(fail())
      }
      const timer = setTimeout(failure, ms)
      try {
        Promise.resolve(task()).then(value => {
          if (settled) return // Late ACK can never revive admission or an owner.
          if (phase === 'closed' || steady() - start >= ms || steady() < start) { failure(); return }
          settled = true; clearTimeout(timer); resolve(value)
        }, failure)
      } catch { failure() }
    })
    let kid
    const base = prefix(config.epoch), sentinel = `${config.epoch}:${config.runId}`
    client = createClient({ socket: { path: config.socketPath, connectTimeout: 2500, reconnectStrategy: false },
      disableOfflineQueue: true, commandsQueueMaxLength: 128 })
    client.on('error', quarantine)
    await bounded(async () => {
      kid = createHash('sha256').update(new Uint8Array(await crypto.subtle.exportKey('raw', pair.publicKey))).digest('hex')
      if (phase === 'closed') { quarantine(); throw fail() }
      await client.connect()
      if (phase === 'closed') { quarantine(); throw fail() }
      requireValue(client.isReady)
      const actualRun = runId(await client.info('server'))
      if (phase === 'closed') { quarantine(); throw fail() }
      requireValue(actualRun === config.runId)
      const actualSentinel = await client.get(`${base}:epoch`)
      if (phase === 'closed') { quarantine(); throw fail() }
      requireValue(actualSentinel === sentinel)
    }, 3000)
    phase = 'ready'; ready()
    let pending = 0
    const evaluate = async (script, verified, marker) => {
      ready()
      const result = await bounded(() => client.eval(script, { keys: [`${base}:epoch`, `${base}:${verified.digest}`],
        arguments: [sentinel, marker, String(verified.metadata.issued_at * 1000),
          String(verified.metadata.expires_at * 1000), String(clock())] }), 500)
      ready()
      const allowed = script === claimScript ? [0, 1, -1, -2, -3, -4] : [1, -1, -2, -3, -4, -5]
      if (!Number.isSafeInteger(result) || !allowed.includes(result)) { quarantine(); throw fail() }
      if (result === -1 || result === -2 || result === -4 || result === -5) { quarantine(); throw fail() }
      return result
    }
    // The brand and record never escape. Digest/expiry are computed only after HPKE authentication.
    const admitted = new WeakSet()
    const claim = async verified => {
      requireValue(admitted.has(verified))
      const marker = randomUUID()
      const result = await evaluate(claimScript, verified, marker)
      if (result !== 1) throw fail()
      validateTimeWindow(verified.metadata, Math.floor(clock() / 1000))
      return marker
    }
    return Object.freeze({
      open: async inputEnvelope => {
        let opened, outputPlaintext, openHolder, returned = false
        try {
          ready(); requireValue(pending + holders.size < 128)
          pending++
          try {
            const envelope = snapshotEnvelope(inputEnvelope, kid)
            validateTimeWindow(envelope.metadata, Math.floor(clock() / 1000))
            opened = await openExperimentalRequest(pair, envelope)
            ready(); validateTimeWindow(envelope.metadata, Math.floor(clock() / 1000))
            const verified = Object.freeze({ digest: digest(envelope), metadata: envelope.metadata })
            admitted.add(verified)
            const marker = await claim(verified)
            ready()
            const plaintext = new Uint8Array(opened.plaintext); outputPlaintext = plaintext
            opened.plaintext.fill(0)
            const holder = { seal: opened.sealResponse, timer: undefined }
            openHolder = holder
            opened = undefined
            holders.add(holder)
            holder.timer = setTimeout(() => discard(holder), Math.max(0, verified.metadata.expires_at * 1000 - clock()))
            holder.timer.unref?.()
            let spent = false
            const result = Object.freeze({ plaintext, seal: async (inputBytes, inputBinding) => {
              if (spent) throw fail()
              spent = true
              const owner = holder.seal; discard(holder)
              let copy
              try {
                ready(); requireValue(owner)
                validateTimeWindow(verified.metadata, Math.floor(clock() / 1000))
                const row = record(inputBinding, ['kind', 'status'])
                const binding = Object.freeze({ kind: row.kind, status: row.status })
                responseAad(verified.metadata, { request_digest: verified.digest, ...binding })
                copy = bytes(inputBytes)
                requireValue(await evaluate(verifyScript, verified, marker) === 1)
                ready(); validateTimeWindow(verified.metadata, Math.floor(clock() / 1000))
                const response = await owner(copy, binding)
                ready(); validateTimeWindow(verified.metadata, Math.floor(clock() / 1000))
                return response
              } catch { throw fail() }
              finally { copy?.fill(0) }
            } })
            returned = true
            return result
          } finally { pending-- }
        } catch { throw fail() }
        finally {
          opened?.plaintext.fill(0); opened = undefined
          if (!returned) { outputPlaintext?.fill(0); if (openHolder) discard(openHolder) }
        }
      },
      close: () => quarantine(),
    })
  } catch { quarantine(); throw fail() }
}
