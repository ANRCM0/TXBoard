import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { admin } from './session'
import { UserDetailPage } from '../pages/users/UserDetailPage'

const confirmRequest = vi.hoisted(() => ({ current: null as null | {
  title: string
  message?: string
  action: () => void
} }))
vi.mock('../components/ui/ConfirmDialog', () => ({
  requestConfirm: (request: typeof confirmRequest.current) => { confirmRequest.current = request },
}))
vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})

const user = { id: '12', email: 'alice@example.test', banned: false, is_admin: false, created_at: null, last_login_at: null }
let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  confirmRequest.current = null
  vi.mocked(admin.read).mockReset().mockResolvedValue({ data: { ...user, subscription: { authorized: false, reason: 'ENTITLEMENT_MISSING' } } } as never)
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: { id: user.id, banned: true } } as never)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }

it('bans a user with the observed status and refreshes the account', async () => {
  await act(async () => root.render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/user/12']}>
    <Routes><Route path="/user/:userId" element={<UserDetailPage />} /></Routes>
  </MemoryRouter></QueryClientProvider>)); await settle()
  const ban = [...host.querySelectorAll<HTMLButtonElement>('button')].find(node => node.textContent?.trim() === '封禁用户')!
  await act(async () => ban.click()); await settle()
  expect(confirmRequest.current?.title).toBe('封禁用户')
  await act(async () => { confirmRequest.current!.action() }); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('identity.accounts.status.update',
    { user_id: '12', expected_banned: false, banned: true }, expect.any(String))
  expect(admin.read).toHaveBeenCalledTimes(2)
})
