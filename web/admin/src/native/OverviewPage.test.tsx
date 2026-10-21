import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, afterEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, useAdminSession } from './session'
import { OverviewPage } from './OverviewPage'

vi.mock('./session', () => ({ admin: { read: vi.fn() }, useAdminSession: vi.fn(() => true) }))
let host: HTMLDivElement, root: Root, client: QueryClient
const snapshot: CoreOutput<'analytics.native-dashboard.get'> = {
  time_zone: 'UTC', basis: 'native-paid-purchase-and-settled-usage/v1', observed_at: 1792540800,
  start_date: '2026-10-01', end_date: '2026-10-20', starts_at: 1790812800, ends_before: 1792540801,
  cards: { today_income: [{ currency: 'CNY', amount_minor: '9007199254741000', count: '1' }], month_income: [], last_month_income: [], month_commission_transfer: [], last_month_commission_transfer: [], month_new_accounts: '12345', pending_tickets: '7', pending_commission_payouts: '3' },
  online: { nodes: null, users: null, devices: null },
  trends: [{ date: '2026-10-20', income: [{ currency: 'CNY', amount_minor: '9007199254741000', count: '1' }], earned_commission: [], commission_transfer: [] }],
  rankings: { nodes: [{ id: '9007199254740993', name: 'Private ranked node', total_bytes: '9007199254741000' }], users: [{ id: '9007199254740994', name: null, total_bytes: '100' }] },
}
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  vi.mocked(useAdminSession).mockReturnValue(true)
  vi.mocked(admin.read).mockImplementation(async operation => operation === 'analytics.native-dashboard.get'
    ? { data: structuredClone(snapshot), meta: {} } as never
    : { data: { status: 'running', pending_jobs: 2, jobs_per_minute: 3, failed_last_7_days: null }, meta: {} } as never)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render() { await act(async () => root.render(<QueryClientProvider client={client}><MemoryRouter><OverviewPage /></MemoryRouter></QueryClientProvider>)); await settle() }

it('renders eight authoritative cards, exact money/bytes and real ranking links without sampling lists', async () => {
  await render()
  expect(host.querySelectorAll('.overview-metric')).toHaveLength(8)
  expect(host.textContent).toContain('90071992547410.00 CNY')
  expect(host.textContent).toContain('9,007,199,254,741,000 字节')
  expect(host.querySelector('a[href="/server/node/9007199254740993"]')).not.toBeNull()
  expect(host.querySelector('a[href="/user/9007199254740994"]')).not.toBeNull()
  expect(host.textContent).toContain('在线设备：未知')
  expect(vi.mocked(admin.read).mock.calls.map(call => call[0]).sort()).toEqual(['analytics.native-dashboard.get', 'platform.queue.snapshot.get'])
})
it('loads each preset date range from the server and keeps currencies separate', async () => {
  await render()
  const select = host.querySelector<HTMLSelectElement>('select[aria-label="概览时间范围"]')!
  for (const days of [7, 30, 90, 180, 365]) {
    await act(async () => { select.value = String(days); select.dispatchEvent(new Event('change', { bubbles: true })) }); await settle()
    const call = vi.mocked(admin.read).mock.calls.filter(call => call[0] === 'analytics.native-dashboard.get').at(-1)!
    const dates = call[1] as { start_date: string; end_date: string }
    expect((Date.parse(dates.end_date) - Date.parse(dates.start_date)) / 86400000 + 1).toBe(days)
  }
})
it('hides cached statistics and queue snapshots after a forbidden refresh', async () => {
  await render()
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('Forbidden', 403, 'FORBIDDEN', false))
  await act(async () => host.querySelector<HTMLButtonElement>('button[aria-label="刷新概览"]')!.click()); await settle()
  expect(host.textContent).toContain('已隐藏统计、趋势与排行榜')
  expect(host.textContent).not.toContain('90071992547410.00 CNY')
  expect(host.textContent).not.toContain('Private ranked node')
  expect(host.textContent).not.toContain('运行中')
})
it('does not fetch private dashboard data without a session', async () => {
  vi.mocked(useAdminSession).mockReturnValue(false)
  vi.mocked(admin.read).mockClear()
  await render()
  expect(admin.read).not.toHaveBeenCalled()
  expect(host.textContent).not.toContain('Private ranked node')
})
it('validates custom UTC dates before requesting a new snapshot', async () => {
  await render()
  const select = host.querySelector<HTMLSelectElement>('select[aria-label="概览时间范围"]')!
  await act(async () => { select.value = 'custom'; select.dispatchEvent(new Event('change', { bubbles: true })) }); await settle()
  await act(async () => host.querySelector<HTMLButtonElement>('button[aria-label="选择日期范围"]')!.click()); await settle()
  const initialCalls = vi.mocked(admin.read).mock.calls.filter(call => call[0] === 'analytics.native-dashboard.get').length
  const start = host.querySelector<HTMLInputElement>('input[aria-label="开始日期"]')!
  const end = host.querySelector<HTMLInputElement>('input[aria-label="结束日期"]')!
  const update = async (input: HTMLInputElement, value: string) => { await act(async () => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, value); input.dispatchEvent(new Event('input', { bubbles: true })); input.dispatchEvent(new Event('change', { bubbles: true })) }); await settle() }
  await update(start, '2024-01-01'); await update(end, '2026-01-01')
  const apply = [...host.querySelectorAll<HTMLButtonElement>('button')].find(button => button.textContent === '应用日期范围')!
  await act(async () => apply.click()); await settle()
  expect(host.textContent).toContain('请选择不超过 366 天')
  expect(vi.mocked(admin.read).mock.calls.filter(call => call[0] === 'analytics.native-dashboard.get')).toHaveLength(initialCalls)
  await update(start, '2026-01-01'); await update(end, '2026-01-07')
  await act(async () => apply.click()); await settle()
  expect(admin.read).toHaveBeenCalledWith('analytics.native-dashboard.get', { start_date: '2026-01-01', end_date: '2026-01-07' }, expect.any(AbortSignal))
})
