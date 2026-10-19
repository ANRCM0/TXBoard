import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { OrderAdministration, OrderCommandRecovery } from './OrderAdministration'
import { requestConfirm } from '../components/ui/ConfirmDialog'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
vi.mock('../components/ui/ConfirmDialog', () => ({ requestConfirm: vi.fn() }))
let host: HTMLDivElement, root: Root, client: QueryClient
const onDenied = vi.fn()
const emptyAudit = { data: [], meta: { next_after_id: '0', has_more: false } }
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.mocked(admin.read).mockReset().mockResolvedValue(emptyAudit as never)
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: {} } as never)
  vi.mocked(requestConfirm).mockClear(); onDenied.mockReset(); workflowActions.reset()
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(status = 0, recovery = false) {
  await act(async () => root.render(<QueryClientProvider client={client}>
    {recovery ? <OrderCommandRecovery /> : <OrderAdministration tradeNo="ORDER-ORIGINAL" status={status} onDenied={onDenied} />}
  </QueryClientProvider>)); await settle()
}
function button(text: string) {
  const scope = document.querySelector<HTMLElement>('[role="dialog"][aria-modal="true"]') ?? host
  return [...scope.querySelectorAll<HTMLButtonElement>('button')].find(node =>
    node.getAttribute('aria-label') === text || node.textContent?.trim() === text)!
}
async function close() {
  await act(async () => button('关闭待支付订单').click())
  expect(admin.write).not.toHaveBeenCalled()
  expect(requestConfirm).toHaveBeenLastCalledWith(expect.objectContaining({
    title: '关闭待支付订单', confirmLabel: '确认关闭订单', danger: true, action: expect.any(Function),
  }))
  await act(async () => vi.mocked(requestConfirm).mock.calls.at(-1)![0].action()); await settle()
}

it('requires explicit close confirmation and sends the observed pending state with one original key', async () => {
  await render(); await close()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('commerce.admin-orders.close',
    { trade_no: 'ORDER-ORIGINAL', expected_status: 0, reason: 'admin_reviewed' }, expect.any(String))
  expect(host.textContent).toContain('订单关闭请求已确认')
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('retains unknown close input and key across navigation until explicit recovery', async () => {
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('response lost'))
  await render(); await close()
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(workflowActions.snapshot().pending?.unknown).toBe(true)
  await render(0, true); await settle()
  expect(admin.write).toHaveBeenCalledTimes(1)
  await act(async () => button('确认原订单操作结果').click()); await settle()
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('explains unconfirmed payments and does not repeatedly submit a rejected close', async () => {
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('ADMIN_ORDER_PAYMENT_UNCONFIRMED', 409))
  await render(); await close(); await settle()
  expect(host.textContent).toContain('存在未确认的支付尝试')
  expect(admin.write).toHaveBeenCalledTimes(1)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('requests recovery of the original processing order and waits for fulfillment state', async () => {
  await render(1)
  expect(host.textContent).not.toContain('关闭待支付订单')
  await act(async () => button('恢复原订单履约').click()); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('commerce.admin-orders.retry',
    { trade_no: 'ORDER-ORIGINAL', expected_status: 1, reason: 'admin_reviewed' }, expect.any(String))
  expect(host.textContent).toContain('恢复请求已受理')
  expect(host.textContent).not.toContain('履约已完成')
})

it('withdraws parent details on forbidden audit and does not offer a mutation', async () => {
  const denied = new CoreAdminApiError('FORBIDDEN', 403)
  vi.mocked(admin.read).mockRejectedValue(denied)
  await render()
  expect(onDenied).toHaveBeenCalledWith(denied)
  expect(button('关闭待支付订单').disabled).toBe(true)
  expect(admin.write).not.toHaveBeenCalled()
})

it('ignores a late write acknowledgement after the administrator context resets', async () => {
  let resolve!: (value: never) => void
  vi.mocked(admin.write).mockImplementationOnce(() => new Promise(done => { resolve = done }))
  await render(); await close()
  await act(async () => { workflowActions.reset(); resolve({ data: {} } as never) }); await settle()
  expect(host.textContent).not.toContain('订单关闭请求已确认')
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('reads the next audit cursor without numeric coercion and retains the current order binding', async () => {
  vi.mocked(admin.read).mockResolvedValueOnce({ data: [{ id: '9007199254740993', order_id: '7', actor_id: '12',
    action: 'retry', from_status: 1, to_status: 1, reason: 'reviewed', occurred_at: '2026-10-19T00:00:00+00:00' }],
    meta: { next_after_id: '9007199254740993', has_more: true } } as never)
  await render(3)
  expect(host.textContent).not.toContain('恢复原订单履约')
  await act(async () => button('下一页审计').click()); await settle()
  expect(admin.read).toHaveBeenLastCalledWith('commerce.admin-orders.audit.list',
    { trade_no: 'ORDER-ORIGINAL', after_id: '9007199254740993', limit: 20 }, expect.any(AbortSignal))
})
