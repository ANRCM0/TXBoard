import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { Simulate } from 'react-dom/test-utils'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { SettingsForm } from './SettingsForm'
import { fetchSettings, saveSettings } from '../../api/config'
vi.mock('../../api/config', () => ({ fetchSettings: vi.fn(), saveSettings: vi.fn() }))
vi.mock('sonner', () => ({ toast: { success: vi.fn() } }))
let host: HTMLDivElement
let root: Root
let client: QueryClient
beforeEach(() => {
  ;(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true
  vi.resetAllMocks()
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
})
afterEach(() => { act(() => root.unmount()); host.remove(); client.clear() })
async function mount() {
  await act(async () => {
    root.render(<QueryClientProvider client={client}><MemoryRouter><SettingsForm settingKey="test" title="测试设置" description="设置" fields={[{ key: 'domains', label: '允许域名', type: 'string-array', saveMode: 'blur' }]} /></MemoryRouter></QueryClientProvider>)
    await new Promise(resolve => setTimeout(resolve, 20))
  })
  await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) })
}
it('saves the normalized latest array on the same blur, not the previous value', async () => {
  vi.mocked(fetchSettings).mockResolvedValue({ domains: ['old.example'] })
  vi.mocked(saveSettings).mockResolvedValue(true)
  await mount()
  const input = host.querySelector('textarea')!
  act(() => { input.value = 'new.example, second.example'; Simulate.change(input) })
  await act(async () => { Simulate.blur(input); await new Promise(resolve => setTimeout(resolve, 20)) })
  expect(saveSettings).toHaveBeenCalledWith({ domains: ['new.example', 'second.example'] })
  expect(client.getQueryData(['settings', 'test'])).toEqual({ domains: ['new.example', 'second.example'] })
})
it('does not expose a blank editable form when loading settings fails', async () => {
  vi.mocked(fetchSettings).mockRejectedValue(new Error('offline'))
  await mount()
  expect(host.querySelector('[role="alert"]')).not.toBeNull()
  expect(host.querySelector('textarea')).toBeNull()
  expect(saveSettings).not.toHaveBeenCalled()
})
it('serializes saves and keeps newer edits while an earlier request completes', async () => {
  vi.mocked(fetchSettings).mockResolvedValue({ domains: ['old.example'] })
  let finishFirst!: (value: unknown) => void
  vi.mocked(saveSettings).mockImplementationOnce(() => new Promise(resolve => { finishFirst = resolve })).mockResolvedValue(true)
  await mount()
  const input = host.querySelector('textarea')!
  const edit = async (value: string) => {
    act(() => { input.value = value; Simulate.change(input) })
    await act(async () => { Simulate.blur(input); await new Promise(resolve => setTimeout(resolve, 20)) })
  }
  await edit('first.example')
  await edit('latest.example')
  expect(saveSettings).toHaveBeenCalledTimes(1)
  await act(async () => { finishFirst(true); await new Promise(resolve => setTimeout(resolve, 30)) })
  expect(saveSettings).toHaveBeenLastCalledWith({ domains: ['latest.example'] })
  expect(input.value).toBe('latest.example')
  expect(client.getQueryData(['settings', 'test'])).toEqual({ domains: ['latest.example'] })
})
it('preserves the draft and allows retry after a failed save', async () => {
  vi.mocked(fetchSettings).mockResolvedValue({ domains: ['old.example'] })
  vi.mocked(saveSettings).mockRejectedValueOnce(new Error('offline')).mockResolvedValue(true)
  await mount()
  const input = host.querySelector('textarea')!
  act(() => { input.value = 'new.example'; Simulate.change(input) })
  await act(async () => { Simulate.blur(input); await new Promise(resolve => setTimeout(resolve, 20)) })
  expect(input.value).toBe('new.example')
  expect(host.querySelector('.config-autosave')?.textContent).toContain('保存失败')
  await act(async () => { host.querySelector<HTMLButtonElement>('.config-autosave button')!.click(); await new Promise(resolve => setTimeout(resolve, 20)) })
  expect(saveSettings).toHaveBeenLastCalledWith({ domains: ['new.example'] })
  expect(host.querySelector('.config-autosave')?.textContent).toContain('所有修改已保存')
})
