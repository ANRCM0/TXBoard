import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { AccountFundsPanel, fundsAdjustmentInput } from './AccountFundsPanel'
import { admin, workflowActions } from './session'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
const balances = { user_id: '9007199254740993', currency: 'CNY', wallet_available_minor: '1000', wallet_reserved_minor: '9000',
  commission_available_minor: '3000', commission_reserved_minor: '5000', ledger_revision: 'a'.repeat(64), editable: true }
let root: Root, host: HTMLDivElement, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } }); workflowActions.reset()
  vi.spyOn(window, 'confirm').mockReturnValue(true)
  vi.mocked(admin.read).mockReset().mockImplementation(async operation => ({ data: operation === 'billing.admin-account-balances.get' ? balances : { items: [], next_cursor: null } }) as never)
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: {} } as never)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 25)) }) }
async function render() { await act(async () => root.render(<QueryClientProvider client={client}><AccountFundsPanel userId={balances.user_id} locked={false} onChanged={() => {}} /></QueryClientProvider>)); await settle() }
async function click(text: string, within: ParentNode = host) {
  const button = [...within.querySelectorAll<HTMLButtonElement>('button')].find(item => item.textContent?.trim() === text)
  expect(button, text).toBeTruthy(); await act(async () => button!.click()); await settle()
}
async function fill(label: string, value: string) {
  const dialog = document.querySelector('[role="dialog"]')!, fieldLabel = [...dialog.querySelectorAll('label')].find(item => item.textContent?.startsWith(label))!
  const input = [...dialog.querySelectorAll<HTMLInputElement>('input')].find(item => item.id === fieldLabel.htmlFor)!
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, value); input.dispatchEvent(new Event('input', { bubbles: true })) })
}
it('posts exact positive credits with the observed financial revision after showing the actual amount', async () => {
  await render(); await click('调整可用资金'); await fill('调整金额', '9007199254740993'); await fill('资金调整原因', 'case_482')
  await click('确认资金调整', document.querySelector('[role="dialog"]')!)
  expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('90071992547409.93 CNY'))
  expect(admin.write).toHaveBeenCalledWith('billing.admin-account-adjustments.create', { user_id: balances.user_id, currency: 'CNY',
    bucket: 'wallet_available', direction: 'credit', amount_minor: '9007199254740993', expected_ledger_revision: balances.ledger_revision, reason: 'case_482', note: null }, expect.any(String))
  expect(document.querySelector('[role="dialog"]')).toBeNull()
})
it('validates available funds independently from reserved funds and refuses fractional minor amounts', () => {
  const draft = { bucket: 'wallet_available' as const, direction: 'debit' as const, amount: '1001', reason: 'case_482', note: '' }
  expect(() => fundsAdjustmentInput(balances, draft)).toThrow('冻结余额不能用于本次扣减')
  expect(() => fundsAdjustmentInput(balances, { ...draft, direction: 'credit', amount: '1.23' })).toThrow('精确整数金额')
  expect(() => fundsAdjustmentInput({ ...balances, currency: 'USD' }, { ...draft, bucket: 'commission_available' })).toThrow('只支持 CNY')
  expect(fundsAdjustmentInput(balances, { ...draft, amount: '1000' }).amount_minor).toBe('1000')
})
it('keeps the original financial request and key on an unknown response', async () => {
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('connection lost'))
  await render(); await click('调整可用资金'); await fill('调整金额', '1250'); await fill('资金调整原因', 'case_482')
  await click('确认资金调整', document.querySelector('[role="dialog"]')!)
  const first = vi.mocked(admin.write).mock.calls[0]
  expect(document.querySelector('[role="dialog"]')).toBeNull(); expect(host.textContent).toContain('原操作结果等待确认')
  await click('确认原操作'); expect(vi.mocked(admin.write).mock.calls[1]).toEqual(first)
})
it('withdraws financial projections and the draft after a forbidden reread', async () => {
  await render(); await click('调整可用资金'); await fill('调整金额', '1250')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await client.invalidateQueries({ queryKey: ['native-admin', 'account-funds'] }) }); await settle()
  expect(document.querySelector('[role="dialog"]')).toBeNull(); expect(host.textContent).not.toContain('90.00 CNY')
  expect(host.textContent).toContain('当前账号没有此操作的权限')
})
