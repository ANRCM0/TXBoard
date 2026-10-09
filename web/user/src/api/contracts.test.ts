import type { AxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { login } from './auth'
import { api, nativeApi, nativeRequest, clearAuthData, getAuthData, request, saveAuthData } from './client'
import { fetchGuestConfig } from './comm'

type Seen = AxiosRequestConfig & { headers: Record<string, string> }

let seen: Seen[] = []
let responder: (config: AxiosRequestConfig) => unknown

function installAdapter() {
  const adapter = async (config: AxiosRequestConfig) => {
    seen.push(config as unknown as Seen)
    return {
      data: responder(config as unknown as AxiosRequestConfig),
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
    } as never
  }
  api.defaults.adapter = adapter
  nativeApi.defaults.adapter = adapter
}

beforeEach(() => {
  seen = []
  responder = () => ({ status: 'success', data: null })
  localStorage.clear()
  clearAuthData()
  installAdapter()
})

afterEach(() => {
  localStorage.clear()
  clearAuthData()
})

describe('user response envelope contract', () => {
  it('returns the data of a success envelope', async () => {
    await expect(request<number>(Promise.resolve({ data: { status: 'success', data: 42 } }))).resolves.toBe(42)
  })

  it('rejects a non-success envelope with its message', async () => {
    await expect(
      request<number>(Promise.resolve({ data: { status: 'fail', message: '令牌无效' } })),
    ).rejects.toThrow('令牌无效')
  })

  it('rejects a success envelope without data', async () => {
    await expect(request<number>(Promise.resolve({ data: { status: 'success' } }))).rejects.toThrow()
  })

  it('preserves legacy top-level { data, total } responses', async () => {
    const legacy = { data: [{ id: 1 }], total: 1 }
    await expect(request<typeof legacy>(Promise.resolve({ data: legacy }))).resolves.toEqual(legacy)
  })
})

describe('auth adapter contract', () => {
  it('logs in against /passport/auth/login and stores the bearer token', async () => {
    responder = () => ({ status: 'success', data: { auth_data: 'token-1' } })

    await login({ email: 'a@b.c', password: 'secret' })

    expect(seen[0].method).toBe('post')
    expect(seen[0].url).toBe('/passport/auth/login')
    expect(JSON.parse(String(seen[0].data))).toEqual({ email: 'a@b.c', password: 'secret' })
    expect(getAuthData()).toBe('Bearer token-1')
  })

  it('normalizes an already-prefixed bearer token', () => {
    saveAuthData('Bearer token-2')
    expect(getAuthData()).toBe('Bearer token-2')
  })

  it('clears stored auth data when given an empty token', () => {
    saveAuthData('token-3')
    saveAuthData('')
    expect(getAuthData()).toBe('')
  })

  it('attaches stored auth data to later requests', async () => {
    saveAuthData('token-4')
    responder = () => ({ status: 'success', data: {} })

    await fetchGuestConfig()

    expect(String(seen[0].headers.Authorization)).toBe('Bearer token-4')
  })
})

describe('guest config adapter contract', () => {
  it('reads GET /guest/comm/config', async () => {
    responder = () => ({ status: 'success', data: { app_name: 'TXBoard' } })

    await expect(fetchGuestConfig()).resolves.toEqual({ app_name: 'TXBoard' })
    expect(seen[0].method).toBe('get')
    expect(seen[0].url).toBe('/guest/comm/config')
  })
})


describe('P1-B safe auth key migration and native API client', () => {
  it('transfers an existing legacy bearer to the native key without logging out', () => {
    localStorage.setItem('xboard_auth_data', 'old-token')
    expect(getAuthData()).toBe('Bearer old-token')
    expect(localStorage.getItem('txboard_auth_data')).toBe('Bearer old-token')
    expect(localStorage.getItem('xboard_auth_data')).toBeNull()
  })

  it('prefers a new session when stale legacy data also exists', () => {
    localStorage.setItem('txboard_auth_data', 'Bearer newest')
    localStorage.setItem('xboard_auth_data', 'Bearer outdated')
    expect(getAuthData()).toBe('Bearer newest')
    expect(localStorage.getItem('xboard_auth_data')).toBeNull()
  })

  it('clears both token keys and never resurrects the prior session', () => {
    localStorage.setItem('xboard_auth_data', 'old-token')
    saveAuthData('new-token')
    clearAuthData()
    expect(getAuthData()).toBe('')
    expect(localStorage.getItem('xboard_auth_data')).toBeNull()
    expect(localStorage.getItem('txboard_auth_data')).toBeNull()
  })

  it('sends the same bearer to the native endpoint and validates native envelope', async () => {
    localStorage.setItem('xboard_auth_data', 'legacy-token')
    responder = () => ({ data: { id: 7 }, request_id: 'trace-1' })
    await expect(nativeRequest<{ id: number }>(nativeApi.get('/me'))).resolves.toEqual({ id: 7 })
    expect(seen[0].baseURL).toBe('/txapi')
    expect(seen[0].url).toBe('/me')
    expect(String(seen[0].headers.Authorization)).toBe('Bearer legacy-token')
    expect(localStorage.getItem('xboard_auth_data')).toBeNull()
  })

  it('rejects malformed native envelopes rather than claiming success', async () => {
    await expect(nativeRequest(Promise.resolve({ data: { request_id: '' } as never })))
      .rejects.toThrow('Invalid TXAPI response')
  })
})
