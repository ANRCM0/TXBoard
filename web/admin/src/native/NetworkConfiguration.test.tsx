import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { GroupsPage } from './GroupsPage'
import { RoutesPage } from './RoutesPage'
import { AccessAuditPage } from './AccessAuditPage'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
let host: HTMLDivElement, root: Root, client: QueryClient
const bigId = '9007199254740993'
const route = (id: string) => ({ id, remarks: 'route-' + id, match: ['domain:example.test'], action: 'direct',
  action_value: null, enabled: true, sort: 0, server_count: 0, created_at: null, updated_at: null })
let routes: ReturnType<typeof route>[]
const simulation = { node: { id: bigId, name: 'observed node' }, target: 'example.test', authoritative: false,
  warning: 'custom routes take precedence', match: null, evaluated_routes: [], unresolved_patterns: [] }
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  workflowActions.reset(); routes = [route(bigId), route('2')]
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: { ok: true } } as never)
  vi.mocked(admin.read).mockReset().mockImplementation(async operation => {
    if (operation === 'network.groups.list') return { data: [{ id: bigId, name: 'private group', users_count: 0, plans_count: 0, server_count: 0 }], meta: { total: 1, last_page: 1 } } as never
    if (operation === 'network.routes.list') return { data: routes.map(row => ({ ...row })), meta: {} } as never
    if (operation === 'network.nodes.list') return { data: [{ id: bigId, name: 'observed node' }], meta: { total: 1, last_page: 1 } } as never
    if (operation === 'network.routes.simulate') return { data: simulation, meta: {} } as never
    if (operation === 'network.access-audit.rules.list') return { data: [{ id: bigId, name: 'private rule', match_type: 'domain', match_value: 'example.test', enabled: true }], meta: {} } as never
    return { data: [], meta: { total: 0, last_page: 1 } } as never
  })
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(page: 'groups' | 'routes' | 'audit') {
  await act(async () => root.render(<QueryClientProvider client={client}>{page === 'groups' ? <GroupsPage /> : page === 'routes' ? <RoutesPage /> : <AccessAuditPage />}</QueryClientProvider>)); await settle()
}
function button(text: string) { return [...host.querySelectorAll<HTMLButtonElement>('button')].find(node => node.textContent === text)! }
async function click(text: string) { await act(async () => button(text).click()); await settle() }
function label(text: string) { return [...host.querySelectorAll('label')].find(node => node.textContent?.startsWith(text))! }
async function input(node: HTMLInputElement | HTMLTextAreaElement, value: string) {
  await act(async () => { Object.getOwnPropertyDescriptor(node instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype, 'value')!.set!.call(node, value); node.dispatchEvent(new Event('input', { bubbles: true })) })
}
async function select(node: HTMLSelectElement, value: string) { await act(async () => { node.value = value; node.dispatchEvent(new Event('change', { bubbles: true })) }) }

it('saves the observed group string ID without inventing an unsupported row version', async () => {
  await render('groups'); await click('编辑')
  await input(label('分组名称').querySelector('input')!, 'reviewed group')
  await click('保存分组')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.groups.save', { id: bigId, name: 'reviewed group' }, expect.any(String))
  expect(vi.mocked(admin.write).mock.calls[0]![1]).not.toHaveProperty('expected_version')
})

it('retains an unknown group delete across navigation and reuses only its original operation key', async () => {
  await render('groups')
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost response'))
  await click('删除'); const original = vi.mocked(admin.write).mock.calls[0]
  expect(original![1]).toEqual({ group_id: bigId })
  await render('routes')
  expect(button('确认原路由操作')).toBeUndefined(); expect(button('新增路由').disabled).toBe(true)
  await render('groups'); expect(admin.write).toHaveBeenCalledTimes(1)
  await click('确认原分组操作')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
})

it('hides cached group names and edit forms after permission revocation', async () => {
  await render('groups'); await click('编辑')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await client.invalidateQueries() }); await settle()
  expect(host.textContent).not.toContain('private group')
  expect(button('保存分组')).toBeUndefined(); expect(admin.write).not.toHaveBeenCalled()
})

it('sorts the complete fresh route set with exact IDs and zero-based integer positions', async () => {
  await render('routes'); await click('编辑完整路由排序'); await click('下移'); await click('保存完整路由排序')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.routes.sort', { items: [{ id: '2', sort: 0 }, { id: bigId, sort: 1 }] }, expect.any(String))
})

it('rejects a changed route set rather than submitting an old full-set sort', async () => {
  await render('routes'); await click('编辑完整路由排序')
  routes.push(route('3')); await click('保存完整路由排序')
  expect(admin.write).not.toHaveBeenCalled()
  expect(host.textContent).toContain('路由集合已变化')
})

it('does not offer a partial sort when the complete set exceeds the SDK write limit', async () => {
  routes = Array.from({ length: 501 }, (_, index) => route(String(index + 1)))
  await render('routes')
  expect(button('编辑完整路由排序').disabled).toBe(true)
  expect(host.textContent).toContain('不能以子集替代完整排序')
})

it('binds simulation to a read node and original target while accepting server target normalization', async () => {
  await render('routes')
  await select(label('已读取节点').querySelector('select')!, bigId)
  await input(label('目标').querySelector('input')!, 'https://example.test/path')
  await click('运行只读模拟')
  expect(admin.read).toHaveBeenCalledWith('network.routes.simulate', { node_id: bigId, target: 'https://example.test/path' }, expect.any(AbortSignal))
  expect(host.textContent).toContain('请求目标 https://example.test/path')
  expect(host.textContent).toContain('归一化目标 example.test')
  expect(host.textContent).toContain('非权威参考结果')
  expect(admin.write).not.toHaveBeenCalled()
})

it('aborts and hides a delayed simulation when its target changes', async () => {
  await render('routes')
  await select(label('已读取节点').querySelector('select')!, bigId)
  await input(label('目标').querySelector('input')!, 'old.example.test')
  let resolve!: (value: never) => void
  vi.mocked(admin.read).mockImplementationOnce(() => new Promise(done => { resolve = done }))
  await click('运行只读模拟')
  const call = vi.mocked(admin.read).mock.calls.at(-1)!
  await input(label('目标').querySelector('input')!, 'new.example.test')
  expect(call[2]!.aborted).toBe(true)
  await act(async () => { resolve({ data: simulation, meta: {} } as never) }); await settle()
  expect(host.textContent).not.toContain('custom routes take precedence')
})

it('withdraws a cached simulation when fresh node authorization fails', async () => {
  await render('routes')
  await select(label('已读取节点').querySelector('select')!, bigId)
  await input(label('目标').querySelector('input')!, 'example.test')
  await click('运行只读模拟')
  expect(host.textContent).toContain('custom routes take precedence')
  const original = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation((operation, ...args) => {
    if (operation === 'network.nodes.list') return Promise.reject(new CoreAdminApiError('FORBIDDEN', 403))
    return original(operation, ...args)
  })
  await act(async () => { await client.invalidateQueries({ queryKey: ['native-admin', 'network-route-nodes'] }) }); await settle()
  expect(host.textContent).not.toContain('custom routes take precedence')
  expect(host.textContent).not.toContain('归一化目标')
  expect(button('运行只读模拟').disabled).toBe(true)
})

it('sends access audit IDs as strings and preserves an explicit unmatched filter', async () => {
  await render('audit')
  await input(label('节点 ID').querySelector('input')!, bigId)
  await input(label('用户 ID').querySelector('input')!, '9223372036854775807')
  await select(label('规则命中').querySelector('select')!, 'no')
  await click('筛选审计事件')
  expect(admin.read).toHaveBeenCalledWith('network.access-audit.events.list', { page: 1, per_page: 20, server_id: bigId, user_id: '9223372036854775807', matched: false }, expect.any(AbortSignal))
})

it('keeps forbidden unknown rule writes blocked until a fresh audit authorization check succeeds', async () => {
  await render('audit'); await click('编辑')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403, undefined, true))
  await click('保存审计规则'); const original = vi.mocked(admin.write).mock.calls[0]
  expect(button('确认原审计规则操作').disabled).toBe(true)
  expect(host.textContent).not.toContain('private rule')
  await click('刷新访问审计'); expect(admin.write).toHaveBeenCalledTimes(1)
  await click('确认原审计规则操作')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
})

it('does not retry a rejected referenced group delete or invent optimistic success', async () => {
  await render('groups')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('BUSINESS_REJECTED', 409))
  await click('删除')
  expect(admin.write).toHaveBeenCalledTimes(1)
  expect(workflowActions.snapshot().pending).toBeUndefined()
  expect(host.textContent).toContain('private group')
  expect(host.textContent).toContain('记录已变化')
})
