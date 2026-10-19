import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, afterEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from './api'
import { admin } from './session'
import { UsersPage } from '../pages/users/UsersPage'
import { UserDetailPage } from '../pages/users/UserDetailPage'
import { OrdersPage } from '../pages/finance/OrdersPage'
import { OrderReadDialog } from './OrderReadDialog'

vi.mock('./session', () => {
  const snapshot = { busy: false }
  return { admin: { read: vi.fn(), forgetSession: vi.fn() }, workflowActions: {
    snapshot: () => snapshot, generation: () => 0, subscribe: () => () => {}, run: vi.fn(), clearError: vi.fn(),
  } }
})
const profile = { id: '9007199254740993', email: 'alice@example.test', banned: false, is_admin: false,
  created_at: null, last_login_at: null }
const order = { id: '7', user_id: profile.id, trade_no: 'ORDER-A', plan_id: '8', period: 'monthly',
  type: 1, status: 0, amount_minor: '9007199254740993', currency: 'USD', plan: { id: '8', name: 'Frozen Plus' },
  created_at: '2026-10-18T00:00:00Z', paid_at: null, purchase_terms_version: 'tx-core.purchase-terms/v2' }
let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.mocked(admin.read).mockReset()
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(page: string) {
  await act(async () => root.render(<QueryClientProvider client={client}><MemoryRouter initialEntries={[page]}>
    <Routes>
      <Route path="/user/manage" element={<UsersPage />} />
      <Route path="/user/:userId" element={<UserDetailPage />} />
      <Route path="/finance/order" element={<OrdersPage />} />
    </Routes>
  </MemoryRouter></QueryClientProvider>)); await settle()
}
it('uses backend account filters and pagination from the URL without numeric identity coercion', async () => {
  vi.mocked(admin.read).mockResolvedValue({ data: [profile],
    meta: { page: 2, per_page: 20, total: 21, last_page: 2 } } as never)
  await render('/user/manage?email=alice%40example.test&banned=0&page=2')
  expect(admin.read).toHaveBeenCalledWith('identity.accounts.list',
    { page: 2, per_page: 20, email: profile.email, banned: false }, expect.any(AbortSignal))
  expect(host.querySelector('a[href="/user/' + profile.id + '"]')).not.toBeNull()
  expect(host.textContent).toContain(profile.id)
})
it('never sends an unsafe order user ID from a crafted URL', async () => {
  await render('/finance/order?user_id=9223372036854775808')
  expect(admin.read).not.toHaveBeenCalled()
  expect(host.querySelector('[role="alert"]')?.textContent).toContain('参数有误')
})
it('shows native entitlement state and withdraws a user detail after permission loss', async () => {
  vi.mocked(admin.read).mockResolvedValue({ data: { ...profile, credential_status: 'provider_managed',
    subscription: { schema: 'core-entitlement/v1', authorized: false, reason: 'ENTITLEMENT_MISSING', user_id: profile.id } } } as never)
  await render('/user/' + profile.id)
  expect(host.textContent).toContain('当前没有可用订阅')
  expect(host.textContent).not.toContain('余额')
  vi.mocked(admin.read).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => [...host.querySelectorAll<HTMLButtonElement>('.page-header button')].find(node => node.getAttribute('aria-label') === '刷新' || node.textContent?.trim() === '刷新')!.click()); await settle()
  expect(host.textContent).not.toContain(profile.email)
  expect(host.querySelector('[aria-label="账户信息"]')).toBeNull()
  expect(host.querySelector('[aria-label="当前订阅"]')).toBeNull()
  expect(host.querySelector('[role="alert"]')?.textContent).toContain('没有此操作的权限')
})
it('displays a missing account as a resource error while preserving the administrator session', async () => {
  vi.mocked(admin.read).mockRejectedValueOnce(new CoreAdminApiError('NOT_FOUND', 404))
  await render('/user/22')
  expect(host.querySelector('[role="alert"]')?.textContent).toContain('未找到该用户')
  expect(host.textContent).not.toContain('重新登录')
  expect(admin.forgetSession).not.toHaveBeenCalled()
})
it('keeps a new order dialog isolated from an older response and aborts the detached read', async () => {
  let resolveOld!: (value: never) => void
  vi.mocked(admin.read).mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }) as never)
    .mockResolvedValueOnce({ data: { ...order, trade_no: 'ORDER-B' } } as never)
  const renderDialog = (tradeNo: string) => <QueryClientProvider client={client}><MemoryRouter>
    <OrderReadDialog tradeNo={tradeNo} onClose={() => {}} />
  </MemoryRouter></QueryClientProvider>
  await act(async () => root.render(renderDialog('ORDER-A'))); await settle()
  const signal = vi.mocked(admin.read).mock.calls[0][2]!
  vi.mocked(admin.read).mockResolvedValueOnce({ data: [], meta: { next_after_sequence: '0', has_more: false, history_complete: true } } as never)
  await act(async () => root.render(renderDialog('ORDER-B'))); await settle()
  expect(signal.aborted).toBe(true)
  await act(async () => resolveOld({ data: order } as never)); await settle()
  expect(document.querySelector('[role="dialog"][aria-label="订单详情"]')?.textContent).toContain('ORDER-B')
  expect(document.querySelector('[role="dialog"][aria-label="订单详情"]')?.textContent).not.toContain('ORDER-A')
  expect(document.querySelector('[role="dialog"][aria-label="订单详情"] .reference-order-amount strong')?.textContent).toBe('90071992547409.93 USD')
})
