import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { Simulate } from 'react-dom/test-utils'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getMachines, saveMachine } from '../../api/server'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { MachinesPage } from './MachinesPage'
import type { MachineItem } from '../../api/server'

vi.mock('../../api/server', () => ({
  getMachines: vi.fn(),
  saveMachine: vi.fn().mockResolvedValue({ok:true}),
  deleteMachine: vi.fn().mockResolvedValue({ok:true}),
}))
vi.mock('sonner', () => ({toast:{success:vi.fn(),error:vi.fn()}}))
vi.mock('../../components/ui/ConfirmDialog', () => ({requestConfirm:vi.fn()}))
vi.mock('./MachineOpsDrawer', () => ({
  MachineOpsDrawer: ({machine,onClose}: {machine:MachineItem|null;onClose:()=>void}) =>
    machine ? <div role="dialog" aria-label="机器运维详情"><span>{machine.name}</span>
      <button onClick={onClose}>关闭运维抽屉</button></div> : null,
}))
vi.mock('../../components/server/MachineNodeBinding', () => ({
  MachineNodeBinding: () => <div>节点绑定</div>,
}))

let client: QueryClient
let host: HTMLDivElement
let root: Root
const now = Date.now()
const sample:MachineItem[] = [
  {id:11,name:'Tokyo Host',notes:'日本入口',is_active:true,last_seen_at:now-10_000,
    image_channel:'stable',servers_count:3,load_status:{cpu:0,mem:{used:0,total:100},disk:{used:95,total:100}}},
  {id:12,name:'Unregistered Host',is_active:true,image_channel:'dev',servers_count:undefined},
  {id:13,name:'Disabled Host',is_active:false,last_seen_at:now,servers_count:1,load_status:{cpu:88}},
]

beforeEach(() => {
  ;(globalThis as {IS_REACT_ACT_ENVIRONMENT?: boolean}).IS_REACT_ACT_ENVIRONMENT = true
  vi.clearAllMocks()
  vi.mocked(getMachines).mockResolvedValue(sample)
  client = new QueryClient({defaultOptions:{queries:{retry:false}}})
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
})
afterEach(() => {
  act(() => root.unmount())
  client.clear()
  host.remove()
})
async function mount() {
  await act(async () => {
    root.render(<QueryClientProvider client={client}><MemoryRouter><MachinesPage/></MemoryRouter></QueryClientProvider>)
  })
  await act(async () => { await new Promise(resolve => setTimeout(resolve,0)) })
}
function search(value:string) {
  const input = host.querySelector<HTMLInputElement>('input[aria-label="搜索服务器名称、备注或 SID"]')!
  act(() => { input.value = value; Simulate.change(input) })
}

describe('UI v3 Machine Management', () => {
  it('renders a single summary, combined resource load and explicit not-connected state', async () => {
    await mount()
    expect(host.querySelectorAll('.machines-v3-summary-item')).toHaveLength(5)
    expect(Array.from(host.querySelectorAll('.machines-v3-summary-item strong')).map(x=>x.textContent))
      .toEqual(['3','1','2','2','4'])
    expect(host.querySelectorAll('.machines-v3-table tbody tr')).toHaveLength(3)
    expect(host.querySelectorAll('.machines-v3-table th')).toHaveLength(7)
    const machineRows = Array.from(host.querySelectorAll<HTMLTableRowElement>('.machines-v3-table tbody tr'))
    expect(machineRows[0].textContent).toContain('在线')
    expect(machineRows[1].textContent).toContain('未连接')
    expect(machineRows[2].textContent).toContain('已停用')
    const firstLoad=machineRows[0].querySelector('.machines-v3-load')!
    expect(firstLoad.textContent).toContain('CPU0%')
    expect(firstLoad.textContent).toContain('内存0%')
    expect(firstLoad.textContent).toContain('磁盘95%')
    const secondLoad=machineRows[1].querySelector('.machines-v3-load')!
    expect(secondLoad.textContent).not.toContain('0%')
    expect(secondLoad.textContent).toContain('—')
    expect(machineRows[1].textContent).toContain('未上报')
    expect(host.querySelector('[role="region"]')?.getAttribute('aria-label')).toBe('服务器列表')
  })

  it('has separate filtered-empty and first-use empty states with no tall fake table', async () => {
    await mount()
    search('nothing-matches')
    expect(host.textContent).toContain('没有符合条件的服务器')
    expect(host.querySelector('.machines-v3-table.is-empty')).not.toBeNull()
    expect(host.querySelector('table')).toBeNull()
    expect(host.textContent).toContain('显示 0 / 3 台服务器')
    const reset = Array.from(host.querySelectorAll<HTMLButtonElement>('button'))
      .find(button=>button.textContent?.trim()==='清除筛选')!
    act(() => reset.click())
    expect(host.querySelectorAll('.machines-v3-table tbody tr')).toHaveLength(3)
  })

  it('opens existing machine ops drawer and editor and leaves destructive confirmation intact', async () => {
    await mount()
    const ops=host.querySelector<HTMLButtonElement>('button[aria-label="查看 Tokyo Host 运维详情"]')!
    act(() => ops.click())
    expect(host.querySelector('[role="dialog"][aria-label="机器运维详情"]')?.textContent).toContain('Tokyo Host')
    act(() => host.querySelector<HTMLButtonElement>('[role="dialog"] button')!.click())
    expect(host.querySelector('[role="dialog"][aria-label="机器运维详情"]')).toBeNull()

    const edit=host.querySelector<HTMLButtonElement>('button[aria-label="编辑 Tokyo Host"]')!
    act(() => edit.click())
    expect(document.querySelector('[role="dialog"]')?.textContent).toContain('编辑服务器')
    const switchControl=document.querySelector<HTMLButtonElement>('[role="switch"][aria-label="启用服务器"]')
    expect(switchControl).not.toBeNull()

    const deleteButton=host.querySelector<HTMLButtonElement>('button[aria-label="删除 Tokyo Host"]')!
    act(() => deleteButton.click())
    expect(requestConfirm).toHaveBeenCalledWith(expect.objectContaining({
      title:'删除服务器',danger:true,confirmLabel:'删除',
    }))
    expect(saveMachine).not.toHaveBeenCalled()
  })

  it('can render an initial empty fleet with add-server guidance', async () => {
    vi.mocked(getMachines).mockResolvedValue([])
    await mount()
    expect(host.querySelector('.machines-v3-summary')).toBeNull()
    expect(host.querySelector('table')).toBeNull()
    expect(host.textContent).toContain('还没有服务器')
    expect(host.textContent).toContain('添加服务器')
  })
})
