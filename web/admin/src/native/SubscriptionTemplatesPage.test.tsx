import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { SubscriptionTemplatesPage } from './SubscriptionTemplatesPage'
import { ConfirmDialogHost, dismissConfirm } from '../components/ui/ConfirmDialog'

vi.mock('../lib/localCodeEditor', () => ({ loadLocalCodeEditor: async () => { throw new Error('plain test editor') } }))
vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
let host: HTMLDivElement, root: Root, client: QueryClient
const ids = ['clash/v1', 'clash-meta/v1', 'sing-box/v1', 'stash/v1', 'surfboard/v1', 'surge-ios/v1', 'surge-mac/v1', 'shadowrocket/v1']
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  workflowActions.reset()
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: { ok: true } } as never)
  vi.mocked(admin.read).mockReset().mockResolvedValue({ data: { templates: ids.map(format_id => ({ format_id, version: 'a'.repeat(64), source: '{{ .Default }}', custom: true, sha256: 'b'.repeat(64) })), providers: ['provider-a'] }, meta: {} } as never)
})
afterEach(() => { act(() => dismissConfirm()); act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render() { await act(async () => root.render(<QueryClientProvider client={client}><SubscriptionTemplatesPage /><ConfirmDialogHost /></QueryClientProvider>)); await settle() }
function button(label: string) { return [...document.querySelectorAll<HTMLButtonElement>('button')].find(node => node.textContent?.trim() === label)! }
async function click(label: string) { await act(async () => button(label).click()); await settle() }
async function edit() { await click('编辑模板'); await click('使用纯文本编辑') }
async function change(node: HTMLSelectElement | HTMLTextAreaElement, value: string) {
  await act(async () => {
    Object.getOwnPropertyDescriptor(node instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLSelectElement.prototype, 'value')!.set!.call(node, value)
    node.dispatchEvent(new Event(node instanceof HTMLSelectElement ? 'change' : 'input', { bubbles: true }))
  })
}
async function submit() { await act(async () => host.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))); await settle() }

it('saves an explicitly selected provider and original template version only after deliberate edit', async () => {
  await render(); await edit()
  expect(button('校验并保存').disabled).toBe(true)
  await change(host.querySelector('textarea')!, '{{ if .Connections }}{{ .Default }}{{ end }}')
  await change(host.querySelector('select')!, 'provider-a'); await submit()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('subscription.admin-templates.save', { format_id: 'clash/v1', expected_version: 'a'.repeat(64), source: '{{ if .Connections }}{{ .Default }}{{ end }}', provider_id: 'provider-a' }, expect.any(String))
  expect(host.querySelector('textarea')).toBeNull()
})

it('does not clear source after another page operation is forbidden', async () => {
  await render(); await edit(); await change(host.querySelector('textarea')!, 'unsaved template')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await workflowActions.run('settings.configuration.update', { expected_version: 'a'.repeat(64), values: { app_name: 'other page' } }).catch(() => {}) })
  expect(host.querySelector('textarea')!.value).toBe('unsaved template')
  expect(host.textContent).not.toContain('模板访问权限已失效')
  expect(host.textContent).not.toContain('当前账号没有此操作的权限')
})

it('keeps dirty source until an explicit discard and selects both Surge platforms', async () => {
  await render(); await edit(); await change(host.querySelector('textarea')!, 'draft source')
  await click('Stash'); expect(document.body.textContent).toContain('放弃未保存的订阅模板修改？')
  await click('继续编辑'); expect(host.querySelector('textarea')!.value).toBe('draft source')
  await click('Stash'); await click('放弃修改'); expect(host.querySelector('[aria-label="Stash 已保存源码"]')).not.toBeNull()
  await click('Surge'); await change(host.querySelector('select')!, 'surge-mac/v1')
  expect(host.querySelector('[aria-label="Surge macOS 已保存源码"]')).not.toBeNull()
  expect(admin.write).not.toHaveBeenCalled()
})

it('recovers an unknown save using the exact original input and idempotency key', async () => {
  await render(); await edit(); await change(host.querySelector('textarea')!, '{{ .Default }}\n')
  await change(host.querySelector('select')!, 'provider-a')
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost acknowledgement'))
  await submit(); const original = vi.mocked(admin.write).mock.calls[0]
  expect(workflowActions.snapshot().pending?.unknown).toBe(true)
  expect(button('校验并保存').disabled).toBe(true)
  await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('clears source and hides cached templates after forbidden refresh or generation change', async () => {
  await render(); await edit(); await change(host.querySelector('textarea')!, 'private draft')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新模板')
  expect(host.querySelector('textarea')).toBeNull(); expect(host.textContent).not.toContain('private draft')
  expect(host.querySelector('[aria-label="Clash 已保存源码"]')).toBeNull()
  vi.mocked(admin.read).mockResolvedValue({ data: { templates: ids.map(format_id => ({ format_id, version: 'a'.repeat(64), source: '{{ .Default }}', custom: false, sha256: 'b'.repeat(64) })), providers: [] }, meta: {} } as never)
  await act(async () => workflowActions.reset()); await settle()
  expect(host.querySelector('textarea')).toBeNull()
})

it('requires confirmation before restoring the saved owner version', async () => {
  await render(); await click('恢复默认模板')
  expect(admin.write).not.toHaveBeenCalled()
  await click('恢复默认')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('subscription.admin-templates.reset', { format_id: 'clash/v1', expected_version: 'a'.repeat(64) }, expect.any(String))
})

it('preserves parser-rejected source but requires fresh editing after a version conflict', async () => {
  await render(); await edit(); await change(host.querySelector('textarea')!, 'rejected source')
  await change(host.querySelector('select')!, 'provider-a')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('SUBSCRIPTION_TEMPLATE_SOURCE_INVALID', 422))
  await submit(); expect(host.querySelector('textarea')!.value).toBe('rejected source')
  expect(host.textContent).toContain('节点未通过模板语法或文档格式校验')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('SUBSCRIPTION_TEMPLATE_VERSION_CONFLICT', 409))
  await submit(); expect(host.querySelector('textarea')).toBeNull()
  expect(host.textContent).toContain('模板记录已变化，请重新编辑并核对修改。')
  await edit(); expect(host.querySelector('textarea')!.value).toBe('{{ .Default }}')
})
