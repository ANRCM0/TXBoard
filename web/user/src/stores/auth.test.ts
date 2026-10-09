import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { fetchUserInfo } from '../api/user'
import { getAuthData, saveAuthData } from '../api/client'
import { useAuthStore } from './auth'

vi.mock('../api/auth', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  register: vi.fn(),
}))

vi.mock('../api/user', () => ({
  fetchUserInfo: vi.fn(),
}))

const user = {
  email: 'operator@txboard.local',
  transfer_enable: 0,
  u: 0,
  d: 0,
  expired_at: null,
  plan_id: null,
  balance: 0,
  commission_balance: 0,
  uuid: 'test-user',
}

describe('auth store request deduplication', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('shares one user request across concurrent callers', async () => {
    vi.mocked(fetchUserInfo).mockResolvedValue(user)
    const auth = useAuthStore()

    const [first, second] = await Promise.all([auth.loadUser(), auth.loadUser()])

    expect(fetchUserInfo).toHaveBeenCalledTimes(1)
    expect(first).toEqual(user)
    expect(second).toEqual(user)
    expect(auth.user).toEqual(user)
  })
  it('reuses the native profile request for session revalidation', async () => {
    saveAuthData('Bearer known-session')
    vi.mocked(fetchUserInfo).mockResolvedValue(user)
    const auth = useAuthStore()

    await expect(auth.checkSession()).resolves.toBe(true)
    expect(fetchUserInfo).toHaveBeenCalledTimes(1)
    expect(auth.user).toEqual(user)
    expect(getAuthData()).toBe('Bearer known-session')
  })

  it('does not destroy the bearer on an offline session check', async () => {
    saveAuthData('Bearer offline-session')
    vi.mocked(fetchUserInfo).mockRejectedValue(new Error('Network Error'))
    const auth = useAuthStore()

    await expect(auth.checkSession()).resolves.toBe(false)
    expect(auth.authenticated).toBe(true)
    expect(getAuthData()).toBe('Bearer offline-session')
  })

  it('clears a rejected native session', async () => {
    saveAuthData('Bearer invalid-session')
    vi.mocked(fetchUserInfo).mockRejectedValue({ response: { status: 401 } })
    const auth = useAuthStore()

    await expect(auth.checkSession()).resolves.toBe(false)
    expect(auth.authenticated).toBe(false)
    expect(getAuthData()).toBe('')
  })
})
