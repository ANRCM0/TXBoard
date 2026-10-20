import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ConfigurationPage, type ConfigurationGroup } from './ConfigurationPage'
import { MailTemplatesPage } from './MailTemplatesPage'
import { ConfirmDialogHost, dismissConfirm } from '../components/ui/ConfirmDialog'

vi.mock('../lib/localCodeEditor', () => ({ loadLocalCodeEditor: async () => { throw new Error('unit fixture: use explicit plain editor') } }))

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
let host: HTMLDivElement, root: Root, client: QueryClient, version: string
const initialVersion = 'a'.repeat(64), revisedVersion = 'b'.repeat(64)
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } }); version = initialVersion
  workflowActions.reset(); vi.mocked(admin.write).mockReset().mockResolvedValue({ data: { ok: true } } as never)
  vi.mocked(admin.read).mockReset().mockImplementation(async operation => {
    if (operation === 'settings.configuration.get') return { data: { version, values: { app_name: 'private site', email_host: 'smtp.example.com', email_username: 'private user' }, credentials: [{ name: 'email_password', configured: true }] }, meta: {} } as never
    if (operation === 'notifications.mail-templates.list') return { data: [{ name: 'verify', label: '邮箱验证', customized: true, subject: '验证', updated_at: null }], meta: {} } as never
    return { data: { name: 'verify', label: '邮箱验证', customized: true, subject: '验证', content: '<img src=x onerror=alert(1)>', required_vars: ['code'], optional_vars: [] }, meta: {} } as never
  })
})
afterEach(() => { act(() => dismissConfirm()); act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(group: ConfigurationGroup | 'mail' = 'site') {
  await act(async () => root.render(<QueryClientProvider client={client}>{group === 'mail' ? <MailTemplatesPage /> : <ConfigurationPage group={group} />}<ConfirmDialogHost /></QueryClientProvider>)); await settle()
}
function button(text: string) { return [...host.querySelectorAll<HTMLButtonElement>('button')].find(node => node.getAttribute('aria-label') === text || node.textContent?.trim() === text)! }
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
  await vi.waitFor(() => expect(admin.write).toHaveBeenCalledExactlyOnceWith('settings.configuration.update', { expected_version: initialVersion, values: { email_host: 'smtp.new.example' } }, expect.any(String)))
})

it('preserves the original sensitive write and key across navigation until explicit recovery', async () => {
  await render('email'); await click('开始编辑')
  await input(host.querySelector<HTMLInputElement>('#configuration-email_password')!, 'new-secret')
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost acknowledgement'))
  await submit()
  await vi.waitFor(() => expect(workflowActions.snapshot().pending?.unknown).toBe(true))
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
  version = revisedVersion; vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('SETTING_VERSION_CONFLICT', 409))
  await submit()
  await vi.waitFor(() => expect(host.querySelector('form.native-content-form')).toBeNull())
  expect(host.textContent).toContain('请重新编辑并核对修改')
  await click('开始编辑')
  expect(host.querySelector<HTMLInputElement>('#configuration-app_name')!.value).toBe('private site')
  await input(host.querySelector<HTMLInputElement>('#configuration-app_name')!, 'reviewed-intent'); await submit()
  await vi.waitFor(() => expect(admin.write).toHaveBeenCalledTimes(2))
  expect(vi.mocked(admin.write).mock.calls[1]![1]).toEqual({ expected_version: revisedVersion, values: { app_name: 'reviewed-intent' } })
  expect(vi.mocked(admin.write).mock.calls[1]![2]).not.toBe(vi.mocked(admin.write).mock.calls[0]![2])
})

it('clears sensitive drafts and hides stale settings after forbidden refresh', async () => {
  await render('email'); await click('开始编辑')
  await input(host.querySelector<HTMLInputElement>('#configuration-email_password')!, 'unsubmitted-secret')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新设置')
  expect(host.querySelector('input')).toBeNull()
  expect(host.textContent).not.toContain('private user')
  expect(admin.write).not.toHaveBeenCalled()
})

it('keeps a settings draft visible after an unrelated mail operation is forbidden', async () => {
  await render('email'); await click('开始编辑')
  await input(host.querySelector<HTMLInputElement>('#configuration-email_host')!, 'smtp.draft.example')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await workflowActions.run('notifications.mail-templates.reset', { name: 'verify' }).catch(() => {}) })
  expect(host.querySelector<HTMLInputElement>('#configuration-email_host')!.value).toBe('smtp.draft.example')
  expect(host.textContent).not.toContain('已清空表单并隐藏数据')
  expect(host.textContent).not.toContain('当前账号没有此操作的权限')
})

it('keeps a mail draft visible after an unrelated settings operation is forbidden', async () => {
  await render('mail'); await click('编辑源码'); await click('使用纯文本编辑')
  await input(host.querySelector<HTMLTextAreaElement>('textarea')!, 'unsaved mail')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await workflowActions.run('settings.configuration.update', { expected_version: initialVersion, values: { app_name: 'other page' } }).catch(() => {}) })
  expect(host.querySelector<HTMLTextAreaElement>('textarea')!.value).toBe('unsaved mail')
  expect(host.textContent).not.toContain('已清空编辑内容并隐藏数据')
  expect(host.textContent).not.toContain('当前账号没有此操作的权限')
})

it('renders template markup as text and sends test mail only after explicit confirmation', async () => {
  await render('mail')
  expect(host.querySelector('img')).toBeNull()
  expect(host.querySelector('pre')!.textContent).toContain('<img')
  const help = [...host.querySelectorAll('details')].find(node => node.querySelector('summary')?.textContent === '模板变量与填写说明')!
  expect(help.open).toBe(false)
  await act(async () => help.querySelector('summary')!.click())
  expect(help.open).toBe(true)
  expect(help.querySelector('code')?.textContent).toBe('code必需')
  expect(admin.write).not.toHaveBeenCalled()
  vi.spyOn(window, 'confirm').mockReturnValue(true)
  const email = host.querySelector<HTMLInputElement>('input[type=email]')!
  await input(email, 'operator@example.com')
  await act(async () => { host.querySelector('form.native-filters')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })) }); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('notifications.mail-templates.test', { name: 'verify', email: 'operator@example.com' }, expect.any(String))
})

it('clears template source when the session generation changes', async () => {
  await render('mail'); await click('编辑源码'); await click('使用纯文本编辑')
  await input(host.querySelector('textarea')!, 'old-session-private-template')
  await act(async () => workflowActions.reset()); await settle()
  expect(host.querySelector('textarea')).toBeNull()
  expect(host.textContent).not.toContain('old-session-private-template')
})

it('isolates the email preview and updates it from the source draft', async () => {
  await render('mail'); await click('预览邮件')
  const preview = host.querySelector('iframe')!
  expect(preview.getAttribute('sandbox')).toBe('')
  expect(preview.getAttribute('referrerpolicy')).toBe('no-referrer')
  expect(preview.srcdoc).toContain("default-src 'none'")
  expect(preview.srcdoc).toContain("form-action 'none'")
  expect(preview.srcdoc).toContain('<img src=x onerror=alert(1)>')
  expect(host.querySelector('img, script')).toBeNull()
  await click('编辑源码'); await click('使用纯文本编辑')
  await input(host.querySelector('textarea')!, '<h1>{{code}}</h1><script>bad()</script>')
  expect(preview.srcdoc).toContain('<h1>{{code}}</h1>')
  expect([...host.querySelectorAll('h1')].some(node => node.textContent === '{{code}}')).toBe(false)
  expect(host.querySelector('script')).toBeNull()
  expect(admin.write).not.toHaveBeenCalled()
})

it('retains unsaved email source after sending the saved template as a test', async () => {
  await render('mail'); await click('编辑源码'); await click('使用纯文本编辑')
  await input(host.querySelector('textarea')!, 'unsaved draft {{code}}')
  vi.spyOn(window, 'confirm').mockReturnValue(true)
  await act(async () => host.querySelector('form.native-filters')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('notifications.mail-templates.test', { name: 'verify' }, expect.any(String))
  expect(host.querySelector('textarea')!.value).toBe('unsaved draft {{code}}')
})

it('saves template source without evaluating HTML and replays an unknown reset with its original key', async () => {
  await render('mail'); await click('编辑源码'); await click('使用纯文本编辑')
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
  await render('mail'); await click('编辑源码'); await click('使用纯文本编辑')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新模板')
  expect(host.querySelector('textarea')).toBeNull()
  expect(host.querySelector('pre')).toBeNull()
  expect(button('发送测试邮件')).toBeUndefined()
})

async function confirmMail(text: string) {
  const dialog = document.querySelector('[role="dialog"]')!
  const target = [...dialog.querySelectorAll<HTMLButtonElement>('button')].find(node => node.textContent?.trim() === text)!
  await act(async () => target.click()); await settle()
}

it('keeps dirty mail when switching is cancelled and switches only after discard confirmation', async () => {
  const read = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation(async (operation, ...args) => {
    if (operation === 'notifications.mail-templates.list') return { data: [
      { name: 'verify', label: '邮箱验证', customized: true }, { name: 'notify', label: '通知邮件', customized: false },
    ], meta: {} } as never
    return read(operation, ...args)
  })
  await render('mail'); await click('编辑源码'); await click('使用纯文本编辑')
  await input(host.querySelector('textarea')!, 'private draft {{code}}')
  const select = host.querySelector('select')!
  async function choose() { await act(async () => { Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')!.set!.call(select, 'notify'); select.dispatchEvent(new Event('change', { bubbles: true })) }); await settle() }
  await choose(); expect(document.body.textContent).toContain('放弃未保存的邮件修改？')
  await confirmMail('继续编辑')
  expect(select.value).toBe('verify'); expect(host.querySelector('textarea')!.value).toBe('private draft {{code}}')
  await choose(); await confirmMail('放弃修改')
  expect(host.querySelector('select')!.value).toBe('notify'); expect(host.querySelector('textarea')).toBeNull()
  expect(admin.write).not.toHaveBeenCalled()
})

it('confirms cancelling a dirty mail edit and inserts variables at the selected source range', async () => {
  await render('mail'); await click('编辑源码'); await click('使用纯文本编辑')
  const textarea = host.querySelector('textarea')!
  await input(textarea, 'before REPLACE after')
  await act(async () => textarea.focus())
  textarea.setSelectionRange(7, 14)
  await click('插入变量 code')
  expect(textarea.value).toBe('before {{code}} after')
  await click('取消编辑'); await confirmMail('继续编辑')
  expect(host.querySelector('textarea')!.value).toBe('before {{code}} after')
  await click('取消编辑'); await confirmMail('放弃修改')
  expect(host.querySelector('textarea')).toBeNull()
  expect(admin.write).not.toHaveBeenCalled()
})

it('inserts mail variables into the focused subject and previews explicit sample data without saving it', async () => {
  await render('mail'); await click('编辑源码'); await click('使用纯文本编辑')
  const subject = host.querySelector<HTMLInputElement>('form.native-content-form input')!
  await input(subject, '代码 REPLACE'); await act(async () => subject.focus()); subject.setSelectionRange(3, 10)
  await click('插入变量 code')
  expect(subject.value).toBe('代码 {{code}}')
  await input(host.querySelector('textarea')!, '<p>{{code}}</p>')
  await click('预览邮件')
  const toggle = host.querySelector<HTMLInputElement>('.settings-sample-toggle input')!
  await act(async () => toggle.click())
  expect(host.querySelector('iframe')!.srcdoc).toContain('<p>123456</p>')
  expect(host.querySelector('.settings-preview-subject')!.textContent).toContain('代码 123456')
  expect(host.textContent).toContain('示例数据，仅用于本地预览')
  expect(subject.value).toBe('代码 {{code}}')
  expect(admin.write).not.toHaveBeenCalled()
})

it('shows only active captcha fields and never submits hidden provider drafts or blank saved secrets', async () => {
  const read = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation(async (operation, ...args) => operation === 'settings.configuration.get'
    ? { data: { version, values: { captcha_enable: true, captcha_type: 'recaptcha', recaptcha_site_key: 'site-a', turnstile_site_key: 'site-b' }, credentials: [{ name: 'recaptcha_key', configured: true }] }, meta: {} } as never
    : read(operation, ...args))
  await render('safe'); await click('开始编辑')
  expect(host.querySelector('#configuration-recaptcha_key')).not.toBeNull()
  expect(host.querySelector('#configuration-turnstile_secret_key')).toBeNull()
  await input(host.querySelector<HTMLInputElement>('#configuration-recaptcha_key')!, 'unsaved-hidden-secret')
  const select = host.querySelector<HTMLSelectElement>('#configuration-captcha_type')!
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')!.set!.call(select, 'turnstile'); select.dispatchEvent(new Event('change', { bubbles: true })) })
  expect(host.querySelector('#configuration-recaptcha_key')).toBeNull()
  expect(host.querySelector<HTMLInputElement>('#configuration-turnstile_site_key')!.value).toBe('site-b')
  await submit()
  expect(vi.mocked(admin.write).mock.calls[0]![1]).toEqual({ expected_version: initialVersion, values: { captcha_type: 'turnstile' } })
})

it('confirms cancellation of changed settings before discarding the draft', async () => {
  await render(); await click('开始编辑')
  await input(host.querySelector<HTMLInputElement>('#configuration-app_name')!, 'draft')
  await click('取消编辑'); await confirmMail('继续编辑')
  expect(host.querySelector<HTMLInputElement>('#configuration-app_name')!.value).toBe('draft')
  await click('取消编辑'); await confirmMail('放弃修改')
  expect(host.querySelector('form.native-content-form')).toBeNull()
  expect(admin.write).not.toHaveBeenCalled()
})

it('registers only saved Telegram settings after confirmation and preserves the original operation across navigation', async () => {
  await render('telegram')
  await click('注册已保存的 Webhook')
  expect(admin.write).not.toHaveBeenCalled()
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('registration acknowledgement lost'))
  await confirmMail('确认注册')
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(original).toEqual(['settings.telegram.webhook.register', { expected_version: initialVersion }, expect.any(String)])
  await render('email'); await render('telegram')
  expect(admin.write).toHaveBeenCalledTimes(1)
  await click('使用原 Webhook 操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
  expect(host.textContent).toContain('Telegram 已确认')
})

it('diagnoses SMTP separately from sending mail and never claims delivery from a successful handshake', async () => {
  const read = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation(async (operation, ...args) => operation === 'settings.smtp.inspect'
    ? { data: { connected: true, authentication_used: true }, meta: {} } as never : read(operation, ...args))
  await render('email'); await click('诊断已保存的 SMTP')
  expect(admin.read).toHaveBeenCalledWith('settings.smtp.inspect', { expected_version: initialVersion })
  expect(host.textContent).toContain('SMTP 连接握手已完成')
  expect(host.textContent).toContain('此结果不代表邮件已投递')
  expect(admin.write).not.toHaveBeenCalled()
  await click('开始编辑')
  expect(button('诊断已保存的 SMTP').disabled).toBe(true)
  expect(host.textContent).not.toContain('SMTP 连接握手已完成')
})

it('clears Telegram diagnostic status when a later inspection fails', async () => {
  const read = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation(async (operation, ...args) => operation === 'settings.telegram.webhook.inspect'
    ? { data: { configured: true, matches_saved: true, pending_updates: 3, delivery_error: false }, meta: {} } as never : read(operation, ...args))
  await render('telegram'); await click('检查 Webhook 状态')
  expect(host.textContent).toContain('已设置回调')
  vi.mocked(admin.read).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await click('检查 Webhook 状态')
  expect(host.textContent).not.toContain('已设置回调')
  expect(admin.write).not.toHaveBeenCalled()
})
