import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { api, getAuthData, nativeApi, saveAuthData } from './client'
import { loginWithMailLink, token2Login } from './auth'

const nativeAdapter = nativeApi.defaults.adapter
const oldAdapter = api.defaults.adapter
let calls: Array<{ url?: string; baseURL?: string; method?: string; data?: unknown; params?: unknown }> = []
let payload: unknown

beforeEach(() => {
  calls = []
  localStorage.clear()
  payload = { data: { ok: true }, request_id: 'native-mail' }
  nativeApi.defaults.adapter = async config => {
    calls.push(config)
    return { data: payload, status: 200, statusText: 'OK', headers: {}, config } as never
  }
  api.defaults.adapter = async () => {
    throw new Error('legacy mail and token API must not be called')
  }
})
afterEach(() => {
  api.defaults.adapter = oldAdapter
  nativeApi.defaults.adapter = nativeAdapter
  localStorage.clear()
})

describe('LR-08 native mail-link and token exchange', () => {
  it('requests a mail login link via native POST', async () => {
    await expect(loginWithMailLink('test@example.test')).resolves.toEqual({ ok: true })
    expect(calls[0]).toMatchObject({ baseURL: '/txapi', method: 'post', url: '/auth/mail-link' })
    expect(JSON.parse(String(calls[0].data))).toEqual({ email: 'test@example.test' })
  })
  it('redeems one-time tokens in POST body, never GET query', async () => {
    payload = { data: { auth_data: 'Bearer redeemed-token' }, request_id: 'native-mail' }
    await token2Login('temporary-secret')
    expect(calls[0]).toMatchObject({ baseURL: '/txapi', method: 'post', url: '/auth/one-time-token' })
    expect(JSON.parse(String(calls[0].data))).toEqual({ verify: 'temporary-secret' })
    expect(calls[0].params).toBeUndefined()
    expect(getAuthData()).toBe('Bearer redeemed-token')
  })
  it('rejects missing token response without clearing existing credentials', async () => {
    saveAuthData('Bearer existing')
    payload = { data: { ok: true }, request_id: 'native-mail' }
    await expect(token2Login('invalid')).rejects.toThrow('Invalid TXAPI token login response')
    expect(getAuthData()).toBe('Bearer existing')
  })
})
