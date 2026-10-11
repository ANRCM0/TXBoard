import {QueryClient, QueryClientProvider} from '@tanstack/react-query'
import {act} from 'react'
import {createRoot,type Root} from 'react-dom/client'
import {Simulate} from 'react-dom/test-utils'
import {afterEach,beforeEach,describe,expect,it,vi} from 'vitest'
import {savePlan} from '../../api/finance'
import type {PlanItem} from '../../api/finance'
import {requestConfirm} from '../ui/ConfirmDialog'
import {PlanEditor} from './PlanEditor'

vi.mock('../../api/finance',()=>({savePlan:vi.fn()}))
vi.mock('../ui/ConfirmDialog',()=>({requestConfirm:vi.fn()}))
vi.mock('sonner',()=>({toast:{success:vi.fn(),error:vi.fn()}}))

let root:Root,host:HTMLDivElement,client:QueryClient
let onClose:ReturnType<typeof vi.fn>,onSaved:ReturnType<typeof vi.fn>
const existing:PlanItem={
  id:9,name:'VIP Legacy',transfer_enable:150,group_id:77,reset_traffic_method:9,
  prices:{monthly:10,half_yearly:55,reset_traffic:3},force_update:false,
}
async function flush(){await act(async()=>{await new Promise(resolve=>setTimeout(resolve,0))})}
async function mount(plan:PlanItem|null=null){
  await act(async()=>{
    root.render(<QueryClientProvider client={client}><PlanEditor open plan={plan}
      groups={[]} onClose={onClose} onSaved={onSaved}/></QueryClientProvider>)
  })
  await flush()
}
function dialog(){return document.querySelector<HTMLElement>('.plan-v4-drawer')!}
function form(){return dialog().querySelector<HTMLFormElement>('form.plan-v4-form')!}
function setInput(name:string,value:string){
  const el=dialog().querySelector<HTMLInputElement>('input[name="'+name+'"]')!
  act(()=>{el.value=value;Simulate.change(el)})
}
beforeEach(()=>{
  ;(globalThis as {IS_REACT_ACT_ENVIRONMENT?:boolean}).IS_REACT_ACT_ENVIRONMENT=true
  vi.clearAllMocks()
  vi.mocked(savePlan).mockResolvedValue(9)
  host=document.createElement('div');document.body.append(host);root=createRoot(host)
  client=new QueryClient({defaultOptions:{queries:{retry:false},mutations:{retry:false}}})
  onClose=vi.fn();onSaved=vi.fn()
})
afterEach(()=>{
  act(()=>root.unmount())
  client.clear()
  host.remove()
  document.querySelectorAll('.modal-root').forEach(n=>n.remove())
})

describe('guided plan editor UX',()=>{
  it('uses a right drawer with compact sections and closed optional fields on creation',async()=>{
    await mount()
    expect(document.querySelector('.modal-placement-right .plan-v4-drawer')).not.toBeNull()
    expect(dialog().textContent).toContain('基础信息')
    expect(dialog().textContent).toContain('售卖周期与价格')
    expect(dialog().textContent).toContain('访问与使用限制')
    expect(dialog().querySelectorAll('input[name^="price-"]')).toHaveLength(3)
    expect(dialog().querySelector('input[name="speed_limit"]')).toBeNull()
    const expand=Array.from(dialog().querySelectorAll<HTMLButtonElement>('.plan-v4-disclosure'))
      .find(b=>b.textContent?.includes('其他计费周期'))!
    act(()=>expand.click())
    expect(dialog().querySelectorAll('input[name^="price-"]')).toHaveLength(8)
  })
  it('retains unknown legacy enum and optional price entries without resetting them',async()=>{
    await mount(existing)
    expect(dialog().querySelector<HTMLSelectElement>('select[name="group_id"]')?.value).toBe('77')
    expect(dialog().querySelector<HTMLSelectElement>('select[name="reset_traffic_method"]')?.value).toBe('9')
    expect(dialog().querySelector<HTMLInputElement>('input[name="price-half_yearly"]')?.value).toBe('55')
    expect(dialog().querySelector<HTMLInputElement>('input[name="price-reset_traffic"]')?.value).toBe('3')
    await act(async()=>Simulate.submit(form()))
    await flush()
    const payload=vi.mocked(savePlan).mock.calls[0]?.[0]
    expect(payload).toMatchObject({
      id:9,group_id:77,reset_traffic_method:9,prices:{monthly:10,half_yearly:55,reset_traffic:3},
    })
    expect(payload).not.toHaveProperty('force_update')
  })
  it('reports inline errors and focuses first invalid field instead of submitting',async()=>{
    await mount()
    setInput('plan-name','')
    setInput('transfer_enable','-2')
    await act(async()=>Simulate.submit(form()))
    expect(savePlan).not.toHaveBeenCalled()
    expect(dialog().querySelectorAll('[role="alert"]').length).toBeGreaterThanOrEqual(2)
    expect(document.activeElement).toBe(dialog().querySelector('input[name="plan-name"]'))
    setInput('plan-name','Starter')
    setInput('transfer_enable','100')
    await act(async()=>Simulate.submit(form()))
    await flush()
    expect(vi.mocked(savePlan).mock.calls[0]?.[0]).toMatchObject({name:'Starter',transfer_enable:100})
  })
  it('guards dismissal after edits and allows normal close for untouched form',async()=>{
    await mount()
    const close=dialog().querySelector<HTMLButtonElement>('.modal-header button[aria-label="关闭"]')!
    act(()=>close.click())
    expect(onClose).toHaveBeenCalledTimes(1)
    onClose.mockClear()
    setInput('plan-name','Edited')
    act(()=>close.click())
    expect(onClose).not.toHaveBeenCalled()
    expect(requestConfirm).toHaveBeenCalledWith(expect.objectContaining({
      title:'放弃未保存的套餐修改？',danger:true,
    }))
    const request=vi.mocked(requestConfirm).mock.calls[0][0]
    act(()=>request.action())
    expect(onClose).toHaveBeenCalledTimes(1)
  })
  it('generates multi-period discounts only when selected and expands extras',async()=>{
    await mount()
    setInput('price-monthly','10')
    const generate=Array.from(dialog().querySelectorAll<HTMLButtonElement>('button'))
      .find(b=>b.textContent?.includes('按月价生成'))!
    act(()=>generate.click())
    expect(dialog().querySelector<HTMLInputElement>('input[name="price-yearly"]')?.value).toBe('102.00')
    expect(dialog().querySelector<HTMLInputElement>('input[name="price-half_yearly"]')?.value).toBe('54.00')
    expect(dialog().querySelector('.plan-v4-disclosure[aria-expanded="true"]')).not.toBeNull()
  })
})
