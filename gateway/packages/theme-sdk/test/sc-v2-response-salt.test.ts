import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSaltedExperimentalRequest as createExperimentalRequest, openSaltedExperimentalRequest as openExperimentalRequest } from '../src/experimental/sc-v2-response-salt.js'
import { toBase64url, suiteIds, type RequestMetadata } from '../src/experimental/sc-v2-codec.js'
import vector from './vectors/sc-v2-response-salt.json' with { type: 'json' }
import publicFixture from './vectors/hpke-kat.json' with { type: 'json' }

const encoded = (text: string) => new TextEncoder().encode(text)
const now = 1791770400
function metadata(patch: Partial<RequestMetadata> = {}): RequestMetadata {
  return { version: 2, mode: 0, domain: 'user', kid: '0123456789abcdef'.repeat(4), operation: 'user.profile.get',
    operation_version: '2', request_id: '12345678-1234-4234-8234-123456789abc', issued_at: now, expires_at: now + 60,
    nonce: toBase64url(new Uint8Array(32)), origin: 'https://gateway.example.test', path: '/gateway/v2/channel/user', ...patch }
}
async function keys(): Promise<CryptoKeyPair> {
  return crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits'])
}
const failure = 'SC_EXPERIMENT_INVALID'
beforeEach(() => { vi.spyOn(Date, 'now').mockReturnValue(now * 1000) })
afterEach(() => vi.restoreAllMocks())

describe('offline salted SC candidate: separate response material', () => {
  it('matches the fixed locked-library ciphertext and response reference', async () => {
    const { d: _knownPublicScalar, ...publicJwk } = publicFixture.recipientJwk
    const algorithm = { name: 'ECDH', namedCurve: 'P-256' }
    const pair = { privateKey: await crypto.subtle.importKey('jwk', publicFixture.recipientJwk, algorithm, false, ['deriveBits']),
      publicKey: await crypto.subtle.importKey('jwk', publicJwk, algorithm, true, []) }
    vi.spyOn(crypto, 'getRandomValues').mockImplementation((buffer: any) => { buffer.set(Buffer.from(vector.response.response_nonce, 'base64url')); return buffer })
    const recipient = await openExperimentalRequest(pair, vector.envelope)
    expect(Buffer.from(recipient.plaintext).toString('hex')).toBe(vector.request_plaintext_hex)
    expect(await recipient.sealResponse(Buffer.from(vector.response_plaintext_hex, 'hex'), { kind: 'error', status: 403 })).toEqual(vector.response)
  })
  it.each([new Uint8Array(), encoded('中文🚀\u0000'), new Uint8Array(3056).fill(173)])('roundtrips opaque byte payload of length $length with nonextractable recipient', async plaintext => {
    const pair = await keys()
    expect(pair.privateKey.extractable).toBe(false)
    const sender = await createExperimentalRequest(pair.publicKey, metadata(), plaintext)
    const recipient = await openExperimentalRequest(pair, sender.envelope)
    expect(recipient.plaintext).toEqual(plaintext)
    const response = await recipient.sealResponse(plaintext, { kind: 'result', status: 200 })
    expect(await sender.openResponse(response)).toEqual(plaintext)
    expect(Object.keys(sender)).toEqual(['envelope', 'openResponse'])
    expect(Object.keys(recipient)).toEqual(['plaintext', 'sealResponse'])
    expect(Object.isFrozen(sender.envelope.metadata)).toBe(true)
    await expect(recipient.sealResponse(encoded('second'), { kind: 'error', status: 500 })).rejects.toThrow(failure)
    await expect(sender.openResponse(response)).rejects.toThrow(failure)
  })
  it('snapshots caller metadata, Node Buffer bytes and response binding before first await', async () => {
    const pair = await keys(), inputMetadata = metadata(), plaintext = Buffer.from('first')
    const pending = createExperimentalRequest(pair.publicKey, inputMetadata, plaintext)
    inputMetadata.operation = 'user.order.get'; plaintext.fill(0)
    const sender = await pending
    expect(sender.envelope.metadata.operation).toBe('user.profile.get')
    const mutable = { ...sender.envelope, metadata: { ...sender.envelope.metadata } }
    const opening = openExperimentalRequest(pair, mutable)
    mutable.metadata.operation = 'user.order.get'; mutable.ct = 'invalid'
    const recipient = await opening
    expect(recipient.plaintext).toEqual(encoded('first'))
    const responseBytes = Buffer.from('response'), binding = { kind: 'result' as const, status: 200 }
    const sealing = recipient.sealResponse(responseBytes, binding)
    responseBytes.fill(0); binding.status = 201
    const response = await sealing
    expect(response.status).toBe(200)
    const mutableResponse = { ...response }
    const responseOpening = sender.openResponse(mutableResponse)
    mutableResponse.kind = 'error'; mutableResponse.ct = 'invalid'
    expect(await responseOpening).toEqual(encoded('response'))
  })
  it('permits only one concurrent seal/open winner', async () => {
    const pair = await keys(), sender = await createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))
    const recipient = await openExperimentalRequest(pair, sender.envelope)
    const sealed = await Promise.allSettled([recipient.sealResponse(encoded('response'), { kind: 'result', status: 200 }),
      recipient.sealResponse(encoded('loser'), { kind: 'error', status: 500 })])
    expect(sealed[0]!.status).toBe('fulfilled'); expect(sealed[1]!.status).toBe('rejected')
    if (sealed[0]!.status !== 'fulfilled') throw Error('test failure')
    const opened = await Promise.allSettled([sender.openResponse(sealed[0]!.value), sender.openResponse(sealed[0]!.value)])
    expect(opened[0]!.status).toBe('fulfilled'); expect(opened[1]!.status).toBe('rejected')
  })
  it('claims closure before an accessor can reenter and spends invalid inputs', async () => {
    const pair = await keys(), sender = await createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))
    const recipient = await openExperimentalRequest(pair, sender.envelope)
    let invoked = false
    const accessor = { get kind() { invoked = true; return 'result' }, status: 200 }
    await expect(recipient.sealResponse(encoded('reject'), accessor as { kind: 'result'; status: number })).rejects.toThrow(failure)
    expect(invoked).toBe(false)
    await expect(recipient.sealResponse(encoded('again'), { kind: 'result', status: 200 })).rejects.toThrow(failure)
    await expect(sender.openResponse({})).rejects.toThrow(failure)
    await expect(sender.openResponse({})).rejects.toThrow(failure)
  })
  it('duplicate recipient openings derive distinct key/nonce material while business replay remains outside scope', async () => {
    const pair = await keys(), sender = await createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))
    const deriveBits = crypto.subtle.deriveBits.bind(crypto.subtle)
    const keysSeen: string[] = [], nonces: string[] = []
    vi.spyOn(crypto.subtle, 'deriveBits').mockImplementation(async (...args: any[]) => {
      const result = await (deriveBits as any)(...args)
      const label = args[0].name === 'HKDF' ? new TextDecoder().decode(args[0].info) : ''
      if (label === 'key' || label === 'nonce') {
        const hex = Buffer.from(result).toString('hex')
        if (args[2] === 256) keysSeen.push(hex)
        if (args[2] === 96) nonces.push(hex)
      }
      return result
    })
    const responses = await Promise.all(Array.from({length:32},async (_,i) => {
      const recipient = await openExperimentalRequest(pair,sender.envelope)
      expect(recipient.plaintext).toEqual(encoded('request'))
      return recipient.sealResponse(encoded('independent-'+i), {kind:'result',status:200})
    }))
    expect(new Set(responses.map(r=>(r as any).response_nonce)).size).toBe(32)
    expect(keysSeen).toHaveLength(32);expect(new Set(keysSeen).size).toBe(32)
    expect(nonces).toHaveLength(32);expect(new Set(nonces).size).toBe(32)
    expect(new TextDecoder().decode(await sender.openResponse(responses[0]))).toBe('independent-0')
  })
  it('rejects tampered request AAD and wrong recipient key; wellformed other domains are not authorization decisions', async () => {
    const pair = await keys(), sender = await createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))
    const tampered = [metadata({ operation: 'user.order.get' }), metadata({ kid: 'a'.repeat(64) }),
      metadata({ domain: 'admin', path: '/gateway/v2/channel/admin' }), metadata({ origin: 'https://other.example.test' })]
    for (const change of tampered) await expect(openExperimentalRequest(pair, { ...sender.envelope, metadata: change })).rejects.toThrow(failure)
    await expect(openExperimentalRequest(await keys(), sender.envelope)).rejects.toThrow(failure)
    const other = await createExperimentalRequest(pair.publicKey, tampered[2]!, encoded('bytes-only'))
    expect((await openExperimentalRequest(pair, other.envelope)).plaintext).toEqual(encoded('bytes-only'))
  })
  it.each(['request_digest', 'kind', 'status', 'response_nonce', 'ct'] as const)('authenticates response %s and spends a bad-tag attempt', async field => {
    const pair = await keys(), sender = await createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))
    const recipient = await openExperimentalRequest(pair, sender.envelope)
    const response = await recipient.sealResponse(encoded('response'), { kind: 'result', status: 200 })
    const changed = { ...response, [field]: field === 'request_digest' ? toBase64url(new Uint8Array(32))
      : field === 'response_nonce' ? toBase64url(new Uint8Array(32)) : field === 'kind' ? 'error' : field === 'status' ? 201 : toBase64url(new Uint8Array(24)) }
    await expect(sender.openResponse(changed)).rejects.toThrow(failure)
    await expect(sender.openResponse(response)).rejects.toThrow(failure)
  })
  it('refuses exchanged responses between independent requests', async () => {
    const pair = await keys()
    const a = await createExperimentalRequest(pair.publicKey, metadata(), encoded('a'))
    const b = await createExperimentalRequest(pair.publicKey, metadata(), encoded('b'))
    expect(a.envelope.enc).not.toBe(b.envelope.enc)
    const responder = await openExperimentalRequest(pair, b.envelope)
    await expect(a.openResponse(await responder.sealResponse(encoded('b-result'), { kind: 'result', status: 200 }))).rejects.toThrow(failure)
  })
  it('rechecks expiry at both closures and spends expired attempts', async () => {
    const pair = await keys(), sender = await createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))
    const recipient = await openExperimentalRequest(pair, sender.envelope)
    vi.mocked(Date.now).mockReturnValue((now + 60) * 1000)
    await expect(recipient.sealResponse(encoded('late'), { kind: 'result', status: 200 })).rejects.toThrow(failure)
    await expect(sender.openResponse({})).rejects.toThrow(failure)
    vi.mocked(Date.now).mockReturnValue(now * 1000)
    await expect(recipient.sealResponse(encoded('late'), { kind: 'result', status: 200 })).rejects.toThrow(failure)
  })
  it('refuses oversized, shared and detached buffers without exposing library errors', async () => {
    const pair = await keys()
    await expect(createExperimentalRequest(pair.publicKey, metadata(), new Uint8Array(3057))).rejects.toThrow(failure)
    await expect(createExperimentalRequest(pair.publicKey, metadata(), new Uint8Array(new SharedArrayBuffer(1)))).rejects.toThrow(failure)
    const buffer = new ArrayBuffer(1), detached = new Uint8Array(buffer)
    structuredClone(buffer, { transfer: [buffer] })
    await expect(createExperimentalRequest(pair.publicKey, metadata(), detached)).rejects.toThrow(failure)
    const getterMetadata = { ...metadata() }
    Object.defineProperty(getterMetadata, 'kid', { get: () => { throw Error('private source') }, enumerable: true })
    await expect(createExperimentalRequest(pair.publicKey, getterMetadata, encoded('secret'))).rejects.toThrow(failure)
  })
  it('accepts the explicit nonextractable odd-y generator key pair', async () => {
    const b64 = (hex: string) => Buffer.from(hex, 'hex').toString('base64url')
    const jwk = { kty: 'EC', crv: 'P-256', x: b64('6b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c296'),
      y: b64('4fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5'), d: b64('0'.repeat(63)+'1') }
    const algorithm = { name: 'ECDH', namedCurve: 'P-256' }
    const privateKey = await crypto.subtle.importKey('jwk', jwk, algorithm, false, ['deriveBits'])
    const { d: _publicFixtureScalar, ...publicJwk } = jwk
    const publicKey = await crypto.subtle.importKey('jwk', publicJwk, algorithm, true, [])
    const sender = await createExperimentalRequest(publicKey, metadata(), encoded('odd-y'))
    expect((await openExperimentalRequest({ privateKey, publicKey }, sender.envelope)).plaintext).toEqual(encoded('odd-y'))
  })
  it('rejects wrong curve, key type and mismatched explicit pair', async () => {
    const pair = await keys(), wrong = await keys()
    const curve = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-384' }, false, ['deriveBits'])
    await expect(createExperimentalRequest(curve.publicKey, metadata(), encoded('reject'))).rejects.toThrow(failure)
    await expect(createExperimentalRequest(pair.privateKey, metadata(), encoded('reject'))).rejects.toThrow(failure)
    const sender = await createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))
    await expect(openExperimentalRequest({ publicKey: pair.publicKey, privateKey: wrong.privateKey }, sender.envelope)).rejects.toThrow(failure)
    await expect(openExperimentalRequest({ publicKey: pair.publicKey, privateKey: pair.publicKey }, sender.envelope)).rejects.toThrow(failure)
  })
  it('encrypts a byte error response without returning a plaintext error body', async () => {
    const pair = await keys(), sender = await createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))
    const recipient = await openExperimentalRequest(pair, sender.envelope)
    const result = await recipient.sealResponse(encoded('private failure detail'), { kind: 'error', status: 403 })
    expect(JSON.stringify(result)).not.toContain('private failure detail')
    expect(await sender.openResponse(result)).toEqual(encoded('private failure detail'))
  })
  it('handles twelve independent contexts concurrently without secret cross-contamination', async () => {
    const pair = await keys()
    const results = await Promise.all(Array.from({ length: 12 }, async (_, index) => {
      const sender = await createExperimentalRequest(pair.publicKey, metadata({ request_id: crypto.randomUUID() }), encoded(String(index)))
      const recipient = await openExperimentalRequest(pair, sender.envelope)
      const response = await recipient.sealResponse(recipient.plaintext, { kind: 'result', status: 200 })
      expect(new TextDecoder().decode(await sender.openResponse(response))).toBe(String(index))
      return sender.envelope.enc
    }))
    expect(new Set(results).size).toBe(12)
    expect(Object.isFrozen(suiteIds)).toBe(true)
  })
  it('claims before synchronous Proxy traps can reenter response sealing', async () => {
    const pair = await keys(), sender = await createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))
    const recipient = await openExperimentalRequest(pair, sender.envelope)
    let reentered: Promise<unknown> | undefined
    const binding = new Proxy({ kind: 'result' as const, status: 200 }, { getPrototypeOf(target) {
      reentered = recipient.sealResponse(encoded('reentry'), { kind: 'error', status: 500 })
      // Attach immediately to avoid an unhandled rejected Promise.
      void reentered.catch(() => undefined)
      return Reflect.getPrototypeOf(target)
    } })
    const response = await recipient.sealResponse(encoded('winner'), binding)
    await expect(reentered).rejects.toThrow(failure)
    expect(await sender.openResponse(response)).toEqual(encoded('winner'))
  })
  it('copies nonzero-offset Buffer subviews and permits closed null-prototype records', async () => {
    const pair = await keys(), backing = Buffer.from('prefixVALUEsuffix'), subview = backing.subarray(6, 11)
    const input = Object.assign(Object.create(null), metadata())
    const pending = createExperimentalRequest(pair.publicKey, input, subview)
    backing.fill(0)
    const sender = await pending, recipient = await openExperimentalRequest(pair, sender.envelope)
    expect(recipient.plaintext).toEqual(encoded('VALUE'))
    expect(await sender.openResponse(await recipient.sealResponse(encoded('ok'), Object.assign(Object.create(null), { kind: 'result', status: 200 })))).toEqual(encoded('ok'))
    await expect(createExperimentalRequest(pair.publicKey, { ...metadata(), extra: true } as RequestMetadata, encoded('reject'))).rejects.toThrow(failure)
    const symbolic = { ...metadata(), [Symbol('extra')]: true }
    await expect(createExperimentalRequest(pair.publicKey, symbolic, encoded('reject'))).rejects.toThrow(failure)
  })
  it('rejects changed request ciphertext and encapsulation directly', async () => {
    const pair = await keys(), sender = await createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))
    await expect(openExperimentalRequest(pair, { ...sender.envelope, ct: toBase64url(new Uint8Array(24)) })).rejects.toThrow(failure)
    await expect(openExperimentalRequest(pair, { ...sender.envelope, enc: toBase64url(new Uint8Array(65)) })).rejects.toThrow(failure)
  })
  it('rejects a private key without deriveBits usage and snapshots key pair before await', async () => {
    const pair = await keys(), sender = await createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))
    const badUsage = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveKey'])
    await expect(openExperimentalRequest(badUsage, sender.envelope)).rejects.toThrow(failure)
    const wrongPair = await keys(), mutablePair = { ...pair }
    const opening = openExperimentalRequest(mutablePair, sender.envelope)
    mutablePair.privateKey = wrongPair.privateKey
    expect((await opening).plaintext).toEqual(encoded('request'))
  })
  it.each(['create', 'open', 'seal', 'decrypt'] as const)('rejects %s completing after expiry', async phase => {
    const pair = await keys()
    const expire = () => vi.mocked(Date.now).mockReturnValue((now + 60) * 1000)
    if (phase === 'create') {
      const original = crypto.subtle.digest.bind(crypto.subtle)
      vi.spyOn(crypto.subtle, 'digest').mockImplementation(async (...args) => { const result = await original(...args); expire(); return result })
      await expect(createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))).rejects.toThrow(failure)
      return
    }
    const sender = await createExperimentalRequest(pair.publicKey, metadata(), encoded('request'))
    if (phase === 'open') {
      const original = crypto.subtle.digest.bind(crypto.subtle)
      vi.spyOn(crypto.subtle, 'digest').mockImplementation(async (...args) => { const result = await original(...args); expire(); return result })
      await expect(openExperimentalRequest(pair, sender.envelope)).rejects.toThrow(failure)
      return
    }
    const recipient = await openExperimentalRequest(pair, sender.envelope)
    if (phase === 'seal') {
      const original = crypto.subtle.encrypt.bind(crypto.subtle)
      vi.spyOn(crypto.subtle, 'encrypt').mockImplementation(async (...args) => { const result = await original(...args); expire(); return result })
      await expect(recipient.sealResponse(encoded('late'), { kind: 'result', status: 200 })).rejects.toThrow(failure)
    } else {
      const response = await recipient.sealResponse(encoded('late'), { kind: 'result', status: 200 })
      const original = crypto.subtle.decrypt.bind(crypto.subtle)
      vi.spyOn(crypto.subtle, 'decrypt').mockImplementation(async (...args) => { const result = await original(...args); expire(); return result })
      await expect(sender.openResponse(response)).rejects.toThrow(failure)
    }
  })
})

describe('salted profile separation and entropy failure', () => {
  it.each(['missing','padded','short','extra','symbol'])('rejects %s response nonce framing without a retry', async kind => {
    const pair = await keys(),sender = await createExperimentalRequest(pair.publicKey,metadata(),encoded('request'))
    const recipient = await openExperimentalRequest(pair,sender.envelope)
    const response: any = {...await recipient.sealResponse(encoded('response'),{kind:'result',status:200})}
    if (kind === 'missing') delete response.response_nonce
    if (kind === 'padded') response.response_nonce += '='
    if (kind === 'short') response.response_nonce = toBase64url(new Uint8Array(31))
    if (kind === 'extra') response.extra = 'unknown'
    if (kind === 'symbol') response[Symbol('extra')] = true
    await expect(sender.openResponse(response)).rejects.toThrow(failure)
    await expect(sender.openResponse(response)).rejects.toThrow(failure)
  })
  it('does not decrypt either legacy or new profile under the other HPKE info label', async () => {
    const legacy = await import('../src/experimental/sc-v2-crypto.js')
    const pair = await keys()
    const old = await legacy.createExperimentalRequest(pair.publicKey,metadata(),encoded('old'))
    await expect(openExperimentalRequest(pair,old.envelope)).rejects.toThrow(failure)
    const candidate = await createExperimentalRequest(pair.publicKey,metadata(),encoded('new'))
    await expect(legacy.openExperimentalRequest(pair,candidate.envelope)).rejects.toThrow(failure)
  })
  it('spends a response closure when secure randomness is unavailable, exposing no plaintext', async () => {
    const pair = await keys(), sender = await createExperimentalRequest(pair.publicKey,metadata(),encoded('private'))
    const recipient = await openExperimentalRequest(pair,sender.envelope)
    const random = vi.spyOn(crypto,'getRandomValues').mockImplementation(() => {throw Error('private RNG state')})
    await expect(recipient.sealResponse(encoded('secret response'),{kind:'result',status:200})).rejects.toThrow(failure)
    random.mockRestore()
    await expect(recipient.sealResponse(encoded('retry'),{kind:'result',status:200})).rejects.toThrow(failure)
  })
  it('requires closed canonical salt, rejects caller salt and spends accessor attempts', async () => {
    const pair = await keys(), sender = await createExperimentalRequest(pair.publicKey,metadata(),encoded('request'))
    const recipient = await openExperimentalRequest(pair,sender.envelope)
    await expect(recipient.sealResponse(encoded('response'),{kind:'result',status:200,response_nonce:'caller-choice'} as any)).rejects.toThrow(failure)
    const owner = await openExperimentalRequest(pair,sender.envelope)
    const response = await owner.sealResponse(encoded('response'),{kind:'result',status:200})
    let accessed = false
    const malformed = {...response}
    Object.defineProperty(malformed,'response_nonce',{get(){accessed=true;return 'bad'},enumerable:true})
    await expect(sender.openResponse(malformed)).rejects.toThrow(failure)
    expect(accessed).toBe(false)
    await expect(sender.openResponse(response)).rejects.toThrow(failure)
  })
})
