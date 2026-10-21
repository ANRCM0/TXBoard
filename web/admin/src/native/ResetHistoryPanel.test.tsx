import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin } from './session'
import { ResetHistoryPanel } from './ResetHistoryPanel'

vi.mock('./session', () => ({ admin: { read: vi.fn() }, useAdminSession: () => true }))
let host: HTMLDivElement, root: Root, client: QueryClient
const id = '9007199254740993'
const record = { id, user_id: id, subscription_id: '8', occurred_at: 1792400000, trigger: 'manual', subscription_version: '2', previous_period_id: '9', usage_period_id: '10', previous_upload_bytes: '1', previous_download_bytes: '2', previous_billable_bytes: '9007199254740999', previous_quota_bytes: '9223372036854775807' }
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.mocked(admin.read).mockReset().mockResolvedValue({ data: { records: [record], next_before_id: id }, meta: {} } as never)
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render() { act(() => root.render(<QueryClientProvider client={client}><MemoryRouter><ResetHistoryPanel /></MemoryRouter></QueryClientProvider>)); await settle() }
async function click(text: string) { await act(async () => [...host.querySelectorAll<HTMLButtonElement>('button')].find(button => button.textContent === text)!.click()); await settle() }
it('paginates immutable history with the exact cursor and starts from the beginning after a user filter', async () => {
  await render()
  expect(host.querySelector('a')!.getAttribute('href')).toBe('/user/' + id)
  expect(host.querySelector('[title="9007199254740999 字节"]')).not.toBeNull()
  await click('下一页历史')
  expect(admin.read).toHaveBeenLastCalledWith('subscription.lifecycle.resets.list', expect.objectContaining({ before_id: id, limit: 20 }), expect.any(AbortSignal))
  const input = host.querySelector<HTMLInputElement>('[aria-label="历史用户 ID"]')!
  act(() => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, id); input.dispatchEvent(new Event('input', { bubbles: true })) })
  act(() => input.form!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))); await settle()
  const latest = vi.mocked(admin.read).mock.lastCall![1]
  expect(latest).toMatchObject({ user_id: id, limit: 20 }); expect(latest).not.toHaveProperty('before_id')
})
it('withdraws retained historical records when authorization cannot be confirmed', async () => {
  await render()
  vi.mocked(admin.read).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新历史')
  expect(host.querySelector('table')).toBeNull()
  expect(host.querySelector('a')).toBeNull()
  expect(host.textContent).toContain('已隐藏重置记录')
})
