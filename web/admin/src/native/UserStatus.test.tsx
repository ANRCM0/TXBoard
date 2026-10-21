import { act, type ReactElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin } from './session'
import { UserDetailPage } from '../pages/users/UserDetailPage'
import { UsersPage } from '../pages/users/UsersPage'

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
const order = { id: '103', trade_no: 'USER-ORDER-A', plan_id: '8', period: 'monthly', type: 1, status: 3,
  amount_minor: '2900', plan: { id: '8', name: 'Observed plan' }, paid_at: '2026-10-19T00:00:00Z',
  created_at: '2026-10-19T00:00:00Z', user_id: '12', currency: 'CNY', purchase_terms_version: 'tx-core.purchase-terms/v2' }
let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  confirmRequest.current = null
  vi.mocked(admin.read).mockReset().mockImplementation(async operation => {
    if (['billing.admin-account-balances.get', 'billing.admin-customer-policy.get', 'usage.admin-settled-entries.list'].includes(operation)) throw new CoreAdminApiError('FORBIDDEN', 403)
    if (operation === 'commerce.admin-orders.list') return { data: [order], meta: { page: 1, per_page: 5, total: 1, last_page: 1 } } as never
    if (operation === 'identity.accounts.list') return { data: [user], meta: { page: 1, per_page: 20, total: 1, last_page: 1 } } as never
    if (operation === 'subscription.admin.get') return { data: { user_id: user.id, lifecycle: null }, meta: {} } as never
    return { data: { ...user, subscription: { authorized: false, reason: 'ENTITLEMENT_MISSING' } }, meta: {} } as never
  })
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: { id: user.id, banned: true } } as never)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(path: string, element: ReactElement) {
  await act(async () => root.render(<QueryClientProvider client={client}><MemoryRouter initialEntries={[path]}>
    {element}
  </MemoryRouter></QueryClientProvider>)); await settle()
}

it('bans a user with the observed status, refreshes the account and shows recent orders', async () => {
  await render('/user/12', <Routes><Route path="/user/:userId" element={<UserDetailPage />} /></Routes>)
  expect(admin.read).toHaveBeenCalledWith('commerce.admin-orders.list', { page: 1, per_page: 5, user_id: '12' }, expect.any(AbortSignal))
  await act(async () => { await new Promise(resolve => setTimeout(resolve, 50)) })
  await act(async () => { await vi.waitFor(() => expect(host.textContent).toContain('USER-ORDER-A')) })
  const ban = [...host.querySelectorAll<HTMLButtonElement>('button')].find(node => node.textContent?.trim() === '封禁用户')!
  await act(async () => ban.click()); await settle()
  expect(confirmRequest.current?.title).toBe('封禁用户')
  await act(async () => { confirmRequest.current!.action() }); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('identity.accounts.status.update',
    { user_id: '12', expected_banned: false, banned: true }, expect.any(String))
  expect(vi.mocked(admin.read).mock.calls.filter(call => call[0] === 'identity.accounts.get')).toHaveLength(2)
})

it('creates a user from the list and opens the new detail page', async () => {
  vi.mocked(admin.write).mockResolvedValueOnce({ data: { id: '20', email: 'new@example.test' } } as never)
  await render('/user/manage', <Routes>
    <Route path="/user/manage" element={<UsersPage />} />
    <Route path="/user/:userId" element={<div>new user detail</div>} />
  </Routes>)
  const create = [...host.querySelectorAll<HTMLButtonElement>('button')].find(button => button.textContent?.includes('新建用户'))!
  await act(async () => create.click()); await settle()
  const dialog = document.querySelector<HTMLElement>('[role="dialog"][aria-label="新建用户"]')!
  const email = dialog.querySelector<HTMLInputElement>('input[type="email"]')!
  const password = dialog.querySelector<HTMLInputElement>('input[type="password"]')!
  await act(async () => {
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(email, 'new@example.test')
    email.dispatchEvent(new Event('input', { bubbles: true }))
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(password, 'secure-pass-2026')
    password.dispatchEvent(new Event('input', { bubbles: true }))
  })
  const submit = [...dialog.querySelectorAll<HTMLButtonElement>('button')].find(button => button.textContent?.trim() === '创建用户')!
  await act(async () => submit.click()); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('identity.accounts.create',
    { email: 'new@example.test', password: 'secure-pass-2026' }, expect.any(String))
  expect(host.textContent).toContain('new user detail')
})

it('offers the same version-checked status action from the user list', async () => {
  await render('/user/manage', <Routes><Route path="/user/manage" element={<UsersPage />} /></Routes>)
  const ban = host.querySelector<HTMLButtonElement>('button[aria-label="封禁 alice@example.test"]')!
  expect(ban).not.toBeNull()
  await act(async () => ban.click()); await settle()
  expect(confirmRequest.current?.title).toBe('封禁用户')
  await act(async () => { confirmRequest.current!.action() }); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('identity.accounts.status.update',
    { user_id: '12', expected_banned: false, banned: true }, expect.any(String))
  expect(vi.mocked(admin.read).mock.calls.filter(call => call[0] === 'identity.accounts.list')).toHaveLength(2)
})
