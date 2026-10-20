import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { workflowActions } from './session'
import { PendingWorkflowNotice } from './PendingWorkflowNotice'
import { availableAdminPaths, workflowDestination } from './routes'
import { coreNavigationGroups } from '../navigation/core'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const attachmentState = { busy: false, pending: false, denied: false, entries: [] }
  return { workflowActions: createWorkflowActions(vi.fn().mockRejectedValue(new TypeError('lost'))),
    attachmentTransfers: { subscribe: () => () => {}, snapshot: () => attachmentState } }
})
let host: HTMLDivElement, root: Root
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  workflowActions.reset()
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
})
afterEach(() => { act(() => root.unmount()); host.remove(); workflowActions.reset() })

it('links an unknown action back to its owner from another page without replaying it', async () => {
  await expect(workflowActions.run('billing.coupons.disable', { coupon_id: '7', expected_version: '1' })).rejects.toBeDefined()
  await act(async () => root.render(<MemoryRouter initialEntries={['/user/ticket']}><PendingWorkflowNotice /></MemoryRouter>))
  const pending = workflowActions.snapshot().pending
  expect(host.querySelector('a')?.getAttribute('href')).toBe('/finance/coupon')
  expect(host.textContent).toContain('优惠券管理有一项操作结果待确认')
  expect(host.textContent).not.toContain(pending!.key)
  await act(async () => host.querySelector('a')!.click())
  expect(workflowActions.snapshot().pending).toBe(pending)
  await act(async () => workflowActions.reset())
  expect(host.querySelector('[aria-label="管理操作待确认"]')).toBeNull()
})

it('only maps bounded current admin operations to enabled management destinations', () => {
  const operations = ['commerce.admin-orders.close', 'support.admin-tickets.reply', 'content.notices.update',
    'content.knowledge.update', 'billing.coupons.disable', 'billing.admin-gift-cards.revoke', 'billing.admin-commissions.payouts.approve',
    'catalog.plans.publish', 'catalog.offers.availability.update', 'network.groups.save', 'network.routes.sort', 'network.access-audit.rules.save',
    'settings.configuration.update', 'notifications.mail-templates.save', 'billing.admin-payment-methods.save',
    'network.nodes.patch', 'network.machines.update', 'subscription.lifecycle.reset',
    'subscription.lifecycle.suspend', 'identity.accounts.create']
  for (const id of operations) expect(availableAdminPaths).toContain(workflowDestination(id)?.path)
  expect(workflowDestination('unknown.action')).toBeUndefined()
  expect(workflowDestination('identity.accounts.status.update', { user_id: '12' })).toEqual({ path: '/user/12', label: '用户详情' })
  expect(workflowDestination('identity.accounts.status.update')).toEqual({ path: '/user/manage', label: '用户管理' })
  expect(workflowDestination('subscription.lifecycle.suspend')).toEqual({ path: '/user/manage', label: '用户管理' })
  expect(workflowDestination('subscription.lifecycle.reset')).toEqual({ path: '/user/traffic-reset', label: '订阅流量重置' })
  expect(workflowDestination('platform.module-configuration.save', { module_id: 'user-theme' })).toEqual({ path: '/system/modules', label: '模块中心' })
  expect(workflowDestination('settings.configuration.update', { values: { admin_color_scheme: 'green' } })).toEqual({ path: '/config/appearance', label: '后台外观' })
  expect(workflowDestination('settings.configuration.update', { values: { admin_color_scheme: 'green', app_name: 'Example' } })).toEqual({ path: '/config/system', label: '系统配置' })
  expect(new Set(availableAdminPaths).size).toBe(availableAdminPaths.length)
  for (const group of coreNavigationGroups) for (const [path] of group.items) expect(availableAdminPaths).toContain(path)
})
