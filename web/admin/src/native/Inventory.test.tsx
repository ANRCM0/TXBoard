import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from './api'
import { MemoryRouter } from 'react-router-dom'
import { admin } from './session'
import { NodesPage } from './NodesPage'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  return { admin: { read: vi.fn() }, workflowActions: createWorkflowActions(vi.fn()) }
})
const row = { id: '9007199254740993', name: 'Tokyo-A', type: 'vless', host: '2001:db8::1', port: '443',
  server_port: null, parent_id: null, machine_id: null, show: true, enabled: true, sort: null,
  u: '9007199254740999', d: null }
let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.mocked(admin.read).mockReset()
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 15)) }) }
async function render() {
  await act(async () => root.render(<QueryClientProvider client={client}><MemoryRouter><NodesPage /></MemoryRouter></QueryClientProvider>))
  await settle()
}
it('shows canonical IDs/byte counts and requests the next server page', async () => {
  vi.mocked(admin.read).mockResolvedValue({ data: [row], meta: { page: 1, per_page: 20, total: 21, last_page: 2 } } as never)
  await render()
  expect(host.textContent).toContain('9007199254740993')
  expect(host.textContent).toContain('9007199254740999')
  expect(host.textContent).toContain('[2001:db8::1]:443')
  vi.mocked(admin.read).mockResolvedValueOnce({ data: [{ ...row, id: '2', name: 'Second page' }],
    meta: { page: 2, per_page: 20, total: 21, last_page: 2 } } as never)
  const next = [...host.querySelectorAll('button')].find(button => button.textContent === '下一页')!
  await act(async () => next.click()); await settle()
  expect(admin.read).toHaveBeenLastCalledWith('network.nodes.list', { page: 2, per_page: 20 }, expect.any(AbortSignal))
  expect(host.textContent).toContain('Second page')
  expect(host.textContent).not.toContain('Tokyo-A')
})
it('withdraws cached inventory after a permission failure and does not show an empty-success message', async () => {
  vi.mocked(admin.read).mockResolvedValue({ data: [row], meta: { page: 1, per_page: 20, total: 1, last_page: 1 } } as never)
  await render()
  vi.mocked(admin.read).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => host.querySelector<HTMLButtonElement>('.page-header button')!.click()); await settle()
  expect(host.textContent).not.toContain('Tokyo-A')
  expect(host.textContent).not.toContain('暂无数据')
  expect(host.querySelector('[role="alert"]')?.textContent).toContain('没有此操作的权限')
  expect(host.querySelector('table')).toBeNull()
})
