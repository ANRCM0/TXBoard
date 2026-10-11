import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { getMachineCredentials, getMachineNodes, type MachineItem } from '../../api/server'
import { MachineOpsDrawer } from './MachineOpsDrawer'

vi.mock('../../api/server', async importOriginal => {
  const actual = await importOriginal<typeof import('../../api/server')>()
  return {
    ...actual,
    getMachineNodes: vi.fn().mockResolvedValue([]),
    getMachineCredentials: vi.fn().mockResolvedValue({ token: 'secret-1', install_command: 'install-test-1' }),
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
  vi.clearAllMocks()
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


function selectTab(name: string) {
  const tabs = Array.from(document.querySelectorAll<HTMLButtonElement>('.machine-ops-tab'))
  const selected = tabs.find(tab => tab.textContent?.trim() === name)
  if (!selected) throw new Error('Missing tab ' + name)
  act(() => selected.click())
  return selected
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

  selectTab('运行时')
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

  selectTab('运行时')
  const drawer = document.querySelector('.machine-ops-root')
  expect(drawer?.textContent).toContain('远程更新不可用')
  expect(drawer?.textContent).toContain('txnode upgrade')

  const button = Array.from(drawer?.querySelectorAll('button') || []).find(item =>
    item.textContent?.includes('更新到 latest'),
  )
  expect(button?.hasAttribute('disabled')).toBe(true)
})

it('presents four keyboard-accessible focused panels and only fetches nodes on demand', async () => {
  render({ id: 15, name: 'US-01', is_active: true, last_seen_at: Date.now(), load_status: {} })
  const tabs = document.querySelectorAll('[role="tab"]')
  expect(tabs).toHaveLength(4)
  expect(document.querySelectorAll('[role="tabpanel"]')).toHaveLength(1)
  expect(document.querySelector('[role="tabpanel"]')?.textContent).toContain('当前负载')
  expect(document.querySelector('[role="tabpanel"]')?.textContent).not.toContain('TX-Node Runtime')
  expect(getMachineNodes).not.toHaveBeenCalled()
  selectTab('节点')
  expect(getMachineNodes).toHaveBeenCalledWith(15)
  await act(async () => {
    queryClient.setQueryData(['machineNodes', 15], [])
    await new Promise(resolve => setTimeout(resolve, 0))
  })
  expect(document.querySelector('[role="tabpanel"]')?.textContent).toContain('该机器暂未绑定节点')
  expect(document.querySelector('[role="tabpanel"]')?.textContent).toContain('绑定节点')
  selectTab('概览')
  expect(document.querySelector('[role="tabpanel"]')?.textContent).toContain('当前负载')
})

it('shows concise runtime and node shortcuts and only fetches nodes after opening them', () => {
  render({
    id: 55, name: 'Fleet-55', is_active: true, last_seen_at: Date.now(),
    servers_count: 3,
    load_status: {runtime: {version: 'v2.3.0', deployment: 'docker'}},
  })
  const overview = document.querySelector('[role="tabpanel"]')
  expect(overview?.textContent).toContain('运行时概况')
  expect(overview?.textContent).toContain('承载节点概况')
  expect(overview?.textContent).toContain('3 个节点')
  expect(overview?.textContent).toContain('v2.3.0')
  expect(getMachineNodes).not.toHaveBeenCalled()
  act(() => document.querySelector<HTMLButtonElement>('.machine-ops-glance-item')?.click())
  expect(document.querySelector('[role="tab"][aria-selected="true"]')?.textContent).toContain('运行时')
  expect(document.querySelector('.machine-ops-glance')).toBeNull()
  selectTab('概览')
  act(() => document.querySelectorAll<HTMLButtonElement>('.machine-ops-glance-item')[1]?.click())
  expect(document.querySelector('[role="tab"][aria-selected="true"]')?.textContent).toContain('节点')
  expect(getMachineNodes).toHaveBeenCalledWith(55)
})

it('supports arrow, Home and End keys without exposing inactive panels', () => {
  render({ id: 16, name: 'Keyboard', is_active: true, last_seen_at: Date.now(), load_status: {} })
  const current = () => document.querySelector<HTMLButtonElement>('[role="tab"][aria-selected="true"]')
  const press = (key: string) => {
    act(() => current()?.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true })))
  }
  expect(current()?.textContent).toContain('概览')
  press('ArrowRight')
  expect(current()?.textContent).toContain('运行时')
  expect(document.querySelectorAll('[role="tabpanel"]')).toHaveLength(1)
  press('End')
  expect(current()?.textContent).toContain('安全')
  press('Home')
  expect(current()?.textContent).toContain('概览')
})

it('does not leave credentials visible after switching tabs or machines', async () => {
  render({ id: 20, name: 'Tokyo', is_active: true, last_seen_at: Date.now(), load_status: {} })
  selectTab('安全')
  const show = Array.from(document.querySelectorAll<HTMLButtonElement>('.machine-ops-root button'))
    .find(button => button.textContent?.includes('查看凭据'))
  expect(show).toBeDefined()
  await act(async () => { show!.click(); await Promise.resolve() })
  expect(getMachineCredentials).toHaveBeenCalledWith(20)
  expect(document.querySelector('.machine-ops-credentials')?.textContent).toContain('secret-1')
  selectTab('概览')
  expect(document.body.textContent).not.toContain('secret-1')
  selectTab('安全')
  expect(document.querySelector('.machine-ops-credentials')).toBeNull()
  render({ id: 21, name: 'Osaka', is_active: true, last_seen_at: Date.now(), load_status: {} })
  expect(document.body.textContent).not.toContain('secret-1')
  expect(document.querySelector('[role="tab"][aria-selected="true"]')?.textContent).toContain('概览')
})

it('ignores a late credential response after leaving the security panel', async () => {
  let resolveRequest: ((credentials: { token: string; install_command: string }) => void) | undefined
  vi.mocked(getMachineCredentials).mockImplementationOnce(() => new Promise(resolve => { resolveRequest = resolve }))
  render({ id: 22, name: 'SG', is_active: true, last_seen_at: Date.now(), load_status: {} })
  selectTab('安全')
  const show = Array.from(document.querySelectorAll<HTMLButtonElement>('.machine-ops-root button'))
    .find(button => button.textContent?.includes('查看凭据'))!
  act(() => show.click())
  selectTab('概览')
  await act(async () => { resolveRequest?.({ token: 'late-secret', install_command: 'late-command' }); await Promise.resolve() })
  selectTab('安全')
  expect(document.body.textContent).not.toContain('late-secret')
  expect(document.querySelector('.machine-ops-credentials')).toBeNull()
})
