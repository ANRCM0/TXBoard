import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getNodes, getMachines, getGroups, getRoutes, getProtocolDefinitions } from '../../api/server'
import { getAgentActions, getAgentFleetHealth } from '../../api/agent'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { NodeDetailPage } from './NodeDetailPage'

vi.mock('../../api/server',()=>({
  getNodes:vi.fn(),getMachines:vi.fn(),getGroups:vi.fn(),getRoutes:vi.fn(),
  getProtocolDefinitions:vi.fn(),saveNode:vi.fn().mockResolvedValue({}),
  copyNode:vi.fn().mockResolvedValue(3),deleteNode:vi.fn().mockResolvedValue({}),
}))
vi.mock('../../api/agent',()=>({
  getAgentActions:vi.fn(),getAgentFleetHealth:vi.fn(),
}))
vi.mock('../../components/ui/ConfirmDialog',()=>({requestConfirm:vi.fn()}))
vi.mock('sonner',()=>({toast:{success:vi.fn(),error:vi.fn()}}))
vi.mock('./NodeEditorModal',()=>({
  NodeEditorModal:({open}:{open:boolean}) => open ? <div role="dialog" aria-label="节点编辑器">编辑器已打开</div>:null,
}))

let root:Root
let client:QueryClient
let host:HTMLDivElement
const secret='VERY_SECRET_VALUE_DO_NOT_RENDER'
const nodes=[
  {id:2,name:'Hysteria — Tokyo',host:'2001:db8::1',port:8443,
    type:'hysteria',machine_id:9,enabled:true,show:false,
    online:undefined,rate:1.5,u:1024,d:524288,transfer_enable:1073741824,
    parent_id:1,group_ids:[7],route_ids:[8],tags:['JP'],protocol_settings:{password:secret}},
  {id:1,name:'Parent',type:'shadowsocks'},
  {id:4,name:'Child',type:'vless',parent_id:2},
]

async function mount(path='/server/node/2') {
  await act(async()=>{root.render(<QueryClientProvider client={client}>
    <MemoryRouter initialEntries={[path]}>
      <Routes><Route path="/server/node/:nodeId" element={<NodeDetailPage/>}/></Routes>
    </MemoryRouter>
  </QueryClientProvider>)})
  await act(async()=>{await new Promise(resolve=>setTimeout(resolve,0))})
}
beforeEach(()=>{
  ;(globalThis as {IS_REACT_ACT_ENVIRONMENT?:boolean}).IS_REACT_ACT_ENVIRONMENT=true
  vi.clearAllMocks()
  vi.mocked(getNodes).mockResolvedValue(nodes)
  vi.mocked(getMachines).mockResolvedValue([{id:9,name:'Host Tokyo',is_active:true}])
  vi.mocked(getGroups).mockResolvedValue([{id:7,name:'VIP'}])
  vi.mocked(getRoutes).mockResolvedValue([{id:8,remarks:'Direct'}])
  vi.mocked(getProtocolDefinitions).mockResolvedValue([])
  vi.mocked(getAgentFleetHealth).mockResolvedValue({
    status:'healthy',summary:{status:'healthy',total_nodes:1,healthy_nodes:1,degraded_nodes:0,critical_nodes:0,warning_count:0},
    generated_at:123,nodes:[{node_id:2,name:'Tokyo',status:'healthy',online:true,websocket:true,kernel_running:null,warnings:[]}],
  })
  vi.mocked(getAgentActions).mockResolvedValue([])
  client=new QueryClient({defaultOptions:{queries:{retry:false}}})
  host=document.createElement('div')
  document.body.append(host)
  root=createRoot(host)
})
afterEach(()=>{
  act(()=>root.unmount())
  vi.useRealTimers()
  client.clear()
  host.remove()
})
describe('Node Detail UI v3',()=>{
  it('presents a compact overview and preserves machine/parent/child links and Fleet status',async()=>{
    await mount()
    expect(host.querySelectorAll('.node-detail-v3-summary>div')).toHaveLength(4)
    expect(host.textContent).toContain('[2001:db8::1]:8443')
    expect(host.textContent).toContain('状态未知')
    expect(host.textContent).toContain('订阅隐藏')
    expect(host.textContent).toContain('1 GB')
    expect(host.textContent).toContain('VIP')
    expect(host.textContent).toContain('Direct')
    expect(host.querySelector('a[href="/server/machine/9"]')).not.toBeNull()
    expect(host.querySelector('a[href="/server/node/1"]')).not.toBeNull()
    expect(host.querySelector('a[href="/server/node/4"]')).not.toBeNull()
    expect(host.textContent).toContain('未上报')
    expect(host.querySelectorAll('.node-detail-v3-actions .data-table')).toHaveLength(0)
    expect(host.textContent).toContain('暂无 Agent 动作')
  })
  it('does not mount secret protocol config until explicitly requested, then auto-hides',async()=>{
    await mount()
    expect(host.textContent).not.toContain(secret)
    expect(host.querySelector('[data-testid="node-raw-config"]')).toBeNull()
    vi.useFakeTimers()
    const reveal=Array.from(host.querySelectorAll<HTMLButtonElement>('button')).find(x=>x.textContent?.includes('临时查看原始配置'))!
    act(()=>reveal.click())
    expect(host.textContent).toContain(secret)
    expect(host.querySelector('[data-testid="node-raw-config"]')).not.toBeNull()
    act(()=>vi.advanceTimersByTime(18_050))
    expect(host.querySelector('[data-testid="node-raw-config"]')).toBeNull()
    expect(host.textContent).not.toContain(secret)
  })
  it('hides raw secret when edit is opened and retains delete confirmation',async()=>{
    await mount()
    const reveal=Array.from(host.querySelectorAll<HTMLButtonElement>('button')).find(x=>x.textContent?.includes('临时查看原始配置'))!
    act(()=>reveal.click())
    expect(host.textContent).toContain(secret)
    const edit=Array.from(host.querySelectorAll<HTMLButtonElement>('button')).find(x=>x.textContent?.trim()==='编辑')!
    act(()=>edit.click())
    expect(host.textContent).not.toContain(secret)
    expect(host.querySelector('[role="dialog"]')).not.toBeNull()
    const del=Array.from(host.querySelectorAll<HTMLButtonElement>('button')).find(x=>x.textContent?.trim()==='删除')!
    act(()=>del.click())
    expect(requestConfirm).toHaveBeenCalledWith(expect.objectContaining({
      title:'删除节点',danger:true,confirmLabel:'删除节点',
    }))
  })
  it('does not request node inventory for an invalid route identifier',async()=>{
    await mount('/server/node/0')
    expect(getNodes).not.toHaveBeenCalled()
    expect(host.textContent).toContain('节点 ID 无效')
  })
})
