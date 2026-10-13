/** Manual reference generation, public fixture keys only. Never imported by the SDK. */
import { readFile, writeFile } from 'node:fs/promises'
import { CipherSuite, Aes256Gcm, DhkemP256HkdfSha256, HkdfSha256 } from '@hpke/core'
import * as codec from '../packages/theme-sdk/dist/experimental/sc-v2-codec.js'
import {saltedResponseAad} from '../packages/theme-sdk/dist/experimental/sc-v2-response-salt.js'

const metadataVector = JSON.parse(await readFile(new URL('../packages/theme-sdk/test/vectors/sc-v2-codec.json', import.meta.url), 'utf8'))
const old = JSON.parse(await readFile(new URL('../packages/theme-sdk/test/vectors/hpke-kat.json', import.meta.url), 'utf8'))
const algorithm = { name: 'ECDH', namedCurve: 'P-256' }
async function pair(jwk) {
  const { d, ...publicJwk } = jwk
  return { privateKey: await crypto.subtle.importKey('jwk', jwk, algorithm, false, ['deriveBits']),
    publicKey: await crypto.subtle.importKey('jwk', publicJwk, algorithm, true, []) }
}
const recipient = await pair(old.recipientJwk), ephemeral = await pair(old.ephemeralJwk)
const suite = new CipherSuite({ kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm() })
const info = new TextEncoder().encode('txboard.sc-v2-response-salt/1/request')
// ekm exists only in this manual reference fixture, never in the experimental primitive.
const sender = await suite.createSenderContext({ recipientPublicKey: recipient.publicKey, info, ekm: ephemeral })
const metadata = metadataVector.metadata, plaintext = new TextEncoder().encode('离线字节🚀\u0000')
const ct = new Uint8Array(await sender.seal(plaintext, codec.requestAad(metadata)))
const enc = new Uint8Array(sender.enc)
const requestDigest = codec.toBase64url(new Uint8Array(await crypto.subtle.digest('SHA-256', codec.requestDigestInput(metadata, enc, ct))))
const responseNonce = Uint8Array.from({length:32},(_,i)=>i+1)
const binding = { request_digest:requestDigest,kind:'error',status:403,response_nonce:codec.toBase64url(responseNonce) }
const exported = new Uint8Array(await sender.export(new TextEncoder().encode('txboard.sc-v2-response-salt/1/response'),32))
const salt = new Uint8Array(97);salt.set(enc);salt.set(responseNonce,65)
const hkdf = await crypto.subtle.importKey('raw',exported,'HKDF',false,['deriveBits'])
const parameters = {name:'HKDF',hash:'SHA-256',salt}
const rawKey = new Uint8Array(await crypto.subtle.deriveBits({...parameters,info:new TextEncoder().encode('key')},hkdf,256))
const nonce = new Uint8Array(await crypto.subtle.deriveBits({...parameters,info:new TextEncoder().encode('nonce')},hkdf,96))
const key = await crypto.subtle.importKey('raw',rawKey,'AES-GCM',false,['encrypt'])
const responsePlaintext = new TextEncoder().encode('固定错误字节，不是业务响应')
const responseCt = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv: nonce, additionalData: saltedResponseAad(metadata, binding), tagLength: 128 }, key, responsePlaintext))
const vector = { description: 'Deterministic locked-library reference only; reuses public fixture keys from hpke-kat.json. No production security approval.',
  info: new TextDecoder().decode(info), key_fixture: 'hpke-kat.json', envelope: { metadata, enc: codec.toBase64url(enc), ct: codec.toBase64url(ct) },
  request_plaintext_hex: Buffer.from(plaintext).toString('hex'), response: { ...binding, ct: codec.toBase64url(responseCt) },
  response_plaintext_hex: Buffer.from(responsePlaintext).toString('hex'),
  public_fixture_derivation: {exported_hex:Buffer.from(exported).toString('hex'),salt_hex:Buffer.from(salt).toString('hex'),key_hex:Buffer.from(rawKey).toString('hex'),nonce_hex:Buffer.from(nonce).toString('hex'),aad_hex:Buffer.from(saltedResponseAad(metadata,binding)).toString('hex')}  }
await writeFile(new URL('../packages/theme-sdk/test/vectors/sc-v2-response-salt.json', import.meta.url), JSON.stringify(vector, null, 2)+'\n')
console.log('Wrote public deterministic fixture; review before committing changed bytes.')
