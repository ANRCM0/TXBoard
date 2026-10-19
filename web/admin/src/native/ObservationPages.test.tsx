import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, afterEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from './api'
import { admin } from './session'
import { AuditLogsPage } from './AuditLogsPage'
import { ModulesPage } from './ModulesPage'
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
const plugin = { id: 'payment-demo', name: 'Payment Demo', version: '1.0.0', type: 'plugin', source: 'package', installed: true, enabled: true, active: null, health: 'healthy', capabilities: ['payment'], compatibility: { txboard: '^0.2.0' } }
const theme = { ...plugin, id: 'theme-demo', name: 'Theme Demo', type: 'theme' }
it('filters plugin inventory, loads capabilities and lifecycle declarations without executing them, and hides all module data on permission loss', async () => {
  vi.mocked(admin.read).mockImplementation(async operation => {
    if (operation === 'platform.modules.list') return { data: { modules: [plugin, theme], summary: { total: 2, discovery_errors: 0 } } } as never
    if (operation === 'platform.modules.get') return { data: plugin } as never
    return { data: { module_id: plugin.id, operations: ['disable', 'upgrade', 'uninstall'] } } as never
  })
  await render(<ModulesPage kind="plugin" />)
  expect(host.textContent).toContain(plugin.name); expect(host.textContent).not.toContain(theme.name)
  await click(plugin.name)
  expect(admin.read).toHaveBeenCalledWith('platform.modules.get', { module_id: plugin.id }, expect.any(AbortSignal))
  expect(admin.read).toHaveBeenCalledWith('platform.modules.operations.list', { module_id: plugin.id }, expect.any(AbortSignal))
  expect(host.textContent).toContain('停用、升级、卸载')
  const help = [...host.querySelectorAll('details')].find(node => node.querySelector('summary')?.textContent === '如何变更模块？')!
  await act(async () => { help.open = true })
  expect(help.textContent).toContain('安装、启停、升级及卸载需在部署环境中执行')
  expect(help.textContent).toContain('此页面仅供查看')
  expect(Array.from(host.querySelectorAll('button')).some(button => ['安装', '升级', '卸载', '停用'].includes(button.textContent ?? ''))).toBe(false)
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新')
  expect(host.textContent).not.toContain(plugin.name)
  expect(host.querySelector('[aria-label="模块详情"]')).toBeNull()
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
  expect(host.querySelector('[aria-label="失败记录详情"]')?.textContent).toContain('SendMail')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新')
  expect(host.textContent).not.toContain('SendMail'); expect(host.textContent).not.toContain('redis')
})
it('withdraws cached data and aborts in-flight module detail when the administrator session ends', async () => {
  let resolveDetail!: (value: never) => void
  vi.mocked(admin.read).mockImplementation(async operation => {
    if (operation === 'platform.modules.list') return { data: { modules: [plugin], summary: { total: 1, discovery_errors: 0 } } } as never
    if (operation === 'platform.modules.get') return new Promise(resolve => { resolveDetail = resolve }) as never
    return { data: { module_id: plugin.id, operations: [] } } as never
  })
  await render(<ModulesPage />); await click(plugin.name)
  const signal = vi.mocked(admin.read).mock.calls.find(call => call[0] === 'platform.modules.get')![2]!
  session.active = false
  await render(<ModulesPage />)
  expect(host.textContent).not.toContain(plugin.name)
  // Unmounting the observer cancels the detached detail request.
  await render(<div />)
  expect(signal.aborted).toBe(true)
  await act(async () => resolveDetail({ data: plugin } as never)); await settle()
  expect(host.textContent).not.toContain(plugin.name)
})
