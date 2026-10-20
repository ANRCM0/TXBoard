import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { admin } from './session'
import { UserDetailPage } from '../pages/users/UserDetailPage'

const confirmRequest = vi.hoisted(() => ({ current: null as null | { title: string; action: () => void } }))
vi.mock('../components/ui/ConfirmDialog', () => ({
  requestConfirm: (request: typeof confirmRequest.current) => { confirmRequest.current = request },
}))
vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})

const user = { id: '12', email: 'lifecycle@example.test', banned: false, is_admin: false, created_at: null, last_login_at: null }
const subscription = { schema: 'core-entitlement/v1', authorized: true, reason: 'ENTITLEMENT_GRANTED',
  user_id: user.id, subscription_id: '88', subscription_version: '1', plan_id: '8', terms_id: '9',
  terms_version: '1', plan_revision_id: '10', usage_period_id: '11', period_terms_id: '9', period_version: '1',
  group_id: '7', speed_limit_mbps: null, device_limit: null, expires_at: null, next_reset_at: null,
  quota_bytes: '1000', consumed_bytes: '100', remaining_bytes: '900', upload_bytes: '60', download_bytes: '40' }
let lifecycle: Record<string, unknown> = {
  subscription_id: '88', subscription_version: '1', plan_id: '8', terms_id: '9',
  usage_period_id: '11', period_version: '1', status: 'active', reason: null,
  expires_at: null, next_reset_at: null, ended_at: null, updated_at: 1792368000,
}
let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  lifecycle = { ...lifecycle, status: 'active', reason: null, subscription_version: '1', ended_at: null }
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  confirmRequest.current = null
  vi.mocked(admin.read).mockReset().mockImplementation(async operation => {
    if (operation === 'identity.accounts.get') return { data: { ...user, subscription }, meta: {} } as never
    if (operation === 'subscription.admin.get') return { data: { user_id: user.id, lifecycle }, meta: {} } as never
    if (operation === 'commerce.admin-orders.list') return { data: [], meta: { page: 1, per_page: 5, total: 0, last_page: 1 } } as never
    return { data: {}, meta: {} } as never
  })
  vi.mocked(admin.write).mockReset().mockImplementation(async (operation, input) => {
    if (operation === 'subscription.lifecycle.suspend') {
      lifecycle = { ...lifecycle, status: 'suspended', reason: (input as { reason?: string }).reason ?? 'manual', subscription_version: '2' }
    } else if (operation === 'subscription.lifecycle.resume') {
      lifecycle = { ...lifecycle, status: 'active', reason: null, subscription_version: '3' }
    }
    return { data: {}, meta: {} } as never
  })
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 30)) }) }
function button(text: string, scope: ParentNode = host) {
  return [...scope.querySelectorAll<HTMLButtonElement>('button')].find(node => node.textContent?.trim() === text)!
}
async function input(node: HTMLInputElement, value: string) {
  await act(async () => {
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(node, value)
    node.dispatchEvent(new Event('input', { bubbles: true }))
  })
}

it('suspends and resumes a subscription with observed versions and explicit confirmation', async () => {
  await act(async () => root.render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/user/12']}>
    <Routes><Route path="/user/:userId" element={<UserDetailPage />} /></Routes>
  </MemoryRouter></QueryClientProvider>)); await settle()
  await vi.waitFor(async () => {
    await settle()
    expect(button('暂停订阅')).toBeDefined()
  })
  await act(async () => button('暂停订阅').click()); await settle()
  const dialog = document.querySelector<HTMLElement>('[role="dialog"][aria-label="暂停订阅"]')!
  await input(dialog.querySelector<HTMLInputElement>('input')!, 'review')
  await act(async () => button('确认暂停', dialog).click()); await settle()
  await vi.waitFor(async () => {
    await settle()
    expect(admin.write).toHaveBeenCalledWith('subscription.lifecycle.suspend',
      { subscription_id: '88', expected_version: '1', reason: 'review' }, expect.any(String))
    expect(host.textContent).toContain('已暂停')
  })
  await act(async () => button('恢复订阅').click()); await settle()
  expect(confirmRequest.current?.title).toBe('恢复订阅')
  await act(async () => { confirmRequest.current!.action() }); await settle()
  await vi.waitFor(async () => {
    await settle()
    expect(admin.write).toHaveBeenCalledWith('subscription.lifecycle.resume',
      { subscription_id: '88', expected_version: '2' }, expect.any(String))
    expect(host.textContent).toContain('生效中')
  })
})
