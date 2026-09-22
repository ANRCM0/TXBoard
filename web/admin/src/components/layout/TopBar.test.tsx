import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { TopBar } from './TopBar'
vi.mock('../../lib/routePreload', () => ({ preloadAdminRoute: vi.fn() }))
let host: HTMLDivElement
let root: Root
function Location() { return <output>{useLocation().pathname}</output> }
beforeEach(() => {
  ;(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  act(() => root.render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><TopBar onOpenMenu={() => {}} /><Location /></MemoryRouter></QueryClientProvider>))
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
