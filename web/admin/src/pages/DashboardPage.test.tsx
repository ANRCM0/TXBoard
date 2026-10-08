import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { Simulate } from 'react-dom/test-utils'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { getDashboardStats, getOrderChart, getTrafficRank } from '../api/statistics'
import { DashboardPage } from './DashboardPage'

vi.mock('../api/statistics', () => ({
  getDashboardStats: vi.fn(),
  getOrderChart: vi.fn(),
  getTrafficRank: vi.fn(),
}))
vi.mock('../api/agent', () => ({
  getAgentActions: vi.fn().mockResolvedValue([]),
  getAgentFleetHealth: vi.fn().mockResolvedValue({
    summary: { critical_nodes: 0, degraded_nodes: 0 },
  }),
}))

let host: HTMLDivElement
let root: Root
let client: QueryClient

async function flush() {
  await act(async () => {
    await new Promise(resolve => setTimeout(resolve, 0))
  })
}

function picker(label: string) {
  const element = Array.from(host.querySelectorAll<HTMLButtonElement>('.dashboard-period-trigger'))
    .find(button => button.getAttribute('aria-label') === label + '统计周期')
  if (!element) throw new Error('missing period picker: ' + label)
  return element
}

async function selectPreset(label: string, selected: string) {
  act(() => picker(label).click())
  const button = Array.from(host.querySelectorAll<HTMLButtonElement>('.dashboard-period-option'))
    .find(option => option.textContent?.trim() === selected)
  if (!button) throw new Error('missing option: ' + selected)
  act(() => button.click())
  await flush()
}

beforeEach(async () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  localStorage.clear()
  vi.clearAllMocks()
  vi.mocked(getDashboardStats).mockResolvedValue({})
  vi.mocked(getOrderChart).mockResolvedValue({ list: [], summary: { paid_total: 100 } })
  vi.mocked(getTrafficRank).mockResolvedValue([])
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  await act(async () => {
    root.render(
      <QueryClientProvider client={client}>
        <MemoryRouter><DashboardPage /></MemoryRouter>
      </QueryClientProvider>,
    )
  })
  await flush()
})

afterEach(() => {
  act(() => root.unmount())
  client.clear()
  host.remove()
  localStorage.clear()
})

it('removes the global statistics selector and exposes four independent pickers', () => {
  expect(host.querySelector('.dashboard-range-row')).toBeNull()
  expect(host.querySelectorAll('.dashboard-period-trigger')).toHaveLength(4)
  for (const title of ['订单收入趋势', '周期汇总', '用户流量排行', '节点流量排行']) {
    expect(picker(title).textContent).toContain('最近30天')
  }
})

it('changes only the selected traffic ranking when its period changes', async () => {
  vi.mocked(getTrafficRank).mockClear()
  vi.mocked(getOrderChart).mockClear()
  await selectPreset('用户流量排行', '最近7天')
  expect(picker('用户流量排行').textContent).toContain('最近7天')
  expect(picker('节点流量排行').textContent).toContain('最近30天')
  expect(picker('订单收入趋势').textContent).toContain('最近30天')
  expect(getTrafficRank).toHaveBeenCalledTimes(1)
  expect(vi.mocked(getTrafficRank).mock.calls[0][0]).toBe('user')
  expect(getOrderChart).not.toHaveBeenCalled()
  expect(JSON.parse(localStorage.getItem('txboard:dashboard:period:user-rank') || '{}'))
    .toEqual({ preset: '7d' })
})

it('applies custom dates only to the income trend', async () => {
  vi.mocked(getOrderChart).mockClear()
  act(() => picker('订单收入趋势').click())
  const custom = Array.from(host.querySelectorAll<HTMLButtonElement>('.dashboard-period-option'))
    .find(button => button.textContent === '自定义范围')!
  act(() => custom.click())
  const start = host.querySelector<HTMLInputElement>('input[aria-label="订单收入趋势开始日期"]')!
  const end = host.querySelector<HTMLInputElement>('input[aria-label="订单收入趋势结束日期"]')!
  act(() => {
    start.value = '2026-09-01'
    Simulate.change(start)
    end.value = '2026-09-03'
    Simulate.change(end)
  })
  await act(async () => {
    host.querySelector<HTMLButtonElement>('.dashboard-period-custom-actions button[type="submit"]')!.click()
  })
  await flush()
  expect(getOrderChart).toHaveBeenCalledWith({ start_date: '2026-09-01', end_date: '2026-09-03' })
  expect(picker('订单收入趋势').textContent).toContain('自定义范围')
  expect(picker('周期汇总').textContent).toContain('最近30天')
})
