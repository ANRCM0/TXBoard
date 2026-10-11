import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getOrders, getPlans } from '../../api/finance'
import { getTickets } from '../../api/ticket'
import { getUserTrafficResetHistory, resetUserTraffic } from '../../api/traffic-reset'
import { getUserDetail, getUserSubscriptionLink, resetUserSecret } from '../../api/user-admin'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { UserDetailPage } from './UserDetailPage'

vi.mock('../../api/finance', () => ({getOrders:vi.fn(), getPlans:vi.fn()}))
vi.mock('../../api/ticket', () => ({getTickets:vi.fn()}))
vi.mock('../../api/traffic-reset', () => ({getUserTrafficResetHistory:vi.fn(), resetUserTraffic:vi.fn()}))
vi.mock('../../api/user-admin', () => ({
  getUserDetail:vi.fn(), getUserSubscriptionLink:vi.fn(), resetUserSecret:vi.fn(),
}))
vi.mock('../../components/users/UserEditorModal', () => ({UserEditorModal:()=>null}))
vi.mock('../../components/finance/OrderDetailModal', () => ({
  OrderDetailModal:({orderId}:{orderId:number|null}) => orderId === null ? null : <div role="dialog">订单详情 #{orderId}</div>,
}))
vi.mock('../../components/ui/ConfirmDialog', () => ({requestConfirm:vi.fn()}))
vi.mock('sonner', () => ({toast:{success:vi.fn(),error:vi.fn()}}))

const user = {
  id:7, email:'long.user@example.com', balance:18.5, commission_balance:2,
  plan_id:5, plan:{id:5,name:'Pro Plan'}, banned:false, is_staff:true,
  transfer_enable:10 * 1024 ** 3, total_used:2 * 1024 ** 3,
  remarks:'长期备注：必须完整显示并保留详情', reset_count:3,
}

let client:QueryClient
let root:Root
let host:HTMLDivElement

async function flush() {
  await act(async () => {await new Promise(resolve => setTimeout(resolve,0))})
}
async function mount(route='/user/7') {
  await act(async()=>{
    root.render(
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[route]}>
          <Routes><Route path="/user/:userId" element={<UserDetailPage/>}/></Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )
  })
  await flush()
}

beforeEach(()=>{
  ;(globalThis as {IS_REACT_ACT_ENVIRONMENT?:boolean}).IS_REACT_ACT_ENVIRONMENT=true
  vi.clearAllMocks()
  vi.mocked(getUserDetail).mockResolvedValue(user)
  vi.mocked(getPlans).mockResolvedValue([{id:5,name:'Pro Plan'} as never])
  vi.mocked(getOrders).mockResolvedValue({data:[]} as never)
  vi.mocked(getTickets).mockResolvedValue({data:[]} as never)
  vi.mocked(getUserTrafficResetHistory).mockResolvedValue({
    user:{id:7,email:user.email,reset_count:3,last_reset_at:null,next_reset_at:null}, history:[],
  })
  vi.mocked(getUserSubscriptionLink).mockResolvedValue('https://subscription.example.test/secret')
  vi.mocked(resetUserSecret).mockResolvedValue(true)
  vi.mocked(resetUserTraffic).mockResolvedValue({user_id:7,email:user.email,reset_time:'2026-10-12',next_reset_at:null})
  host=document.createElement('div');document.body.append(host);root=createRoot(host)
  client=new QueryClient({defaultOptions:{queries:{retry:false},mutations:{retry:false}}})
})
afterEach(()=>{
  act(()=>root.unmount())
  client.clear()
  host.remove()
})

describe('User detail UI v4',()=>{
  it('groups four account metrics without large standalone cards and preserves the account data',async()=>{
    await mount()
    const summary=host.querySelector('dl.user-detail-v4-summary')
    expect(summary).not.toBeNull()
    expect(summary?.querySelectorAll('.user-detail-v4-metric')).toHaveLength(4)
    expect(host.querySelectorAll('.user-detail-summary-card')).toHaveLength(0)
    expect(summary?.textContent).toContain('Pro Plan')
    expect(summary?.textContent).toContain('8.00 GB')
    expect(host.querySelector('.user-detail-v4-meta')?.textContent).toContain(user.remarks)
    expect(host.querySelector('[role="progressbar"]')?.getAttribute('aria-valuenow')).toBe('20')
    expect(host.textContent).toContain('Staff')
  })

  it('renders compact activity empty messages instead of three empty table frames',async()=>{
    await mount()
    expect(host.querySelectorAll('.user-detail-v4-activity')).toHaveLength(3)
    expect(host.querySelectorAll('.user-detail-v4-activity table')).toHaveLength(0)
    expect(host.textContent).toContain('该用户暂无订单记录')
    expect(host.textContent).toContain('该用户暂无工单记录')
    expect(host.textContent).toContain('暂无流量重置记录')
    expect(host.querySelectorAll('.user-detail-v4-empty[role="status"]')).toHaveLength(3)
  })

  it('preserves real data tables and order detail navigation',async()=>{
    vi.mocked(getOrders).mockResolvedValue({data:[{
      id:11,trade_no:'T-100',plan_id:5,plan:{name:'Pro Plan'},total_amount:1299,status:3,created_at:1,
    }]} as never)
    vi.mocked(getTickets).mockResolvedValue({data:[{
      id:21,subject:'Assistance',status:0,reply_status:0,updated_at:1,
    }]} as never)
    vi.mocked(getUserTrafficResetHistory).mockResolvedValue({
      user:{id:7,email:user.email,reset_count:3,last_reset_at:null,next_reset_at:null},
      history:[{id:31,reset_type:'manual',old_traffic:{formatted:'2 GB'},metadata:{reason:'客服操作'},reset_time:1}],
    })
    await mount()
    expect(host.querySelectorAll('.user-detail-v4-activity table')).toHaveLength(3)
    expect(host.querySelector('table[aria-label="最近订单"]')?.textContent).toContain('¥ 12.99')
    expect(host.querySelector('table[aria-label="最近工单"]')?.textContent).toContain('Assistance')
    expect(host.querySelector('table[aria-label="流量重置记录"]')?.textContent).toContain('客服操作')
    const order=Array.from(host.querySelectorAll<HTMLButtonElement>('button'))
      .find(x=>x.textContent==='T-100')!
    await act(async()=>order.click())
    expect(host.querySelector('[role="dialog"]')?.textContent).toContain('订单详情 #11')
  })

  it('requires explicit confirmation before rotation and traffic reset',async()=>{
    await mount()
    const resetKey=Array.from(host.querySelectorAll<HTMLButtonElement>('button'))
      .find(x=>x.textContent?.includes('重置订阅密钥'))!
    const resetTrafficButton=Array.from(host.querySelectorAll<HTMLButtonElement>('button'))
      .find(x=>x.textContent?.includes('重置已用流量'))!
    await act(async()=>{resetKey.click();resetTrafficButton.click()})
    expect(requestConfirm).toHaveBeenCalledWith(expect.objectContaining({
      title:'重置订阅密钥',danger:true,confirmLabel:'重置密钥',
    }))
    expect(requestConfirm).toHaveBeenCalledWith(expect.objectContaining({
      title:'重置用户流量',danger:true,confirmLabel:'执行重置',
    }))
    expect(resetUserSecret).not.toHaveBeenCalled()
    expect(resetUserTraffic).not.toHaveBeenCalled()
  })

  it('never mistakes an activity query failure for no records',async()=>{
    vi.mocked(getOrders).mockRejectedValue(new Error('server unavailable'))
    await mount()
    expect(host.textContent).not.toContain('该用户暂无订单记录')
    const orderSection=Array.from(host.querySelectorAll('.user-detail-v4-activity'))
      .find(x=>x.querySelector('h2')?.textContent==='最近订单')!
    expect(orderSection.querySelector('[role="alert"]')).not.toBeNull()
    expect(orderSection.querySelector('table')).toBeNull()
    expect(orderSection.querySelector('button')?.textContent).toContain('重新加载')
  })

  it('does not query unsafe route identifiers',async()=>{
    await mount('/user/9007199254740992')
    expect(getUserDetail).not.toHaveBeenCalled()
    expect(getOrders).not.toHaveBeenCalled()
    expect(getUserTrafficResetHistory).not.toHaveBeenCalled()
    expect(host.textContent).toContain('无效的用户 ID')
  })
})
