import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { TopBar } from './TopBar'
import { admin, logoutAdmin } from '../../native/session'
import { setLocale } from '../../lib/i18n'
vi.mock('../../lib/routePreload', () => ({ preloadAdminRoute: vi.fn() }))
vi.mock('../../native/session', () => ({ admin: { read: vi.fn() }, logoutAdmin: vi.fn() }))
let host: HTMLDivElement
let root: Root
let queryClient: QueryClient
function Location() { return <output>{useLocation().pathname}</output> }
beforeEach(() => {
  vi.mocked(admin.read).mockReset().mockResolvedValue({ data: { id: '9007199254740993', email: 'admin@example.test' }, meta: {} } as never)
  vi.mocked(logoutAdmin).mockReset().mockResolvedValue({ serverConfirmed: true })
  ;(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
  queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity } } })
  queryClient.setQueryData(['moduleRegistry'], {
    modules: [
      {
        id: 'access_audit',
        name: 'Access Audit',
        version: '1.2.0',
        type: 'plugin',
        source: 'user',
        installed: true,
        enabled: true,
        active: null,
        health: 'healthy',
        capabilities: ['admin.menu'],
        compatibility: { txboard: '*' },
        admin: {
          navigation: [
            { id: 'dashboard', title: 'Dashboard', path: 'dashboard', order: 10 },
          ],
        },
      },
    ],
    errors: [],
    summary: {
      total: 1,
      health: {
        healthy: 1,
        degraded: 0,
        disabled: 0,
        failed: 0,
        incompatible: 0,
        missing_dependency: 0,
      },
      discovery_errors: 0,
    },
  })
  act(() => root.render(<QueryClientProvider client={queryClient}><MemoryRouter><TopBar onOpenMenu={() => {}} /><Location /></MemoryRouter></QueryClientProvider>))
})
afterEach(async () => { act(() => root.unmount()); queryClient.clear(); host.remove(); await setLocale('zh-CN') })
function key(key: string) { act(() => document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }))) }
it('opens an explicit account menu instead of signing out from the compact trigger', async () => {
  const trigger = host.querySelector<HTMLButtonElement>('[aria-label="账户菜单"]')!
  act(() => trigger.click())
  await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) })
  expect(admin.read).toHaveBeenCalledExactlyOnceWith('identity.admin.get', {}, expect.any(AbortSignal))
  expect(document.querySelector('.admin-account-menu')?.textContent).toContain('admin@example.test')
  expect(document.querySelector('.admin-account-menu')?.textContent).toContain('9007199254740993')
  expect(logoutAdmin).not.toHaveBeenCalled()
  const settings = [...document.querySelectorAll<HTMLButtonElement>('.admin-account-actions button')].find(button => button.textContent === '系统设置')!
  act(() => settings.click())
  expect(host.querySelector('output')?.textContent).toBe('/config/system')
  expect(document.querySelector('.admin-account-menu')).toBeNull()
})
it('supports arrow selection, Enter navigation, and closes the search', () => {
  act(() => host.querySelector<HTMLButtonElement>('.admin-command-trigger')!.click())
  expect(document.activeElement?.getAttribute('role')).toBe('combobox')
  key('ArrowDown')
  expect(document.querySelector('[aria-selected="true"]')?.textContent).toContain('系统设置')
  key('Enter')
  expect(host.querySelector('output')?.textContent).toBe('/config/system')
  expect(document.querySelector('[role="dialog"]')).toBeNull()
})
it('does not navigate when Enter confirms an IME composition', () => {
  act(() => host.querySelector<HTMLButtonElement>('.admin-command-trigger')!.click())
  act(() => document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', isComposing: true, bubbles: true })))
  expect(document.querySelector('[role="dialog"]')).not.toBeNull()
})

function search(value: string) {
  const input = document.querySelector<HTMLInputElement>('[role="combobox"]')!
  act(() => {
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, value)
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })
}

it('supports first/last result selection and recovery from an empty search', () => {
  act(() => host.querySelector<HTMLButtonElement>('.admin-command-trigger')!.click())
  key('End')
  expect(document.querySelector('[aria-selected="true"]')?.textContent).toContain('/config/email/templates')
  key('Home')
  expect(document.querySelector('[aria-selected="true"]')?.textContent).toContain('仪表盘')
  search('no-such-menu')
  expect(document.querySelector('[role="combobox"]')?.hasAttribute('aria-activedescendant')).toBe(false)
  key('Enter')
  expect(host.querySelector('output')?.textContent).toBe('/')
  act(() => document.querySelector<HTMLButtonElement>('.admin-command-summary button')!.click())
  expect(document.activeElement?.getAttribute('role')).toBe('combobox')
  expect(document.querySelectorAll('[role="option"]').length).toBe(35)
})

it('keeps selection and Enter valid when a language change narrows results', async () => {
  await act(async () => setLocale('en-US'))
  act(() => host.querySelector<HTMLButtonElement>('.admin-command-trigger')!.click())
  search('log')
  key('ArrowUp')
  expect(document.querySelectorAll('[role="option"]').length).toBeGreaterThan(1)
  await act(async () => setLocale('zh-CN'))
  expect(document.querySelectorAll('[role="option"]').length).toBe(1)
  expect(document.querySelector('[role="combobox"]')?.getAttribute('aria-activedescendant')).toBe('admin-command-0')
  key('Enter')
  expect(host.querySelector('output')?.textContent).toBe('/system/audit-log')
})

it('does not open a shortcut during composition, repetition or an already handled event', () => {
  for (const properties of [{ isComposing: true }, { repeat: true }, {}]) {
    const event = new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true, cancelable: true, ...properties })
    if (!Object.keys(properties).length) event.preventDefault()
    act(() => window.dispatchEvent(event))
    expect(document.querySelector('.admin-command-dialog')).toBeNull()
  }
})

it('preserves Home and End text editing in a populated search or with modifiers', () => {
  act(() => host.querySelector<HTMLButtonElement>('.admin-command-trigger')!.click())
  search('config')
  for (const properties of [{ key: 'Home' }, { key: 'End' }, { key: 'Home', shiftKey: true }]) {
    const event = new KeyboardEvent('keydown', { ...properties, bubbles: true, cancelable: true })
    act(() => document.activeElement!.dispatchEvent(event))
    expect(event.defaultPrevented).toBe(false)
  }
  search('')
  const selectAll = new KeyboardEvent('keydown', { key: 'End', shiftKey: true, bubbles: true, cancelable: true })
  act(() => document.activeElement!.dispatchEvent(selectAll))
  expect(selectAll.defaultPrevented).toBe(false)
})

it('offers native management routes without treating cached module metadata as executable navigation', () => {
  act(() => host.querySelector<HTMLButtonElement>('.admin-command-trigger')!.click())
  // TopBar commandItems and native/routes.ts own the available management routes.
  // A legacy moduleRegistry cache is inventory data, not a runtime route authority.
  const dialog = document.querySelector('[role="dialog"]')!
  expect(dialog.textContent).toContain('插件管理')
  expect(dialog.textContent).toContain('/config/plugin')
  expect(dialog.textContent).not.toContain('Access Audit · Dashboard')
  expect(dialog.textContent).not.toContain('/plugins/access_audit/dashboard')
  const plugin = [...dialog.querySelectorAll<HTMLButtonElement>('[role="option"]')].find(option => option.textContent?.includes('/config/plugin'))!
  act(() => plugin.click())
  expect(host.querySelector('output')?.textContent).toBe('/config/plugin')
  expect(document.querySelector('[role="dialog"]')).toBeNull()
})
