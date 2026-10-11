import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { Simulate } from 'react-dom/test-utils'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getNodes, copyNode } from '../../api/server'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import type { NodeItem } from '../../api/server'
import { NodesPage } from './NodesPage'

vi.mock('../../api/server', () => ({
  getNodes: vi.fn(),
  getMachines: vi.fn().mockResolvedValue([{id: 9, name: 'Tokyo Machine'}]),
  getGroups: vi.fn().mockResolvedValue([]),
  getRoutes: vi.fn().mockResolvedValue([]),
  getProtocolDefinitions: vi.fn().mockResolvedValue([]),
  saveNode: vi.fn().mockResolvedValue({}),
  deleteNode: vi.fn().mockResolvedValue({}),
  copyNode: vi.fn().mockResolvedValue(99),
}))
vi.mock('../../components/ui/ConfirmDialog', () => ({requestConfirm:vi.fn()}))
vi.mock('sonner', () => ({toast:{success:vi.fn(),error:vi.fn()}}))
vi.mock('./NodeEditorModal', () => ({
  NodeEditorModal: ({open}:{open:boolean}) => open ? <div role="dialog" aria-label="节点编辑器">编辑节点</div> : null,
}))

const rows: NodeItem[] = [
  {id:1,name:'Tokyo-A',type:'shadowsocks',host:'tokyo.example.com',port:443,machine_id:9,rate:1,online:true,enabled:true},
  {id:2,name:'Osaka-B',type:'hysteria',host:'2001:db8::1',port:8443,rate:2,enabled:true},
  {id:3,name:'Disabled-C',type:'vless',host:'us.example.com',online:false,enabled:false},
]

let root: Root
let host: HTMLDivElement
let client: QueryClient

async function flush() {
  await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)) })
}
async function renderNodes() {
  await act(async () => {
    root.render(<QueryClientProvider client={client}><MemoryRouter><NodesPage/></MemoryRouter></QueryClientProvider>)
  })
  await flush()
}

beforeEach(() => {
  ;(globalThis as {IS_REACT_ACT_ENVIRONMENT?:boolean}).IS_REACT_ACT_ENVIRONMENT = true
  vi.clearAllMocks()
  vi.mocked(getNodes).mockResolvedValue(rows)
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
  client = new QueryClient({defaultOptions:{queries:{retry:false}}})
})
afterEach(() => {
  act(() => root.unmount())
  client.clear()
  host.remove()
})

describe('UI v3 NodesPage', () => {
  it('combines identity and protocol, reports real state and keeps a scrollable compact table', async () => {
    await renderNodes()
    expect(host.querySelectorAll('.nodes-v3-identity')).toHaveLength(3)
    expect(host.querySelectorAll('tbody tr')).toHaveLength(3)
    expect(host.querySelector('table')?.querySelectorAll('th')).toHaveLength(6)
    expect(host.textContent).toContain('#2 · hysteria2')
    expect(host.textContent).toContain('[2001:db8::1]:8443')
    expect(host.textContent).toContain('Tokyo Machine')
    expect(host.textContent).toContain('共 3 个节点')
    const status = Array.from(host.querySelectorAll<HTMLElement>('.nodes-v3-status')).map(x=>x.textContent)
    expect(status).toEqual(['在线','未知','已禁用'])
    expect(host.querySelector('[role="region"]')?.getAttribute('aria-label')).toBe('节点列表')
  })

  it('distinguishes a no-results search from first-use empty state and can clear filters', async () => {
    await renderNodes()
    const search=host.querySelector<HTMLInputElement>('input[aria-label="搜索节点"]')!
    act(() => { search.value = 'nomatch'; Simulate.change(search) })
    expect(host.textContent).toContain('没有找到匹配的节点')
    expect(host.querySelector('table')).toBeNull()
    expect(host.textContent).toContain('显示 0 / 3 个节点')
    const reset=Array.from(host.querySelectorAll<HTMLButtonElement>('button')).find(x=>x.textContent?.trim()==='清除筛选')!
    act(() => reset.click())
    expect(host.querySelectorAll('tbody tr')).toHaveLength(3)
  })

  it('keeps row actions reachable as a portal and preserves destructive confirmation', async () => {
    await renderNodes()
    const trigger=host.querySelector<HTMLButtonElement>('.nodes-v3-action-trigger')!
    act(() => trigger.click())
    const menu=document.querySelector<HTMLElement>('[role="menu"]')!
    expect(menu).not.toBeNull()
    expect(menu.closest('.table-wrap')).toBeNull()
    expect(trigger.getAttribute('aria-expanded')).toBe('true')
    const buttons=Array.from(menu.querySelectorAll<HTMLButtonElement>('button'))
    expect(buttons.map(b=>b.textContent)).toEqual(['编辑','复制','删除'])
    expect(document.activeElement).toBe(buttons[0])
    act(() => document.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true,cancelable:true})))
    expect(document.activeElement).toBe(buttons[1])
    act(() => document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true})))
    expect(document.querySelector('[role="menu"]')).toBeNull()
    expect(document.activeElement).toBe(trigger)

    act(() => trigger.click())
    const copy=Array.from(document.querySelectorAll<HTMLButtonElement>('.nodes-v3-menu button')).find(b=>b.textContent==='复制')!
    await act(async () => { copy.click(); await Promise.resolve() })
    expect(vi.mocked(copyNode).mock.calls[0]?.[0]).toBe(1)
    act(() => trigger.click())
    const del=Array.from(document.querySelectorAll<HTMLButtonElement>('.nodes-v3-menu button')).find(b=>b.textContent==='删除')!
    act(() => del.click())
    expect(requestConfirm).toHaveBeenCalledWith(expect.objectContaining({
      title:'删除节点',danger:true,confirmLabel:'删除',
    }))
  })

  it('renders a lightweight empty state without a large table frame', async () => {
    vi.mocked(getNodes).mockResolvedValue([])
    await renderNodes()
    expect(host.textContent).toContain('还没有节点')
    expect(host.querySelector('table')).toBeNull()
    expect(host.querySelector('.nodes-v3-table.is-empty')).not.toBeNull()
    expect(host.textContent).toContain('创建节点')
    expect(host.textContent).toContain('查看服务器')
  })
})
