import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, afterEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from './api'
import { admin } from './session'
import { AuditLogsPage } from './AuditLogsPage'
import { QueuePage } from './QueuePage'

const session = vi.hoisted(() => ({ active: true }))
vi.mock('./session', () => ({ admin: { read: vi.fn() }, useAdminSession: () => session.active }))
let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.mocked(admin.read).mockReset(); session.active = true
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(page: React.ReactNode) {
  await act(async () => root.render(<QueryClientProvider client={client}>{page}</QueryClientProvider>)); await settle()
}
async function click(text: string) {
  const button = Array.from(host.querySelectorAll('button')).find(element => element.getAttribute('aria-label') === text || element.textContent === text || element.querySelector('strong')?.textContent === text)
  expect(button).toBeTruthy(); await act(async () => button!.click()); await settle()
}
function input(label: string, value: string) {
  const field = Array.from(host.querySelectorAll('label')).find(element => element.textContent === label)!.querySelector('input')!
  Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(field, value)
  field.dispatchEvent(new Event('input', { bubbles: true }))
}
const audit = { id: '7', admin_id: '9007199254740993', action: 'account.update', method: 'POST', uri: '/accounts', ip: '127.0.0.1', created_at: 1792368000, admin: null }
it('sends server audit filters and pagination with string IDs, then hides stale rows and pagination on denial', async () => {
  vi.mocked(admin.read).mockResolvedValue({ data: [audit], meta: { page: 1, per_page: 20, total: 40, last_page: 2 } } as never)
  await render(<AuditLogsPage />)
  await act(async () => input('管理员 ID', audit.admin_id))
  await act(async () => input('操作', audit.action))
  await act(async () => input('关键字', 'accounts'))
  await act(async () => host.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))); await settle()
  expect(admin.read).toHaveBeenLastCalledWith('audit.logs.list', { page: 1, per_page: 20, admin_id: audit.admin_id, action: audit.action, keyword: 'accounts' }, expect.any(AbortSignal))
  await click('下一页')
  expect(admin.read).toHaveBeenLastCalledWith('audit.logs.list', expect.objectContaining({ page: 2 }), expect.any(AbortSignal))
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新')
  expect(host.textContent).not.toContain('127.0.0.1')
  expect(host.querySelector('[aria-label="管理审计记录分页"]')).toBeNull()
})
it('rejects an unsafe audit administrator ID before sending a filtered query', async () => {
  vi.mocked(admin.read).mockResolvedValue({ data: [], meta: { total: 0, last_page: 1, page: 1, per_page: 20 } } as never)
  await render(<AuditLogsPage />)
  const calls = vi.mocked(admin.read).mock.calls.length
  await act(async () => input('管理员 ID', '9223372036854775808'))
  await act(async () => host.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))); await settle()
  expect(admin.read).toHaveBeenCalledTimes(calls)
  expect(host.textContent).toContain('有效的十进制字符串')
})
it('shows queue metadata while withholding exception messages and removes all observations on denied detail', async () => {
  vi.mocked(admin.read).mockImplementation(async operation => {
    if (operation === 'platform.queue.snapshot.get') return { data: { status: 'running', connection: 'redis', processes: 2, recent_jobs: 3, jobs_per_minute: 1, wait_seconds: 0, longest_wait_queue: 'mail', pending_jobs: 0, observed_at: '2026-10-19T00:00:00Z', message: 'SECRET-SNAPSHOT', failed_last_7_days: 1, failed_jobs_available: true } } as never
    return { data: [{ id: '12', connection: 'redis', queue: 'mail', job: 'SendMail', message: 'SECRET-EXCEPTION', payload: 'SECRET-PAYLOAD', failed_at: '2026-10-19T00:00:00Z' }] } as never
  })
  await render(<QueuePage />)
  expect(host.textContent).toContain('运行中'); expect(host.textContent).toContain('SendMail')
  expect(host.textContent).not.toContain('SECRET-')
  vi.mocked(admin.read).mockResolvedValueOnce({ data: { id: '12', connection: 'redis', queue: 'mail', job: 'SendMail', failed_at: '2026-10-19T00:00:00Z' } } as never)
  await click('#12')
  expect(document.querySelector('[aria-label="失败记录详情"]')?.textContent).toContain('SendMail')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => document.querySelector<HTMLButtonElement>('[role=dialog] button[aria-label=关闭]')!.click()); await settle()
  await click('刷新失败记录')
  expect(host.textContent).not.toContain('SendMail'); expect(host.textContent).not.toContain('redis')
})
