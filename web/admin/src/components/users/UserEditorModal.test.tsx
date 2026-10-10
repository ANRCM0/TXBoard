import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import type { AdminUser } from '../../api/user-admin'
import { UserEditorModal } from './UserEditorModal'

vi.mock('../../api/user-admin', async importOriginal => {
  const actual = await importOriginal<typeof import('../../api/user-admin')>()
  return {
    ...actual,
    generateUser: vi.fn(),
    updateUser: vi.fn(),
  }
})

let host: HTMLDivElement
let root: Root
let queryClient: QueryClient

beforeEach(() => {
  ;(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
  queryClient = new QueryClient({
    defaultOptions: { mutations: { retry: false } },
  })
})

afterEach(() => {
  act(() => root.unmount())
  queryClient.clear()
  host.remove()
  document.querySelectorAll('.modal-root').forEach(element => element.remove())
})

it('renders long user editing as a right-side structured drawer', () => {
  const user = {
    id: 85,
    email: 'user@example.com',
    balance: 12.5,
    commission_balance: 1.25,
    transfer_enable: 60 * 1024 * 1024 * 1024,
    u: 100 * 1024 * 1024,
    d: 400 * 1024 * 1024,
    banned: false,
    plan_id: 1,
    plan: { id: 1, name: 'Try' },
  } as AdminUser

  act(() => {
    root.render(
      <QueryClientProvider client={queryClient}>
        <UserEditorModal
          open
          user={user}
          plans={[{ id: 1, name: 'Try' } as any]}
          onClose={() => {}}
          onSaved={() => {}}
        />
      </QueryClientProvider>,
    )
  })

  const dialog = document.querySelector('.modal-placement-right')
  expect(dialog).not.toBeNull()
  expect(dialog?.textContent).toContain('账户')
  expect(dialog?.textContent).toContain('订阅与流量')
  expect(dialog?.textContent).toContain('资金与佣金')
  expect(dialog?.textContent).toContain('限制与归属')
  expect(dialog?.textContent).toContain('备注与账户状态')
  expect(document.querySelector('.user-editor-footer')).not.toBeNull()
})
