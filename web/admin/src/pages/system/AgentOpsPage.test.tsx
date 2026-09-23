import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { AgentOpsPage } from './AgentOpsPage'

vi.mock('../../api/agent', () => ({
  approveAgentAction: vi.fn(),
  createAgentToken: vi.fn(),
  getAgentAbilities: vi.fn().mockResolvedValue({
    default_read: ['agent:system:read'],
    all: ['agent:system:read', 'agent:nodes:operate'],
  }),
  getAgentActions: vi.fn().mockResolvedValue([]),
  getAgentFleetHealth: vi.fn().mockResolvedValue({
    status: 'healthy',
    summary: {
      status: 'healthy',
      total_nodes: 2,
      healthy_nodes: 2,
      degraded_nodes: 0,
      critical_nodes: 0,
      warning_count: 0,
    },
    nodes: [],
    generated_at: 1,
  }),
  getAgentInspections: vi.fn().mockResolvedValue([]),
  getAgentTokens: vi.fn().mockResolvedValue([]),
  rejectAgentAction: vi.fn(),
  revokeAgentToken: vi.fn(),
  runAgentInspection: vi.fn(),
}))

vi.mock('../../api/server', () => ({
  getMachines: vi.fn().mockResolvedValue([]),
  getNodes: vi.fn().mockResolvedValue([]),
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
    defaultOptions: {
      queries: { retry: false, staleTime: Infinity },
    },
  })

  act(() => {
    root.render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <AgentOpsPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )
  })
})

afterEach(() => {
  act(() => root.unmount())
  queryClient.clear()
  host.remove()
  document.querySelectorAll('.modal-root').forEach(element => element.remove())
})

it('keeps token creation out of the main page until requested', () => {
  expect(host.textContent).toContain('Agent 运维')
  expect(host.querySelectorAll('.agent-overview-card')).toHaveLength(5)
  expect(document.querySelector('.agent-token-modal')).toBeNull()

  const create = Array.from(host.querySelectorAll('button')).find(button =>
    button.textContent?.includes('创建 Agent Token'),
  )
  expect(create).toBeTruthy()

  act(() => create!.click())

  expect(document.querySelector('.agent-token-modal')).not.toBeNull()
  expect(document.querySelector('.agent-token-modal')?.textContent).toContain('基本信息')
  expect(document.querySelector('.agent-token-modal')?.textContent).toContain('Abilities')
  expect(document.querySelector('.agent-token-modal')?.textContent).toContain('资源范围')
})
