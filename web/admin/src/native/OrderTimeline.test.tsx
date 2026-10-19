import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { OrderTimeline } from './OrderTimeline'
import { admin } from './session'
import { CoreAdminApiError } from './api'

vi.mock('./session', () => ({ admin: { read: vi.fn() } }))
const created = { sequence: '1', type: 'created', status: 0, occurred_at: '2026-10-19T00:00:00Z' }
const paid = { sequence: '2', type: 'payment_accepted', status: 1, occurred_at: '2026-10-19T00:01:00Z' }
const fulfilled = { sequence: '3', type: 'fulfilled', status: 3, occurred_at: '2026-10-19T00:02:00Z' }
function page(data: unknown[], cursor: string, more = false, complete = true) {
  return { data, meta: { next_after_sequence: cursor, has_more: more, history_complete: complete } } as never
}
let host: HTMLDivElement, root: Root
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  vi.useFakeTimers()
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  vi.mocked(admin.read).mockReset()
})
afterEach(() => { act(() => root.unmount()); host.remove(); vi.useRealTimers() })
async function render(tradeNo = 'ORDER-A') {
  await act(async () => root.render(<OrderTimeline key={tradeNo} tradeNo={tradeNo} />))
}
async function poll() { await act(async () => { await vi.advanceTimersByTimeAsync(5000) }) }

it('drains pages in sequence and stops after the terminal event', async () => {
  vi.mocked(admin.read).mockResolvedValueOnce(page([created], '1', true))
    .mockResolvedValueOnce(page([paid, fulfilled], '3'))
  await render()
  expect(vi.mocked(admin.read).mock.calls.map(call => call[1])).toEqual([
    { trade_no: 'ORDER-A', after_sequence: '0', limit: 100 },
    { trade_no: 'ORDER-A', after_sequence: '1', limit: 100 },
  ])
  expect(host.querySelectorAll('[aria-label="订单状态时间线"] > ol > li')).toHaveLength(3)
  expect([...host.querySelectorAll('[aria-label="订单状态时间线"] > ol > li strong')].map(node => node.textContent)).toEqual(['订单已创建', '支付已确认', '履约已完成'])
  expect([...host.querySelectorAll('[aria-label="订单状态时间线"] time')].map(node => node.getAttribute('dateTime'))).toEqual([created.occurred_at, paid.occurred_at, fulfilled.occurred_at])
  await poll()
  expect(admin.read).toHaveBeenCalledTimes(2)
})
it('keeps the accepted cursor through an outage and reconnects without duplicate events', async () => {
  vi.mocked(admin.read).mockResolvedValueOnce(page([created], '1'))
    .mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(page([paid], '2'))
  await render(); await poll()
  expect(host.querySelector('[role="alert"]')).not.toBeNull()
  await act(async () => window.dispatchEvent(new Event('online')))
  expect(vi.mocked(admin.read).mock.calls[2][1]).toMatchObject({ after_sequence: '1' })
  expect(host.querySelectorAll('[aria-label="订单状态时间线"] > ol > li')).toHaveLength(2)
  expect(host.querySelector('[role="alert"]')).toBeNull()
})
it('warns about incomplete legacy history without inventing events', async () => {
  vi.mocked(admin.read).mockResolvedValue(page([], '0', false, false))
  await render()
  expect(host.textContent).toContain('历史记录不完整')
  expect(host.querySelectorAll('[aria-label="订单状态时间线"] > ol > li')).toHaveLength(0)
})
it('withdraws history on permission loss and waits for an explicit retry', async () => {
  vi.mocked(admin.read).mockResolvedValueOnce(page([created], '1'))
    .mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
    .mockResolvedValueOnce(page([created, paid, fulfilled], '3'))
  await render(); await poll()
  expect(host.querySelectorAll('[aria-label="订单状态时间线"] > ol > li')).toHaveLength(0)
  expect(host.textContent).toContain('没有此操作的权限')
  await poll()
  await act(async () => window.dispatchEvent(new Event('online')))
  expect(admin.read).toHaveBeenCalledTimes(2)
  await act(async () => [...host.querySelectorAll<HTMLButtonElement>('[aria-label="订单状态时间线"] button')].find(node => node.getAttribute('aria-label') === '重新加载时间线' || node.textContent?.trim() === '重新加载时间线')!.click())
  expect(vi.mocked(admin.read).mock.calls[2][1]).toMatchObject({ after_sequence: '0' })
  expect(host.querySelectorAll('[aria-label="订单状态时间线"] > ol > li')).toHaveLength(3)
})
it('aborts the previous order and ignores its late event page', async () => {
  let resolveOld!: (value: never) => void
  vi.mocked(admin.read).mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }) as never)
    .mockResolvedValueOnce(page([], '0', false, false))
  await render()
  const signal = vi.mocked(admin.read).mock.calls[0][2]!
  await render('ORDER-B')
  expect(signal.aborted).toBe(true)
  await act(async () => resolveOld(page([created], '1')))
  expect(host.querySelectorAll('[aria-label="订单状态时间线"] > ol > li')).toHaveLength(0)
  expect(host.textContent).toContain('历史记录不完整')
})
