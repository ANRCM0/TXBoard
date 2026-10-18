import { describe, expect, it, vi } from 'vitest'
import { createCoreAdminSession } from '../src/index.js'

const key = '11111111-1111-4111-8111-111111111111'
const envelope = (data: unknown) => Response.json({ ok: true, data,
  meta: { version: '1', requestId: 'trace', coreRequestId: key, coreMeta: {} } })
const credentials = { email: 'admin@example.test', password: 'long-password' }
const loggedIn = () => envelope({ auth_data: 'Bearer admin-token-long', is_admin: true, secure_path: 'rotated_path' })
const forbidden = (status: number) => Response.json({ ok: false, error: {
  code: status === 401 ? 'UNAUTHORIZED' : 'FORBIDDEN', message: 'safe error' },
  meta: { version: '1', requestId: 'trace' } }, { status })

describe('administrator session lifecycle', () => {
  it('separates reads from commands and preserves the original action key after an unknown response', async () => {
    const send = vi.fn<typeof fetch>().mockResolvedValueOnce(loggedIn())
      .mockRejectedValueOnce(new Error('lost response'))
      .mockResolvedValueOnce(envelope({ id: '9007199254740993', banned: true }))
    const admin = createCoreAdminSession({ fetchImpl: send })
    await admin.login(credentials, key)
    const input = { user_id: '9007199254740993', expected_banned: false, banned: true }
    expect(() => admin.read('identity.accounts.status.update' as never, input as never)).toThrow()
    expect(send).toHaveBeenCalledTimes(1)
    await expect(admin.write('identity.accounts.status.update', input, key))
      .rejects.toMatchObject({ needsStateConfirmation: true })
    expect(send).toHaveBeenCalledTimes(2)
    const result = await admin.write('identity.accounts.status.update', input, key)
    expect(result.data).toEqual({ id: input.user_id, banned: true })
    const first = new Headers(send.mock.calls[1]![1]!.headers)
    const second = new Headers(send.mock.calls[2]![1]!.headers)
    expect(first.get('Idempotency-Key')).toBe(key)
    expect(second.get('Idempotency-Key')).toBe(key)
    expect(second.get('X-Core-Admin-Path')).toBe('rotated_path')
  })
  it('keeps credentials internal, retains session on 403, and clears current session on 401', async () => {
    const send = vi.fn<typeof fetch>().mockResolvedValueOnce(loggedIn())
      .mockResolvedValueOnce(forbidden(403)).mockResolvedValueOnce(forbidden(401))
    const ended = vi.fn()
    const admin = createCoreAdminSession({ fetchImpl: send, onSessionEnded: ended })
    expect(await admin.login(credentials, key)).toEqual({ isAdmin: true })
    await expect(admin.read('identity.admin.get', {})).rejects.toMatchObject({ status: 403 })
    expect(admin.hasSession()).toBe(true)
    await expect(admin.read('identity.admin.get', {})).rejects.toMatchObject({ status: 401 })
    expect(admin.hasSession()).toBe(false)
    expect(ended).toHaveBeenCalledTimes(1)
  })
  it('rejects stale responses and aborts the old request when the local context changes', async () => {
    let complete: (response: Response) => void = () => {}
    const send = vi.fn<typeof fetch>().mockResolvedValueOnce(loggedIn())
      .mockImplementationOnce(() => new Promise(resolve => { complete = resolve }))
    const admin = createCoreAdminSession({ fetchImpl: send })
    await admin.login(credentials, key)
    const pending = expect(admin.read('identity.admin.get', {})).rejects.toMatchObject({ name: 'StaleAdminContext' })
    await vi.waitFor(() => expect(send).toHaveBeenCalledTimes(2))
    const signal = send.mock.calls[1]![1]!.signal!
    admin.forgetSession()
    expect(signal.aborted).toBe(true)
    complete(envelope({ id: '1', email: 'old-account@example.test' }))
    await pending
    expect(admin.hasSession()).toBe(false)
  })
  it('clears local state after uncertain logout without claiming server confirmation or retrying', async () => {
    const send = vi.fn<typeof fetch>().mockResolvedValueOnce(loggedIn()).mockRejectedValueOnce(new Error('response lost'))
    const admin = createCoreAdminSession({ fetchImpl: send })
    await admin.login(credentials, key)
    expect(await admin.logout(key)).toEqual({ serverConfirmed: false })
    expect(admin.hasSession()).toBe(false)
    expect(send).toHaveBeenCalledTimes(2)
  })
})
