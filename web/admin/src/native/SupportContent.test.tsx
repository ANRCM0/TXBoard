import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, attachmentTransfers, workflowActions } from './session'
import { TicketsPage } from './TicketsPage'
import { ContentPage } from './ContentPage'
import { ConfirmDialogHost, dismissConfirm } from '../components/ui/ConfirmDialog'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  const state = { busy: false, pending: false, denied: false, entries: [] }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)),
    attachmentTransfers: { subscribe: () => () => {}, snapshot: () => state, markAttached: vi.fn(), readyIds: () => [],
      upload: vi.fn(), recover: vi.fn(), refresh: vi.fn(), download: vi.fn(), cancel: vi.fn(), resume: vi.fn() } }
})
let host: HTMLDivElement, root: Root, client: QueryClient
let version: string
const ticket = () => ({ id: '7', user_id: '12', subject: 'private ticket', status: 0, reply_status: 0,
  assigned_admin_id: null, row_version: version, updated_at: '2026-10-19T00:00:00Z' })
const notice = () => ({ id: '9', title: 'private notice', content: '<img src=x onerror=alert(1)>',
  img_url: null, tags: [], popup: false, show: false, sort: 0, audience: 'authenticated',
  publish_at: null, expire_at: null, row_version: version })
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } }); version = '1'
  workflowActions.reset(); vi.mocked(admin.write).mockReset().mockResolvedValue({ data: {} } as never)
  vi.mocked(admin.read).mockReset().mockImplementation(async operation => {
    if (operation === 'support.admin-tickets.list') return { data: [ticket()], meta: { total: 1, last_page: 1 } } as never
    if (operation === 'support.admin-tickets.get') return { data: ticket(), meta: { current_admin_id: '99' } } as never
    if (operation === 'content.notices.list') return { data: [notice()], meta: { total: 1, last_page: 1 } } as never
    if (operation === 'content.notices.get') return { data: notice(), meta: {} } as never
    return { data: [], meta: { has_more: false, next_after_id: '0' } } as never
  })
})
afterEach(() => { act(() => { dismissConfirm(); root.unmount() }); client.clear(); host.remove(); workflowActions.reset() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(content: boolean | 'knowledge' = false) {
  await act(async () => root.render(<QueryClientProvider client={client}>{content ? <ContentPage kind={content === 'knowledge' ? 'knowledge' : 'notices'} /> : <TicketsPage />}<ConfirmDialogHost /></QueryClientProvider>))
  await settle()
}
function button(text: string) { return [...document.body.querySelectorAll<HTMLButtonElement>('button')].find(node => node.getAttribute('aria-label') === text || node.textContent === text)! }
async function click(text: string) { await act(async () => button(text).click()); await settle() }
async function openRowMenu() {
  await act(async () => document.querySelector<HTMLButtonElement>('.row-actions-trigger')!.click()); await settle()
}
async function input(node: HTMLInputElement | HTMLTextAreaElement, value: string) {
  await act(async () => {
    Object.getOwnPropertyDescriptor(node instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype, 'value')!.set!.call(node, value)
    node.dispatchEvent(new Event('input', { bubbles: true }))
  })
}

it('claims using the observed ticket version and refreshes assignment after a conflict', async () => {
  await render(); await click('查看')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('TICKET_VERSION_CONFLICT', 409))
  await click('认领给我')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('support.admin-tickets.claim', { ticket_id: '7', expected_version: '1' }, expect.any(String))
  expect(workflowActions.snapshot().pending).toBeUndefined()
  expect(vi.mocked(admin.read).mock.calls.filter(call => call[0] === 'support.admin-tickets.get').length).toBeGreaterThan(1)
})

it('retains the original close key across navigation and replays only after explicit recovery', async () => {
  await render(); await click('查看')
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost acknowledgement'))
  await click('关闭工单')
  expect(admin.write).not.toHaveBeenCalled()
  await click('确认关闭工单')
  const original = vi.mocked(admin.write).mock.calls[0]
  await render(true)
  expect(document.body.textContent).not.toContain('使用原操作确认结果')
  expect(button('新增').disabled).toBe(true)
  await render(); expect(admin.write).toHaveBeenCalledTimes(1)
  await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('hides cached ticket content and write controls after forbidden detail refresh', async () => {
  await render(); await click('查看')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await client.invalidateQueries() }); await settle()
  expect(document.body.textContent).not.toContain('private ticket')
  expect(button('关闭工单')).toBeUndefined()
  expect(admin.write).not.toHaveBeenCalled()
})

it('renders untrusted content as textarea text and submits the version originally opened', async () => {
  await render(true); await click('编辑')
  const textarea = document.body.querySelector('textarea')!
  expect(textarea.value).toContain('<img')
  expect(document.body.querySelector('img')).toBeNull()
  await input(textarea, 'reviewed content')
  version = '2'
  await act(async () => { await client.invalidateQueries({ queryKey: ['native-admin', 'content'] }) }); await settle()
  expect(document.body.querySelector('textarea')!.value).toBe('reviewed content')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('CONTENT_VERSION_CONFLICT', 409))
  await act(async () => { document.body.querySelector('form.native-content-form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })) }); await settle()
  expect(vi.mocked(admin.write).mock.calls[0]![1]).toMatchObject({ content_id: '9', expected_version: '1', content: 'reviewed content' })
  expect(document.body.querySelector('textarea')).toBeNull()
  expect(document.body.textContent).toContain('记录已变化')
  await click('编辑')
  expect(document.body.querySelector('textarea')!.value).toContain('<img')
})

it('confirms an unknown content publish using the original key and keeps support recovery separate', async () => {
  await render(true)
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost acknowledgement'))
  await openRowMenu(); await click('启用')
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(original![0]).toBe('content.notices.visibility.toggle')
  await render()
  expect(document.body.textContent).not.toContain('使用原操作确认结果')
  await render(true); await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
})

it('blocks attachment download and reply content after support permissions are revoked', async () => {
  await render(); await click('查看')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await client.invalidateQueries() }); await settle()
  expect(document.body.querySelector('input[type="file"]')).toBeNull()
  expect(attachmentTransfers.download).not.toHaveBeenCalled()
})

it('requires a successful explicit authorization refresh before recovering a forbidden unknown publish', async () => {
  await render(true)
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403, undefined, true))
  await openRowMenu(); await click('启用')
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(button('使用原操作确认结果').disabled).toBe(true)
  await click('刷新')
  expect(admin.write).toHaveBeenCalledTimes(1)
  expect(button('使用原操作确认结果').disabled).toBe(false)
  await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
})

it('does not apply a late content write acknowledgement after session generation changes', async () => {
  await render(true); await click('编辑')
  let resolve!: (value: never) => void
  vi.mocked(admin.write).mockImplementationOnce(() => new Promise(done => { resolve = done }))
  await act(async () => { document.body.querySelector('form.native-content-form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })) })
  const reads = vi.mocked(admin.read).mock.calls.length
  await act(async () => { workflowActions.reset(); resolve({ data: {} } as never) }); await settle()
  expect(vi.mocked(admin.read).mock.calls.length).toBe(reads)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('recovers knowledge pending writes only from the knowledge page using the original key', async () => {
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost acknowledgement'))
  await workflowActions.run('content.knowledge.visibility.toggle', { content_id: '9', expected_version: '1', show: true }).catch(() => {})
  const original = vi.mocked(admin.write).mock.calls[0]
  await render(true)
  expect(button('使用原操作确认结果')).toBeUndefined()
  expect(button('新增').disabled).toBe(true)
  await click('刷新')
  expect(admin.write).toHaveBeenCalledTimes(1)
  await render('knowledge'); await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
})

it('discards a delayed notices sort read after navigating to knowledge', async () => {
  await render(true)
  let resolve!: (value: never) => void
  vi.mocked(admin.read).mockImplementationOnce(() => new Promise(done => { resolve = done }))
  await click('编辑完整排序')
  await render('knowledge')
  await act(async () => resolve({ data: [notice()], meta: { total: 1, last_page: 1 } } as never)); await settle()
  expect(button('保存排序')).toBeUndefined()
  expect(document.body.textContent).not.toContain('private notice')
  expect(button('编辑完整排序').disabled).toBe(false)
})


it('asks for archive confirmation in the shared dialog and keeps the observed version', async () => {
  await render(true); await openRowMenu(); await click('归档')
  const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
  expect(dialog.textContent).toContain('private notice')
  expect(admin.write).not.toHaveBeenCalled()
  await act(async () => [...dialog.querySelectorAll<HTMLButtonElement>('button')].find(node => node.textContent === '确认归档')!.click()); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('content.notices.delete', { content_id: '9', expected_version: '1' }, expect.any(String))
})

it('does not archive through a dialog opened before navigating to another content kind', async () => {
  await render(true); await openRowMenu(); await click('归档'); await render('knowledge')
  await act(async () => [...document.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')].find(node => node.textContent === '确认归档')!.click()); await settle()
  expect(admin.write).not.toHaveBeenCalled()
})

it('focuses the title when creating and editing content', async () => {
  await render(true); await click('新增')
  expect(document.activeElement).toBe(document.body.querySelector('.support-content-editor input'))
  await click('关闭编辑'); await click('编辑')
  expect(document.activeElement).toBe(document.body.querySelector('.support-content-editor input'))
})

it('drags the complete list across pages and saves each originally observed version', async () => {
  await render(true)
  vi.mocked(admin.read).mockImplementation(async (operation, input) => {
    if (operation === 'content.notices.list') return { data: [{ ...notice(), id: String(input && 'page' in input && input.page === 2 ? 10 : 9) }], meta: { total: 2, last_page: 2 } } as never
    return { data: [], meta: { has_more: false } } as never
  })
  await click('编辑完整排序')
  const rows = document.body.querySelectorAll<HTMLElement>('.support-sort ol > li')
  expect(rows.length).toBe(2)
  const transfer = { effectAllowed: '', dropEffect: '', setData: vi.fn() }
  function drag(node: Element, type: string) { const event = new Event(type, { bubbles: true, cancelable: true }); Object.defineProperty(event, 'dataTransfer', { value: transfer }); node.dispatchEvent(event) }
  await act(async () => { drag(rows[0]!.querySelector('[draggable]')!, 'dragstart'); drag(rows[1]!, 'dragover'); drag(rows[1]!, 'drop') })
  expect(document.body.querySelector('[role="status"].support-sort-feedback')!.textContent).toContain('第 2 项')
  await click('保存排序')
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('content.notices.sort', { items: [{ id: '10', expected_version: '1' }, { id: '9', expected_version: '1' }] }, expect.any(String))
})

it('preserves keyboard movement and ignores drops without a local drag source', async () => {
  await render(true)
  vi.mocked(admin.read).mockImplementation(async operation => operation === 'content.notices.list'
    ? { data: [notice(), { ...notice(), id: '10' }], meta: { total: 2, last_page: 1 } } as never
    : { data: [], meta: { has_more: false } } as never)
  await click('编辑完整排序')
  const row = document.body.querySelector('.support-sort ol > li')!
  await act(async () => row.dispatchEvent(new Event('drop', { bubbles: true, cancelable: true })))
  await click('下移'); await click('保存排序')
  expect(vi.mocked(admin.write).mock.calls[0]![1]).toEqual({ items: [{ id: '10', expected_version: '1' }, { id: '9', expected_version: '1' }] })
})

it('keeps ticket text until a close draft confirmation is explicitly accepted', async () => {
  await render(); await click('查看')
  const reply = document.querySelector<HTMLTextAreaElement>('#native-ticket-reply')!
  await input(reply, 'unsent reply')
  await click('关闭详情')
  expect(document.querySelector('[aria-label="放弃未发送的回复？"]')).not.toBeNull()
  await click('取消')
  expect(document.querySelector<HTMLTextAreaElement>('#native-ticket-reply')!.value).toBe('unsent reply')
  await click('关闭详情'); await click('放弃并关闭')
  expect(document.querySelector('[aria-label="工单会话"]')).toBeNull()
  expect(admin.write).not.toHaveBeenCalled()
})

it('confirms switching a ticket sidebar without moving the previous reply to the new ticket', async () => {
  const previousRead = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation((operation, input, ...args) => {
    if (operation === 'support.admin-tickets.list') return Promise.resolve({ data: [ticket(), { ...ticket(), id: '8', subject: 'another ticket' }], meta: { total: 2, last_page: 1 } } as never)
    if (operation === 'support.admin-tickets.get') return Promise.resolve({ data: { ...ticket(), id: (input as { ticket_id: string }).ticket_id }, meta: { current_admin_id: '99' } } as never)
    return previousRead(operation, input, ...args)
  })
  await render(); await click('查看')
  await input(document.querySelector<HTMLTextAreaElement>('#native-ticket-reply')!, 'first ticket draft')
  const second = () => [...document.querySelectorAll<HTMLButtonElement>('.support-ticket-sidebar button')].find(node => node.textContent?.includes('another ticket'))!
  await act(async () => second().click()); await settle()
  expect(document.querySelector<HTMLTextAreaElement>('#native-ticket-reply')!.value).toBe('first ticket draft')
  await click('放弃并切换')
  expect(document.querySelector<HTMLTextAreaElement>('#native-ticket-reply')!.value).toBe('')
  expect(document.querySelector('.support-ticket-sidebar [aria-current="true"]')!.textContent).toContain('another ticket')
  expect(admin.write).not.toHaveBeenCalled()
})

it('sends only Ctrl Enter outside composition and keeps the observed ticket version', async () => {
  await render(); await click('查看')
  const reply = document.querySelector<HTMLTextAreaElement>('#native-ticket-reply')!
  await input(reply, 'reviewed reply')
  await act(async () => reply.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, isComposing: true, bubbles: true })))
  await act(async () => reply.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })))
  expect(admin.write).not.toHaveBeenCalled()
  await act(async () => reply.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, bubbles: true }))); await settle()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('support.admin-tickets.reply', { ticket_id: '7', expected_version: '1', message: 'reviewed reply', attachment_ids: [] }, expect.any(String))
})

it('does not refresh another page after a detached ticket reply finishes', async () => {
  await render(); await click('查看')
  await input(document.querySelector<HTMLTextAreaElement>('#native-ticket-reply')!, 'reply in flight')
  let resolve!: (value: never) => void
  vi.mocked(admin.write).mockImplementationOnce(() => new Promise(done => { resolve = done }))
  await click('发送回复'); await render(true)
  const reads = vi.mocked(admin.read).mock.calls.length
  await act(async () => resolve({ data: {} } as never)); await settle()
  expect(vi.mocked(admin.read).mock.calls.length).toBe(reads)
  expect(document.querySelector('[aria-label="工单会话"]')).toBeNull()
})

it('does not move a reader away from earlier messages when a new page arrives', async () => {
  const previousRead = vi.mocked(admin.read).getMockImplementation()!
  let newer = false
  vi.mocked(admin.read).mockImplementation((operation, ...args) => operation === 'support.admin-tickets.messages.list'
    ? Promise.resolve({ data: [{ id: newer ? '2' : '1', author_kind: 'user', created_at: '2026-10-19T00:00:00Z', message: 'message', attachments: [] }], meta: { has_more: false, next_after_id: newer ? '2' : '1' } } as never)
    : previousRead(operation, ...args))
  await render(); await click('查看')
  const log = document.querySelector<HTMLElement>('[role="log"]')!
  Object.defineProperties(log, { scrollHeight: { value: 1000, configurable: true }, clientHeight: { value: 200, configurable: true } })
  log.scrollTop = 100
  await act(async () => log.dispatchEvent(new Event('scroll', { bubbles: true })))
  newer = true
  await act(async () => { await client.invalidateQueries({ queryKey: ['native-admin', 'support', 'messages'] }) }); await settle()
  expect(log.scrollTop).toBe(100)
  await click('查看最新已读取消息')
  expect(log.scrollTop).toBe(1000)
})

it('protects the content draft and keeps save controls outside the scrollable form body', async () => {
  await render(true); await click('编辑')
  const dialog = document.querySelector('[aria-label="编辑内容"]')!
  expect(dialog.querySelector('.modal-footer button[type="submit"]')!.getAttribute('form')).toBe('native-notices-editor-form')
  expect(dialog.querySelector('.modal-body form')).not.toBeNull()
  await input(dialog.querySelector('textarea')!, 'unsaved body')
  await click('关闭编辑'); await click('取消')
  expect(dialog.querySelector<HTMLTextAreaElement>('textarea')!.value).toBe('unsaved body')
  await click('关闭编辑'); await click('放弃并关闭')
  expect(document.querySelector('[aria-label="编辑内容"]')).toBeNull()
  expect(admin.write).not.toHaveBeenCalled()
})

it('preserves custom knowledge language and limits category filters to the loaded page', async () => {
  const knowledge = { ...notice(), category: '帮助', language: 'x-custom', body: 'knowledge body' }
  const previousRead = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation((operation, ...args) => operation === 'content.knowledge.list'
    ? Promise.resolve({ data: [knowledge, { ...knowledge, id: '10', category: '其他' }], meta: { page: 1, per_page: 20, total: 50, last_page: 3 } } as never)
    : operation === 'content.knowledge.get' ? Promise.resolve({ data: knowledge, meta: {} } as never) : previousRead(operation, ...args))
  await render('knowledge')
  const category = [...document.querySelectorAll<HTMLSelectElement>('select')].find(node => node.closest('label')?.textContent?.startsWith('当前页分类'))!
  const readCount = vi.mocked(admin.read).mock.calls.length
  await act(async () => { category.value = '帮助'; category.dispatchEvent(new Event('change', { bubbles: true })) })
  expect(document.querySelectorAll('tbody tr')).toHaveLength(1)
  expect(document.body.textContent).toContain('当前显示 1 / 2 项')
  expect(vi.mocked(admin.read).mock.calls.length).toBe(readCount)
  await click('编辑')
  const custom = [...document.querySelectorAll<HTMLInputElement>('[role="dialog"] input')].find(node => node.closest('label')?.textContent === '自定义语言代码')!
  expect(custom.value).toBe('x-custom')
  expect(document.querySelector('[role="dialog"]')!.textContent).toContain('English · en-US')
  await act(async () => document.querySelector('form.native-content-form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))); await settle()
  expect(vi.mocked(admin.write).mock.calls[0]![1]).toMatchObject({ language: 'x-custom', expected_version: '1' })
})

it('clears the content modal draft on permission loss', async () => {
  await render(true); await click('编辑')
  await input(document.querySelector<HTMLTextAreaElement>('[role="dialog"] textarea')!, 'private draft')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await client.invalidateQueries({ queryKey: ['native-admin', 'content'] }) }); await settle()
  expect(document.querySelector('[aria-label="编辑内容"]')).toBeNull()
  expect(document.body.textContent).not.toContain('private notice')
  expect(admin.write).not.toHaveBeenCalled()
})
