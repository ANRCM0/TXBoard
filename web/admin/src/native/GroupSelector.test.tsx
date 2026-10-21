import { act, useState, useSyncExternalStore } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { GroupCreationRecovery, GroupSelector } from './GroupSelector'
import { admin, workflowActions } from './session'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})

const selectedId = '9007199254740993', createdId = '9007199254740995'
let host: HTMLDivElement, root: Root, client: QueryClient
function Harness() {
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [value, setValue] = useState([selectedId])
  return <><input aria-label="父级草稿" defaultValue="unchanged parent draft" />
    <GroupCreationRecovery onCreated={id => setValue(current => [...new Set([...current, id])])} />
    {!state.pending || state.busy ? <GroupSelector value={value} onChange={setValue} /> : null}<output>{JSON.stringify(value)}</output></>
}
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } }); workflowActions.reset()
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: { id: createdId } } as never)
  vi.mocked(admin.read).mockReset().mockImplementation(async (operation, input) => {
    if (operation === 'network.groups.names.get') return { data: { groups: (input as { group_ids: string[] }).group_ids.map(id => ({ id, name: id === selectedId ? '跨页已选权限组' : '新建权限组' })) }, meta: {} } as never
    if (operation === 'network.groups.list') {
      const page = (input as { page: number }).page
      return { data: [{ id: page === 2 ? '2' : '1', name: page === 2 ? '第二页权限组' : '第一页权限组' }], meta: { page, per_page: 100, total: 101, last_page: 2 } } as never
    }
    throw new Error('UNEXPECTED_READ')
  })
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render() { await act(async () => root.render(<QueryClientProvider client={client}><Harness /></QueryClientProvider>)); await settle() }
function button(text: string) { return [...host.querySelectorAll<HTMLButtonElement>('button')].find(element => element.textContent?.trim() === text)! }
async function click(text: string) { await act(async () => button(text).click()); await settle() }
async function input(label: string, value: string) {
  const element = host.querySelector<HTMLInputElement>('input[aria-label="' + label + '"]')!
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(element, value); element.dispatchEvent(new Event('input', { bubbles: true })) }); await settle()
}

it('resolves selected names outside the current page and retains exact IDs across paginated search', async () => {
  await render()
  expect(host.textContent).toContain('跨页已选权限组 · #' + selectedId)
  expect(admin.read).toHaveBeenCalledWith('network.groups.names.get', { group_ids: [selectedId] }, expect.any(AbortSignal))
  await click('选择权限组'); await input('搜索本页权限组', '不存在')
  expect(host.textContent).toContain('本页没有符合条件的权限组')
  await click('下一页权限组')
  const option = [...host.querySelectorAll<HTMLInputElement>('input[type=checkbox]')].find(element => element.parentElement?.textContent?.includes('第二页权限组'))!
  await act(async () => option.click()); await settle()
  expect(host.querySelector('output')?.textContent).toBe(JSON.stringify([selectedId, '2']))
  expect(host.textContent).toContain('仅搜索当前页')
})

it('creates and selects a named group while retaining the parent draft', async () => {
  await render(); await click('选择权限组'); await input('新权限组名称', ' 新建权限组 ')
  await click('创建并选择权限组')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.groups.save', { name: '新建权限组' }, expect.any(String))
  expect(host.querySelector('output')?.textContent).toBe(JSON.stringify([selectedId, createdId]))
  expect(host.querySelector<HTMLInputElement>('input[aria-label="父级草稿"]')?.value).toBe('unchanged parent draft')
})

it('keeps the picker mounted during a delayed confirmed creation so its selected result is applied', async () => {
  await render(); await click('选择权限组'); await input('新权限组名称', '慢响应权限组')
  let finish!: (value: unknown) => void
  vi.mocked(admin.write).mockImplementationOnce(() => new Promise(resolve => { finish = resolve }) as never)
  await click('创建并选择权限组')
  expect(button('选择权限组') ?? button('收起权限组')).toBeDefined()
  expect(workflowActions.snapshot().busy).toBe(true)
  await act(async () => finish({ data: { id: createdId } })); await settle()
  expect(host.querySelector('output')?.textContent).toBe(JSON.stringify([selectedId, createdId]))
})

it('recovers an unknown inline creation after its picker unmounts using the identical original request', async () => {
  await render(); await click('选择权限组'); await input('新权限组名称', '待确认权限组')
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('response lost')); await click('创建并选择权限组')
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(workflowActions.snapshot().pending?.unknown).toBe(true)
  expect(button('选择权限组')).toBeUndefined()
  await click('确认原权限组创建')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
  expect(host.querySelector('output')?.textContent).toBe(JSON.stringify([selectedId, createdId]))
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('withdraws selected names when the authoritative resolver denies access', async () => {
  await render(); expect(host.textContent).toContain('跨页已选权限组')
  const original = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation((operation, ...args) => operation === 'network.groups.names.get'
    ? Promise.reject(new CoreAdminApiError('FORBIDDEN', 403)) : original(operation, ...args))
  await act(async () => { await client.invalidateQueries({ queryKey: ['native-admin', 'group-selector-names'] }) }); await settle()
  expect(host.textContent).not.toContain('跨页已选权限组')
  expect(button('选择权限组').disabled).toBe(true)
})
