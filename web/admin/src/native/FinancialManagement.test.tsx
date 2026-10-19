import { act, type ReactNode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { CouponsPage } from './CouponsPage'
import { GiftCardsPage } from './GiftCardsPage'
import { CommissionsPage } from './CommissionsPage'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
const uuid = '00000000-0000-4000-8000-000000000001'
const coupon = { id: '41', code: 'OFFER2026', name: 'Coupon secret', discount_kind: 'fixed', value: '100',
  minimum_minor: '0', total_limit: 50, user_limit: 1, used_total: 0, starts_at: null, expires_at: null,
  offer_ids: [], enabled: true, row_version: '3', currency: 'CNY' }
const card = { card_id: uuid, issuer_id: '81', recipient_id: null, amount_minor: '500', currency: 'CNY',
  state: 'reserved', row_version: '1', expires_at: 2000000000, created_at: 1800000000, closed_at: null }
const payout = { id: uuid, user_id: '82', amount_minor: '300', currency: 'CNY', state: 'requested',
  row_version: '1', destination: 'native-wallet', actor_id: null, reason: null, created_at: 1800000000, closed_at: null }
const policy = { enabled: true, rate_bps: 1000, hold_seconds: 0, row_version: '7', refund_policy: 'net-before-vesting-retained-after/v1' }
const meta = { page: 1, per_page: 20, total: 12345, last_page: 618 }
const fixtures = {
  'billing.coupons.list': [coupon],
  'billing.admin-gift-cards.list': [card],
  'billing.admin-gift-batches.list': [],
  'billing.admin-commissions.rewards.list': [],
  'billing.admin-commissions.payouts.list': [payout],
}
let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  workflowActions.reset()
  vi.mocked(admin.read).mockReset().mockImplementation(async operation => ({
    data: operation === 'billing.admin-commissions.policy.get' ? policy : fixtures[operation as keyof typeof fixtures] ?? [], meta,
  }) as never)
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: {} } as never)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(page: ReactNode) {
  await act(async () => root.render(<QueryClientProvider client={client}>{page}</QueryClientProvider>)); await settle()
}
function button(text: string) { return [...host.querySelectorAll<HTMLButtonElement>('button')].find(node => node.getAttribute('aria-label') === text || node.textContent?.trim() === text)! }
async function click(text: string) { await act(async () => button(text).click()); await settle() }

it.each([
  { name: 'coupons', page: <CouponsPage />, action: '停用', operation: 'billing.coupons.disable', input: { coupon_id: '41', expected_version: '3' } },
  { name: 'gift cards', page: <GiftCardsPage />, action: '撤销并退回发行人', operation: 'billing.admin-gift-cards.revoke', input: { card_id: uuid, expected_version: '1' } },
  { name: 'commission approval', page: <CommissionsPage />, action: '批准转入钱包', operation: 'billing.admin-commissions.payouts.approve', input: { payout_id: uuid, expected_version: '1', reason: 'reviewed' } },
  { name: 'commission rejection', page: <CommissionsPage />, action: '驳回并释放', operation: 'billing.admin-commissions.payouts.reject', input: { payout_id: uuid, expected_version: '1', reason: 'reviewed' } },
])('retains the original $name mutation input and key after a lost response', async ({ page, action, operation, input }) => {
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost response'))
  await render(page); await click(action)
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(original).toEqual([operation, input, expect.any(String)])
  expect(workflowActions.snapshot().pending?.unknown).toBe(true)
  expect(button(action).disabled).toBe(true)
  await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it.each([
  { name: 'coupons', page: <CouponsPage />, secret: 'Coupon secret', prefix: 'coupons' },
  { name: 'gift cards', page: <GiftCardsPage />, secret: uuid, prefix: 'gift-cards' },
  { name: 'commissions', page: <CommissionsPage />, secret: uuid, prefix: 'commissions' },
])('withdraws cached $name rows and pagination after forbidden refresh', async ({ page, secret, prefix }) => {
  await render(page)
  expect(host.textContent).toContain(secret)
  expect(host.textContent).toContain('12345')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await client.invalidateQueries({ queryKey: ['native-admin', prefix] }) }); await settle()
  expect(host.textContent).not.toContain(secret)
  expect(host.textContent).not.toContain('12345')
  expect(host.textContent).toContain('已隐藏数据')
})

it('never offers another financial page a recovery control for a coupon command', async () => {
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost response'))
  await render(<CouponsPage />); await click('停用')
  await render(<GiftCardsPage />)
  expect(host.textContent).not.toContain('使用原操作确认结果')
  expect(button('撤销并退回发行人').disabled).toBe(true)
  expect(admin.write).toHaveBeenCalledTimes(1)
})

it('saves a new commission policy version without rewriting frozen order policy', async () => {
  await render(<CommissionsPage />); await click('编辑下一版本')
  await act(async () => host.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('billing.admin-commissions.policy.update', {
    enabled: true, rate_bps: 1000, hold_seconds: 0, expected_version: '7', refund_policy: 'net-before-vesting-retained-after/v1',
  }, expect.any(String))
})

it('rejects an invalid commission reason before contacting the backend', async () => {
  await render(<CommissionsPage />)
  const input = host.querySelector<HTMLInputElement>('input[maxlength="64"]')!
  await act(async () => {
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, 'invalid reason')
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })
  await click('批准转入钱包')
  expect(admin.write).not.toHaveBeenCalled()
  expect(host.textContent).toContain('有效的审核原因')
})

it('requires a successful explicit permission refresh before recovering an unknown forbidden payout', async () => {
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403, undefined, true))
  await render(<CommissionsPage />); await click('批准转入钱包')
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(button('使用原操作确认结果').disabled).toBe(true)
  expect(host.textContent).not.toContain(uuid)
  vi.mocked(admin.read).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新')
  expect(button('使用原操作确认结果').disabled).toBe(true)
  await click('刷新')
  expect(button('使用原操作确认结果').disabled).toBe(false)
  expect(admin.write).toHaveBeenCalledTimes(1)
  await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
})

it('creates a coupon through the current fixed immutable policy contract', async () => {
  await render(<CouponsPage />); await click('创建优惠券')
  const inputs = [...host.querySelectorAll<HTMLInputElement>('form input')]
  await act(async () => {
    for (const [index, value] of [[0, 'newcode8'], [1, 'New coupon']] as const) {
      Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(inputs[index], value)
      inputs[index].dispatchEvent(new Event('input', { bubbles: true }))
    }
  })
  await act(async () => host.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('billing.coupons.create', {
    code: 'NEWCODE8', name: 'New coupon', currency: 'CNY', discount_kind: 'fixed', value: '100', minimum_minor: '0',
    total_limit: 100, user_limit: 1, starts_at: null, expires_at: null, offer_ids: [],
  }, expect.any(String))
})

it('loads the selected gift batch and revokes the observed card version without exposing codes', async () => {
  const batch = { batch_id: uuid, issuer_id: '81', label: 'Batch test', quantity: 1, amount_minor: '500',
    total_minor: '500', currency: 'CNY', expires_at: 2000000000, created_at: 1800000000 }
  vi.mocked(admin.read).mockImplementation(async operation => ({ data:
    operation === 'billing.admin-gift-batches.get' ? { batch, cards: [card] } :
    operation === 'billing.admin-gift-batches.list' ? [batch] :
    fixtures[operation as keyof typeof fixtures] ?? [], meta,
  }) as never)
  await render(<GiftCardsPage />); await click('查看逐卡状态')
  expect(admin.read).toHaveBeenCalledWith('billing.admin-gift-batches.get', { batch_id: uuid }, expect.any(AbortSignal))
  const batchDetail = [...host.querySelectorAll('section')].find(node => node.querySelector('h2')?.textContent === '批次详情')!
  expect(batchDetail.querySelector('h3')?.textContent).toBe('Batch test')
  expect(batchDetail.querySelectorAll('.commerce-metrics > div')).toHaveLength(4)
  expect(batchDetail.querySelector('.commerce-metrics > div')?.textContent).toBe('待兑换1张')
  const table = host.querySelector('[aria-label="批次内卡片"]')!
  await act(async () => table.querySelector<HTMLButtonElement>('button')!.click()); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('billing.admin-gift-cards.revoke', { card_id: uuid, expected_version: '1' }, expect.any(String))
})

it('ignores an acknowledgement after administrator context replacement without refreshing the new context', async () => {
  let resolve!: (value: never) => void
  vi.mocked(admin.write).mockImplementationOnce(() => new Promise(done => { resolve = done }) as never)
  await render(<CommissionsPage />); await click('批准转入钱包')
  const reads = vi.mocked(admin.read).mock.calls.length
  await act(async () => { workflowActions.reset(); resolve({ data: {} } as never) }); await settle()
  expect(admin.read).toHaveBeenCalledTimes(reads)
  expect(workflowActions.snapshot().pending).toBeUndefined()
  expect(host.querySelector('[role="alert"]')).toBeNull()
})
