import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { UsersPage } from '../pages/users/UsersPage'
import { UserDetailPage } from '../pages/users/UserDetailPage'
import { OrdersPage } from '../pages/finance/OrdersPage'
import { accountRules, orderRules, parseListRules } from './ListRuleFilter'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
const user = { id: '9007199254740993', email: 'member@example.test', banned: false, is_admin: false, created_at: null, last_login_at: null,
  identity_editable: true, identity_revision: 'a'.repeat(64), metadata_revision: 'c'.repeat(64), remarks: 'Observed note',
  referral: { sponsor_id: '9007199254740995', sponsor_email: 'sponsor@example.test', bound_at: 1790000000 },
  credential_status: 'provider_managed', subscription: { authorized: false, reason: 'ENTITLEMENT_MISSING' } }
let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  workflowActions.reset(); vi.mocked(admin.write).mockReset(); vi.mocked(admin.read).mockReset()
  vi.mocked(admin.read).mockImplementation(async operation => {
    if (['billing.admin-account-balances.get', 'billing.admin-customer-policy.get', 'usage.admin-settled-entries.list'].includes(operation)) throw new CoreAdminApiError('FORBIDDEN', 403)
    if (operation === 'identity.accounts.get') return { data: user } as never
    if (operation === 'subscription.admin.get') return { data: { user_id: user.id, lifecycle: null } } as never
    return { data: operation === 'identity.accounts.list' ? [user] : [], meta: { page: 1, per_page: 20, total: 1, last_page: 1 } } as never
  })
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 25)) }) }
async function render(path: string, kind: 'users' | 'orders' | 'detail' = 'users') {
  await act(async () => root.render(<QueryClientProvider client={client}><MemoryRouter initialEntries={[path]}>
    {kind === 'users' ? <UsersPage /> : kind === 'orders' ? <OrdersPage /> : <Routes><Route path="/user/:userId" element={<UserDetailPage />} /></Routes>}
  </MemoryRouter></QueryClientProvider>)); await settle()
}
async function fill(input: HTMLInputElement, value: string) {
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, value); input.dispatchEvent(new Event('input', { bubbles: true })) })
}
async function click(text: string, container: ParentNode = host) {
  const button = [...container.querySelectorAll<HTMLButtonElement>('button')].find(node => node.textContent?.trim() === text)!
  expect(button, text).toBeTruthy(); await act(async () => button.click()); await settle()
}
it('keeps exact IDs in AND server queries and rejects invalid or currency-free amount queries', async () => {
  const filters = [{ field: 'id', operator: 'gte', value: user.id }, { field: 'email', operator: 'contains', value: '%_literal' }]
  const params = new URLSearchParams({ filters: JSON.stringify(filters), sort_by: 'email', sort_direction: 'asc' })
  await render('/user/manage?' + params)
  expect(admin.read).toHaveBeenCalledWith('identity.accounts.list', { page: 1, per_page: 20, filters, sort_by: 'email', sort_direction: 'asc' }, expect.any(AbortSignal))
  expect(parseListRules(new URLSearchParams({ filters: '[null]' }), accountRules).valid).toBe(false)
  expect(parseListRules(new URLSearchParams({ filters: JSON.stringify(Array(11).fill(filters[0])) }), accountRules).valid).toBe(false)
  expect(parseListRules(new URLSearchParams({ sort_by: 'amount_minor' }), orderRules).valid).toBe(false)
  expect(parseListRules(new URLSearchParams(), orderRules).sort_by).toBe('created_at')
  for (const rule of [{ field: 'currency', operator: 'eq', value: 'usd' }, { field: 'currency', operator: 'contains', value: 'USD' }]) {
    expect(parseListRules(new URLSearchParams({ filters: JSON.stringify([rule]) }), orderRules).valid).toBe(false)
  }
  expect(parseListRules(new URLSearchParams({ filters: JSON.stringify([{ field: 'currency', operator: 'eq', value: 'USD' }]), sort_by: 'amount_minor' }), orderRules).valid).toBe(true)
})
it('fails closed before sending an invalid order amount sort', async () => {
  await render('/finance/order?sort_by=amount_minor', 'orders')
  expect(admin.read).not.toHaveBeenCalled(); expect(host.querySelector('[role="alert"]')).toBeTruthy()
})
it('submits a whole batch, shows only account identities, and retains the original unknown operation', async () => {
  vi.mocked(admin.write).mockRejectedValueOnce(new Error('connection lost'))
  await render('/user/manage'); await click('批量创建')
  const dialog = document.querySelector('[role="dialog"][aria-label="批量创建用户"]')!
  await fill(dialog.querySelector('[aria-label="批量邮箱 1"]')!, 'First@example.test')
  await fill(dialog.querySelector('[aria-label="批量密码 1"]')!, 'private-pass-one')
  await click('添加账户（1/20）', dialog)
  await fill(dialog.querySelector('[aria-label="批量邮箱 2"]')!, 'second@example.test')
  await fill(dialog.querySelector('[aria-label="批量密码 2"]')!, 'private-pass-two')
  await click('创建整个批次', dialog)
  expect(admin.write).toHaveBeenCalledWith('identity.accounts.batch-create', { accounts: [{ email: 'first@example.test', password: 'private-pass-one' }, { email: 'second@example.test', password: 'private-pass-two' }] }, expect.any(String))
  expect(document.querySelector('[role="dialog"][aria-label="批量创建用户"]')).toBeNull()
  expect(host.textContent).toContain('原操作结果等待确认'); expect(host.textContent).not.toContain('private-pass-one')
  const pending = workflowActions.snapshot().pending!
  expect(pending.input).toEqual({ accounts: [{ email: 'first@example.test', password: 'private-pass-one' }, { email: 'second@example.test', password: 'private-pass-two' }] })
  vi.spyOn(window, 'confirm').mockReturnValue(true)
  vi.mocked(admin.write).mockResolvedValueOnce({ data: { items: [{ id: user.id, email: 'first@example.test' }], created_count: 1 } } as never)
  await click('确认原操作')
  expect(vi.mocked(admin.write).mock.calls[1]?.[2]).toBe(pending.key)
})
it('submits a profile against the observed revision without provider fields and clears passwords on conflicts', async () => {
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('NATIVE_ACCOUNT_VERSION_CONFLICT', 409))
  await render('/user/' + user.id, 'detail'); await click('编辑登录资料')
  const dialog = document.querySelector('[role="dialog"][aria-label="编辑登录资料"]')!
  await fill(dialog.querySelector('input[type="email"]')!, 'Renamed@example.test')
  await fill(dialog.querySelector('input[type="password"]')!, 'new-native-password')
  await click('保存登录资料', dialog)
  expect(admin.write).toHaveBeenCalledWith('identity.accounts.profile.update', { user_id: user.id, expected_revision: user.identity_revision, email: 'renamed@example.test', password: 'new-native-password' }, expect.any(String))
  expect(dialog.querySelector<HTMLInputElement>('input[type="password"]')!.value).toBe('')
  expect(dialog.querySelector('[role="alert"]')).toBeTruthy()
})
it('reads a fresh protected revision before editing from a list row', async () => {
  vi.mocked(admin.write).mockResolvedValue({ data: { id: user.id, email: 'list-edited@example.test', identity_revision: 'b'.repeat(64), sessions_revoked: 0 } } as never)
  await render('/user/manage')
  await act(async () => host.querySelector<HTMLButtonElement>('.row-actions-trigger')!.click()); await settle()
  await act(async () => host.querySelector<HTMLButtonElement>(`[aria-label="编辑 ${user.email} 登录资料"]`)!.click()); await settle()
  expect(admin.read).toHaveBeenCalledWith('identity.accounts.get', { user_id: user.id }, expect.any(AbortSignal))
  const dialog = document.querySelector('[role="dialog"][aria-label="编辑登录资料"]')!
  await fill(dialog.querySelector('input[type="email"]')!, 'list-edited@example.test')
  await click('保存登录资料', dialog)
  expect(admin.write).toHaveBeenCalledWith('identity.accounts.profile.update', { user_id: user.id, expected_revision: user.identity_revision, email: 'list-edited@example.test' }, expect.any(String))
  expect(document.querySelector('[role="dialog"][aria-label="编辑登录资料"]')).toBeNull()
})
it('withdraws a populated profile editor when permission is revoked and protects admin profiles', async () => {
  await render('/user/' + user.id, 'detail'); await click('编辑登录资料')
  const dialog = document.querySelector('[role="dialog"][aria-label="编辑登录资料"]')!
  await fill(dialog.querySelector('input[type="password"]')!, 'retained-private-password')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await client.invalidateQueries({ queryKey: ['native-admin', 'account-detail'] }) }); await settle()
  expect(document.querySelector('[role="dialog"][aria-label="编辑登录资料"]')).toBeNull()
  expect(host.textContent).not.toContain(user.email)
  vi.mocked(admin.read).mockImplementation(async operation => {
    if (['billing.admin-account-balances.get', 'billing.admin-customer-policy.get', 'usage.admin-settled-entries.list'].includes(operation)) throw new CoreAdminApiError('FORBIDDEN', 403)
    return operation === 'identity.accounts.get' ? { data: { ...user, is_admin: true, identity_editable: false } } as never : { data: [] } as never
  })
  await click('刷新')
  expect(host.textContent).not.toContain('编辑登录资料')
})
it('reads immutable native referrals and saves notes through their separate observed revision', async () => {
  vi.mocked(admin.write).mockResolvedValue({ data: { id: user.id, remarks: 'New note', metadata_revision: 'd'.repeat(64) } } as never)
  await render('/user/' + user.id, 'detail')
  const sponsor = host.querySelector<HTMLAnchorElement>('a[href="/user/9007199254740995"]')!
  expect(sponsor.textContent).toContain('sponsor@example.test')
  await click('编辑备注')
  const dialog = document.querySelector('[role="dialog"][aria-label="编辑管理备注"]')!
  const textarea = dialog.querySelector('textarea')!
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')!.set!.call(textarea, 'New note'); textarea.dispatchEvent(new Event('input', { bubbles: true })) })
  await click('保存备注', dialog)
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('identity.accounts.metadata.update', { user_id: user.id, expected_revision: user.metadata_revision, remarks: 'New note' }, expect.any(String))
  expect(document.querySelector('[role="dialog"][aria-label="编辑管理备注"]')).toBeNull()
  expect(vi.mocked(admin.read).mock.calls.filter(call => call[0] === 'identity.accounts.get')).toHaveLength(2)
})
