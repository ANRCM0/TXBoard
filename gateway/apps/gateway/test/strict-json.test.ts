import { describe, expect, it } from 'vitest'
import { parseStrictJson } from '../src/security/strict-json.js'

describe('decrypted request JSON', () => {
  it.each([
    '{"email":"first@example.test","email":"second@example.test"}',
    '{"data":{"id":1,"\\u0069d":2}}',
    '{"x":' + '['.repeat(32) + '0' + ']'.repeat(32) + '}',
    '{"number":01}',
    '{"number":NaN}',
    '{"number":1e}',
    '{"value":true} trailing',
  ])('rejects ambiguous, excessive-depth or malformed input %s', text => {
    expect(() => parseStrictJson(text)).toThrow(SyntaxError)
  })

  it('keeps standard JSON values, escaped keys and numeric syntax', () => {
    const text = '{"a":{"text":"a\\\"b"},"b":[null,true,-1,1.5,1e3],"\\u0063":0}'
    expect(parseStrictJson(text)).toEqual(JSON.parse(text))
    expect(parseStrictJson('{"left":{"id":1},"right":{"id":2}}'))
      .toEqual({ left: { id: 1 }, right: { id: 2 } })
  })
})
