import { describe, expect, it, vi } from 'vitest'
import { createCoreAdminClient } from '../src/index.js'

const key = '11111111-1111-4111-8111-111111111111'
const result = (data: unknown, coreMeta = {}) => Response.json({ ok: true, data,
  meta: { version: '1', requestId: 'trace', coreRequestId: key, coreMeta } })

describe('administrator browser SDK', () => {
  it('logs in without sending an old user identity, then sends the exact administrator context', async () => {
    const send = vi.fn<typeof fetch>().mockResolvedValueOnce(result({ auth_data: 'Bearer administrator-token', is_admin: true, secure_path: 'rotated_path' }))
      .mockResolvedValueOnce(result({ id: '9007199254740993', email: 'admin@example.test' }))
    const client = createCoreAdminClient({ getToken: () => 'Bearer administrator-token', getAdminPath: () => 'rotated_path', fetchImpl: send })
    await client.call('identity.admin-session.create', { email: 'admin@example.test', password: 'password-long' }, { idempotencyKey: key })
    const loginHeaders = new Headers(send.mock.calls[0]![1]!.headers)
    expect(loginHeaders.has('Authorization')).toBe(false)
    expect(loginHeaders.has('X-Core-Admin-Path')).toBe(false)
    expect((await client.call('identity.admin.get', {})).id).toBe('9007199254740993')
    const headers = new Headers(send.mock.calls[1]![1]!.headers)
    expect(headers.get('Authorization')).toBe('Bearer administrator-token')
    expect(headers.get('X-Core-Admin-Path')).toBe('rotated_path')
  })

  it('rejects wrong domain, absent management context and forged actor fields before network I/O', async () => {
    const send = vi.fn<typeof fetch>()
    const client = createCoreAdminClient({ getToken: () => 'administrator-token', getAdminPath: () => null, fetchImpl: send })
    await expect(client.call('identity.admin.get', {})).rejects.toMatchObject({ code: 'ADMIN_CONTEXT_REQUIRED' })
    await expect(client.call('billing.wallet.get' as never, {})).rejects.toMatchObject({ code: 'FORBIDDEN' })
    await expect(client.call('identity.admin.get', { user_id: '1' } as never)).rejects.toMatchObject({ code: 'INPUT_INVALID' })
    expect(send).not.toHaveBeenCalled()
  })

  it('preserves pagination and rejects secret or unexpected nested output', async () => {
    const send = vi.fn<typeof fetch>().mockResolvedValueOnce(result([], { page: 1, per_page: 10, total: 0, last_page: 1 }))
      .mockResolvedValueOnce(result({ id: '1', email: 'admin@example.test', token: 'private' }))
    const client = createCoreAdminClient({ getToken: () => 'administrator-token', getAdminPath: () => 'rotated_path', fetchImpl: send })
    expect((await client.callResult('network.nodes.list', {})).meta.total).toBe(0)
    await expect(client.call('identity.admin.get', {})).rejects.toMatchObject({ code: 'INVALID_RESPONSE' })
  })
})
