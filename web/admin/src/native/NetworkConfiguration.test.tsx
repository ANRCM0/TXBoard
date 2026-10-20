import { act } from 'react'
import { createHash } from 'node:crypto'
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
const orderingRevision = () => createHash('sha256').update(JSON.stringify(routes.map(row => [row.id, row.sort]))).digest('hex')
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
    if (operation === 'network.routes.ordering.get') return { data: { revision: orderingRevision(), route_ids: routes.map(row => row.id) }, meta: {} } as never
    if (operation === 'network.nodes.list') return { data: [{ id: bigId, name: 'observed node' }], meta: { total: 1, last_page: 1 } } as never
    if (operation === 'network.routes.simulate') return { data: simulation, meta: {} } as never
    if (operation === 'network.access-audit.rules.list') return { data: [{ id: bigId, name: 'private rule', match_type: 'domain', match_value: 'example.test', enabled: true }], meta: {} } as never
    return { data: [], meta: { total: 0, last_page: 1 } } as never
  })
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks(); vi.unstubAllGlobals() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(page: 'groups' | 'routes' | 'audit') {
  await act(async () => root.render(<QueryClientProvider client={client}>{page === 'groups' ? <GroupsPage /> : page === 'routes' ? <RoutesPage /> : <AccessAuditPage />}</QueryClientProvider>)); await settle()
}
function button(text: string) {
  const scope = document.querySelector<HTMLElement>('[role="dialog"][aria-modal="true"]') ?? host
  return [...scope.querySelectorAll<HTMLButtonElement>('button')].find(node =>
    node.getAttribute('aria-label') === text || node.textContent?.trim() === text)!
}
async function click(text: string) { await act(async () => button(text).click()); await settle() }
function label(text: string) {
  const scope = document.querySelector<HTMLElement>('[role="dialog"][aria-modal="true"]') ?? host
  return [...scope.querySelectorAll('label')].find(node => node.textContent?.startsWith(text))!
}
async function input(node: HTMLInputElement | HTMLTextAreaElement, value: string) {
  await act(async () => { Object.getOwnPropertyDescriptor(node instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype, 'value')!.set!.call(node, value); node.dispatchEvent(new Event('input', { bubbles: true })) })
}
async function select(node: HTMLSelectElement, value: string) { await act(async () => { node.value = value; node.dispatchEvent(new Event('change', { bubbles: true })) }) }

it('saves the observed group string ID without inventing an unsupported row version', async () => {
  await render('groups'); await click('编辑')
  expect(document.querySelector('[role="dialog"][aria-label="编辑分组"]')).not.toBeNull()
  await input(label('分组名称').querySelector('input')!, 'reviewed group')
  await click('保存分组')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.groups.save', { id: bigId, name: 'reviewed group' }, expect.any(String))
  expect(vi.mocked(admin.write).mock.calls[0]![1]).not.toHaveProperty('expected_version')
  expect(document.querySelector('[role="dialog"][aria-label="编辑分组"]')).toBeNull()
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
  expect(document.querySelector('[role="dialog"][aria-label="编辑分组"]')).toBeNull()
})

it('sorts the complete fresh route set with exact IDs and zero-based integer positions', async () => {
  await render('routes')
  // Display filtering must never turn the full-set ordering command into a subset write.
  await input(host.querySelector<HTMLInputElement>('input[aria-label="搜索路由"]')!, 'route-' + bigId)
  expect(host.querySelectorAll('tbody tr')).toHaveLength(1)
  await click('编辑完整路由排序'); await click('下移'); await click('保存完整路由排序')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.routes.ordering.replace', { expected_revision: orderingRevision(), route_ids: ['2', bigId] }, expect.any(String))
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
  expect(host.textContent).toMatch(/请求目标\s*https:\/\/example\.test\/path/)
  expect(host.textContent).toMatch(/归一化目标\s*example\.test/)
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


it('supports pointer route reordering while retaining the complete large-ID route set', async () => {
  await render('routes'); await click('编辑完整路由排序')
  const rows = host.querySelectorAll('.network-sort-list > li')
  const transfer = { effectAllowed: '', dropEffect: '', setData: vi.fn() }
  function drag(node: Element, type: string) { const event = new Event(type, { bubbles: true, cancelable: true }); Object.defineProperty(event, 'dataTransfer', { value: transfer }); node.dispatchEvent(event) }
  await act(async () => { drag(rows[0]!.querySelector('[draggable]')!, 'dragstart'); drag(rows[1]!, 'dragover'); drag(rows[1]!, 'drop') })
  await click('保存完整路由排序')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.routes.ordering.replace', { expected_revision: orderingRevision(), route_ids: ['2', bigId] }, expect.any(String))
})

it('shows route action parameters only for actions that require them and rejects excess matches', async () => {
  await render('routes'); await click('新增路由')
  expect(label('动作参数')).toBeUndefined()
  await select(label('动作').querySelector('select')!, 'dns')
  expect(label('动作参数').querySelector('input')!.required).toBe(true)
  await input(label('备注').querySelector('input')!, 'example DNS')
  await input(label('动作参数').querySelector('input')!, '1.1.1.1')
  await input(label('匹配规则').querySelector('textarea')!, Array(201).fill('domain:example.test').join('\n'))
  await click('保存路由')
  expect(admin.write).not.toHaveBeenCalled()
  expect(document.querySelector('[role="dialog"]')!.textContent).toContain('请输入 1 至 200 项')
})

it('preserves a full ordering draft when another administrator changes the order of the same IDs', async () => {
  await render('routes'); await click('编辑完整路由排序'); await click('下移')
  routes[0]!.sort = 99
  await click('保存完整路由排序')
  expect(admin.write).not.toHaveBeenCalled()
  expect(button('保存完整路由排序').disabled).toBe(true)
  expect(host.querySelectorAll('.network-sort-list > li')).toHaveLength(2)
  expect(host.textContent).toContain('当前排序草稿已保留')
  await click('重新读取完整排序')
  expect(window.confirm).toHaveBeenCalled()
  expect(button('保存完整路由排序').disabled).toBe(false)
})

it('handles an owner revision conflict after preflight and requires a new reviewed ordering before retry', async () => {
  await render('routes'); await click('编辑完整路由排序'); await click('下移')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('NETWORK_ROUTE_ORDERING_CONFLICT', 409))
  await click('保存完整路由排序')
  expect(button('保存完整路由排序').disabled).toBe(true)
  expect(workflowActions.snapshot().pending).toBeUndefined()
  const original = vi.mocked(admin.write).mock.calls[0]
  await click('重新读取完整排序'); await click('下移'); await click('保存完整路由排序')
  expect(admin.write).toHaveBeenCalledTimes(2)
  expect(vi.mocked(admin.write).mock.calls[1]![2]).not.toBe(original![2])
})

it('recovers an unknown ordering after navigation with the same complete IDs revision and request key', async () => {
  await render('routes'); await click('编辑完整路由排序'); await click('下移')
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost response')); await click('保存完整路由排序')
  const original = vi.mocked(admin.write).mock.calls[0]
  await render('groups'); await render('routes'); await click('确认原路由操作')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('combines searchable multi-select route facets over the complete set and removes individual filters', async () => {
  routes[1]!.action = 'block'; routes.push({ ...route('3'), action: 'dns', enabled: false })
  await render('routes'); await click('动作筛选')
  await act(async () => label('直连').querySelector<HTMLInputElement>('input')!.click())
  await act(async () => label('指定 DNS').querySelector<HTMLInputElement>('input')!.click()); await settle()
  expect(host.querySelectorAll('tbody tr')).toHaveLength(2)
  await input(host.querySelector<HTMLInputElement>('input[aria-label="搜索动作筛选"]')!, '阻止')
  expect(host.querySelector('.network-facet-options')?.textContent).toContain('阻止访问')
  expect(host.querySelector('.network-facet-options')?.textContent).not.toContain('直连')
  await click('动作筛选'); await click('状态筛选')
  await act(async () => label('停用').querySelector<HTMLInputElement>('input')!.click()); await settle()
  expect(host.querySelectorAll('tbody tr')).toHaveLength(1)
  expect(host.textContent).toContain('筛选完整路由集合')
  await click('状态筛选'); await click('移除筛选 停用')
  expect(host.querySelectorAll('tbody tr')).toHaveLength(2)
})

it('keeps DNS and proxy parameter drafts separate and omits parameters for a direct route', async () => {
  await render('routes'); await click('新增路由')
  await input(label('备注').querySelector('input')!, 'reviewed route')
  await input(label('匹配规则').querySelector('textarea')!, 'example.test')
  await select(label('动作').querySelector('select')!, 'dns'); await input(label('动作参数').querySelector('input')!, '1.1.1.1')
  await select(label('动作').querySelector('select')!, 'proxy'); await input(label('动作参数').querySelector('input')!, 'proxy-outbound')
  await select(label('动作').querySelector('select')!, 'dns')
  expect(label('动作参数').querySelector('input')!.value).toBe('1.1.1.1')
  await select(label('动作').querySelector('select')!, 'direct'); await click('保存路由')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.routes.save', { remarks: 'reviewed route', match: ['example.test'], action: 'direct', action_value: null, enabled: true }, expect.any(String))
})

it('uses cards with visible operations on mobile for both group and route records', async () => {
  vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() })))
  await render('groups')
  expect(host.querySelectorAll('.ui-mobile-record')).toHaveLength(1)
  expect(host.querySelector('.ui-mobile-record footer')?.textContent).toContain('编辑')
  expect(host.querySelector('table')).toBeNull()
  await render('routes')
  expect(host.querySelectorAll('.ui-mobile-record')).toHaveLength(2)
  expect(host.querySelector('.ui-mobile-record footer')?.textContent).toContain('删除')
})

it('requires confirmation before discarding changed group and route drafts', async () => {
  await render('groups'); await click('编辑'); await input(label('分组名称').querySelector('input')!, 'unsaved group')
  vi.mocked(window.confirm).mockReturnValue(false); await click('取消')
  expect(document.querySelector('[role="dialog"][aria-label="编辑分组"]')).not.toBeNull()
  vi.mocked(window.confirm).mockReturnValue(true); await click('取消'); await render('routes'); await click('编辑')
  await input(label('备注').querySelector('input')!, 'unsaved route'); vi.mocked(window.confirm).mockReturnValue(false); await click('取消')
  expect(document.querySelector('[role="dialog"][aria-label="编辑路由"]')).not.toBeNull()
  expect(admin.write).not.toHaveBeenCalled()
})
