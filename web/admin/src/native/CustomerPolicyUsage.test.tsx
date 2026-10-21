import { act, type ReactNode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { CustomerPolicyPanel, customerPolicyInput } from './CustomerPolicyPanel'
import { UsageCorrectionPanel, usageCorrectionInput } from './UsageCorrectionPanel'
import { admin, workflowActions } from './session'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
const policy = { user_id: '9007199254740993', discount_bps: 1000, commission_mode: 'inherit' as const, commission_rate_bps: null,
  policy_version: '2', policy_revision: 'a'.repeat(64), editable: true }
const entry = { entry_id: '9007199254740995', subscription_id: '2', period_id: '3', batch_id: '4', node_id: '5', ended_at: '1792533600',
  original_up_bytes: '9007199254740993', original_down_bytes: '11', billed_up_bytes: '9007199254740993', billed_down_bytes: '11',
  entry_revision: 'b'.repeat(64), period_version: '7', editable: true }
let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } }); workflowActions.reset()
  vi.spyOn(window, 'confirm').mockReturnValue(true)
  vi.mocked(admin.read).mockReset().mockImplementation(async operation => ({ data: operation === 'billing.admin-customer-policy.get' ? policy
    : operation === 'usage.admin-settled-entries.list' ? { basis: 'settled-billed-with-admin-corrections/v1', items: [entry], next_cursor: null } : { items: [], next_cursor: null } }) as never)
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: {} } as never)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(node: ReactNode) { await act(async () => root.render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)); await settle() }
async function click(text: string) { const button = [...document.querySelectorAll<HTMLButtonElement>('button')].find(item => item.textContent?.trim() === text)!; expect(button, text).toBeTruthy(); expect(button.disabled).toBe(false); await act(async () => button.click()); await settle() }
async function fill(label: string, value: string) {
  const dialog = document.querySelector('[role="dialog"]')!, labels = [...dialog.querySelectorAll('label')], field = labels.find(item => item.textContent?.startsWith(label))!
  const input = [...dialog.querySelectorAll<HTMLInputElement>('input')].find(item => item.id === field.htmlFor)!
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, value); input.dispatchEvent(new Event('input', { bubbles: true })) })
}

it('converts percentages exactly and keeps changed fields separate from immutable policy and usage evidence', () => {
  expect(customerPolicyInput(policy, { discount: '12.34', mode: 'inherit', rate: '0', reason: 'case_482' })).toEqual({ user_id: policy.user_id,
    expected_policy_revision: policy.policy_revision, changes: { discount_bps: 1234, commission_rate_bps: 0 }, reason: 'case_482' })
  expect(() => customerPolicyInput(policy, { discount: '100.01', mode: 'inherit', rate: '', reason: 'case_482' })).toThrow('百分比')
  expect(usageCorrectionInput(policy.user_id, entry, { up: '9007199254740994', down: '0', reason: 'case_482' }).billed_up_bytes).toBe('9007199254740994')
  expect(() => usageCorrectionInput(policy.user_id, entry, { up: '1.5', down: '0', reason: 'case_482' })).toThrow('精确字节整数')
})
it('saves only changed future-purchase policy fields against the displayed revision', async () => {
  await render(<CustomerPolicyPanel userId={policy.user_id} locked={false} onChanged={() => {}} />)
  await click('修改个人政策'); await fill('个人折扣', '12.50'); await fill('政策变更原因', 'case_482'); await click('保存个人政策')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('billing.admin-customer-policy.update', { user_id: policy.user_id,
    expected_policy_revision: policy.policy_revision, changes: { discount_bps: 1250 }, reason: 'case_482' }, expect.any(String))
  expect(document.querySelector('[role="dialog"]')).toBeNull()
})
it('preserves the original correction input and key after losing the response', async () => {
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('network lost'))
  await render(<UsageCorrectionPanel userId={policy.user_id} locked={false} onChanged={() => {}} />)
  expect(host.textContent).toContain(entry.original_up_bytes)
  await click('修正这条计费用量'); await fill('修正后计费上行', '9007199254740994'); await fill('流量修正原因', 'case_482'); await click('保存流量修正')
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(original[1]).toEqual({ user_id: policy.user_id, entry_id: entry.entry_id, expected_entry_revision: entry.entry_revision,
    expected_period_version: '7', billed_up_bytes: '9007199254740994', billed_down_bytes: '11', reason: 'case_482' })
  expect(workflowActions.snapshot().pending?.operation).toBe('usage.admin-corrections.create')
  await click('确认原操作'); expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
})
it('withdraws a populated draft and read projections after current authorization is lost', async () => {
  await render(<CustomerPolicyPanel userId={policy.user_id} locked={false} onChanged={() => {}} />)
  await click('修改个人政策'); await fill('政策变更原因', 'private_case')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await client.invalidateQueries({ queryKey: ['native-admin', 'customer-policy'] }) }); await settle()
  expect(document.querySelector('[role="dialog"]')).toBeNull(); expect(host.textContent).not.toContain(policy.policy_version + '%')
  expect(host.textContent).not.toContain('private_case'); expect(host.textContent).not.toContain('修改个人政策')
})

it.each([
  ['policy', '查看政策历史', '修改个人政策', 'billing.admin-customer-policy.history'],
  ['usage', '查看流量修正记录', '修正这条计费用量', 'usage.admin-corrections.list'],
])('recovers %s history authorization after the denied history has been hidden', async (kind, historyButton, editButton, historyOperation) => {
  const reads = vi.mocked(admin.read).getMockImplementation()!
  let allowed = false
  vi.mocked(admin.read).mockImplementation(async (...args) => {
    if (args[0] === historyOperation && !allowed) throw new CoreAdminApiError('FORBIDDEN', 403)
    return reads(...args)
  })
  await render(kind === 'policy' ? <CustomerPolicyPanel userId={policy.user_id} locked={false} onChanged={() => {}} />
    : <UsageCorrectionPanel userId={policy.user_id} locked={false} onChanged={() => {}} />)
  await click(historyButton)
  expect(host.textContent).toContain('已隐藏资源数据')
  expect(host.textContent).not.toContain(editButton)
  allowed = true
  await click('重新加载')
  expect(host.textContent).not.toContain('已隐藏资源数据')
  expect(host.textContent).toContain(editButton)
  expect(vi.mocked(admin.read).mock.calls.filter(call => call[0] === historyOperation)).toHaveLength(2)
})
