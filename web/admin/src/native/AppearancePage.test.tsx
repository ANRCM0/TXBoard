import { act, StrictMode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from './api'
import { admin, workflowActions } from './session'
import { AppearancePage } from './AppearancePage'
import { ConfirmDialogHost } from '../components/ui/ConfirmDialog'
import { siteAppearanceDefaults } from '../lib/useSiteAppearance'

const state = vi.hoisted(() => ({ active: true, workflow: { busy: false } as Record<string, unknown> }))
vi.mock('./session', () => ({ admin: { read: vi.fn() }, useAdminSession: () => state.active, workflowActions: {
  subscribe: () => () => {}, snapshot: () => state.workflow, generation: () => 0, run: vi.fn(), clearError: vi.fn(), confirmReadAuthorization: vi.fn(),
} }))
let host: HTMLDivElement, root: Root, client: QueryClient
const version = 'a'.repeat(64)
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  state.active = true; state.workflow = { busy: false }; vi.mocked(admin.read).mockReset(); vi.mocked(workflowActions.run).mockReset()
  vi.mocked(admin.read).mockResolvedValue({ data: { values: siteAppearanceDefaults, credentials: [], version } } as never)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render() { await act(async () => root.render(<StrictMode><QueryClientProvider client={client}><AppearancePage /><ConfirmDialogHost /></QueryClientProvider></StrictMode>)); await settle(); await settle() }
async function click(text: string) { const button = [...document.querySelectorAll('button')].find(row => row.textContent === text); expect(button).toBeTruthy(); await act(async () => button!.click()); await settle(); await settle() }
function field(label: string) { const row = [...host.querySelectorAll('label')].find(element => element.textContent === label)!; expect(row).toBeTruthy(); return document.getElementById(row.htmlFor) as HTMLInputElement | HTMLSelectElement }
async function change(label: string, value: string) {
  const input = field(label)
  await act(async () => { Object.getOwnPropertyDescriptor(input instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype, 'value')!.set!.call(input, value); input.dispatchEvent(new Event(input instanceof HTMLSelectElement ? 'change' : 'input', { bubbles: true })) }); await settle()
}
it('saves only changed shell fields with the original settings revision and refreshes the real consumer defaults', async () => {
  await render(); await change('侧栏表面风格', 'contrast'); await change('强调色', 'green')
  vi.mocked(admin.read).mockResolvedValue({ data: { values: { ...siteAppearanceDefaults, admin_sidebar_style: 'contrast', admin_color_scheme: 'green' }, credentials: [], version: 'b'.repeat(64) } } as never)
  await click('保存外观')
  expect(workflowActions.run).toHaveBeenCalledWith('settings.configuration.update', { expected_version: version, values: { admin_sidebar_style: 'contrast', admin_color_scheme: 'green' } })
  expect(host.textContent).toContain('后台外观已保存'); expect(field('强调色').value).toBe('green'); expect(host.textContent).toContain('当前配置')
})
it('keeps the draft after a version conflict until the administrator explicitly reloads', async () => {
  await render(); await change('页面标题区风格', 'accent')
  vi.mocked(workflowActions.run).mockRejectedValue(new CoreAdminApiError('BUSINESS_REJECTED', 409)); await click('保存外观')
  expect(field('页面标题区风格').value).toBe('accent'); await click('重新读取'); expect(document.body.textContent).toContain('重新读取并放弃外观草稿？')
  await click('确认'); expect(field('页面标题区风格').value).toBe('normal')
})
it('rejects unsafe login image input locally and asks before cancelling valid unsaved changes', async () => {
  await render(); await change('登录背景地址', 'https://user:pass@example.test/login.jpg'); await click('保存外观')
  expect(workflowActions.run).not.toHaveBeenCalled(); expect(host.textContent).toContain('无凭据的 HTTPS')
  await click('取消修改'); expect(document.body.textContent).toContain('放弃外观草稿？'); await click('放弃修改'); expect(field('登录背景地址').value).toBe('')
})
it('associates an unsafe background address with its field, focuses it and clears the error after correction', async () => {
  await render(); await change('登录背景地址', 'https://user:pass@example.test/login.jpg'); await click('保存外观')
  const input = field('登录背景地址') as HTMLInputElement
  expect(workflowActions.run).not.toHaveBeenCalled()
  expect(document.activeElement).toBe(input)
  expect(input.getAttribute('aria-invalid')).toBe('true')
  const errorId = input.getAttribute('aria-errormessage')!
  expect(document.getElementById(errorId)?.textContent).toContain('无凭据的 HTTPS')
  expect(input.getAttribute('aria-describedby')).toContain('-hint')
  expect(input.getAttribute('aria-describedby')).toContain('-error')
  await change('登录背景地址', '/safe/login.jpg')
  expect(input.hasAttribute('aria-invalid')).toBe(false)
  expect(input.hasAttribute('aria-errormessage')).toBe(false)
  expect(host.textContent).not.toContain('背景地址须为')
  expect(workflowActions.run).not.toHaveBeenCalled()
})
it('recovers a retained appearance command with its original input and clears all drafts on current permission loss', async () => {
  const input = { expected_version: version, values: { admin_color_scheme: 'green' } }
  state.workflow = { busy: false, pending: { operation: 'settings.configuration.update', input, key: 'original-key', unknown: true } }
  await render(); await click('恢复原请求'); expect(workflowActions.run).toHaveBeenCalledWith('settings.configuration.update', input)
  state.workflow = { busy: false }; await render(); await change('登录背景地址', '/private-draft.jpg')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403)); await click('重新读取'); await click('确认')
  expect(host.querySelector('form')).toBeNull(); expect(host.textContent).not.toContain('/private-draft.jpg'); expect(host.textContent).toContain('已清空表单和草稿')
})
