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
vi.mock('../components/dashboard/QueueDashboardSections', () => ({
  QueueDashboardSections: () => <div data-testid="queue-monitoring">队列状态 · 作业详情</div>,
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

it('removes both redundant summary cards while retaining the trend, rankings and queue monitoring', () => {
  expect(host.querySelector('.dashboard-range-row')).toBeNull()
  expect(host.querySelector('.dashboard-summary-grid')).toBeNull()
  expect(host.querySelector('.dashboard-summary-list')).toBeNull()
  expect(host.textContent).not.toContain('周期汇总')
  expect(host.textContent).not.toContain('流量与佣金')
  expect(host.querySelectorAll('.dashboard-period-trigger')).toHaveLength(3)
  for (const title of ['订单收入趋势', '用户流量排行', '节点流量排行']) {
    expect(picker(title).textContent).toContain('最近30天')
  }
  expect(host.querySelector('[data-testid="queue-monitoring"]')?.textContent).toContain('队列状态')
  expect(host.querySelector('[data-testid="queue-monitoring"]')?.textContent).toContain('作业详情')
  expect(getOrderChart).toHaveBeenCalledTimes(1)
  expect(localStorage.getItem('txboard:dashboard:period:order-summary')).toBeNull()
})

it('groups six metrics inside one shared compact surface without removing dashboard sections', () => {
  expect(host.querySelector('.dashboard-v3-kpis')).not.toBeNull()
  expect(host.querySelectorAll('.dashboard-v3-kpis .dashboard-v3-kpi')).toHaveLength(6)
  expect(host.querySelectorAll('.dashboard-v3-kpis .admin-stat-card')).toHaveLength(0)
  expect(host.querySelector('.dashboard-chart-card')).not.toBeNull()
  expect(host.querySelectorAll('.dashboard-rank-grid .rank-card')).toHaveLength(2)
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
  expect(host.querySelector('.dashboard-summary-grid')).toBeNull()
})

it('collapses verified zero tasks into one healthy summary', async () => {
  await act(async () => {
    client.setQueryData(['dashboardStats'], { ticketPendingTotal: 0, commissionPendingTotal: 0 })
  })
  await flush()
  expect(host.querySelector('.dashboard-ops-clear')?.textContent).toContain('当前没有待处理事项')
  expect(host.querySelector('.dashboard-action-stats')).toBeNull()
})

it('prioritizes nonzero tasks and preserves uncertainty for absent data', async () => {
  await act(async () => {
    client.setQueryData(['dashboardStats'], { ticketPendingTotal: 3, commissionPendingTotal: 0 })
  })
  await flush()
  expect(host.querySelectorAll('.dashboard-action-stats .dash-link')).toHaveLength(1)
  expect(host.querySelector('.dashboard-action-stats')?.textContent).toContain('待处理工单')
  await act(async () => { client.setQueryData(['dashboardStats'], {}) })
  await flush()
  expect(host.querySelector('.dashboard-ops-clear')).toBeNull()
  expect(host.querySelector('.dashboard-ops-status')?.textContent).toContain('尚不能确认全部正常')
})

it('shows guidance for empty income periods and the chart when paid income exists', async () => {
  expect(host.querySelector('.dashboard-chart-empty')?.textContent).toContain('所选时间范围暂无收入趋势')
  expect(host.querySelector<HTMLAnchorElement>('.dashboard-chart-empty a')?.getAttribute('href')).toBe('/finance/order')
  const chartQuery = client.getQueryCache().findAll({ queryKey: ['orderChart'] })[0]
  expect(chartQuery).toBeDefined()
  await act(async () => {
    client.setQueryData(chartQuery.queryKey, {
      list: [{ date: '2026-10-10', paid_total: 520 }],
      summary: { paid_total: 520 },
    })
  })
  await flush()
  expect(host.querySelector('svg.dashboard-v3-trend-svg')).not.toBeNull()
  expect(host.querySelector('.dashboard-v3-trend-dates')?.textContent).toContain('2026-10-10')
  expect(host.querySelector('.dashboard-chart-empty')).toBeNull()
})


it('renders compact rank rows with readable short traffic units and long-name truncation support', async () => {
  const qs=client.getQueryCache().findAll({queryKey:['trafficRank','user']})
  expect(qs.length).toBeGreaterThan(0)
  await act(async () => {
    client.setQueryData(qs[0].queryKey, [
      {name:'very-long@example.com',value:1048576},
      {name:'zero@example.com',value:0},
      {name:'missing@example.com',value:null},
    ])
  })
  await flush()
  const userRank=host.querySelectorAll('.rank-card')[0]
  const ranked=Array.from(userRank.querySelectorAll('.dashboard-v3-rank-row'))
  expect(ranked).toHaveLength(3)
  expect(ranked[0].querySelector('.dashboard-v3-rank-name')?.getAttribute('title')).toBe('very-long@example.com')
  expect(ranked[0].querySelector('strong')?.textContent).toBe('1 MB')
  expect(ranked[1].querySelector('strong')?.textContent).toBe('0 B')
  expect(ranked[2].querySelector('strong')?.textContent).toBe('—')
})

it('does not fabricate a zero-paid chart point when the source reports an unknown amount', async () => {
  const q=client.getQueryCache().findAll({queryKey:['orderChart']})[0]
  await act(async () => {
    client.setQueryData(q.queryKey, {
      list:[
        {date:'2026-10-09',paid_total:null},
        {date:'2026-10-10',paid_total:520},
      ],
      summary:{paid_total:520},
    })
  })
  await flush()
  const chart=host.querySelector('svg.dashboard-v3-trend-svg')
  expect(chart).not.toBeNull()
  expect(chart?.getAttribute('aria-label')).not.toContain('2026-10-09')
  expect(host.querySelectorAll('.dashboard-v3-trend-svg .income-chart-dot')).toHaveLength(1)
})
