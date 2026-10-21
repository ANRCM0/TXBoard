import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, afterEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { NodesPage } from './NodesPage'
import { admin, workflowActions } from './session'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
const id = '9007199254740993', otherId = '9007199254740995'
let host: HTMLDivElement, root: Root, client: QueryClient, revision: string, denied: boolean
const nodes = [{ id, name: 'Outside current page', type: 'vless' }, { id: otherId, name: 'Filtered node', type: 'trojan' }]
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  workflowActions.reset(); revision = 'a'.repeat(64); denied = false
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: { ok: true } } as never)
  vi.mocked(admin.read).mockReset().mockImplementation(async operation => {
    if (operation === 'network.nodes.ordering.get') {
      if (denied) throw new CoreAdminApiError('FORBIDDEN', 403)
      return { data: { revision, nodes: structuredClone(nodes) }, meta: {} } as never
    }
    return { data: [], meta: { page: 2, per_page: 20, total: 2, last_page: 2 } } as never
  })
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(key = 'first') {
  await act(async () => root.render(<QueryClientProvider client={client}><MemoryRouter key={key} initialEntries={['/server/manage?machine_id=7']}><NodesPage /></MemoryRouter></QueryClientProvider>))
  await settle()
}
function button(label: string) { return [...host.querySelectorAll<HTMLButtonElement>('button')].find(node => node.getAttribute('aria-label') === label || node.textContent?.trim() === label)! }
async function click(label: string) { await act(async () => button(label).click()); await settle() }

it('edits a fresh full set independently of an empty filtered page and submits exact IDs', async () => {
  await render(); await click('编辑完整节点排序')
  expect(admin.read).toHaveBeenCalledWith('network.nodes.ordering.get', {})
  expect(host.querySelectorAll('.network-sort-list > li')).toHaveLength(2)
  await click('上移节点 ' + otherId)
  await click('保存完整节点排序')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.nodes.ordering.replace', {
    expected_revision: 'a'.repeat(64), node_ids: [otherId, id],
  }, expect.any(String))
})

it('keeps a changed-set draft while blocking writes until an explicit reload', async () => {
  await render(); await click('编辑完整节点排序'); await click('上移节点 ' + otherId)
  revision = 'b'.repeat(64); await click('保存完整节点排序')
  expect(admin.write).not.toHaveBeenCalled()
  expect(host.querySelector('.network-sort-list > li')?.textContent).toContain(otherId)
  expect(button('保存完整节点排序').disabled).toBe(true)
  await click('重新读取完整节点排序')
  expect(window.confirm).toHaveBeenCalled()
  expect(button('保存完整节点排序').disabled).toBe(false)
})

it('retains a server-side CAS conflict draft and requires fresh ordering before another write', async () => {
  await render(); await click('编辑完整节点排序')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('NETWORK_NODE_ORDERING_CONFLICT', 409))
  await click('保存完整节点排序')
  expect(host.querySelectorAll('.network-sort-list > li')).toHaveLength(2)
  expect(button('保存完整节点排序').disabled).toBe(true)
})

it('withdraws ordering labels and draft on a denied fresh read and requires authorization recovery', async () => {
  await render(); await click('编辑完整节点排序'); denied = true
  await click('保存完整节点排序')
  expect(host.textContent).not.toContain('Outside current page')
  expect(host.querySelector('.network-sort-list')).toBeNull()
  expect(admin.write).not.toHaveBeenCalled()
  denied = false; await click('刷新节点')
  await click('编辑完整节点排序')
  expect(host.textContent).toContain('Outside current page')
})

it('recovers an unknown outcome after remount with the original revision, IDs and request key', async () => {
  await render(); await click('编辑完整节点排序'); await click('上移节点 ' + otherId)
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost response'))
  await click('保存完整节点排序')
  const original = vi.mocked(admin.write).mock.calls[0]
  await render('remounted'); await click('确认原操作')
  expect(admin.write).toHaveBeenCalledTimes(2)
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
})

it('reports an overlimit collection without offering a partial-page sort', async () => {
  await render()
  vi.mocked(admin.read).mockRejectedValueOnce(new CoreAdminApiError('NETWORK_NODE_SORT_LIMIT', 422))
  await click('编辑完整节点排序')
  expect(host.textContent).toContain('不能以分页或筛选子集替代')
  expect(host.querySelector('.network-sort-list')).toBeNull()
  expect(admin.write).not.toHaveBeenCalled()
})
