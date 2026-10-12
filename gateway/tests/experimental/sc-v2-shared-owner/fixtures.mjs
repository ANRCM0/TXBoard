/** Test helpers only: public APIs here are not package/production exports. */
import { createHash, generateKeyPairSync, randomBytes, randomUUID } from 'node:crypto'
import { createServer, createConnection } from 'node:net'
import { mkdtemp, rm } from 'node:fs/promises'
import { join } from 'node:path'
import { tmpdir } from 'node:os'
import { createClient } from 'redis'
import { createExperimentalRequest } from '../../../packages/theme-sdk/dist/experimental/sc-v2-crypto.js'
import { fromBase64url, requestDigestInput, toBase64url } from '../../../packages/theme-sdk/dist/experimental/sc-v2-codec.js'
export const failure = /SC_OWNER_INVALID/
export const pause = ms => new Promise(resolve => setTimeout(resolve, ms))
export const encode = value => new TextEncoder().encode(value)
export const keyPrefix = epoch => `txbgw:lab:sc-owner:1:${epoch}`
export async function material(jwk) {
  const privateJwk = jwk ?? generateKeyPairSync('ec', { namedCurve: 'prime256v1' }).privateKey.export({ format: 'jwk' })
  const { d: _fixtureScalar, ...publicJwk } = privateJwk
  const algorithm = { name: 'ECDH', namedCurve: 'P-256' }
  const pair = { privateKey: await crypto.subtle.importKey('jwk', privateJwk, algorithm, false, ['deriveBits']),
    publicKey: await crypto.subtle.importKey('jwk', publicJwk, algorithm, true, []) }
  const kid = createHash('sha256').update(new Uint8Array(await crypto.subtle.exportKey('raw', pair.publicKey))).digest('hex')
  return { pair, kid, jwk: privateJwk }
}
export function metadata(kid, patch = {}) {
  const now = Math.floor(Date.now() / 1000)
  return { version: 2, mode: 0, domain: 'user', kid, operation: 'lab.echo', operation_version: '1',
    request_id: randomUUID(), issued_at: now, expires_at: now + 60, nonce: toBase64url(randomBytes(32)),
    origin: 'https://sc-owner.example.test', path: '/gateway/v2/channel/user', ...patch }
}
export function request(key, patch = {}, plaintext = encode('fixture request')) {
  return createExperimentalRequest(key.pair.publicKey, metadata(key.kid, patch), plaintext)
}
export function digest(envelope) {
  return createHash('sha256').update(requestDigestInput(envelope.metadata, fromBase64url(envelope.enc, 65),
    fromBase64url(envelope.ct, Math.floor(envelope.ct.length * 3 / 4)))).digest('base64url')
}
export function instruments() {
  const original = {}, counts = { exports: 0, imports: 0, encrypts: 0 }
  for (const method of ['sign', 'importKey', 'encrypt']) original[method] = crypto.subtle[method]
  // Pinned common1.10.1 labeledExpand uses one HMAC block for each 32/12-byte export.
  crypto.subtle.sign = function (...args) {
    if ((args[0] === 'HMAC' || args[0]?.name === 'HMAC')
      && Buffer.from(args[2]).includes(Buffer.from('txboard.sc-v2-codec/1/response/'))) counts.exports++
    return original.sign.apply(this, args)
  }
  crypto.subtle.importKey = function (...args) {
    if (args[0] === 'raw' && (args[2] === 'AES-GCM' || args[2]?.name === 'AES-GCM')
      && args[4]?.length === 1 && args[4][0] === 'encrypt') counts.imports++
    return original.importKey.apply(this, args)
  }
  crypto.subtle.encrypt = function (...args) { counts.encrypts++; return original.encrypt.apply(this, args) }
  return { counts, reset: () => { for (const key of Object.keys(counts)) counts[key] = 0 },
    close: () => { for (const key of Object.keys(original)) crypto.subtle[key] = original[key] } }
}
export async function pathFixture() {
  const directory = await mkdtemp(join(tmpdir(), 'txboard-sc-owner.'))
  const path = process.platform === 'win32' ? `\\\\.\\pipe\\txboard-sc-owner-${randomUUID()}` : join(directory, 'redis.sock')
  return { path, close: () => rm(directory, { recursive: true, force: true }) }
}
/** Bounded RESP2 parser used only by synthetic peers/proxies. */
export function frame(buffer, offset = 0, depth = 0) {
  if (depth > 10 || buffer.length > 262144) throw Error('fixture frame bounds')
  if (offset >= buffer.length) return undefined
  const end = buffer.indexOf('\r\n', offset)
  if (end < 0) return undefined
  const type = String.fromCharCode(buffer[offset]), text = buffer.subarray(offset + 1, end).toString()
  let next = end + 2
  if ('+-:'.includes(type)) return { value: type === ':' ? Number(text) : text, next }
  if (type === '$') {
    const length = Number(text)
    if (length === -1) return { value: null, next }
    if (!Number.isSafeInteger(length) || length < 0 || length > 65536) throw Error('fixture bulk bounds')
    if (buffer.length < next + length + 2) return undefined
    return { value: buffer.subarray(next, next + length).toString(), next: next + length + 2 }
  }
  if (type === '*') {
    const length = Number(text), value = []
    if (length === -1) return { value: null, next }
    if (!Number.isSafeInteger(length) || length < 0 || length > 128) throw Error('fixture array bounds')
    for (let index = 0; index < length; index++) {
      const row = frame(buffer, next, depth + 1)
      if (!row) return undefined
      value.push(row.value); next = row.next
    }
    return { value, next }
  }
  throw Error('fixture unsupported RESP')
}
export const bulk = value => value === null ? '$-1\r\n' : `$${Buffer.byteLength(value)}\r\n${value}\r\n`
export async function fakeRedis() {
  const paths = await pathFixture(), epoch = randomUUID(), runId = randomBytes(20).toString('hex')
  const keys = new Map([[`${keyPrefix(epoch)}:epoch`, `${epoch}:${runId}`]])
  const peers = new Set(), timers = new Set(), stats = { claims: 0, verifies: 0, commands: 0 }
  const behavior = { claimReply: undefined, claimDelay: 0, verifyReply: undefined, verifyDelay: 0 }
  const server = createServer(socket => {
    peers.add(socket); socket.on('error', () => {}); socket.on('close', () => peers.delete(socket))
    let buffer = Buffer.alloc(0)
    socket.on('data', data => {
      buffer = Buffer.concat([buffer, data])
      try {
        while (true) {
          const parsed = frame(buffer)
          if (!parsed) break
          buffer = buffer.subarray(parsed.next); stats.commands++
          const args = parsed.value, command = args[0].toUpperCase()
          let reply = '+OK\r\n', delay = 0
          if (command === 'INFO') reply = bulk(`# Server\r\nrun_id:${runId}\r\n`)
          else if (command === 'GET') reply = bulk(keys.get(args[1]) ?? null)
          else if (command === 'EVAL') {
            const isClaim = args[1].includes('SC_OWNER_CLAIM_V1')
            const key = args[4], marker = args[6]
            if (isClaim) {
              stats.claims++
              const first = !keys.has(key)
              if (first) keys.set(key, marker)
              reply = behavior.claimReply ?? `:${first ? 1 : 0}\r\n`; delay = behavior.claimDelay
            } else {
              stats.verifies++; reply = behavior.verifyReply ?? `:${keys.get(key) === marker ? 1 : -5}\r\n`; delay = behavior.verifyDelay
            }
          }
          if (delay) {
            const timer = setTimeout(() => { timers.delete(timer); if (!socket.destroyed) socket.write(reply) }, delay)
            timers.add(timer)
          } else socket.write(reply)
        }
      } catch { socket.destroy() }
    })
  })
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(paths.path, resolve) })
  return { epoch, runId, path: paths.path, stats, keys, behavior, config: pair => ({ enabled: true,
    environment: 'testing', socketPath: paths.path, epoch, runId, keyPair: pair }),
    close: async () => { for (const timer of timers) clearTimeout(timer); for (const peer of peers) peer.destroy()
      await new Promise(resolve => server.close(resolve)); await paths.close() } }
}
export async function inspector(path) {
  const client = createClient({ socket: { path, reconnectStrategy: false }, disableOfflineQueue: true })
  client.on('error', () => {}); await client.connect(); return client
}
export async function realFixture() {
  const path = process.env.SC_OWNER_REDIS_SOCKET
  if (!path?.startsWith('/tmp/txboard-sc-owner.')) throw Error('Run the isolated Linux launcher')
  const client = await inspector(path), info = await client.info('server')
  const runId = info.split('\r\n').find(row => row.startsWith('run_id:')).slice(7), epoch = randomUUID()
  await client.set(`${keyPrefix(epoch)}:epoch`, `${epoch}:${runId}`)
  return { epoch, runId, path, client, config: pair => ({ enabled: true, environment: 'testing',
    socketPath: path, epoch, runId, keyPair: pair }), close: () => { if (client.isOpen) client.destroy() } }
}
/** Actual Redis executes commands; only its claim ACK is manipulated by this Unix proxy. */
export async function ackProxy(upstream, mode) {
  const paths = await pathFixture(), peers = new Set(), timers = new Set(), stats = { dropped: 0, delayed: 0 }
  const server = createServer(front => {
    const back = createConnection(upstream), queue = []
    peers.add(front); peers.add(back)
    for (const socket of [front, back]) {
      socket.on('error', () => {}); socket.on('close', () => { peers.delete(socket); front.destroy(); back.destroy() })
    }
    let input = Buffer.alloc(0), output = Buffer.alloc(0)
    front.on('data', data => {
      input = Buffer.concat([input, data])
      try {
        while (true) {
          const parsed = frame(input)
          if (!parsed) break
          const raw = input.subarray(0, parsed.next); input = input.subarray(parsed.next)
          queue.push(parsed.value[0] === 'EVAL' && parsed.value[1].includes('SC_OWNER_CLAIM_V1')); back.write(raw)
        }
      } catch { front.destroy(); back.destroy() }
    })
    back.on('data', data => {
      output = Buffer.concat([output, data])
      try {
        while (true) {
          const parsed = frame(output)
          if (!parsed) break
          const raw = Buffer.from(output.subarray(0, parsed.next)); output = output.subarray(parsed.next)
          const claim = queue.shift()
          if (claim && parsed.value === 1 && mode === 'drop') { stats.dropped++; continue }
          if (claim && parsed.value === 1 && (mode === 'delay' || mode === 'drip')) {
            stats.delayed++
            if (mode === 'delay') {
              const timer = setTimeout(() => { timers.delete(timer); if (!front.destroyed) front.write(raw) }, 800)
              timers.add(timer)
            } else for (let index = 0; index < raw.length; index++) {
              const timer = setTimeout(() => { timers.delete(timer); if (!front.destroyed) front.write(raw.subarray(index, index + 1)) }, index * 250)
              timers.add(timer)
            }
          } else front.write(raw)
        }
      } catch { front.destroy(); back.destroy() }
    })
  })
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(paths.path, resolve) })
  return { path: paths.path, stats, close: async () => {
    for (const timer of timers) clearTimeout(timer)
    for (const peer of peers) peer.destroy()
    await new Promise(resolve => server.close(resolve)); await paths.close()
  } }
}
