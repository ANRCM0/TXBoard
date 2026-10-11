import {act} from 'react'
import {createRoot,type Root} from 'react-dom/client'
import {QueryClient,QueryClientProvider} from '@tanstack/react-query'
import {MemoryRouter,Route,Routes} from 'react-router-dom'
import {afterEach,beforeEach,describe,expect,it,vi} from 'vitest'
import {getMachines,getMachineNodes,type MachineItem} from '../../api/server'
import {getAgentActions,getAgentFleetHealth} from '../../api/agent'
import {requestConfirm} from '../../components/ui/ConfirmDialog'
import {MachineDetailPage} from './MachineDetailPage'
vi.mock('../../api/server',()=>({
  getMachines:vi.fn(),getMachineNodes:vi.fn(),saveMachine:vi.fn().mockResolvedValue({}),
  deleteMachine:vi.fn().mockResolvedValue({}),
}))
vi.mock('../../api/agent',()=>({getAgentActions:vi.fn(),getAgentFleetHealth:vi.fn()}))
vi.mock('sonner',()=>({toast:{success:vi.fn(),error:vi.fn()}}))
vi.mock('../../components/ui/ConfirmDialog',()=>({requestConfirm:vi.fn()}))
vi.mock('../../components/server/MachineHistoryChart',()=>({MachineHistoryChart:()=> <div>History chart</div>}))
vi.mock('../../components/server/MachineNodeBinding',()=>({MachineNodeBinding:()=> <div>Node binding editor</div>}))
vi.mock('./MachineCredentialsDialog',()=>({
  MachineCredentialsDialog:({machineId}:{machineId:number})=><div role="dialog" aria-label="Credential isolation">Token for #{machineId}</div>,
}))
let root:Root,host:HTMLDivElement,client:QueryClient
const sample:MachineItem[]=[{id:7,name:'Tokyo Host',is_active:true,image_channel:'dev',notes:'JP',
  load_status:{cpu:0,mem:{used:0,total:100},disk:{used:75,total:100}}},
  {id:8,name:'Never Connected',is_active:true}]
beforeEach(()=>{
  ;(globalThis as {IS_REACT_ACT_ENVIRONMENT?:boolean}).IS_REACT_ACT_ENVIRONMENT=true
  vi.clearAllMocks()
  vi.mocked(getMachines).mockResolvedValue(sample)
  vi.mocked(getMachineNodes).mockResolvedValue([{id:17,name:'Node J',host:'::1',port:8443,type:'hysteria',enabled:true,show:true}])
  vi.mocked(getAgentFleetHealth).mockResolvedValue({
    status:'healthy',summary:{status:'healthy',total_nodes:1,healthy_nodes:1,degraded_nodes:0,critical_nodes:0,warning_count:0},
    nodes:[{node_id:17,name:'Node J',status:'healthy',online:true,websocket:true,kernel_running:true,warnings:[]}],
    generated_at:0,
  })
  vi.mocked(getAgentActions).mockResolvedValue([])
  host=document.createElement('div');document.body.append(host);root=createRoot(host)
  client=new QueryClient({defaultOptions:{queries:{retry:false}}})
})
afterEach(()=>{act(()=>root.unmount());client.clear();host.remove()})
async function mount(path='/server/machine/7'){
  await act(async()=>root.render(<QueryClientProvider client={client}>
    <MemoryRouter initialEntries={[path]}>
      <Routes><Route path="/server/machine/:machineId" element={<MachineDetailPage/>}/></Routes>
    </MemoryRouter>
  </QueryClientProvider>))
  await act(async()=>{await new Promise(resolve=>setTimeout(resolve,0))})
}
describe('Machine Detail v3 page',()=>{
  it('renders consolidated metrics, machine state and node links',async()=>{
    await mount()
    expect(host.querySelectorAll('.machine-detail-v3-summary>div')).toHaveLength(4)
    expect(host.textContent).toContain('CPU0.0%')
    expect(host.textContent).toContain('内存0.0%')
    expect(host.textContent).toContain('未上报')
    expect(host.textContent).toContain('[::1]:8443')
    expect(host.querySelector('a[href="/server/node/17"]')).not.toBeNull()
    expect(host.textContent).toContain('暂无 Agent 动作')
    expect(host.querySelectorAll('.machine-detail-v3 .data-table')).toHaveLength(1)
    expect(host.textContent).toContain('History chart')
  })
  it('opens edit/binding/credential views while keeping deletion behind confirmation',async()=>{
    await mount()
    const byText=(value:string)=>Array.from(host.querySelectorAll<HTMLButtonElement>('button')).find(b=>b.textContent?.trim()===value)!
    act(()=>byText('编辑').click())
    expect(document.body.textContent).toContain('编辑机器')
    expect(document.body.querySelector('[role="switch"][aria-label="启用机器"]')).not.toBeNull()
    act(()=>byText('节点绑定').click())
    expect(document.body.textContent).toContain('Node binding editor')
    act(()=>byText('Token / 安装命令').click())
    expect(host.textContent).toContain('Token for #7')
    act(()=>byText('删除').click())
    expect(requestConfirm).toHaveBeenCalledWith(expect.objectContaining({
      title:'删除机器',danger:true,confirmLabel:'删除机器',
    }))
  })
  it('does not fetch inventory for invalid machine identifiers',async()=>{
    await mount('/server/machine/0')
    expect(getMachines).not.toHaveBeenCalled()
    expect(host.textContent).toContain('机器 ID 无效')
  })
  it('distinguishes unknown connection from offline',async()=>{
    await mount('/server/machine/8')
    expect(host.textContent).toContain('未连接')
    expect(host.textContent).not.toContain('机器离线')
  })
})
