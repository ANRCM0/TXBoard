import { act, type ReactNode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter, Route, Routes, useParams } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { MachinesPage } from './MachinesPage'
import { MachineDetailPage } from './MachineDetailPage'
import { MachineNodeManager } from './MachineNodeManager'
import { MachineCredentialsPanel, MachineHistoryPanel } from './MachineRuntimePanels'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
const id = '9007199254740993'
const credentials = { token: 'only-memory-server-token', install_command: 'install --token only-memory-server-token' }
let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  workflowActions.reset()
  vi.mocked(admin.read).mockReset().mockImplementation(async operation => operation === 'network.machines.overview.get'
    ? { data: { observed_at: 1, freshness_seconds: 180, high_load_threshold_percent: 80, total: 0, enabled: 0, online: 0, high_load: 0, unknown_load: 0 }, meta: {} } as never
    : operation === 'network.machines.list'
    ? { data: [], meta: { page: 1, per_page: 20, total: 0, last_page: 1 } } as never
    : { data: credentials, meta: {} } as never)
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: { id }, meta: {} } as never)
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.useRealTimers(); vi.restoreAllMocks() })
async function render(element: ReactNode) {
  await act(async () => root.render(<QueryClientProvider client={client}>{element}</QueryClientProvider>))
  await settle()
}
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 15)) }) }
async function click(text: string) {
  const button = [...document.querySelectorAll<HTMLButtonElement>('button')].find(element => element.textContent?.trim() === text)!
  expect(button, text).toBeDefined(); expect(button.disabled).toBe(false)
  await act(async () => button.click())
}
function Destination() { return <p>服务器详情 #{useParams().machineId}</p> }
function list() { return <MemoryRouter initialEntries={['/server/machine']}><Routes><Route path="/server/machine" element={<MachinesPage />} /><Route path="/server/machine/:machineId" element={<Destination />} /></Routes></MemoryRouter> }
async function draft(name = 'Tokyo Edge') {
  await click('新建服务器')
  const input = document.querySelector<HTMLInputElement>('[role="dialog"] input:not([type="checkbox"])')!
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, name); input.dispatchEvent(new Event('input', { bubbles: true })) })
}

it('creates a server through the native operation and navigates with the exact returned identifier', async () => {
  await render(list()); await draft()
  const form = document.querySelector<HTMLFormElement>('[role="dialog"] form')!
  await act(async () => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.machines.create', { name: 'Tokyo Edge', notes: null, is_active: true, image_channel: 'stable' }, expect.any(String))
  expect(host.textContent).toContain('服务器详情 #' + id)
  expect(host.textContent).not.toContain(credentials.token)
})

it('recovers a lost server creation response with the original input and idempotency key', async () => {
  vi.mocked(admin.write).mockRejectedValueOnce(new Error('lost')).mockResolvedValue({ data: { id } } as never)
  await render(list()); await draft()
  await act(async () => document.querySelector<HTMLFormElement>('[role="dialog"] form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))
  const first = vi.mocked(admin.write).mock.calls[0]
  expect(workflowActions.snapshot().pending?.operation).toBe('network.machines.create')
  await click('确认原操作')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(first)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('reads credentials only on request, keeps them outside the query cache and clears them after three minutes', async () => {
  await render(<MachineCredentialsPanel machineId={id} disabled={false} />)
  expect(admin.read).not.toHaveBeenCalled()
  vi.useFakeTimers()
  await click('查看认证信息')
  expect(admin.read).toHaveBeenCalledExactlyOnceWith('network.machines.credentials.get', { machine_id: id }, expect.any(AbortSignal))
  expect(host.textContent).toContain(credentials.token)
  expect(JSON.stringify(client.getQueryCache().getAll())).not.toContain(credentials.token)
  await act(async () => vi.advanceTimersByTime(180000))
  expect(host.textContent).not.toContain(credentials.token)
})

it('aborts a credential read and rejects its late result after a resource switch', async () => {
  let resolve!: (value: unknown) => void
  vi.mocked(admin.read).mockReturnValueOnce(new Promise(done => { resolve = done }) as never)
  await render(<MachineCredentialsPanel machineId="A" disabled={false} />)
  await click('查看认证信息')
  const signal = vi.mocked(admin.read).mock.calls[0][2] as AbortSignal
  await render(<MachineCredentialsPanel machineId="B" disabled={false} />)
  expect(signal.aborted).toBe(true)
  await act(async () => resolve({ data: credentials }))
  expect(host.textContent).not.toContain(credentials.token)
})

it('clears revealed credentials immediately when the session scope changes', async () => {
  await render(<MachineCredentialsPanel machineId={id} disabled={false} />)
  await click('查看认证信息')
  expect(host.textContent).toContain(credentials.token)
  await act(async () => workflowActions.reset())
  expect(host.textContent).not.toContain(credentials.token)
  expect(admin.read).toHaveBeenCalledTimes(1)
})

it('confirms token rotation before writing and retrieves its credentials separately', async () => {
  await render(<MachineCredentialsPanel machineId={id} disabled={false} />)
  vi.mocked(window.confirm).mockReturnValueOnce(false)
  await click('重置 Token')
  expect(admin.write).not.toHaveBeenCalled()
  vi.mocked(admin.write).mockResolvedValueOnce({ data: { ok: true } } as never)
  await click('重置 Token')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.machines.token.rotate', { machine_id: id }, expect.any(String))
  expect(admin.read).toHaveBeenCalledExactlyOnceWith('network.machines.credentials.get', { machine_id: id }, expect.any(AbortSignal))
  expect(host.textContent).toContain(credentials.token)
})

it('does not retain credentials after an authorization failure', async () => {
  await render(<MachineCredentialsPanel machineId={id} disabled={false} />)
  await click('查看认证信息'); await click('隐藏认证信息')
  vi.mocked(admin.read).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await click('查看认证信息')
  expect(host.textContent).not.toContain(credentials.token)
  expect(host.querySelector('[role="alert"]')?.textContent).toContain('权限')
})

it('plots exact-byte usage ratios, leaves absent network samples empty and clears stale history after failure', async () => {
  vi.mocked(admin.read).mockResolvedValue({ data: { machine_id: id, range_hours: 1, points: [
    { cpu: '25.5', mem_used: '9007199254740993', mem_total: '18014398509481986', disk_used: '0', disk_total: '0', net_in_speed: null, net_out_speed: '1.25', recorded_at: 1792500000 },
  ] }, meta: {} } as never)
  await render(<MachineHistoryPanel machineId={id} />)
  expect(host.textContent).toContain('50%')
  expect(host.textContent).toContain('9007199254740993 / 18014398509481986 字节')
  expect(host.textContent).toContain('1.25 B/s')
  await click('6小时'); await settle()
  expect(admin.read).toHaveBeenLastCalledWith('network.machines.history.get', { machine_id: id, range_hours: 6, limit: 360 }, expect.any(AbortSignal))
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await client.refetchQueries({ queryKey: ['native-admin', 'machine-history', id] }) })
  await settle()
  expect(host.textContent).not.toContain('9007199254740993 / 18014398509481986 字节')
  expect(host.textContent).not.toContain('25.5%')
})

it('withdraws the complete detail and revealed token on history denial and authorizes history before recovery', async () => {
  let denied = false
  vi.mocked(admin.read).mockImplementation(async operation => {
    if (operation === 'network.machines.get') return { data: { id, name: 'authorized-server', notes: null, is_active: true,
      image_channel: 'stable', servers_count: 0, last_seen_at: null, created_at: 1792500000, updated_at: 1792500000 }, meta: {} } as never
    if (operation === 'network.machines.nodes.list') return { data: [], meta: { page: 1, per_page: 20, total: 0, last_page: 1 } } as never
    if (operation === 'network.machines.history.get') {
      if (denied) throw new CoreAdminApiError('FORBIDDEN', 403)
      return { data: { machine_id: id, range_hours: 1, points: [] }, meta: {} } as never
    }
    return { data: credentials, meta: {} } as never
  })
  await render(<MemoryRouter initialEntries={['/server/machine/' + id]}><Routes><Route path="/server/machine/:machineId" element={<MachineDetailPage />} /></Routes></MemoryRouter>)
  await settle()
  await click('查看认证信息')
  expect(host.textContent).toContain(credentials.token)
  denied = true
  await act(async () => { await client.refetchQueries({ queryKey: ['native-admin', 'machine-history', id] }) })
  await settle()
  expect(host.textContent).not.toContain(credentials.token)
  expect(host.textContent).not.toContain('authorized-server')
  await click('刷新服务器'); await settle()
  expect(host.textContent).not.toContain('authorized-server')
  denied = false
  await click('刷新服务器'); await settle()
  expect(host.textContent).toContain('authorized-server')
  expect(host.textContent).not.toContain(credentials.token)
})


it('reports token-rotation permission loss to the detail owner and removes already revealed credentials', async () => {
  const onAuthorizationFailure = vi.fn()
  await render(<MachineCredentialsPanel machineId={id} disabled={false} onAuthorizationFailure={onAuthorizationFailure} />)
  await click('查看认证信息')
  expect(host.textContent).toContain(credentials.token)
  const denied = new CoreAdminApiError('FORBIDDEN', 403)
  vi.mocked(admin.write).mockRejectedValueOnce(denied)
  await click('重置 Token'); await settle()
  expect(onAuthorizationFailure).toHaveBeenCalledExactlyOnceWith(denied)
  expect(host.textContent).not.toContain(credentials.token)
  expect(admin.read).toHaveBeenCalledTimes(1)
})

const boundNode = { id: '9007199254740995', name: 'Bound exact node', type: 'shadowsocks', host: 'node.example.test', port: '443', show: true, enabled: true, sort: 1 }
const nodeConfiguration = { machine_id: id, enabled: true, cert_config: { private_key: 'retained-secret' }, protocol_settings: { cipher: 'aes-128-gcm' }, custom_routes: [{ retained: true }] }
function nodeReads(configuration = nodeConfiguration) {
  vi.mocked(admin.read).mockImplementation(async operation => {
    if (operation === 'network.machines.nodes.list') return { data: [boundNode], meta: { page: 1, per_page: 20, total: 1, last_page: 1 } } as never
    if (operation === 'network.nodes.configuration.get') return { data: { node_id: boundNode.id, revision: 'a'.repeat(64), configuration_json: JSON.stringify(configuration) }, meta: {} } as never
    throw new Error('Unexpected test operation ' + operation)
  })
}
function manager() { return <MemoryRouter><MachineNodeManager machineId={id} disabled={false} onAuthorizationFailure={vi.fn()} /></MemoryRouter> }
it('changes a bound node using the observed revision while retaining all configuration fields', async () => {
  nodeReads(); await render(manager()); await click('停用节点')
  const [operation, input] = vi.mocked(admin.write).mock.calls[0]
  expect(operation).toBe('network.nodes.configuration.replace')
  expect(input).toMatchObject({ node_id: boundNode.id, expected_revision: 'a'.repeat(64) })
  expect(JSON.parse((input as { configuration_json: string }).configuration_json)).toEqual({ ...nodeConfiguration, enabled: false })
  expect(host.textContent).not.toContain('retained-secret')
})
it('refuses a bound-node mutation when another administrator moved its association', async () => {
  nodeReads({ ...nodeConfiguration, machine_id: '999' }); await render(manager()); await click('解除绑定')
  expect(admin.write).not.toHaveBeenCalled()
})
it('rejects a late node configuration read after leaving the machine detail', async () => {
  nodeReads(); await render(manager())
  let resolve!: (value: unknown) => void
  const read = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation((operation, ...args) => operation === 'network.nodes.configuration.get'
    ? new Promise(done => { resolve = done }) as never : read(operation, ...args))
  await click('停用节点'); await act(async () => root.render(<p>Left machine</p>))
  await act(async () => resolve({ data: { revision: 'a'.repeat(64), configuration_json: JSON.stringify(nodeConfiguration) } }))
  expect(admin.write).not.toHaveBeenCalled()
})
it('restores the original node request after remount even when the machine actions are locked by pending work', async () => {
  nodeReads(); vi.mocked(admin.write).mockRejectedValueOnce(new Error('lost response')).mockResolvedValue({ data: { id: boundNode.id } } as never)
  await render(manager()); await click('停用节点')
  const original = vi.mocked(admin.write).mock.calls[0]
  await act(async () => root.render(<p>Leaving</p>)); await render(<MemoryRouter><MachineNodeManager machineId={id} disabled onAuthorizationFailure={vi.fn()} /></MemoryRouter>)
  await click('确认原操作')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})
