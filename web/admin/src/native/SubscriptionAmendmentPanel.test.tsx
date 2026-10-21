import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { SubscriptionAmendmentPanel } from './SubscriptionAmendmentPanel'
import { admin, workflowActions } from './session'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
const amendment = { subscription_id: '9007199254740993', subscription_version: '7', terms_id: '9007199254740995', usage_period_id: '5', period_version: '4',
  status: 'active', plan_id: '2', plan_revision_id: '10', quota_bytes: '107374182400', consumed_bytes: '1000', expires_at: null, speed_limit_mbps: '100', device_limit: '3', editable: true }
const changed = vi.fn()
let root: Root, host: HTMLDivElement, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  changed.mockReset(); workflowActions.reset(); vi.mocked(admin.read).mockReset(); vi.mocked(admin.write).mockReset()
  vi.mocked(admin.read).mockImplementation(async operation => ({ data: operation === 'subscription.admin.amendment.get'
    ? { user_id: '9007199254740997', amendment } : { items: [], next_cursor: null } }) as never)
  vi.mocked(admin.write).mockResolvedValue({ data: {} } as never)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 25)) }) }
async function render() { await act(async () => root.render(<QueryClientProvider client={client}><SubscriptionAmendmentPanel userId="9007199254740997" locked={false} onChanged={changed} /></QueryClientProvider>)); await settle() }
async function click(text: string, within: ParentNode = host) {
  const button = [...within.querySelectorAll<HTMLButtonElement>('button')].find(item => item.textContent?.trim() === text)
  expect(button, text).toBeTruthy(); await act(async () => button!.click()); await settle()
}
async function fill(label: string, value: string) {
  const dialog = document.querySelector('[role="dialog"]')!
  const fieldLabel = [...dialog.querySelectorAll('label')].find(item => item.textContent?.startsWith(label))!
  const input = [...dialog.querySelectorAll<HTMLInputElement>('input')].find(item => item.id === fieldLabel.htmlFor)!
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, value); input.dispatchEvent(new Event('input', { bubbles: true })) })
}
it('submits only changed exact fields with all current owner revisions', async () => {
  await render(); await click('调整订阅条款'); await fill('流量配额', '9007199254740993'); await fill('调整原因编号', 'case_482')
  await click('保存订阅条款', document.querySelector('[role="dialog"]')!)
  expect(admin.write).toHaveBeenCalledWith('subscription.admin.amendment.apply', { user_id: '9007199254740997', subscription_id: amendment.subscription_id,
    expected_subscription_version: '7', expected_terms_id: amendment.terms_id, expected_period_version: '4', changes: { quota_bytes: '9007199254740993' }, reason: 'case_482' }, expect.any(String))
  expect(document.querySelector('[role="dialog"]')).toBeNull(); expect(changed).toHaveBeenCalled()
})
it('rejects quota below reserved consumption and protects a dirty draft', async () => {
  vi.spyOn(window, 'confirm').mockReturnValue(false)
  await render(); await click('调整订阅条款'); await fill('流量配额', '999'); await fill('调整原因编号', 'case_482')
  await click('保存订阅条款', document.querySelector('[role="dialog"]')!)
  expect(document.querySelector('[role="dialog"]')?.textContent).toContain('不能低于已用及已接受待结算流量')
  expect(admin.write).not.toHaveBeenCalled(); await click('取消', document.querySelector('[role="dialog"]')!)
  expect(window.confirm).toHaveBeenCalled(); expect(document.querySelector('[role="dialog"]')).toBeTruthy()
})
it('retains the original unknown request for explicit recovery and hides the draft', async () => {
  vi.mocked(admin.write).mockRejectedValueOnce(new Error('connection lost'))
  await render(); await click('调整订阅条款'); await fill('设备数', '8'); await fill('调整原因编号', 'case_482')
  await click('保存订阅条款', document.querySelector('[role="dialog"]')!)
  const first = vi.mocked(admin.write).mock.calls[0]
  expect(document.querySelector('[role="dialog"]')).toBeNull(); expect(host.textContent).toContain('原操作结果等待确认')
  vi.spyOn(window, 'confirm').mockReturnValue(true); await click('确认原操作')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(first)
})
it('hides stale terms and closes the editor when current permission is withdrawn', async () => {
  await render(); await click('调整订阅条款'); await fill('设备数', '8')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await client.invalidateQueries({ queryKey: ['native-admin', 'subscription-amendment'] }) }); await settle()
  expect(document.querySelector('[role="dialog"]')).toBeNull(); expect(host.textContent).not.toContain(amendment.quota_bytes)
  expect(host.textContent).toContain('当前账号没有此操作的权限')
})

it('binds the explicitly selected immutable plan and recovers its original request without changing expiry', async () => {
  const binding = { plan_id: '9007199254740999', revision_id: '9007199254741001', content_hash: 'f'.repeat(64) }
  const originalRead = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation(async (...args) => {
    if (args[0] === 'catalog.admin-plans.list') return { data: [{ plan_id: binding.plan_id, current_revision_id: binding.revision_id, name: 'Immutable target' }], meta: { last_page: 1 } } as never
    if (args[0] === 'catalog.admin-plans.get') return { data: { plan_id: binding.plan_id, current_revision_id: binding.revision_id,
      revision: { revision_id: binding.revision_id, content_hash: binding.content_hash, terms: { name: 'Immutable target', quota_gib: 4, speed_limit_mbps: 55, device_limit: 5 } } } } as never
    return originalRead(...args)
  })
  vi.spyOn(window, 'confirm').mockReturnValue(true); vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost plan response'))
  await render(); await click('调整订阅条款')
  const dialog = document.querySelector('[role="dialog"]')!
  await click('管理员权益套餐改配', dialog)
  await click('Immutable target · #' + binding.plan_id + ' · 修订 #' + binding.revision_id, dialog)
  await fill('调整原因编号', 'entitlement_case'); await click('保存订阅条款', document.querySelector('[role="dialog"]')!)
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(original[1]).toMatchObject({ expected_terms_id: amendment.terms_id, expected_subscription_version: '7', expected_period_version: '4', changes: { plan_revision: binding }, reason: 'entitlement_case' })
  expect((original[1] as { changes: object }).changes).toEqual({ plan_revision: binding })
  expect(document.querySelector('[role="dialog"]')).toBeNull(); await click('确认原操作')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
})

it('does not import a plan whose current publication changed after the list was observed', async () => {
  const originalRead = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation(async (...args) => {
    if (args[0] === 'catalog.admin-plans.list') return { data: [{ plan_id: '3', current_revision_id: '12', name: 'Changed target' }], meta: { last_page: 1 } } as never
    if (args[0] === 'catalog.admin-plans.get') return { data: { current_revision_id: '13', revision: { revision_id: '12' } } } as never
    return originalRead(...args)
  })
  await render(); await click('调整订阅条款')
  const dialog = document.querySelector('[role="dialog"]')!
  await click('管理员权益套餐改配', dialog); await click('Changed target · #3 · 修订 #12', dialog)
  expect(document.querySelector('[role="dialog"]')?.textContent).toContain('套餐发布修订已变化')
  expect(admin.write).not.toHaveBeenCalled()
})
