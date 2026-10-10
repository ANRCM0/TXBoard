import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { api, getAuthData, nativeApi, saveAuthData } from './client'
import { forgetPassword, loginWithMailLink, sendEmailVerify, token2Login } from './auth'

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

describe('LR-09 native recovery transport', () => {
  it('sends email codes over native POST with captcha', async () => {
    await sendEmailVerify('reset@example.test', 'forget', { turnstile_token: 'turnstile-test' })
    expect(calls[0]).toMatchObject({ method:'post', baseURL:'/txapi', url:'/auth/email-code' })
    expect(JSON.parse(String(calls[0].data))).toEqual({
      email:'reset@example.test', purpose:'forget', turnstile_token:'turnstile-test',
    })
  })
  it('resets password over native POST, never legacy passport', async () => {
    await forgetPassword({ email:'reset@example.test', email_code:'123456', password:'reset-secret' })
    expect(calls[0]).toMatchObject({ method:'post', baseURL:'/txapi', url:'/auth/password/forgot' })
  })
})
