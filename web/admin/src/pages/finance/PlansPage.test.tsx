import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { Simulate } from 'react-dom/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { deletePlan, getPlans, sortPlans, updatePlanFlags } from '../../api/finance'
import { toast } from 'sonner'
import { getGroups } from '../../api/server'
import type { PlanItem } from '../../api/finance'
import { PlansPage } from './PlansPage'

vi.mock('../../api/finance', () => ({
  getPlans: vi.fn(),
  sortPlans: vi.fn(),
  deletePlan: vi.fn(),
  updatePlanFlags: vi.fn(),
}))
vi.mock('../../api/server', () => ({ getGroups: vi.fn() }))
vi.mock('../../components/finance/PlanEditor', () => ({ PlanEditor: () => null }))
vi.mock('../../components/ui/ConfirmDialog', () => ({ requestConfirm: vi.fn() }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

const plans = [
  { id: 1, name: '基础版', prices: {monthly: 9}, transfer_enable: 50, show: true, sell: true, renew: true, tags: ['入门'] },
  { id: 2, name: '专业版', prices: {monthly: 29}, transfer_enable: 100, show: false, sell: true, renew: true, tags: ['热门'] },
  { id: 3, name: '企业版', prices: {}, transfer_enable: 300, show: true, sell: false, renew: false },
] as PlanItem[]

let root: Root
let host: HTMLDivElement
let client: QueryClient

async function flush() {
  await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)) })
}
async function renderPage() {
  await act(async () => {
    root.render(<QueryClientProvider client={client}><PlansPage/></QueryClientProvider>)
  })
  await flush()
}

beforeEach(() => {
  ;(globalThis as {IS_REACT_ACT_ENVIRONMENT?:boolean}).IS_REACT_ACT_ENVIRONMENT = true
  vi.clearAllMocks()
  vi.mocked(getPlans).mockResolvedValue(plans)
  vi.mocked(getGroups).mockResolvedValue([])
  vi.mocked(sortPlans).mockResolvedValue(undefined as never)
  vi.mocked(deletePlan).mockResolvedValue(undefined as never)
  vi.mocked(updatePlanFlags).mockResolvedValue(undefined as never)
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
})

afterEach(() => {
  act(() => root.unmount())
  client.clear()
  host.remove()
})

describe('accessible plan management', () => {
  it('uses lightweight price text and named icon/switch controls', async () => {
    await renderPage()
    expect(host.querySelectorAll('tbody tr')).toHaveLength(3)
    expect(host.querySelector('.plan-price-badge')).toBeNull()
    expect(host.textContent).toContain('月付 ¥ 9.00')
    expect(host.querySelector('[aria-label="编辑套餐 基础版"]')).not.toBeNull()
    expect(host.querySelector('[aria-label="删除套餐 基础版"]')).not.toBeNull()
    const switchEl = host.querySelector<HTMLButtonElement>('[role="switch"][aria-label="显示套餐 基础版"]')!
    expect(switchEl.getAttribute('aria-checked')).toBe('true')
    expect(host.querySelector('[role="region"][aria-label="套餐列表"]')).not.toBeNull()
    act(() => switchEl.click())
    await flush()
    expect(updatePlanFlags).toHaveBeenCalledWith(1, { show: false })
  })

  it('provides keyboard-clickable up/down controls with a persisted full order', async () => {
    await renderPage()
    expect(host.querySelector<HTMLButtonElement>('[aria-label="上移套餐 基础版"]')!.disabled).toBe(true)
    expect(host.querySelector<HTMLButtonElement>('[aria-label="下移套餐 企业版"]')!.disabled).toBe(true)
    const move = host.querySelector<HTMLButtonElement>('[aria-label="上移套餐 专业版"]')!
    act(() => move.click())
    await flush()
    expect(vi.mocked(sortPlans).mock.calls[0]?.[0]).toEqual([2, 1, 3])
  })

  it('distinguishes filtered-empty from initial empty and disables sorting under filters', async () => {
    await renderPage()
    const search = host.querySelector<HTMLInputElement>('[aria-label="搜索套餐名称、编号或标签"]')!
    act(() => { search.value = 'not-found'; Simulate.change(search) })
    expect(host.textContent).toContain('没有找到匹配的套餐')
    expect(host.querySelector('table')).toBeNull()
    const clear = Array.from(host.querySelectorAll<HTMLButtonElement>('button'))
      .find(button => button.textContent?.trim() === '清除搜索')!
    act(() => clear.click())
    expect(host.querySelectorAll('tbody tr')).toHaveLength(3)
  })

  it('locks reordering while a search filter is active', async () => {
    await renderPage()
    const search = host.querySelector<HTMLInputElement>('[aria-label="搜索套餐名称、编号或标签"]')!
    act(() => { search.value = '专业'; Simulate.change(search) })
    expect(host.querySelectorAll('tbody tr')).toHaveLength(1)
    expect(host.querySelector<HTMLButtonElement>('[aria-label="上移套餐 专业版"]')?.disabled).toBe(true)
    expect(host.querySelector<HTMLButtonElement>('[aria-label="下移套餐 专业版"]')?.disabled).toBe(true)
    expect(sortPlans).not.toHaveBeenCalled()
  })

  it('restores prior order and gives feedback if sort persistence fails', async () => {
    vi.mocked(sortPlans).mockRejectedValueOnce(new Error('sort rejected'))
    await renderPage()
    act(() => host.querySelector<HTMLButtonElement>('[aria-label="上移套餐 专业版"]')!.click())
    await flush()
    expect(vi.mocked(sortPlans).mock.calls[0]?.[0]).toEqual([2, 1, 3])
    expect(toast.error).toHaveBeenCalledWith('排序保存失败，已恢复此前顺序')
    const names = Array.from(host.querySelectorAll('.plan-name strong')).map(node => node.textContent)
    expect(names).toEqual(['基础版', '专业版', '企业版'])
  })

  it('shows a retryable error without a false first-use empty state', async () => {
    vi.mocked(getPlans).mockRejectedValueOnce(new Error('unavailable'))
    await renderPage()
    expect(host.querySelector('[role="alert"]')).not.toBeNull()
    expect(host.textContent).not.toContain('还没有套餐')
    expect(host.querySelector('table')).toBeNull()
  })

  it('keeps first-use empty state out of a pointless table frame', async () => {
    vi.mocked(getPlans).mockResolvedValue([])
    await renderPage()
    expect(host.textContent).toContain('还没有套餐')
    expect(host.querySelector('table')).toBeNull()
    expect(host.querySelector('.plans-v4-empty')).not.toBeNull()
  })
})
