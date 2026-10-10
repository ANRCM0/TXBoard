import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Sidebar } from './Sidebar'

vi.mock('../../api/module', () => ({
  getModuleRegistry: vi.fn().mockResolvedValue({ modules: [] }),
}))
vi.mock('../../lib/routePreload', () => ({
  preloadAdminRoute: vi.fn(),
  scheduleAdminRouteWarmup: vi.fn(() => () => {}),
}))

const STORAGE_KEY = 'txboard:admin:sidebar:open-groups'

let host: HTMLDivElement
let root: Root
let queryClient: QueryClient

function mountSidebar() {
  root = createRoot(host)
  act(() => {
    root.render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <Sidebar open={false} onClose={() => {}} collapsed={false} onExpand={() => {}} />
        </MemoryRouter>
      </QueryClientProvider>,
    )
  })
}

function expanded(group: string) {
  return host.querySelector<HTMLButtonElement>(`[aria-controls="admin-nav-${group}"]`)?.getAttribute('aria-expanded')
}

function toggle(group: string) {
  const button = host.querySelector<HTMLButtonElement>(`[aria-controls="admin-nav-${group}"]`)
  if (!button) throw new Error(`Missing sidebar group: ${group}`)
  act(() => button.click())
}

beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  localStorage.clear()
  host = document.createElement('div')
  document.body.append(host)
  queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  queryClient.setQueryData(['moduleRegistry'], { modules: [] })
})

afterEach(() => {
  act(() => root.unmount())
  queryClient.clear()
  host.remove()
  localStorage.clear()
})

describe('admin sidebar group state', () => {
  it('preserves independently collapsed groups after remounting (browser refresh)', () => {
    mountSidebar()
    expect(expanded('system')).toBe('true')
    expect(expanded('commerce')).toBe('true')

    toggle('system')
    toggle('commerce')
    expect(expanded('system')).toBe('false')
    expect(expanded('commerce')).toBe('false')
    expect(expanded('extensions')).toBe('true')

    act(() => root.unmount())
    mountSidebar()
    expect(expanded('system')).toBe('false')
    expect(expanded('commerce')).toBe('false')
    expect(expanded('extensions')).toBe('true')

    toggle('system')
    act(() => root.unmount())
    mountSidebar()
    expect(expanded('system')).toBe('true')
    expect(expanded('commerce')).toBe('false')
  })

  it('ignores unknown keys and invalid values while restoring valid preferences', () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
      system: false,
      node: 'false',
      retired_group: false,
    }))
    mountSidebar()

    expect(expanded('system')).toBe('false')
    expect(expanded('node')).toBe('true')
    expect(expanded('content')).toBe('true')
    expect(JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}')).not.toHaveProperty('retired_group')
  })

  it('falls back to expanded groups when stored JSON is malformed', () => {
    localStorage.setItem(STORAGE_KEY, '{broken')
    mountSidebar()

    expect(expanded('system')).toBe('true')
    expect(expanded('node')).toBe('true')
    expect(() => JSON.parse(localStorage.getItem(STORAGE_KEY) || '')).not.toThrow()
  })
})
