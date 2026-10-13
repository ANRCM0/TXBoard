import { generateKeyPairSync, createHash } from 'node:crypto'
import { describe, expect, it, vi } from 'vitest'
import { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } from '@hpke/core'
import { createTXBoardClient } from '../src/index.js'

const ok = (data: unknown) => Response.json({ ok: true, data, meta: { version: '1', requestId: 'fixture' } })
const token = 'Bearer CurrentCase|abc123'
async function fixture() {
  const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
  const jwk = privateKey.export({ format: 'jwk' }); const suite = new CipherSuite({ kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm() })
  const secret = await suite.kem.importKey('jwk', jwk, false)
  const pub = await suite.kem.importKey('jwk', { kty: 'EC', crv: 'P-256', x: jwk.x, y: jwk.y }, true)
  const bytes = new Uint8Array(await suite.kem.serializePublicKey(pub)); const kid = createHash('sha256').update(bytes).digest('hex').slice(0, 24)
  return { secret, suite, kid, discovery: { protocol: 'HPKE-RFC9180', suite: 'DHKEM(P-256,HKDF-SHA256)+HKDF-SHA256+AES-256-GCM',
    keys: [{ kid, publicKey: Buffer.from(bytes).toString('base64url'), scope: 'account-workflows' }] } }
}
describe('current sessions SDK', () => {
  it('list reads the current credential each call and keeps decimal IDs exact', async () => {
    let current = token; const fetchImpl = vi.fn(async () => ok({ items: [{ id: '9007199254740993' }] }))
    const sdk = createTXBoardClient({ fetchImpl, getToken: () => current })
    expect((await sdk.auth.sessions.list()).items[0]!.id).toBe('9007199254740993')
    current = 'Bearer changed|abc123'; await sdk.auth.sessions.list()
    expect(fetchImpl.mock.calls[0]![1].headers.get('authorization')).toBe(token)
    expect(fetchImpl.mock.calls[1]![1].headers.get('authorization')).toBe(current)
  })
  it.each(['list', 'revoke', 'logout'])('missing credential refuses %s without discovery or mutation', async action => {
    const fetchImpl = vi.fn(), sdk = createTXBoardClient({ fetchImpl })
    const run = action === 'list' ? () => sdk.auth.sessions.list() : action === 'revoke' ? () => sdk.auth.sessions.revoke('9') : () => sdk.auth.logout()
    await expect(run()).rejects.toMatchObject({ code: 'UNAUTHORIZED', status: 401 }); expect(fetchImpl).not.toHaveBeenCalled()
  })
  it.each(['0', '01', '-1', '1e3', '1\n', '1000000000000000000', 1, null])('refuses non-canonical string ID %# before credential or transport', value => {
    const fetchImpl = vi.fn(), getToken = vi.fn(() => token), sdk = createTXBoardClient({ fetchImpl, getToken })
    expect(() => sdk.auth.sessions.revoke(value as string)).toThrow('Invalid session identifier')
    expect(fetchImpl).not.toHaveBeenCalled(); expect(getToken).not.toHaveBeenCalled()
  })
  it.each(['session-revoke', 'session-logout'])('encrypts %s with distinct AAD and a single captured current credential', async operation => {
    const f = await fixture(), getToken = vi.fn().mockReturnValueOnce(token).mockReturnValue('Bearer other|abc123')
    const fetchImpl = vi.fn(async (url, init) => String(url).endsWith('/crypto/key') ? ok(f.discovery) : ok({ ok: true }))
    const sdk = createTXBoardClient({ fetchImpl, getToken })
    expect(await (operation === 'session-revoke' ? sdk.auth.sessions.revoke('9007199254740993') : sdk.auth.logout())).toEqual({ ok: true })
    expect(getToken).toHaveBeenCalledTimes(1); expect(fetchImpl).toHaveBeenCalledTimes(2)
    const [url, init] = fetchImpl.mock.calls[1]!; expect(url).toBe('/gateway/v1/secure/auth/' + operation)
    expect(init.headers.get('authorization')).toBe(token); expect(init.method).toBe('POST'); expect(init.redirect).toBe('error'); expect(init.credentials).toBe('omit')
    const sealed = JSON.parse(init.body); const recipient = await f.suite.createRecipientContext({ recipientKey: f.secret,
      enc: Buffer.from(sealed.enc, 'base64url'), info: new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE') })
    const aad = new TextEncoder().encode('txboard-gateway:v1\nPOST\n/gateway/v1/secure/auth/' + operation + '\n' + f.kid + '\n' + sealed.ts + '\n' + sealed.nonce + '\n-')
    const plaintext = await recipient.open(Buffer.from(sealed.ct, 'base64url'), aad)
    expect(JSON.parse(new TextDecoder().decode(plaintext))).toEqual(operation === 'session-revoke' ? { session_id: '9007199254740993' } : {})
  })
  it.each(['network', '503', 'invalid', 'version'])('does not retry an unknown %s mutation result', async outcome => {
    const f = await fixture(); const fetchImpl = vi.fn(async url => {
      if (String(url).endsWith('/crypto/key')) return ok(f.discovery)
      if (outcome === 'network') throw new Error('fixture connection lost')
      if (outcome === '503') return Response.json({ ok: false, error: { code: 'UPSTREAM_UNAVAILABLE', message: 'unavailable' }, meta: { version: '1' } }, { status: 503 })
      if (outcome === 'invalid') return new Response('not-json', { status: 200 })
      return Response.json({ ok: true, data: { ok: true }, meta: { version: '2' } })
    })
    const sdk = createTXBoardClient({ fetchImpl, getToken: () => token, maxRetries: 5 })
    await expect(sdk.auth.logout()).rejects.toMatchObject({ retryable: false, needsStateConfirmation: true })
    expect(fetchImpl).toHaveBeenCalledTimes(2)
  })
})
