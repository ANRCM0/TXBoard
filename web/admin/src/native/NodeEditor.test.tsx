import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { NodeEditor } from './NodeEditor'
import type { NodeConfiguration, NodeProtocol } from './nodeConfiguration'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
const id = '9007199254740993', machine = '9007199254740995', revision = 'a'.repeat(64)
const vless: NodeProtocol = { type: 'vless', label: 'VLESS', schema_version: 1,
  defaults: { tls: 0, network: 'tcp', reality_settings: { public_key: '', private_key: '', short_id: '' } },
  form_schema: [
    { key: 'tls', label: 'TLS 模式', type: 'select', options: [{ value: 0, label: '关闭' }, { value: 1, label: 'TLS' }, { value: 2, label: 'Reality' }] },
    { key: 'network', label: '传输协议', type: 'text' },
    { key: 'reality_settings.public_key', label: 'Reality Public Key', type: 'text', visible_when: [{ field: 'tls', equals: 2 }] },
    { key: 'reality_settings.private_key', label: 'Reality Private Key', type: 'text', visible_when: [{ field: 'tls', equals: 2 }], generator: { kind: 'x25519', label: '生成 Reality 密钥对', map: { public_key: 'reality_settings.public_key', private_key: 'reality_settings.private_key' } } },
  ] }
const protocols = ['shadowsocks', 'vless', 'vmess', 'trojan', 'hysteria', 'tuic', 'anytls', 'socks', 'http', 'naive', 'mieru'].map(type =>
  type === 'vless' ? vless : { type, label: type.toUpperCase(), schema_version: 1, defaults: { tls: 0 }, form_schema: [{ key: 'tls', label: 'TLS 开关', type: 'number' }] } satisfies NodeProtocol)
const initial: NodeConfiguration = { type: 'vless', name: 'authoritative-node', host: 'edge.example.test', port: '443', server_port: 443, code: null,
  group_ids: [id], route_ids: [], parent_id: null, machine_id: machine, show: false, enabled: true, rate: '1', rate_time_enable: false,
  rate_time_ranges: [], tags: ['existing'], transfer_enable: '9007199254740999', protocol_settings: { ...vless.defaults, tls: 2, reality_settings: { public_key: 'public-old', private_key: 'private-sensitive', short_id: 'cafe' } },
  custom_outbounds: [], custom_routes: [], cert_config: null }
let host: HTMLDivElement, root: Root, client: QueryClient, current: NodeConfiguration, version: string
const closed = vi.fn(), saved = vi.fn()
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } }); current = structuredClone(initial); version = revision
  workflowActions.reset(); closed.mockClear(); saved.mockClear(); vi.spyOn(window, 'confirm').mockReturnValue(true)
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: { id } } as never)
  vi.mocked(admin.read).mockReset().mockImplementation(async (operation, input) => {
    if (operation === 'network.nodes.protocols.list') return { data: { metadata_json: JSON.stringify(protocols) }, meta: {} } as never
    if (operation === 'network.nodes.configuration.get') return { data: { node_id: id, revision: version, configuration_json: JSON.stringify(current) }, meta: {} } as never
    if (operation === 'network.groups.list') return { data: [{ id: (input as { page: number }).page === 2 ? id : '1', name: 'named-group' }], meta: { page: (input as { page: number }).page, per_page: 100, total: 101, last_page: 2 } } as never
    if (operation === 'network.groups.names.get') return { data: { groups: (input as { group_ids: string[] }).group_ids.map(id => ({ id, name: 'selected-group' })) }, meta: {} } as never
    return { data: { material_json: JSON.stringify({ public_key: 'new-public', private_key: 'new-private' }) }, meta: {} } as never
  })
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(edit = true) { await act(async () => root.render(<QueryClientProvider client={client}><NodeEditor nodeId={edit ? id : undefined} defaultMachineId={edit ? undefined : machine} onClose={closed} onSaved={saved} /></QueryClientProvider>)); await settle() }
function button(text: string) { return [...document.querySelectorAll<HTMLButtonElement>('button')].find(node => node.textContent?.trim() === text)! }
async function click(text: string) { await act(async () => button(text).click()); await settle() }
function control(label: string) {
  const node = [...document.querySelectorAll('label')].find(node => node.textContent?.replace(/ \*$/, '').trim() === label)!
  return document.getElementById(node.htmlFor) as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
}
async function input(label: string, value: string) {
  const node = control(label)
  await act(async () => {
    Object.getOwnPropertyDescriptor(node instanceof HTMLSelectElement ? HTMLSelectElement.prototype : node instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype, 'value')!.set!.call(node, value)
    node.dispatchEvent(new Event(node instanceof HTMLSelectElement ? 'change' : 'input', { bubbles: true }))
  }); await settle()
}
async function submit() { await act(async () => document.querySelector('form.node-editor-form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))); await settle() }

it('creates a protocol-specific full configuration with exact quota, named paginated groups and a preset server', async () => {
  await render(false)
  expect(control('协议类型').querySelectorAll('option')).toHaveLength(12)
  expect(admin.write).not.toHaveBeenCalled()
  await input('协议类型', 'vless'); await input('节点名称', 'new-node'); await input('连接地址', 'new.example.test')
  expect(document.body.textContent).not.toContain('Reality Private Key')
  await input('TLS 模式', '2'); await input('Reality Public Key', 'client-key'); await input('Reality Private Key', 'server-key')
  await input('流量上限（GB）', '9007199.254740999')
  await click('选择权限组'); await click('下一页权限组')
  const group = [...document.querySelectorAll<HTMLInputElement>('input[type=checkbox]')].find(node => node.parentElement?.textContent?.includes('#' + id))!
  await act(async () => group.click()); await submit()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.nodes.create', { configuration_json: expect.any(String) }, expect.any(String))
  const data = JSON.parse((vi.mocked(admin.write).mock.calls[0][1] as { configuration_json: string }).configuration_json)
  expect(data).toMatchObject({ type: 'vless', name: 'new-node', machine_id: machine, group_ids: [id], transfer_enable: '9007199254740999',
    protocol_settings: { tls: 2, reality_settings: { public_key: 'client-key', private_key: 'server-key' } } })
  expect(saved).toHaveBeenCalledExactlyOnceWith(id)
})

it('reads authoritative full configuration and submits the observed revision while preserving unrelated settings', async () => {
  await render()
  expect((control('Reality Private Key') as HTMLInputElement).value).toBe('private-sensitive')
  expect(control('Reality Private Key').getAttribute('type')).toBe('password')
  await input('节点名称', 'edited-name'); await submit()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('network.nodes.configuration.replace', { node_id: id, expected_revision: revision, configuration_json: expect.any(String) }, expect.any(String))
  const payload = JSON.parse((vi.mocked(admin.write).mock.calls[0][1] as { configuration_json: string }).configuration_json)
  expect(payload).toEqual({ ...initial, name: 'edited-name' })
  expect(client.getQueryCache().getAll().some(query => JSON.stringify(query.state.data)?.includes('private-sensitive'))).toBe(false)
})

it('keeps separate protocol drafts when switching types and requires confirmation before discarding edits', async () => {
  await render(); await input('Reality Public Key', 'draft-public'); await input('协议类型', 'trojan'); await input('协议类型', 'vless')
  expect((control('Reality Public Key') as HTMLInputElement).value).toBe('draft-public')
  vi.mocked(window.confirm).mockReturnValue(false); await click('取消')
  expect(closed).not.toHaveBeenCalled(); expect(admin.write).not.toHaveBeenCalled()
})

it('blocks malformed advanced JSON rather than submitting the last valid value', async () => {
  await render(); const details = document.querySelector('details')!; details.open = true
  await input('自定义 Routes', '{'); await submit()
  expect(admin.write).not.toHaveBeenCalled(); expect(document.body.textContent).toContain('JSON 格式有误')
})

it('preserves a conflicting draft until an explicit authoritative reload and uses a new revision and request key', async () => {
  await render(); await input('节点名称', 'stale-intent')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('NETWORK_NODE_CONFIGURATION_CONFLICT', 409)); await submit()
  expect((control('节点名称') as HTMLInputElement).value).toBe('stale-intent')
  expect(button('保存完整配置').disabled).toBe(true)
  current.name = 'new-authoritative'; version = 'b'.repeat(64)
  await click('重新读取配置'); expect((control('节点名称') as HTMLInputElement).value).toBe('new-authoritative')
  await input('节点名称', 'reviewed-intent'); await submit()
  const calls = vi.mocked(admin.write).mock.calls
  expect(calls[1][1]).toMatchObject({ expected_revision: version })
  expect(calls[1][2]).not.toBe(calls[0][2])
})

it('recovers a lost save after remount with the identical configuration and original request key', async () => {
  await render(); await input('节点名称', 'retained-intent')
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost response')); await submit()
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(workflowActions.snapshot().pending?.unknown).toBe(true)
  await act(async () => root.render(<div />)); await render()
  expect(document.querySelector('form.node-editor-form')).toBeNull()
  await click('确认原节点保存')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('withdraws configuration and sensitive fields after a denied write', async () => {
  await render(); vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403)); await submit()
  expect(document.querySelector('form.node-editor-form')).toBeNull()
  expect(document.querySelector('input[type=password]')).toBeNull()
  expect(document.body.textContent).not.toContain('private-sensitive')
})

it('does not apply a generated key response to another selected protocol', async () => {
  await render()
  let finish!: (value: unknown) => void
  const read = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation((operation, ...args) => operation === 'network.nodes.material.generate' ? new Promise(resolve => { finish = resolve }) as never : read(operation, ...args))
  await click('生成 Reality 密钥对'); await input('协议类型', 'trojan')
  await act(async () => finish({ data: { material_json: JSON.stringify({ public_key: 'late-public', private_key: 'late-private' }) } })); await settle()
  await input('协议类型', 'vless')
  expect((control('Reality Private Key') as HTMLInputElement).value).toBe('private-sensitive')
  expect(admin.write).not.toHaveBeenCalled()
})

it('retains the node draft and sensitive configuration when only inline group creation is forbidden', async () => {
  await render(); await input('节点名称', 'retained-node-draft'); await click('选择权限组')
  const name = document.querySelector<HTMLInputElement>('input[aria-label="新权限组名称"]')!
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(name, 'new-permission-group'); name.dispatchEvent(new Event('input', { bubbles: true })) })
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403)); await click('创建并选择权限组')
  expect(workflowActions.snapshot().errorOperation).toBe('network.groups.save')
  expect((control('节点名称') as HTMLInputElement).value).toBe('retained-node-draft')
  expect((control('Reality Private Key') as HTMLInputElement).value).toBe('private-sensitive')
  expect(button('保存完整配置').disabled).toBe(false)
  expect(admin.write).toHaveBeenCalledTimes(1)
})
