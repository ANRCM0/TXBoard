import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { TopBar } from './TopBar'
vi.mock('../../lib/routePreload', () => ({ preloadAdminRoute: vi.fn() }))
vi.mock('../../api/module', () => ({
  getModuleRegistry: vi.fn().mockResolvedValue({
    modules: [],
    errors: [],
    summary: {
      total: 0,
      health: {
        healthy: 0,
        degraded: 0,
        disabled: 0,
        failed: 0,
        incompatible: 0,
        missing_dependency: 0,
      },
      discovery_errors: 0,
    },
  }),
}))
let host: HTMLDivElement
let root: Root
let queryClient: QueryClient
function Location() { return <output>{useLocation().pathname}</output> }
beforeEach(() => {
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
afterEach(() => { act(() => root.unmount()); host.remove() })
function key(key: string) { act(() => document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }))) }
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

it('includes Module Registry navigation in the command palette', () => {
  act(() => host.querySelector<HTMLButtonElement>('.admin-command-trigger')!.click())
  expect(document.querySelector('[role="dialog"]')?.textContent).toContain('Access Audit · Dashboard')
  expect(document.querySelector('[role="dialog"]')?.textContent).toContain('/plugins/access_audit/dashboard')
})
