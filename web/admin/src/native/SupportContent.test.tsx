import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, attachmentTransfers, workflowActions } from './session'
import { TicketsPage } from './TicketsPage'
import { ContentPage } from './ContentPage'

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
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(content: boolean | 'knowledge' = false) {
  await act(async () => root.render(<QueryClientProvider client={client}>{content ? <ContentPage kind={content === 'knowledge' ? 'knowledge' : 'notices'} /> : <TicketsPage />}</QueryClientProvider>))
  await settle()
}
function button(text: string) { return [...host.querySelectorAll<HTMLButtonElement>('button')].find(node => node.getAttribute('aria-label') === text || node.textContent === text)! }
async function click(text: string) { await act(async () => button(text).click()); await settle() }
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
  const original = vi.mocked(admin.write).mock.calls[0]
  await render(true)
  expect(host.textContent).not.toContain('使用原操作确认结果')
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
  expect(host.textContent).not.toContain('private ticket')
  expect(button('关闭工单')).toBeUndefined()
  expect(admin.write).not.toHaveBeenCalled()
})

it('renders untrusted content as textarea text and submits the version originally opened', async () => {
  await render(true); await click('编辑')
  const textarea = host.querySelector('textarea')!
  expect(textarea.value).toContain('<img')
  expect(host.querySelector('img')).toBeNull()
  await input(textarea, 'reviewed content')
  version = '2'
  await act(async () => { await client.invalidateQueries({ queryKey: ['native-admin', 'content'] }) }); await settle()
  expect(host.querySelector('textarea')!.value).toBe('reviewed content')
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('CONTENT_VERSION_CONFLICT', 409))
  await act(async () => { host.querySelector('form.native-content-form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })) }); await settle()
  expect(vi.mocked(admin.write).mock.calls[0]![1]).toMatchObject({ content_id: '9', expected_version: '1', content: 'reviewed content' })
  expect(host.querySelector('textarea')).toBeNull()
  expect(host.textContent).toContain('记录已变化')
  await click('编辑')
  expect(host.querySelector('textarea')!.value).toContain('<img')
})

it('confirms an unknown content publish using the original key and keeps support recovery separate', async () => {
  await render(true)
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost acknowledgement'))
  await click('启用')
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(original![0]).toBe('content.notices.visibility.toggle')
  await render()
  expect(host.textContent).not.toContain('使用原操作确认结果')
  await render(true); await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
})

it('blocks attachment download and reply content after support permissions are revoked', async () => {
  await render(); await click('查看')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await act(async () => { await client.invalidateQueries() }); await settle()
  expect(host.querySelector('input[type="file"]')).toBeNull()
  expect(attachmentTransfers.download).not.toHaveBeenCalled()
})

it('requires a successful explicit authorization refresh before recovering a forbidden unknown publish', async () => {
  await render(true)
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403, undefined, true))
  await click('启用')
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
  await act(async () => { host.querySelector('form.native-content-form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })) })
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
  expect(host.textContent).not.toContain('private notice')
  expect(button('编辑完整排序').disabled).toBe(false)
})
