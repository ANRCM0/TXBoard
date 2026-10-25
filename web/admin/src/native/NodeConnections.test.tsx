import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, afterEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { NodeConnections } from './NodeConnections'
import { admin, workflowActions } from './session'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, useAdminSession: () => true, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
const nodeId = '9007199254740993', connectionId = 'a'.repeat(32) + ':9007199254740995'
const requestId = 'nco_' + 'b'.repeat(32)
const base = { request_id: requestId, node_id: nodeId, action: 'list' as const, deadline: 1900000000,
  status: 'pending' as const, connections: [], total: 0, next: '', connection_id: '' }
let host: HTMLDivElement, root: Root, client: QueryClient, resultStatus: string, denied: boolean
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } }); workflowActions.reset()
  resultStatus = 'complete'; denied = false
  vi.mocked(admin.write).mockReset().mockImplementation(async (op, input) => ({ data: { ...base,
    request_id: op === 'network.nodes.connections.close' ? 'nco_' + 'c'.repeat(32) : requestId,
    action: op === 'network.nodes.connections.close' ? 'close' : 'list',
    connection_id: 'connection_id' in input ? input.connection_id : '' } }) as never)
  vi.mocked(admin.read).mockReset().mockImplementation(async () => {
    if (denied) throw new CoreAdminApiError('FORBIDDEN', 403)
    return { data: { ...base, status: resultStatus,
      connections: resultStatus === 'complete' ? [{ id: connectionId, user_id: '9007199254740997', transport: 'tcp' }] : [],
      total: resultStatus === 'complete' ? 1 : 0 } } as never
  })
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 25)) }) }
async function render(key = 'one') { await act(async () => root.render(<QueryClientProvider client={client}><NodeConnections key={key} nodeId={nodeId} blocked={false} /></QueryClientProvider>)); await settle() }
function button(label: string) { return [...host.querySelectorAll<HTMLButtonElement>('button')].find(node => node.textContent?.trim() === label)! }
async function click(label: string) { await act(async () => button(label).click()); await settle() }

it('reads on demand and sends the exact opaque ID after confirmation', async () => {
  await render(); expect(admin.write).not.toHaveBeenCalled(); expect(admin.read).not.toHaveBeenCalled()
  await click('读取连接列表')
  expect(host.textContent).toContain(connectionId)
  expect(host.textContent).toContain('9007199254740997')
  vi.mocked(admin.read).mockResolvedValueOnce({ data: { ...base, request_id: 'nco_' + 'c'.repeat(32), action: 'close', status: 'closed', connection_id: connectionId } } as never)
  await click('关闭连接')
  expect(window.confirm).toHaveBeenCalled()
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(['network.nodes.connections.close', { node_id: nodeId, connection_id: connectionId }, expect.any(String)])
  expect(host.textContent).toContain('节点已确认关闭连接')
  expect(button('关闭连接').disabled).toBe(true)
})

it('retains an unknown request across remount and reuses its original key and input', async () => {
  await render(); vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost response'))
  await click('读取连接列表')
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(button('读取连接列表').disabled).toBe(true)
  await render('two'); await click('确认原连接请求')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
  expect(host.textContent).toContain(connectionId)
})

it('shows an expired observation as unknown without retrying the close operation', async () => {
  resultStatus = 'unknown'; await render(); await click('读取连接列表')
  expect(host.textContent).toContain('操作结果未知')
  expect(admin.write).toHaveBeenCalledTimes(1)
  expect(button('读取连接列表').disabled).toBe(false)
})

it('withdraws connection snapshots after permission loss or session replacement', async () => {
  await render(); await click('读取连接列表'); expect(host.textContent).toContain(connectionId)
  denied = true; await act(async () => { await client.invalidateQueries({ queryKey: ['native-admin', 'node-connections'] }) }); await settle()
  expect(host.textContent).not.toContain(connectionId)
  expect(host.textContent).toContain('已隐藏资源数据')
  denied = false; await render('new-session'); await click('读取连接列表')
  expect(host.textContent).toContain(connectionId)
  act(() => workflowActions.reset())
  expect(host.textContent).not.toContain(connectionId)
})
