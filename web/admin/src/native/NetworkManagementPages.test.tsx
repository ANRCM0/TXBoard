import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, afterEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { NodeDetailPage } from './NodeDetailPage'
import { MachineDetailPage } from './MachineDetailPage'
import { NodesPage } from './NodesPage'
import { TrafficResetPage } from './TrafficResetPage'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
const id = '9007199254740993'
const node = { id, name: 'private-node', type: 'vless', host: 'example.test', port: 443, server_port: null, parent_id: null, machine_id: null, show: true, enabled: true, sort: 0, u: '0', d: '0', group_ids: [], route_ids: [], tags: [], created_at: null, updated_at: null }
let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } }); workflowActions.reset()
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: { ok: true } } as never)
  vi.mocked(admin.read).mockReset().mockImplementation(async (operation, input) => {
    if (operation === 'network.nodes.get') return { data: { ...node }, meta: {} } as never
    if (operation === 'network.nodes.list') return { data: [node], meta: { page: 1, per_page: 20, total: 1, last_page: 1 } } as never
    if (operation === 'network.machines.get') return { data: { id, name: 'private-machine', notes: 'retained note', is_active: true, image_channel: 'stable', servers_count: 21, last_seen_at: null, created_at: null, updated_at: null }, meta: {} } as never
    if (operation === 'network.machines.nodes.list') return { data: [], meta: { page: 'page' in input ? input.page : 1, per_page: 20, total: 21, last_page: 2 } } as never
    return { data: { id: '7', email: 'account@example.test', subscription: { authorized: true, subscription_id: '8', subscription_version: id, usage_period_id: '9', quota_bytes: '100', consumed_bytes: '20', remaining_bytes: '80' } }, meta: {} } as never
  })
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(path: string) {
  await act(async () => root.render(<QueryClientProvider client={client}><MemoryRouter key={path} initialEntries={[path]}><Routes>
    <Route path="/node/:nodeId" element={<NodeDetailPage />} /><Route path="/machine/:machineId" element={<MachineDetailPage />} />
    <Route path="/nodes" element={<NodesPage />} /><Route path="/reset" element={<TrafficResetPage />} />
  </Routes></MemoryRouter></QueryClientProvider>)); await settle()
}
function button(text: string) {
  const scope = document.querySelector<HTMLElement>('[role="dialog"][aria-modal="true"]') ?? host
  return [...scope.querySelectorAll<HTMLButtonElement>('button')].find(node =>
    node.getAttribute('aria-label') === text || node.textContent?.trim() === text)!
}
async function readyButton(text: string) {
  await vi.waitFor(async () => {
    await settle()
    expect(button(text), text + ' must be rendered').toBeDefined()
    expect(button(text).disabled, text + ' must be enabled').toBe(false)
  })
}
async function click(text: string) { await readyButton(text); await act(async () => button(text).click()); await settle() }
async function input(value: string) {
  const element = host.querySelector('input')!
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(element, value); element.dispatchEvent(new Event('input', { bubbles: true })) })
}
it('directly reads a large node ID and uses only the supported visibility patch', async () => {
  await render('/node/' + id)
  expect(admin.read).toHaveBeenCalledWith('network.nodes.get', { node_id: id }, expect.any(AbortSignal))
  expect(admin.read).not.toHaveBeenCalledWith('network.nodes.list', expect.anything(), expect.anything())
  await click('隐藏节点')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.nodes.patch', { node_id: id, show: false }, expect.any(String))
})
it('reports a missing node without displaying stale cached detail or requesting login', async () => {
  await render('/node/' + id)
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('BUSINESS_REJECTED', 404))
  await click('刷新节点')
  expect(host.textContent).toContain('节点不存在或已被删除')
  expect(host.textContent).not.toContain('private-node')
  expect(button('重新登录')).toBeUndefined()
})
it('retains a node delete across navigation and confirms the original key from the list', async () => {
  await render('/node/' + id)
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost response'))
  await click('删除节点'); const original = vi.mocked(admin.write).mock.calls[0]
  await render('/nodes')
  expect(admin.write).toHaveBeenCalledTimes(1)
  await click('确认原操作')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
})
it('hides node identity and forms when a write loses authorization', async () => {
  await render('/node/' + id)
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403, undefined, true))
  await click('隐藏节点')
  expect(host.textContent).not.toContain('private-node')
  expect(button('确认原操作').disabled).toBe(true)
  await click('刷新节点'); await click('确认原操作')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(vi.mocked(admin.write).mock.calls[0])
})
it('reads machine notes and paginates the machine-owned node list', async () => {
  await render('/machine/' + id)
  expect(host.querySelector('textarea')?.value).toBe('retained note')
  await click('下一页')
  expect(admin.read).toHaveBeenCalledWith('network.machines.nodes.list', { machine_id: id, page: 2, per_page: 20 }, expect.any(AbortSignal))
})
it('saves machine metadata without submitting credentials or legacy adapter inputs', async () => {
  await render('/machine/' + id); await click('保存服务器')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.machines.update', { machine_id: id, name: 'private-machine', notes: 'retained note', is_active: true, image_channel: 'stable' }, expect.any(String))
})
it('keeps machine mutations locked until the dependent node authorization read finishes', async () => {
  let resolve!: (result: never) => void
  const original = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation((operation, ...args) => operation === 'network.machines.nodes.list'
    ? new Promise(done => { resolve = done }) : original(operation, ...args))
  await render('/machine/' + id)
  await vi.waitFor(() => expect(resolve).toBeDefined())
  expect(button('保存服务器').disabled).toBe(true)
  expect(button('下一页').disabled).toBe(true)
  expect(admin.write).not.toHaveBeenCalled()
  await act(async () => { resolve({ data: [], meta: { page: 1, per_page: 20, total: 21, last_page: 2 } } as never) })
  await click('保存服务器')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.machines.update', { machine_id: id, name: 'private-machine', notes: 'retained note', is_active: true, image_channel: 'stable' }, expect.any(String))
})
it('ignores a delayed node write receipt after session replacement', async () => {
  await render('/node/' + id)
  let resolve!: (result: never) => void
  vi.mocked(admin.write).mockImplementationOnce(() => new Promise(done => { resolve = done }))
  await click('隐藏节点'); const reads = vi.mocked(admin.read).mock.calls.length
  await act(async () => { workflowActions.reset(); resolve({ data: { ok: true } } as never) }); await settle()
  expect(admin.read).toHaveBeenCalledTimes(reads)
  expect(workflowActions.snapshot()).toEqual({ busy: false })
})
it('resets the native subscription with the observed string version and requires confirmation', async () => {
  await render('/reset'); await input('7'); await click('读取当前订阅')
  vi.mocked(window.confirm).mockReturnValueOnce(false)
  await click('重置订阅流量周期'); expect(admin.write).not.toHaveBeenCalled()
  await click('重置订阅流量周期')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('subscription.lifecycle.reset', { subscription_id: '8', expected_version: id }, expect.any(String))
})
it('blocks reset recovery until the original account subscription is freshly read after navigation', async () => {
  await render('/reset'); await input('7'); await click('读取当前订阅')
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost response'))
  await click('重置订阅流量周期'); const original = vi.mocked(admin.write).mock.calls[0]
  await render('/nodes'); await render('/reset')
  expect(button('确认原操作').disabled).toBe(true)
  await input('7'); await click('读取当前订阅'); await click('确认原操作')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
})
it('keeps reset recovery blocked when the read account owns a different subscription', async () => {
  await render('/reset'); await input('7'); await click('读取当前订阅')
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost response'))
  await click('重置订阅流量周期')
  vi.mocked(admin.read).mockResolvedValueOnce({ data: { id: '10', email: 'another@example.test', subscription: { authorized: true, subscription_id: '99', subscription_version: '2' } }, meta: {} } as never)
  await input('10'); await click('读取当前订阅')
  expect(button('确认原操作').disabled).toBe(true)
  expect(admin.write).toHaveBeenCalledTimes(1)
})
