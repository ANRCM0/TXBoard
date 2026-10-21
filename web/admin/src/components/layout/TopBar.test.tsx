import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { TopBar } from './TopBar'
import { admin, logoutAdmin } from '../../native/session'
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
afterEach(() => { act(() => root.unmount()); queryClient.clear(); host.remove() })
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
