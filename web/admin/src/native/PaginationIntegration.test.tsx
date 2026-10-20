import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { UsersPage } from '../pages/users/UsersPage'
import { OrdersPage } from '../pages/finance/OrdersPage'
import { NodesPage } from './NodesPage'
import { admin, workflowActions } from './session'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})

let host: HTMLDivElement, root: Root, client: QueryClient
const account = { id: '7', email: 'private@example.test', banned: false, is_admin: false, created_at: null, last_login_at: null }
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  workflowActions.reset(); vi.mocked(admin.write).mockReset(); vi.mocked(admin.read).mockReset()
  vi.mocked(admin.read).mockImplementation(async (_operation, input) => ({ data: [account],
    meta: { page: 'page' in input ? input.page : 1, per_page: 'per_page' in input ? input.per_page : 20, total: 401, last_page: 21 } }) as never)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset() })
function Location() { return <output data-location>{useLocation().search}</output> }
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(kind: 'users' | 'orders' | 'nodes', url = '/') {
  await act(async () => root.render(<QueryClientProvider client={client}><MemoryRouter initialEntries={[url]}>
    <Location />{kind === 'users' ? <UsersPage /> : kind === 'orders' ? <OrdersPage /> : <NodesPage />}
  </MemoryRouter></QueryClientProvider>)); await settle()
}
async function resize(size: string) {
  await act(async () => {
    const select = host.querySelector<HTMLSelectElement>('[aria-label="每页显示条数"]')!
    select.value = size; select.dispatchEvent(new Event('change', { bubbles: true }))
  }); await settle()
}
it.each(['users', 'orders'] as const)('preserves %s filters while resizing and preserves page size when applying and clearing filters', async kind => {
  if (kind === 'orders') vi.mocked(admin.read).mockResolvedValue({ data: [], meta: { total: 401, last_page: 21 } } as never)
  const filter = kind === 'users' ? 'email=private%40example.test&banned=0' : 'user_id=7&status=0'
  await render(kind, '/?page=3&per_page=50&' + filter)
  await resize('100')
  const params = () => new URLSearchParams(host.querySelector('[data-location]')!.textContent!)
  expect(params().get('page')).toBeNull()
  expect(params().get('per_page')).toBe('100')
  expect(params().get(kind === 'users' ? 'banned' : 'status')).toBe('0')
  expect(admin.read).toHaveBeenLastCalledWith(kind === 'users' ? 'identity.accounts.list' : 'commerce.admin-orders.list',
    expect.objectContaining({ page: 1, per_page: 100 }), expect.any(AbortSignal))
  await act(async () => host.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))); await settle()
  expect(params().get('per_page')).toBe('100')
  const clear = host.querySelector<HTMLButtonElement>('.ui-filter-clear')!
  await act(async () => clear.click()); await settle()
  expect(params().get('per_page')).toBe('100')
  expect(params().get(kind === 'users' ? 'email' : 'user_id')).toBeNull()
})
it.each(['users', 'orders'] as const)('withdraws cached %s totals after command permission loss and restores them only after an authorized refresh', async kind => {
  if (kind === 'orders') vi.mocked(admin.read).mockResolvedValue({ data: [], meta: { total: 401, last_page: 21 } } as never)
  await render(kind)
  expect(host.textContent).toContain('共 401 项')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await workflowActions.run('identity.accounts.status.update', { user_id: '7', expected_banned: false, banned: true }).catch(() => {}) }); await settle()
  expect(host.textContent).not.toContain('共 401 项')
  expect(host.textContent).not.toContain(account.email)
  expect(host.querySelector<HTMLSelectElement>('[aria-label="每页显示条数"]')!.disabled).toBe(true)
  const refresh = [...host.querySelectorAll<HTMLButtonElement>('.page-header button')].find(button => button.textContent === '刷新')!
  await act(async () => refresh.click()); await settle()
  expect(host.textContent).toContain('共 401 项')
})
it('clears node selections on page-size changes and authorization loss', async () => {
  vi.mocked(admin.read).mockResolvedValue({ data: [{ id: '7', name: 'private-node', type: 'vless', host: 'example.test', port: 443, enabled: true, show: true, machine_id: null, u: '0', d: '0' }], meta: { total: 401, last_page: 21 } } as never)
  await render('nodes')
  const checkbox = () => host.querySelector<HTMLInputElement>('[aria-label="选择 private-node"]')!
  await act(async () => checkbox().click())
  await resize('50')
  expect(checkbox().checked).toBe(false)
  await act(async () => checkbox().click())
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await workflowActions.run('network.nodes.batch-update', { ids: ['7'], enabled: true }).catch(() => {}) }); await settle()
  const refresh = host.querySelector<HTMLButtonElement>('.page-header button')!
  await act(async () => refresh.click()); await settle()
  expect(checkbox().checked).toBe(false)
})

it('opens an order with localized purchase details and exact discount amounts and copies its original trade number', async () => {
  const order = { id: '9', trade_no: 'ORDER-EXACT', user_id: '7', plan_id: '8', type: 2, period: 'yearly', status: 3,
    plan: { id: '8', name: '年度套餐' }, amount_minor: '9007199254740993', list_amount_minor: '9007199254741093',
    coupon_discount_minor: '100', currency: 'USD', created_at: '2026-10-19T00:00:00Z', paid_at: null }
  vi.mocked(admin.read).mockImplementation(async operation => {
    if (operation === 'commerce.admin-orders.list') return { data: [order], meta: { total: 1, last_page: 1 } } as never
    if (operation === 'commerce.admin-orders.get') return { data: order } as never
    return { data: [], meta: { next_after_sequence: '0', next_after_id: '0', has_more: false, history_complete: true } } as never
  })
  const copy = vi.fn().mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: copy } })
  await render('orders')
  expect(host.textContent).toContain('续费')
  expect(host.textContent).toContain('年付')
  const details = host.querySelector<HTMLButtonElement>('[aria-label="查看订单 ORDER-EXACT 详情"]')!
  await act(async () => details.click()); await settle()
  const dialog = document.querySelector<HTMLElement>('[role="dialog"][aria-label="订单详情"]')!
  expect(dialog.textContent).toContain('90071992547409.93 USD')
  expect(dialog.textContent).toContain('商品原价90071992547410.93 USD')
  expect(dialog.textContent).toContain('优惠券抵扣1.00 USD')
  expect(dialog.textContent).toContain('暂无支付记录')
  await act(async () => dialog.querySelector<HTMLButtonElement>('[aria-label="复制订单号"]')!.click())
  expect(copy).toHaveBeenCalledExactlyOnceWith('ORDER-EXACT')
  expect(admin.read).toHaveBeenCalledWith('commerce.admin-orders.get', { trade_no: 'ORDER-EXACT' }, expect.any(AbortSignal))
  delete (navigator as { clipboard?: Clipboard }).clipboard
})

it('presents user identity and copies the complete account ID without navigating away', async () => {
  const copy = vi.fn().mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: copy } })
  await render('users')
  expect(host.textContent).toContain('站点账户')
  expect(host.textContent).toContain('尚未登录')
  expect(host.querySelector('[aria-label="查看 private@example.test 详情"]')).not.toBeNull()
  await act(async () => host.querySelector<HTMLButtonElement>('[aria-label="复制用户 ID"]')!.click())
  expect(copy).toHaveBeenCalledExactlyOnceWith('7')
  expect(host.querySelector('[data-location]')!.textContent).toBe('')
  delete (navigator as { clipboard?: Clipboard }).clipboard
})

it.each(['users', 'orders'] as const)('removes only one %s URL filter while retaining the page size and resetting pagination', async kind => {
  if (kind === 'orders') vi.mocked(admin.read).mockResolvedValue({ data: [], meta: { total: 401, last_page: 21 } } as never)
  const filter = kind === 'users' ? 'email=private%40example.test&banned=0' : 'user_id=7&trade_no=ORDER-A&status=0'
  await render(kind, '/?page=3&per_page=50&' + filter)
  const remove = host.querySelector<HTMLButtonElement>(kind === 'users' ? '[aria-label="移除筛选 邮箱 private@example.test"]' : '[aria-label="移除筛选 用户 #7"]')!
  await act(async () => remove.click()); await settle()
  const params = new URLSearchParams(host.querySelector('[data-location]')!.textContent!)
  expect(params.get('page')).toBeNull()
  expect(params.get('per_page')).toBe('50')
  expect(params.get(kind === 'users' ? 'email' : 'user_id')).toBeNull()
  expect(params.get(kind === 'users' ? 'banned' : 'status')).toBe('0')
  if (kind === 'orders') expect(params.get('trade_no')).toBe('ORDER-A')
})

it('uses one user card list and one pagination control at phone width', async () => {
  const previous = window.matchMedia
  window.matchMedia = vi.fn(() => ({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() }) as unknown as MediaQueryList)
  try {
    await render('users', '/?per_page=50')
    expect(host.querySelector('table')).toBeNull()
    expect(host.querySelectorAll('.ui-mobile-record')).toHaveLength(1)
    expect(host.querySelectorAll('[aria-label="用户列表分页"]')).toHaveLength(1)
    expect(host.querySelectorAll('[aria-label="查看 private@example.test 详情"]')).toHaveLength(1)
    expect(host.querySelector('.ui-mobile-record')!.textContent).toContain('站点账户')
  } finally { window.matchMedia = previous }
})
