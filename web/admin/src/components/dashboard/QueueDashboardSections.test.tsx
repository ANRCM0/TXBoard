import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { getQueueFailure, getQueueFailures, getQueueSnapshot } from '../../api/queueMonitor'
import { QueueDashboardSections } from './QueueDashboardSections'

vi.mock('../../api/queueMonitor', () => ({
  getQueueSnapshot: vi.fn(),
  getQueueFailures: vi.fn(),
  getQueueFailure: vi.fn(),
}))

let host: HTMLDivElement
let root: Root
let client: QueryClient

async function flush() {
  await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)) })
}

beforeEach(async () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  vi.resetAllMocks()
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.mocked(getQueueSnapshot).mockResolvedValue({
    status: 'running',
    connection: 'redis',
    processes: 3,
    recent_jobs: 45,
    pending_jobs: 2,
    jobs_per_minute: 9,
    wait_seconds: 4,
    longest_wait_queue: 'default',
    failed_last_7_days: 1,
    failed_jobs_available: true,
    observed_at: '2026-10-09T00:30:00+08:00',
  })
  vi.mocked(getQueueFailures).mockResolvedValue([{
    id: 12, connection: 'redis', queue: 'send_email',
    job: 'App\\Jobs\\SendEmailJob', message: 'SMTP failed', failed_at: '2026-10-08 18:00:00',
  }])
  vi.mocked(getQueueFailure).mockResolvedValue({
    id: 12, connection: 'redis', queue: 'send_email',
    job: 'App\\Jobs\\SendEmailJob', failed_at: '2026-10-08 18:00:00',
    exception: 'SMTP connection failed\n#0 File.php',
  })
  await act(async () => root.render(<QueryClientProvider client={client}><QueueDashboardSections/></QueryClientProvider>))
  await flush()
})

afterEach(() => {
  act(() => root.unmount())
  client.clear()
  host.remove()
})

it('renders genuine Horizon values and job failure count', () => {
  expect(host.querySelectorAll('.dashboard-queue-card')).toHaveLength(2)
  expect(host.textContent).toContain('运行正常')
  expect(host.textContent).toContain('每分钟处理量')
  expect(host.textContent).toContain('45')
  expect(host.textContent).toContain('近7天报错数量')
  expect(host.textContent).toContain('最长预计等待')
})

it('loads failed jobs on demand and opens the redacted exception detail', async () => {
  expect(getQueueFailures).not.toHaveBeenCalled()
  await act(async () => host.querySelector<HTMLButtonElement>('.dashboard-queue-view-errors')!.click())
  await flush()
  expect(getQueueFailures).toHaveBeenCalledTimes(1)
  expect(host.textContent).toContain('SMTP failed')
  await act(async () => host.querySelector<HTMLButtonElement>('.dashboard-queue-failure-row')!.click())
  await flush()
  expect(getQueueFailure).toHaveBeenCalledWith(12)
  expect(document.querySelector('[role="dialog"]')?.textContent).toContain('SMTP connection failed')
  act(() => document.querySelector<HTMLButtonElement>('.modal-header button')!.click())
  expect(document.querySelector('[role="dialog"]')).toBeNull()
})

it('does not render a fake healthy state when Horizon is unreachable', async () => {
  vi.mocked(getQueueSnapshot).mockResolvedValue({
    status: 'unavailable',
    connection: 'redis',
    processes: null,
    recent_jobs: null,
    pending_jobs: null,
    jobs_per_minute: null,
    wait_seconds: null,
    longest_wait_queue: null,
    failed_last_7_days: null,
    failed_jobs_available: false,
    observed_at: '2026-10-09T00:30:00+08:00',
    message: '无法连接 Horizon 指标服务',
  })
  await act(async () => host.querySelector<HTMLButtonElement>('[aria-label="刷新队列状态"]')!.click())
  await flush()
  expect(host.textContent).toContain('状态未知')
  expect(host.textContent).toContain('无法连接 Horizon 指标服务')
  expect(host.textContent).not.toContain('运行正常')
})
