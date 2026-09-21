import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { fetchUserInfo } from '../api/user'
import { useAuthStore } from './auth'

vi.mock('../api/auth', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  register: vi.fn(),
}))

vi.mock('../api/user', () => ({
  checkLogin: vi.fn(),
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
})
