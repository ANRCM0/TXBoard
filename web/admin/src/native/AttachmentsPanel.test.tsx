import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { attachmentTransfers } from './session'
import { AttachmentsPanel } from './AttachmentsPanel'

const fixture = vi.hoisted(() => ({ state: { busy: false, pending: true, denied: true, entries: [], error: '' } }))
vi.mock('./session', () => ({ attachmentTransfers: {
  subscribe: () => () => {}, snapshot: () => fixture.state,
  recover: vi.fn(), refresh: vi.fn(), upload: vi.fn(), download: vi.fn(), resume: vi.fn(), cancel: vi.fn(),
} }))
let host: HTMLDivElement, root: Root
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  fixture.state = { busy: false, pending: true, denied: true, entries: [], error: '' }
  vi.clearAllMocks()
})
afterEach(() => { act(() => root.unmount()); host.remove() })
function button(text: string) { return [...host.querySelectorAll<HTMLButtonElement>('button')].find(node => node.getAttribute('aria-label') === text || node.textContent === text)! }
async function render(disabled = false) { await act(async () => root.render(<AttachmentsPanel ticketId="7" disabled={disabled} />)) }

it('permits read-only authorization refresh for denied pending attachments without replaying the write', async () => {
  await render()
  expect(button('使用原操作确认附件结果').disabled).toBe(true)
  expect(button('重新读取附件权限').disabled).toBe(false)
  await act(async () => button('重新读取附件权限').click())
  expect(attachmentTransfers.refresh).toHaveBeenCalledExactlyOnceWith('7')
  expect(attachmentTransfers.recover).not.toHaveBeenCalled()
  fixture.state = { ...fixture.state, denied: false }
  await render()
  expect(button('使用原操作确认附件结果').disabled).toBe(false)
  await act(async () => button('使用原操作确认附件结果').click())
  expect(attachmentTransfers.recover).toHaveBeenCalledTimes(1)
})

it('honors the parent lock for both permission checks and recovery', async () => {
  await render(true)
  expect(button('使用原操作确认附件结果').disabled).toBe(true)
  expect(button('重新读取附件权限').disabled).toBe(true)
  expect(host.querySelector('input')!.disabled).toBe(true)
  expect(host.querySelector('input')!.accept).toBe('image/png,image/jpeg,application/pdf,text/plain')
  expect(host.textContent).toContain('附件可能包含个人信息')
  expect(attachmentTransfers.upload).not.toHaveBeenCalled()
  expect(attachmentTransfers.download).not.toHaveBeenCalled()
})
