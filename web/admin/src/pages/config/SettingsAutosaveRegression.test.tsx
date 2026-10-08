import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { Simulate } from 'react-dom/test-utils'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchSettings, saveSettings } from '../../api/config'
import { SystemSettingsPage, normalizeSystemSettings } from './SystemSettingsPage'
import { FeatureEntrySettingsPage } from './FeatureEntrySettingsPage'

vi.mock('../../api/config', () => ({ fetchSettings: vi.fn(), saveSettings: vi.fn() }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

let root: Root
let host: HTMLDivElement
let client: QueryClient

const site = {
  app_name: 'Original TXBoard',
  app_description: 'description',
  app_url: 'https://example.test',
  logo: null,
  subscribe_url: null,
  tos_url: null,
  try_out_plan_id: null,
  try_out_hour: 1,
  currency: 'CNY',
  currency_symbol: '¥',
  force_https: false,
  stop_register: false,
  ticket_must_wait_reply: false,
  traffic_warn_rate: null,
}
const features = {
  invite_enable: true,
  commission_enable: true,
  gift_card_enable: true,
  coupon_enable: true,
  ticket_enable: true,
  knowledge_enable: true,
  traffic_log_enable: true,
  announcement_enable: true,
  register_enable: true,
  logo: null,
  subscribe_url: null,
  tos_url: null,
}

beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  vi.clearAllMocks()
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
})
afterEach(() => {
  vi.useRealTimers()
  act(() => root.unmount())
  host.remove()
  client.clear()
})

async function mount(page: React.ReactNode) {
  await act(async () => {
    root.render(<QueryClientProvider client={client}><MemoryRouter>{page}</MemoryRouter></QueryClientProvider>)
    await new Promise(resolve => setTimeout(resolve, 30))
  })
  await act(async () => { await new Promise(resolve => setTimeout(resolve, 30)) })
}

async function flushAutosave() {
  await act(async () => { await vi.advanceTimersByTimeAsync(1100) })
  await act(async () => { await Promise.resolve() })
}

describe('issue #101: site settings autosave', () => {
  it('normalizes nullable site fields before validation and editing', () => {
    const normalized = normalizeSystemSettings(site)
    expect(normalized.logo).toBe('')
    expect(normalized.subscribe_url).toBe('')
    expect(normalized.tos_url).toBe('')
  })

  it('sends an autosave request and shows success for edits when the API returned null URLs', async () => {
    vi.mocked(fetchSettings).mockResolvedValue(site)
    vi.mocked(saveSettings).mockResolvedValue(true)
    await mount(<SystemSettingsPage />)
    const name = host.querySelector<HTMLInputElement>('input[name="app_name"]')!
    expect(name.value).toBe('Original TXBoard')

    vi.useFakeTimers()
    act(() => { name.value = 'New TXBoard'; Simulate.change(name) })
    expect(host.querySelector('.config-autosave')?.textContent).toContain('有待保存')
    await flushAutosave()

    expect(saveSettings).toHaveBeenCalledTimes(1)
    expect(saveSettings).toHaveBeenCalledWith(expect.objectContaining({
      app_name: 'New TXBoard', logo: '', subscribe_url: '', tos_url: '',
    }))
    expect(host.querySelector('.config-autosave')?.textContent).toContain('设置保存成功')
  })

  it('keeps unsaved edits visible and offers retry on failed request', async () => {
    vi.mocked(fetchSettings).mockResolvedValue(site)
    vi.mocked(saveSettings).mockRejectedValueOnce(new Error('server rejected')).mockResolvedValue(true)
    await mount(<SystemSettingsPage />)
    const name = host.querySelector<HTMLInputElement>('input[name="app_name"]')!

    vi.useFakeTimers()
    act(() => { name.value = 'Draft name'; Simulate.change(name) })
    await flushAutosave()
    expect(host.querySelector('.config-autosave')?.textContent).toContain('保存失败')
    expect(name.value).toBe('Draft name')
    await act(async () => {
      host.querySelector<HTMLButtonElement>('.config-autosave button')!.click()
      await Promise.resolve()
    })
    await act(async () => { await Promise.resolve() })
    expect(saveSettings).toHaveBeenCalledTimes(2)
    expect(host.querySelector('.config-autosave')?.textContent).toContain('设置保存成功')
  })
})

describe('issue #101: feature switches autosave', () => {
  it('persists a toggle and displays success despite unrelated nullable site fields', async () => {
    vi.mocked(fetchSettings).mockResolvedValue(features)
    vi.mocked(saveSettings).mockResolvedValue(true)
    await mount(<FeatureEntrySettingsPage />)

    const toggle = host.querySelector<HTMLButtonElement>('[role="switch"][aria-label="知识库"]')!
    expect(toggle.getAttribute('aria-checked')).toBe('true')
    vi.useFakeTimers()
    act(() => { toggle.click() })
    await flushAutosave()

    expect(saveSettings).toHaveBeenCalledWith(expect.objectContaining({ knowledge_enable: false }))
    expect(host.querySelector('.config-autosave')?.textContent).toContain('设置保存成功')
  })

  it('shows failure with retry for a rejected toggle save', async () => {
    vi.mocked(fetchSettings).mockResolvedValue(features)
    vi.mocked(saveSettings).mockRejectedValueOnce(new Error('network error')).mockResolvedValue(true)
    await mount(<FeatureEntrySettingsPage />)

    vi.useFakeTimers()
    act(() => { host.querySelector<HTMLButtonElement>('[role="switch"][aria-label="知识库"]')!.click() })
    await flushAutosave()
    expect(host.querySelector('.config-autosave')?.textContent).toContain('保存失败')
    await act(async () => { host.querySelector<HTMLButtonElement>('.config-autosave button')!.click(); await Promise.resolve() })
    await act(async () => { await Promise.resolve() })
    expect(saveSettings).toHaveBeenCalledTimes(2)
    expect(host.querySelector('.config-autosave')?.textContent).toContain('设置保存成功')
  })
})
