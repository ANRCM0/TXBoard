import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import type { MachineItem } from '../../api/server'
import { MachineOpsDrawer } from './MachineOpsDrawer'

vi.mock('../../api/server', async importOriginal => {
  const actual = await importOriginal<typeof import('../../api/server')>()
  return {
    ...actual,
    getMachineNodes: vi.fn().mockResolvedValue([]),
    getMachineToken: vi.fn().mockResolvedValue(''),
    getInstallCommand: vi.fn().mockResolvedValue(''),
    resetMachineToken: vi.fn(),
    updateMachineRuntime: vi.fn(),
  }
})

vi.mock('../../components/server/MachineHistoryChart', () => ({
  MachineHistoryChart: () => <div>history chart</div>,
}))

let host: HTMLDivElement
let root: Root
let queryClient: QueryClient

beforeEach(() => {
  ;(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
  queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
})

afterEach(() => {
  act(() => root.unmount())
  queryClient.clear()
  host.remove()
  document.querySelectorAll('.machine-ops-root').forEach(element => element.remove())
})

function render(machine: MachineItem) {
  act(() => {
    root.render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <MachineOpsDrawer
            machine={machine}
            onClose={() => {}}
            onEdit={() => {}}
            onBind={() => {}}
          />
        </MemoryRouter>
      </QueryClientProvider>,
    )
  })
}

it('shows reported TX-Node version and enables latest update for capable machine', () => {
  render({
    id: 12,
    name: 'Tokyo-01',
    is_active: true,
    last_seen_at: Date.now(),
    load_status: {
      runtime: {
        version: 'v2.3.0',
        deployment: 'docker',
        updater_available: true,
        update: {
          request_id: 'mup_prev',
          target: 'latest',
          status: 'succeeded',
          updated_at: 1780000000,
          message: 'upgrade completed',
        },
      },
    },
  })

  const drawer = document.querySelector('.machine-ops-root')
  expect(drawer?.textContent).toContain('TX-Node Runtime')
  expect(drawer?.textContent).toContain('v2.3.0')
  expect(drawer?.textContent).toContain('更新成功')

  const button = Array.from(drawer?.querySelectorAll('button') || []).find(item =>
    item.textContent?.includes('更新到 latest'),
  )
  expect(button).toBeTruthy()
  expect(button?.hasAttribute('disabled')).toBe(false)
})

it('keeps older Installer deployments compatible and disables remote update', () => {
  render({
    id: 13,
    name: 'Legacy-machine',
    is_active: true,
    last_seen_at: Date.now(),
    load_status: {
      runtime: {
        version: 'v2.2.3',
        deployment: 'unknown',
        updater_available: false,
      },
    },
  })

  const drawer = document.querySelector('.machine-ops-root')
  expect(drawer?.textContent).toContain('远程更新不可用')
  expect(drawer?.textContent).toContain('txnode upgrade')

  const button = Array.from(drawer?.querySelectorAll('button') || []).find(item =>
    item.textContent?.includes('更新到 latest'),
  )
  expect(button?.hasAttribute('disabled')).toBe(true)
})
