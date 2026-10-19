import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ConfigurationPage, type ConfigurationGroup } from './ConfigurationPage'
import { MailTemplatesPage } from './MailTemplatesPage'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
let host: HTMLDivElement, root: Root, client: QueryClient, version: string
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } }); version = '1'
  workflowActions.reset(); vi.mocked(admin.write).mockReset().mockResolvedValue({ data: { ok: true } } as never)
  vi.mocked(admin.read).mockReset().mockImplementation(async operation => {
    if (operation === 'settings.configuration.get') return { data: { version, values: { app_name: 'private site', email_host: 'smtp.example.com', email_username: 'private user' }, credentials: [{ name: 'email_password', configured: true }] }, meta: {} } as never
    if (operation === 'notifications.mail-templates.list') return { data: [{ name: 'verify', label: '邮箱验证', customized: true, subject: '验证', updated_at: null }], meta: {} } as never
    return { data: { name: 'verify', label: '邮箱验证', customized: true, subject: '验证', content: '<img src=x onerror=alert(1)>', required_vars: ['code'], optional_vars: [] }, meta: {} } as never
  })
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(group: ConfigurationGroup | 'mail' = 'site') {
  await act(async () => root.render(<QueryClientProvider client={client}>{group === 'mail' ? <MailTemplatesPage /> : <ConfigurationPage group={group} />}</QueryClientProvider>)); await settle()
}
function button(text: string) { return [...host.querySelectorAll<HTMLButtonElement>('button')].find(node => node.textContent === text)! }
async function click(text: string) { await act(async () => button(text).click()); await settle() }
async function input(node: HTMLInputElement | HTMLTextAreaElement, value: string) {
  await act(async () => {
    Object.getOwnPropertyDescriptor(node instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype, 'value')!.set!.call(node, value)
    node.dispatchEvent(new Event('input', { bubbles: true }))
  })
}
async function submit() { await act(async () => { host.querySelector('form.native-content-form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })) }); await settle() }

it('sends only changed current-group fields and leaves configured credentials untouched', async () => {
  await render('email'); await click('开始编辑')
  expect(host.textContent).toContain('已配置，留空保留')
  expect(host.querySelector<HTMLInputElement>('#configuration-email_password')!.value).toBe('')
  expect(host.querySelector('#configuration-app_name')).toBeNull()
  await input(host.querySelector<HTMLInputElement>('#configuration-email_host')!, 'smtp.new.example')
  await submit()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('settings.configuration.update', { expected_version: '1', values: { email_host: 'smtp.new.example' } }, expect.any(String))
})

it('preserves the original sensitive write and key across navigation until explicit recovery', async () => {
  await render('email'); await click('开始编辑')
  await input(host.querySelector<HTMLInputElement>('#configuration-email_password')!, 'new-secret')
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost acknowledgement'))
  await submit()
  const original = vi.mocked(admin.write).mock.calls[0]
  await render('mail'); expect(admin.write).toHaveBeenCalledTimes(1)
  await render('email'); expect(host.querySelector('input[type=password]')).toBeNull()
  await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('discards the stale edit on CAS conflict and requires fresh deliberate editing', async () => {
  await render(); await click('开始编辑')
  await input(host.querySelector<HTMLInputElement>('#configuration-app_name')!, 'old-intent')
  version = '2'; vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('SETTING_VERSION_CONFLICT', 409))
  await submit()
  expect(host.querySelector('form.native-content-form')).toBeNull()
  expect(host.textContent).toContain('请重新开始编辑')
  await click('开始编辑')
  expect(host.querySelector<HTMLInputElement>('#configuration-app_name')!.value).toBe('private site')
  await input(host.querySelector<HTMLInputElement>('#configuration-app_name')!, 'reviewed-intent'); await submit()
  expect(vi.mocked(admin.write).mock.calls[1]![1]).toEqual({ expected_version: '2', values: { app_name: 'reviewed-intent' } })
  expect(vi.mocked(admin.write).mock.calls[1]![2]).not.toBe(vi.mocked(admin.write).mock.calls[0]![2])
})

it('clears sensitive drafts and hides stale settings after forbidden refresh', async () => {
  await render('email'); await click('开始编辑')
  await input(host.querySelector<HTMLInputElement>('#configuration-email_password')!, 'unsubmitted-secret')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新权限与设置')
  expect(host.querySelector('input')).toBeNull()
  expect(host.textContent).not.toContain('private user')
  expect(admin.write).not.toHaveBeenCalled()
})

it('renders template markup as text and sends test mail only after explicit confirmation', async () => {
  await render('mail')
  expect(host.querySelector('img')).toBeNull()
  expect(host.querySelector('pre')!.textContent).toContain('<img')
  expect(admin.write).not.toHaveBeenCalled()
  vi.spyOn(window, 'confirm').mockReturnValue(true)
  const email = host.querySelector<HTMLInputElement>('input[type=email]')!
  await input(email, 'operator@example.com')
  await act(async () => { host.querySelector('form.native-filters')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })) }); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('notifications.mail-templates.test', { name: 'verify', email: 'operator@example.com' }, expect.any(String))
})

it('clears template source when the session generation changes', async () => {
  await render('mail'); await click('编辑源码')
  await input(host.querySelector('textarea')!, 'old-session-private-template')
  await act(async () => workflowActions.reset()); await settle()
  expect(host.querySelector('textarea')).toBeNull()
  expect(host.textContent).not.toContain('old-session-private-template')
})

it('saves template source without evaluating HTML and replays an unknown reset with its original key', async () => {
  await render('mail'); await click('编辑源码')
  await input(host.querySelector('textarea')!, '<script>privateTemplate()</script>{{code}}')
  await submit()
  expect(vi.mocked(admin.write).mock.calls[0]).toEqual(['notifications.mail-templates.save', { name: 'verify', subject: '验证', content: '<script>privateTemplate()</script>{{code}}' }, expect.any(String)])
  expect(host.querySelector('script')).toBeNull()
  vi.spyOn(window, 'confirm').mockReturnValue(true)
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('reset acknowledgement lost'))
  await click('恢复默认模板')
  const original = vi.mocked(admin.write).mock.calls[1]
  await render(); await render('mail')
  expect(admin.write).toHaveBeenCalledTimes(2)
  await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[2]).toEqual(original)
})

it('hides cached mail content after read permission is denied', async () => {
  await render('mail'); await click('编辑源码')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新权限与模板')
  expect(host.querySelector('textarea')).toBeNull()
  expect(host.querySelector('pre')).toBeNull()
  expect(button('发送测试邮件')).toBeUndefined()
})
