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
  return { admin, useAdminSession: () => true, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
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
    if (operation === 'subscription.lifecycle.resets.list') return { data: { records: [], next_before_id: null }, meta: {} } as never
    if (operation === 'network.nodes.get') return { data: { ...node }, meta: {} } as never
    if (operation === 'network.nodes.list') return { data: [node], meta: { page: 1, per_page: 20, total: 1, last_page: 1 } } as never
    if (operation === 'network.nodes.traffic-history.get') {
      const days = Number((input as { days?: number }).days ?? 14), now = Math.floor(Date.now() / 1000), today = new Date(now * 1000)
      const start = Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate()) / 1000 - (days - 1) * 86400
      return { data: { time_zone: 'UTC', basis: 'settled-raw-usage/v1', bucket: 'day', starts_at: start, ends_at: now,
        nodes: [{ id, name: 'private-node', points: Array.from({ length: days }, (_, day) => ({
          date: new Date((start + day * 86400) * 1000).toISOString().slice(0, 10),
          total_bytes: String(BigInt(day + 1) * 1000000000n),
        })) }] }, meta: {} } as never
    }
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
it('runs a current-page batch update and clears the selection after success', async () => {
  await render('/nodes')
  const checkbox = host.querySelector<HTMLInputElement>('input[aria-label="选择 private-node"]')!
  expect(checkbox).not.toBeNull()
  await act(async () => checkbox.click()); await settle()
  await click('批量启用')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.nodes.batch-update', { ids: [id], enabled: true }, expect.any(String))
  expect(host.querySelector('input[aria-label="选择 private-node"]')?.matches(':checked')).toBe(false)
})

it('filters server nodes before pagination and prefills the server when opening creation from a deep link', async () => {
  const original = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation(async (operation, ...args) => operation === 'network.nodes.protocols.list'
    ? { data: { metadata_json: JSON.stringify([{ type: 'vless', label: 'VLESS', schema_version: 1, defaults: {}, form_schema: [] }]) }, meta: {} } as never
    : original(operation, ...args))
  await render('/nodes?machine_id=' + id + '&open_create=1')
  await vi.waitFor(() => expect(document.querySelector('form.node-editor-form')).not.toBeNull())
  expect(admin.read).toHaveBeenCalledWith('network.nodes.list', { page: 1, per_page: 20, machine_id: id }, expect.any(AbortSignal))
  expect(document.querySelector('[role="dialog"]')?.textContent).toContain('#' + id)
  await click('取消'); expect(document.querySelector('form.node-editor-form')).toBeNull()
})

it('loads node traffic history and switches the chart range', async () => {
  await render('/node/' + id)
  await vi.waitFor(async () => {
    await settle()
    expect(admin.read).toHaveBeenCalledWith('network.nodes.traffic-history.get', { days: 14, node_ids: [id] }, expect.any(AbortSignal))
  })
  await click('7 天')
  await vi.waitFor(async () => {
    await settle()
    expect(admin.read).toHaveBeenCalledWith('network.nodes.traffic-history.get', { days: 7, node_ids: [id] }, expect.any(AbortSignal))
  })
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
it('locks machine writes after a dependent node read fails and withdraws the detail on permission loss', async () => {
  const original = vi.mocked(admin.read).getMockImplementation()!
  let failure: CoreAdminApiError | undefined
  vi.mocked(admin.read).mockImplementation(async (operation, ...args) => {
    if (operation === 'network.machines.nodes.list' && failure) throw failure
    return original(operation, ...args)
  })
  await render('/machine/' + id)
  await readyButton('保存服务器')
  failure = new CoreAdminApiError('DEPENDENCY_UNAVAILABLE', 503)
  await act(async () => { await client.refetchQueries({ queryKey: ['native-admin', 'machine-nodes', id] }) }); await settle()
  expect(button('保存服务器').disabled).toBe(true)
  expect(button('删除服务器').disabled).toBe(true)
  expect(button('查看认证信息').disabled).toBe(true)
  failure = new CoreAdminApiError('FORBIDDEN', 403)
  await act(async () => { await client.refetchQueries({ queryKey: ['native-admin', 'machine-nodes', id] }) }); await settle()
  expect(host.textContent).not.toContain('private-machine')
  expect(button('保存服务器')).toBeUndefined()
  expect(admin.write).not.toHaveBeenCalled()
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
  vi.mocked(admin.read).mockResolvedValueOnce({ data: { id: '10', email: 'another@example.test', subscription: {
    authorized: true, subscription_id: '99', subscription_version: '2', usage_period_id: '100', quota_bytes: '100', consumed_bytes: '20', remaining_bytes: '80',
  } }, meta: {} } as never)
  await input('10'); await click('读取当前订阅')
  expect(button('确认原操作').disabled).toBe(true)
  expect(admin.write).toHaveBeenCalledTimes(1)
})


it('filters current-page nodes by protocol and deployment and clears hidden selections', async () => {
  const read = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation((operation, ...args) => operation === 'network.nodes.list'
    ? Promise.resolve({ data: [node, { ...node, id: '2', name: 'machine-node', type: 'trojan', machine_id: '12' }], meta: { total: 2, last_page: 1 } } as never)
    : read(operation, ...args))
  await render('/nodes')
  await act(async () => host.querySelector<HTMLInputElement>('input[aria-label="选择 private-node"]')!.click())
  const protocol = host.querySelector<HTMLSelectElement>('select[aria-label="节点协议"]')!
  await act(async () => { protocol.value = 'trojan'; protocol.dispatchEvent(new Event('change', { bubbles: true })) }); await settle()
  expect(host.querySelectorAll('tbody tr')).toHaveLength(1)
  expect(host.querySelector('tbody')!.textContent).toContain('machine-node')
  expect(host.querySelector('tbody')!.textContent).not.toContain('private-node')
  expect(host.querySelector<HTMLInputElement>('input[aria-label="选择 machine-node"]')!.checked).toBe(false)
  const deployment = host.querySelector<HTMLSelectElement>('select[aria-label="部署归属"]')!
  await act(async () => { deployment.value = 'standalone'; deployment.dispatchEvent(new Event('change', { bubbles: true })) }); await settle()
  expect(host.querySelector('tbody')!.textContent).not.toContain('machine-node')
  expect(host.querySelector('tbody')!.textContent).not.toContain('private-node')
  expect(admin.write).not.toHaveBeenCalled()
})

it('shows exact raw node traffic alongside readable decimal GB without losing large integer precision', async () => {
  const read = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation((operation, ...args) => operation === 'network.nodes.list'
    ? Promise.resolve({ data: [{ ...node, u: '9223372036854775807', d: null, host: '2001:db8::1' }], meta: { total: 1, last_page: 1 } } as never)
    : read(operation, ...args))
  await render('/nodes')
  const bytes = host.querySelector('code[title="9223372036854775807 字节"]')!
  expect(bytes.textContent).toBe('9223372036.85 GB')
  expect(host.querySelector('tbody')!.textContent).toContain('未上报')
  expect(host.querySelector('tbody')!.textContent).toContain('[2001:db8::1]:443')
})

it('shows selected current-page nodes through the supported batch update', async () => {
  await render('/nodes')
  await act(async () => host.querySelector<HTMLInputElement>('input[aria-label="选择 private-node"]')!.click())
  await click('批量显示')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.nodes.batch-update', { ids: [id], show: true }, expect.any(String))
})

it('paginates binding servers and submits a server beyond the first hundred', async () => {
  const read = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation((operation, input, ...args) => operation === 'network.machines.list'
    ? Promise.resolve({ data: [{ id: (input as { page: number }).page === 1 ? '10' : id, name: 'binding-server' }], meta: { page: (input as { page: number }).page, per_page: 100, total: 101, last_page: 2 } } as never)
    : read(operation, input, ...args))
  await render('/nodes')
  await act(async () => host.querySelector<HTMLInputElement>('input[aria-label="选择 private-node"]')!.click())
  await click('绑定服务器'); await click('下一页服务器')
  expect(admin.read).toHaveBeenCalledWith('network.machines.list', { page: 2, per_page: 100 }, expect.any(AbortSignal))
  const select = document.querySelector<HTMLSelectElement>('[role="dialog"] select')!
  await act(async () => { select.value = id; select.dispatchEvent(new Event('change', { bubbles: true })) })
  await click('保存服务器归属')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.nodes.batch-update', { ids: [id], machine_id: id }, expect.any(String))
})

it.each([500, 403])('clears cached binding servers and blocks saving after a %s refetch failure', async status => {
  const read = vi.mocked(admin.read).getMockImplementation()!
  let failed = false
  vi.mocked(admin.read).mockImplementation((operation, input, ...args) => operation === 'network.machines.list'
    ? failed ? Promise.reject(new CoreAdminApiError(status === 403 ? 'FORBIDDEN' : 'INTERNAL_ERROR', status))
      : Promise.resolve({ data: [{ id: '10', name: 'private-binding-server' }], meta: { page: 1, per_page: 100, total: 1, last_page: 1 } } as never)
    : read(operation, input, ...args))
  await render('/nodes')
  await act(async () => host.querySelector<HTMLInputElement>('input[aria-label="选择 private-node"]')!.click())
  await click('绑定服务器')
  expect(document.querySelector('[role="dialog"]')!.textContent).toContain('private-binding-server')
  failed = true
  await act(async () => { await client.refetchQueries({ queryKey: ['native-admin', 'node-binding-machines'] }) }); await settle()
  expect(document.body.textContent).not.toContain('private-binding-server')
  const save = [...document.querySelectorAll<HTMLButtonElement>('button')].find(element => element.textContent === '保存服务器归属')
  expect(!save || save.disabled).toBe(true)
  expect(admin.write).not.toHaveBeenCalled()
})


it('withdraws node identity after a traffic-history permission revocation and restores it only after both fresh reads succeed', async () => {
  const original = vi.mocked(admin.read).getMockImplementation()!
  let forbidden = false
  vi.mocked(admin.read).mockImplementation(async (operation, ...args) => {
    if (operation === 'network.nodes.traffic-history.get' && forbidden) throw new CoreAdminApiError('FORBIDDEN', 403)
    return original(operation, ...args)
  })
  await render('/node/' + id)
  expect(host.textContent).toContain('private-node')
  forbidden = true
  await act(async () => { await client.refetchQueries({ queryKey: ['native-admin', 'node-traffic', id] }) }); await settle()
  expect(host.textContent).not.toContain('private-node')
  expect(button('隐藏节点')).toBeUndefined()
  await click('刷新节点')
  expect(host.textContent).not.toContain('private-node')
  forbidden = false
  await click('刷新节点'); await settle()
  expect(host.textContent).toContain('private-node')
  expect(button('隐藏节点')).toBeDefined()
})

it('preserves an unsaved machine draft across background reads and requires consent before replacing it', async () => {
  await render('/machine/' + id)
  const notes = host.querySelector<HTMLTextAreaElement>('textarea')!
  await act(async () => {
    Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')!.set!.call(notes, 'unsaved deployment note')
    notes.dispatchEvent(new Event('input', { bubbles: true }))
  })
  await act(async () => { await client.refetchQueries({ queryKey: ['native-admin', 'machine-detail', id] }) }); await settle()
  expect(notes.value).toBe('unsaved deployment note')
  vi.mocked(window.confirm).mockReturnValueOnce(false)
  await click('刷新服务器')
  expect(notes.value).toBe('unsaved deployment note')
  expect(window.confirm).toHaveBeenLastCalledWith('服务器配置尚未保存，重新读取将替换草稿，确定继续？')
  await click('刷新服务器'); await settle()
  expect(notes.value).toBe('retained note')
})
