/** Offline candidate only. These bytes do not select a runtime route or authorize an actor. */
import { fromBase64url, responseAad, type RequestMetadata, type ResponseBinding } from './sc-v2-codec.js'

export const responseSaltPrototype = 'txboard.sc-v2-response-salt/1' as const
export type SaltedResponseBinding = ResponseBinding & { response_nonce: string }
const encoder = new TextEncoder()

export function saltedResponseAad(metadata: RequestMetadata, input: SaltedResponseBinding): Uint8Array {
  if (input === null || typeof input !== 'object') throw new Error('SC_SALT_CODEC_INVALID')
  const prototype = Object.getPrototypeOf(input)
  const descriptors = Object.getOwnPropertyDescriptors(input)
  const names = ['request_digest', 'kind', 'status', 'response_nonce'] as const
  if ((prototype !== Object.prototype && prototype !== null)
    || Reflect.ownKeys(descriptors).length !== 4
    || !names.every(field => Object.hasOwn(descriptors, field)
      && Object.hasOwn(descriptors[field]!, 'value') && descriptors[field]!.enumerable)) {
    throw new Error('SC_SALT_CODEC_INVALID')
  }
  const binding = Object.fromEntries(names.map(name => [name, descriptors[name]!.value])) as SaltedResponseBinding
  const fields = [encoder.encode(`${responseSaltPrototype}/aad`),
    responseAad(metadata, { request_digest: binding.request_digest, kind: binding.kind, status: binding.status }),
    fromBase64url(binding.response_nonce, 32)]
  const result = new Uint8Array(fields.reduce((sum, field) => sum + 2 + field.length, 0))
  const view = new DataView(result.buffer)
  let offset = 0
  for (const field of fields) {
    view.setUint16(offset, field.length, false); result.set(field, offset + 2); offset += 2 + field.length
  }
  return result
}
