import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from './api'
import { admin, workflowActions } from './session'
import { ModulesPage } from './ModulesPage'
import { ConfirmDialogHost } from '../components/ui/ConfirmDialog'

const state = vi.hoisted(() => ({ active: true, generation: 0, workflow: { busy: false } as Record<string, unknown> }))
vi.mock('./session', () => ({ admin: { read: vi.fn() }, useAdminSession: () => state.active, workflowActions: {
  subscribe: () => () => {}, snapshot: () => state.workflow, generation: () => state.generation, run: vi.fn(), runResult: vi.fn(), confirmReadAuthorization: vi.fn(), clearError: vi.fn(),
} }))
vi.mock('../components/ui/CodeEditor', () => ({ CodeEditor: ({ label, value, onChange, disabled }: { label: string; value: string; onChange(value: string): void; disabled?: boolean }) => <textarea aria-label={label} value={value} onChange={event => onChange(event.target.value)} disabled={disabled} /> }))
let host: HTMLDivElement, root: Root, client: QueryClient
const fingerprint = 'a'.repeat(64), revision = 'b'.repeat(64)
const plugin = { id: 'demo', name: 'Demo Plugin', version: '1.0.0', type: 'plugin', subtype: 'feature', source: 'bundled', description: 'Native extension', author: 'Author', installed: true, enabled: true, active: null, health: 'healthy', managed: true, protected: false, upgrade_available: false, configuration_available: true, presentation_available: true, revision, fingerprint, actions: ['disable'] }
const payment = { ...plugin, id: 'payment', name: 'Payment Plugin', subtype: 'payment' }
const theme = { ...plugin, id: 'theme.demo', name: 'Theme Demo', type: 'theme', subtype: null, actions: [], managed: false, presentation_available: false }
const configuration = { module_id: plugin.id, fingerprint, revision, schema_json: '{"type":"object","properties":{"label":{"type":"string"}}}', configuration_json: '{"label":"Before"}', credentials: [{ name: 'api_key', configured: true }] }
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  state.active = true; state.generation = 0; state.workflow = { busy: false }; vi.mocked(admin.read).mockReset(); vi.mocked(workflowActions.run).mockReset(); vi.mocked(workflowActions.runResult).mockReset()
  vi.mocked(admin.read).mockImplementation(async operation => {
    if (operation === 'platform.module-cards.list') return { data: { modules: [plugin, payment, theme], discovery_errors: 0 } } as never
    if (operation === 'platform.module-cards.get') return { data: { module: plugin, readme: '<script>no()</script>' } } as never
    if (operation === 'platform.module-configuration.get') return { data: configuration } as never
    if (operation === 'platform.module-presentation.get') return { data: { module_id: plugin.id, title: 'Demo Console', entry: 'admin.js', fingerprint, operations: [{ id: 'stats', mode: 'read', input_schema_json: '{}', output_schema_json: '{}' }, { id: 'change', mode: 'domain', input_schema_json: '{}', output_schema_json: '{}' }] } } as never
    return { data: { module_id: plugin.id, operation_id: 'stats', result_json: '{"count":2}', status: 200 } } as never
  })
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(kind: 'plugin' | 'theme' = 'plugin') { await act(async () => root.render(<QueryClientProvider client={client}><ModulesPage kind={kind} /><ConfirmDialogHost /></QueryClientProvider>)); await settle() }
async function click(text: string) { const button = [...document.querySelectorAll('button')].find(row => row.textContent === text); expect(button).toBeTruthy(); await act(async () => button!.click()); await settle(); await settle(); await settle() }
async function change(label: string, value: string) { const input = document.querySelector<HTMLTextAreaElement>(`[aria-label="${label}"]`)!; expect(input).toBeTruthy(); await act(async () => { Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')!.set!.call(input, value); input.dispatchEvent(new Event('input', { bubbles: true })) }); await settle() }

it('defaults to feature cards, changes category and renders package documentation as text', async () => {
  await render(); expect(host.textContent).toContain(plugin.name); expect(host.textContent).not.toContain(payment.name); expect(host.textContent).not.toContain(theme.name)
  await click('支付插件'); expect(host.textContent).toContain(payment.name); expect(host.textContent).not.toContain(plugin.name)
  await click('功能插件'); await click('文档')
  expect(admin.read).toHaveBeenCalledWith('platform.module-cards.get', { module_id: plugin.id }, expect.any(AbortSignal))
  expect(document.querySelector('.module-readme')?.textContent).toBe('<script>no()</script>'); expect(document.querySelector('.module-readme script')).toBeNull()
})
it('saves public configuration with the loaded revision and leaves configured credentials untouched', async () => {
  await render(); await click('配置'); expect(document.body.textContent).toContain('api_key · 已配置')
  await change('公开配置 JSON', '{"label":"After"}'); await click('保存配置')
  expect(workflowActions.run).toHaveBeenCalledWith('platform.module-configuration.save', { module_id: plugin.id, fingerprint, expected_revision: revision, configuration_json: '{"label":"After"}', credential_references: [] })
  expect(document.body.textContent).toContain('配置已保存')
})
it('preserves invalid JSON locally and asks before discarding a draft', async () => {
  await render(); await click('配置'); await change('公开配置 JSON', '{')
  await click('保存配置'); expect(workflowActions.run).not.toHaveBeenCalled()
  await click('关闭'); expect(document.body.textContent).toContain('放弃未保存的修改？'); await click('取消')
  expect(document.querySelector<HTMLTextAreaElement>('[aria-label="公开配置 JSON"]')?.value).toBe('{')
})
it('confirms lifecycle transitions and sends the exact card fingerprint and revision', async () => {
  await render(); await click('停用'); expect(workflowActions.run).not.toHaveBeenCalled(); await click('确认')
  expect(workflowActions.run).toHaveBeenCalledWith('platform.module-lifecycle.execute', { module_id: plugin.id, fingerprint, expected_revision: revision, action: 'disable' })
})
it('reads runtime operations through the query entry and confirms domain writes separately', async () => {
  await render(); await click('运行入口')
  const select = document.querySelector<HTMLSelectElement>('.module-operation-select select')!
  await act(async () => { select.value = 'stats'; select.dispatchEvent(new Event('change', { bubbles: true })) }); await click('查询')
  expect(admin.read).toHaveBeenCalledWith('platform.module-presentation.query', { module_id: plugin.id, fingerprint, operation_id: 'stats', input_json: '{}' })
  expect(document.body.textContent).toContain('"count":2'); expect(workflowActions.runResult).not.toHaveBeenCalled()
})
it('rechecks current module and configuration authority before recovering the retained original input', async () => {
  const original = { module_id: plugin.id, fingerprint, expected_revision: revision, configuration_json: '{"label":"Retained"}', credential_references: [{ name: 'api_key', reference: 'secret:private_alias' }] }
  state.workflow = { busy: false, pending: { operation: 'platform.module-configuration.save', input: original, key: 'original-key', unknown: true } }
  await render(); expect(document.body.textContent).not.toContain('private_alias'); await click('恢复原请求')
  expect(admin.read).toHaveBeenCalledWith('platform.module-configuration.get', { module_id: plugin.id })
  expect(workflowActions.run).toHaveBeenCalledWith('platform.module-configuration.save', original)
})
it('hides cards and configuration drafts when refreshed authorization is denied', async () => {
  await render(); await click('配置'); await change('公开配置 JSON', '{"label":"Private draft"}')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403)); await click('刷新')
  expect(document.body.textContent).not.toContain(plugin.name); expect(document.querySelector('[aria-label="公开配置 JSON"]')).toBeNull()
})

it('confirms runtime domain operations and retains their native result', async () => {
  vi.mocked(workflowActions.runResult).mockResolvedValue({ data: { result_json: '{"changed":true}' } })
  await render(); await click('运行入口')
  const select = document.querySelector<HTMLSelectElement>('.module-operation-select select')!
  await act(async () => { select.value = 'change'; select.dispatchEvent(new Event('change', { bubbles: true })) })
  await click('确认执行'); expect(workflowActions.runResult).not.toHaveBeenCalled(); await click('确认')
  expect(workflowActions.runResult).toHaveBeenCalledWith('platform.module-presentation.execute', { module_id: plugin.id, fingerprint, operation_id: 'change', input_json: '{}' })
  expect(document.body.textContent).toContain('"changed":true')
})

it('offers real theme activation and confirmed deployment from a digest-pinned candidate', async () => {
  const candidate = { id: 'approved-release', module_id: theme.id, title: 'Approved Theme', version: '2.0.0', sha256: 'c'.repeat(64), expected_revision: revision, action: 'upgrade', eligible: true, installed_version: '1.0.0' }
  vi.mocked(admin.read).mockImplementation(async operation => {
    if (operation === 'platform.module-cards.list') return { data: { modules: [{ ...theme, actions: ['enable', 'uninstall'] }], discovery_errors: 0 } } as never
    if (operation === 'platform.theme-packages.list') return { data: { candidates: [candidate] } } as never
    return { data: { module: theme, readme: '' } } as never
  })
  await render('theme'); await click('应用主题'); await click('确认')
  expect(workflowActions.run).toHaveBeenCalledWith('platform.module-lifecycle.execute', { module_id: theme.id, fingerprint, expected_revision: revision, action: 'enable' })
  await click('部署受控主题'); expect(document.body.textContent).toContain('Approved Theme'); await click('升级此主题')
  expect(document.body.textContent).toContain('同步发布新资产'); await click('确认')
  expect(workflowActions.run).toHaveBeenCalledWith('platform.theme-packages.deploy', { candidate_id: candidate.id, sha256: candidate.sha256, expected_revision: revision, action: 'upgrade' })
})

it('recovers a retained theme-package deployment through the current candidate owner without changing its input', async () => {
  const input = { candidate_id: 'retained-release', sha256: fingerprint, expected_revision: revision, action: 'install' }
  state.workflow = { busy: false, pending: { operation: 'platform.theme-packages.deploy', input, key: 'retained-key', unknown: true } }
  vi.mocked(admin.read).mockImplementation(async operation => operation === 'platform.theme-packages.list' ? { data: { candidates: [] } } as never : { data: { modules: [], discovery_errors: 0 } } as never)
  await render('theme'); await click('恢复原请求')
  expect(admin.read).toHaveBeenCalledWith('platform.theme-packages.list', {})
  expect(workflowActions.run).toHaveBeenCalledWith('platform.theme-packages.deploy', input)
})

it('recovers a removed theme through current inventory authorization instead of demanding an existing detail', async () => {
  const input = { module_id: theme.id, fingerprint, expected_revision: revision, action: 'uninstall' }
  state.workflow = { busy: false, pending: { operation: 'platform.module-lifecycle.execute', input, key: 'retained-key', unknown: true } }
  await render('theme'); await click('恢复原请求')
  expect(admin.read).toHaveBeenCalledWith('platform.module-cards.list', {})
  expect(admin.read).not.toHaveBeenCalledWith('platform.module-cards.get', { module_id: theme.id })
  expect(workflowActions.run).toHaveBeenCalledWith('platform.module-lifecycle.execute', input)
})


it('invalidates an open lifecycle confirmation when the session generation changes without changing the active flag', async () => {
  await render(); await click('停用')
  state.generation++
  await render()
  await click('确认')
  expect(workflowActions.run).not.toHaveBeenCalled()
})

it('does not publish a late runtime result into the next session generation', async () => {
  const original = vi.mocked(admin.read).getMockImplementation()!
  let finish!: (value: unknown) => void
  vi.mocked(admin.read).mockImplementation(async (operation, ...args) => operation === 'platform.module-presentation.query'
    ? await new Promise<unknown>(resolve => { finish = resolve }) as never : original(operation, ...args))
  await render(); await click('运行入口')
  const select = document.querySelector<HTMLSelectElement>('.module-operation-select select')!
  await act(async () => { select.value = 'stats'; select.dispatchEvent(new Event('change', { bubbles: true })) })
  await click('查询')
  state.generation++
  await render()
  await act(async () => finish({ data: { result_json: '{"private_previous_session":true}' } }))
  await settle()
  expect(document.body.textContent).not.toContain('private_previous_session')
  expect(document.querySelector('[aria-label="运行结果"]')).toBeNull()
})
