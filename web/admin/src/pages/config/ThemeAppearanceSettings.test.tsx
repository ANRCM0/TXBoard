import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { Simulate } from 'react-dom/test-utils'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { fetchSettings, saveSettings } from '../../api/config'
import { ThemeAppearanceSettings } from './ThemeAppearanceSettings'

vi.mock('../../api/config', () => ({ fetchSettings: vi.fn(), saveSettings: vi.fn() }))
vi.mock('sonner', () => ({ toast: { success: vi.fn() } }))

let root: Root
let host: HTMLDivElement
let client: QueryClient
beforeEach(() => {
  ;(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true
  vi.clearAllMocks()
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
})
afterEach(() => {
  act(() => root.unmount())
  host.remove()
  client.clear()
})
it('edits legacy appearance settings from theme management without switching active theme', async () => {
  vi.mocked(fetchSettings).mockResolvedValue({
    frontend_theme: 'TXBoard',
    frontend_theme_sidebar: 'light',
    frontend_theme_header: 'dark',
    frontend_theme_color: 'default',
    frontend_background_url: '',
  })
  vi.mocked(saveSettings).mockResolvedValue(true)
  await act(async () => {
    root.render(<QueryClientProvider client={client}><ThemeAppearanceSettings /></QueryClientProvider>)
    await new Promise(resolve => setTimeout(resolve, 25))
  })
  // React Query resolves asynchronously; flush the settled query/render cycle.
  await act(async () => { await new Promise(resolve => setTimeout(resolve, 50)) })
  const selects = host.querySelectorAll<HTMLSelectElement>('select')
  expect(selects).toHaveLength(3)
  act(() => { selects[2].value = 'green'; Simulate.change(selects[2]) })
  await act(async () => {
    host.querySelector<HTMLButtonElement>('.theme-appearance-actions button')!.click()
    await new Promise(resolve => setTimeout(resolve, 25))
  })
  expect(saveSettings).toHaveBeenCalledWith({
    frontend_theme_sidebar: 'light',
    frontend_theme_header: 'dark',
    frontend_theme_color: 'green',
    frontend_background_url: '',
  })
  expect(JSON.stringify(vi.mocked(saveSettings).mock.calls)).not.toContain('frontend_theme"')
})
