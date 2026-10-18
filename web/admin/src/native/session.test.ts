import { afterAll, afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { send } = vi.hoisted(() => {
  const send = vi.fn<typeof fetch>()
  vi.stubGlobal('fetch', send)
  return { send }
})
import { admin, loginAdmin, logoutAdmin, revokeAdminSession } from './session'
import { queryClient } from './queryClient'

const requestId = '12345678-1234-4234-8234-123456789abc'
function success(data: unknown, coreMeta: unknown = {}) {
  return Response.json({ ok: true, data, meta: { version: '1', requestId, coreRequestId: requestId, coreMeta } })
}
function failure(status: number) {
  return Response.json({ ok: false, error: { code: status === 401 ? 'UNAUTHORIZED' : 'FORBIDDEN', message: 'withheld' },
    meta: { version: '1', requestId } }, { status })
}
const credentials = { email: 'admin@example.test', password: 'admin-password' }
beforeEach(() => {
  admin.forgetSession()
  localStorage.clear()
  send.mockReset().mockImplementation(async () => success({ auth_data: 'Bearer administrator-token', is_admin: true, secure_path: 'current_path' }))
})
afterEach(() => { admin.forgetSession() })
afterAll(() => { vi.unstubAllGlobals() })

describe('frontend identity and command context', () => {
  it('keeps credentials in memory, clears old cached data and forwards the current path', async () => {
    queryClient.setQueryData(['old-identity'], { privateData: true })
    await loginAdmin(credentials)
    expect(admin.hasSession()).toBe(true)
    expect(queryClient.getQueryData(['old-identity'])).toBeUndefined()
    expect(localStorage.length).toBe(0)
    send.mockResolvedValueOnce(success({ id: '9007199254740993', email: credentials.email }))
    const identity = await admin.read('identity.admin.get', {})
    expect(identity.data.id).toBe('9007199254740993')
    const headers = new Headers(send.mock.calls.at(-1)![1]!.headers)
    expect(headers.get('Authorization')).toBe('Bearer administrator-token')
    expect(headers.get('X-Core-Admin-Path')).toBe('current_path')
    expect(send.mock.calls.every(([url]) => String(url).startsWith('/gateway/v1/core/admin/'))).toBe(true)
    admin.forgetSession()
  })
  it('keeps a forbidden session but clears identity and cache when the server returns 401', async () => {
    await loginAdmin(credentials)
    queryClient.setQueryData(['private'], { secret: true })
    send.mockResolvedValueOnce(failure(403))
    await expect(admin.read('identity.admin.get', {})).rejects.toMatchObject({ status: 403 })
    expect(admin.hasSession()).toBe(true)
    send.mockResolvedValueOnce(failure(401))
    await expect(admin.read('identity.admin.get', {})).rejects.toMatchObject({ status: 401 })
    expect(admin.hasSession()).toBe(false)
    expect(queryClient.getQueryCache().getAll()).toHaveLength(0)
  })
  it('reuses a logical revocation key after a lost response without automatic mutation retries', async () => {
    await loginAdmin(credentials)
    send.mockRejectedValueOnce(new TypeError('network loss'))
    await expect(revokeAdminSession('2')).rejects.toBeDefined()
    const first = new Headers(send.mock.calls.at(-1)![1]!.headers).get('Idempotency-Key')
    expect(send).toHaveBeenCalledTimes(2)
    send.mockResolvedValueOnce(success({ ok: true }))
    await revokeAdminSession('2')
    const second = new Headers(send.mock.calls.at(-1)![1]!.headers).get('Idempotency-Key')
    expect(second).toBe(first)
    expect(first).toMatch(/^[0-9a-f-]{36}$/)
    admin.forgetSession()
  })
  it('forgets local identity even when logout cannot be confirmed', async () => {
    await loginAdmin(credentials)
    queryClient.setQueryData(['private'], true)
    send.mockRejectedValueOnce(new TypeError('network loss'))
    expect(await logoutAdmin()).toEqual({ serverConfirmed: false })
    expect(admin.hasSession()).toBe(false)
    expect(queryClient.getQueryCache().getAll()).toHaveLength(0)
  })
})
