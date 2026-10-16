import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { Simulate } from 'react-dom/test-utils'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getPlans } from '../../api/finance'
import { banUsers, getUsers } from '../../api/user-admin'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import type { AdminUser, UserPageResult } from '../../api/user-admin'
import { UsersPage } from './UsersPage'

vi.mock('../../api/finance', () => ({ getPlans: vi.fn() }))
vi.mock('../../api/user-admin', () => ({
  getUsers: vi.fn(),
  getUserDetail: vi.fn(),
  destroyUser: vi.fn(),
  banUsers: vi.fn(),
}))
vi.mock('../../components/users/UserEditorModal', () => ({ UserEditorModal: () => null }))
vi.mock('../../components/users/UserMailModal', () => ({ UserMailModal: () => null }))
vi.mock('../../components/ui/ConfirmDialog', () => ({ requestConfirm: vi.fn() }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

const users: AdminUser[] = [
  { id: 7, email: 'first@example.com', plan_id: 2, plan: {id:2,name:'Pro'}, balance:12.5,
    transfer_enable: 10 * 1024 ** 3, total_used: 1024 ** 3, banned: false, is_staff:true },
  { id: 8, email: 'second@example.com', balance: 0, transfer_enable: 0, total_used: 0, banned: true },
]

let host: HTMLDivElement
let root: Root
let client: QueryClient
let location: string

function Probe() {
  const value = useLocation()
  location = value.pathname + value.search
  return null
}
function pageResult(data: AdminUser[] = users): UserPageResult {
  return { data, total: data.length ? 32 : 0, current_page:1, per_page:10, last_page:data.length ? 4 : 1 }
}
async function flush() {
  await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)) })
}
async function mount(initial = '/user/manage') {
  await act(async () => {
    root.render(
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[initial]}>
          <UsersPage/><Probe/>
        </MemoryRouter>
      </QueryClientProvider>,
    )
  })
  await flush()
}

beforeEach(() => {
  ;(globalThis as {IS_REACT_ACT_ENVIRONMENT?:boolean}).IS_REACT_ACT_ENVIRONMENT = true
  vi.clearAllMocks()
  vi.mocked(getUsers).mockResolvedValue(pageResult())
  vi.mocked(getPlans).mockResolvedValue([{ id: 2, name: 'Pro' } as never])
  vi.mocked(banUsers).mockResolvedValue(1)
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
  client = new QueryClient({defaultOptions:{queries:{retry:false}, mutations:{retry:false}}})
  location = ''
})
afterEach(() => {
  act(() => root.unmount())
  client.clear()
  host.remove()
})

describe('UsersPage UX v4', () => {
  it('restores filters, pagination, ordering and visible search from a shared URL', async () => {
    await mount('/user/manage?page=2&size=20&q=first%40example.com&plan=2&status=0&sort=balance&desc=0')
    expect(getUsers).toHaveBeenCalledWith({
      current:2, pageSize:20,
      filter:[{ id:'email',value:'first@example.com' },{ id:'plan_id',value:'eq:2' },{ id:'banned',value:'eq:0' }],
      sort:[{ id:'balance',desc:false }],
    })
    expect(host.querySelector<HTMLInputElement>('input[aria-label="搜索用户邮箱"]')?.value).toBe('first@example.com')
    expect(host.querySelector<HTMLSelectElement>('select[aria-label="每页条数"]')?.value).toBe('20')
    expect(host.querySelectorAll('thead th')).toHaveLength(10)
    expect(host.querySelector('.user-identity')?.textContent).toContain('#7')
  })

  it('labels each icon-only operation and preserves destructive confirmation', async () => {
    await mount()
    expect(host.querySelector('[aria-label="编辑用户 first@example.com"]')).not.toBeNull()
    expect(host.querySelector('[aria-label="复制订阅链接 first@example.com"]')).toBeNull()
    expect(host.querySelector('[aria-label="重置订阅密钥 first@example.com"]')).toBeNull()
    const remove = host.querySelector<HTMLButtonElement>('[aria-label="删除用户 first@example.com"]')!
    await act(async () => remove.click())
    expect(requestConfirm).toHaveBeenCalledWith(expect.objectContaining({title:'删除用户',danger:true}))
  })

  it('updates the URL without losing other active filter conditions', async () => {
    await mount('/user/manage?page=3&plan=2&status=1')
    const sort = host.querySelector<HTMLSelectElement>('select[aria-label="排序字段"]')!
    await act(async () => { sort.value = 'created_at'; Simulate.change(sort) })
    expect(new URLSearchParams(location.split('?')[1]).get('sort')).toBe('created_at')
    const params = new URLSearchParams(location.split('?')[1])
    expect(params.get('page')).toBeNull()
    expect(params.get('plan')).toBe('2')
    expect(params.get('status')).toBe('1')
  })

  it('submits search through a form, preserving Enter-key behavior and URL restore', async () => {
    await mount('/user/manage?page=2')
    const input = host.querySelector<HTMLInputElement>('input[aria-label="搜索用户邮箱"]')!
    await act(async () => { input.value = 'search@example.com'; Simulate.change(input) })
    const form = host.querySelector<HTMLFormElement>('form.users-v4-search')!
    await act(async () => { Simulate.submit(form) })
    const params = new URLSearchParams(location.split('?')[1])
    expect(params.get('q')).toBe('search@example.com')
    expect(params.has('page')).toBe(false)
  })

  it('keeps empty filters in the compact empty state and allows clearing', async () => {
    vi.mocked(getUsers).mockResolvedValue(pageResult([]))
    await mount('/user/manage?status=1')
    expect(host.querySelector('table')).toBeNull()
    expect(host.querySelector('.user-table-card.is-empty .tx-empty-state')).not.toBeNull()
    expect(host.textContent).toContain('没有符合条件的用户')
    const clear = Array.from(host.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent?.trim()==='清除筛选')!
    await act(async () => clear.click())
    expect(new URLSearchParams(location.split('?')[1]).has('status')).toBe(false)
  })

  it('preserves failure and retry feedback instead of rendering a false empty state', async () => {
    vi.mocked(getUsers).mockRejectedValue(new Error('Network unavailable'))
    await mount()
    expect(host.querySelector('[role="alert"]')).not.toBeNull()
    expect(host.textContent).not.toContain('还没有用户')
    expect(host.querySelector('table')).toBeNull()
  })
})
