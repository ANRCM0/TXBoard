import { expect, it, vi } from 'vitest'
import { CoreAdminApiError, StaleAdminContext } from '@txboard/core-sdk'
import { createWorkflowActions } from './workflowActions'

const input = { coupon_id: '9007199254740993', expected_version: '7' }
const operation = 'billing.coupons.disable' as const

it('retains one original action through lost and later rejected acknowledgements', async () => {
  const send = vi.fn().mockRejectedValueOnce(new TypeError('lost'))
    .mockRejectedValueOnce(new CoreAdminApiError('BUSINESS_REJECTED', 409)).mockResolvedValue({})
  const actions = createWorkflowActions(send)
  await expect(actions.run(operation, input)).rejects.toBeDefined()
  const original = send.mock.calls[0]
  await expect(actions.run(operation, { ...input, expected_version: '8' })).rejects.toThrow('WORKFLOW_RECOVERY_REQUIRED')
  expect(send).toHaveBeenCalledTimes(1)
  await expect(actions.run(operation, input)).rejects.toMatchObject({ status: 409 })
  expect(actions.snapshot().pending?.unknown).toBe(true)
  await actions.run(operation, input)
  expect(send.mock.calls[1]).toEqual(original)
  expect(send.mock.calls[2]).toEqual(original)
  expect(actions.snapshot()).toEqual({ busy: false })
})

it('does not allow a concurrent write or mutate the caller input retained for recovery', async () => {
  let reject!: (reason: unknown) => void
  const send = vi.fn(() => new Promise((_, no) => { reject = no }))
  const actions = createWorkflowActions(send), draft = { ...input }
  const first = actions.run(operation, draft)
  draft.expected_version = '99'
  await expect(actions.run(operation, input)).rejects.toThrow('WORKFLOW_BUSY')
  reject(new TypeError('lost'))
  await expect(first).rejects.toBeDefined()
  expect(send.mock.calls).toHaveLength(1)
  expect(actions.snapshot().pending?.input).toEqual(input)
})

it('preserves a denied unknown action until its own scope confirms read authorization', async () => {
  const denied = new CoreAdminApiError('FORBIDDEN', 403, undefined, true)
  const send = vi.fn().mockRejectedValue(denied), actions = createWorkflowActions(send)
  await expect(actions.run(operation, input)).rejects.toBe(denied)
  const pending = actions.snapshot().pending
  expect(pending?.unknown).toBe(true)
  actions.clearError()
  expect(actions.snapshot().error).toBe(denied)
  actions.confirmReadAuthorization('support.admin-tickets.')
  expect(actions.snapshot().error).toBe(denied)
  actions.confirmReadAuthorization('billing.coupons.')
  expect(actions.snapshot()).toEqual({ busy: false, pending })
  expect(send).toHaveBeenCalledTimes(1)
})

it.each(['success', 'failure'] as const)('rejects a late %s after reset and leaves the new scope untouched', async result => {
  let resolve!: (value: unknown) => void, reject!: (reason: unknown) => void
  const send = vi.fn(() => new Promise((yes, no) => { resolve = yes; reject = no }))
  const actions = createWorkflowActions(send)
  const task = actions.run(operation, input)
  actions.reset()
  if (result === 'success') resolve({})
  else reject(new CoreAdminApiError('FORBIDDEN', 403, undefined, true))
  await expect(task).rejects.toBeInstanceOf(StaleAdminContext)
  expect(actions.snapshot()).toEqual({ busy: false })
  expect(actions.generation()).toBe(1)
})
