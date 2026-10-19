import { CoreAdminApiError, StaleAdminContext, coreOperations } from '@txboard/core-sdk'
import type { CoreInput } from '@txboard/core-sdk'
import type { CoreAdminWriteOperation } from '@txboard/core-sdk'

type Operation = Extract<CoreAdminWriteOperation,
  `commerce.admin-orders.${string}` | `support.admin-tickets.${string}` | `content.${string}`
  | `billing.coupons.${string}` | `billing.admin-gift-cards.${string}` | `billing.admin-commissions.${string}`
  | `catalog.${string}` | `network.groups.${string}` | `network.routes.${string}` | `network.access-audit.${string}`
  | `network.nodes.${string}` | `network.machines.${string}` | `subscription.lifecycle.${string}`
  | `notifications.mail-templates.${string}` | 'settings.configuration.update' | 'billing.admin-payment-methods.save'>
export type PendingWorkflow = { [K in Operation]: {
  operation: K; input: CoreInput<K>; key: string; unknown: boolean
} }[Operation]
type State = Readonly<{ pending?: PendingWorkflow; busy: boolean; error?: unknown }>
type Send = <K extends Operation>(operation: K, input: CoreInput<K>, key: string) => Promise<unknown>

/** Scope survives navigation, but session replacement clears every retained input. */
export function createWorkflowActions(send: Send) {
  let state: State = { busy: false }, epoch = 0
  const listeners = new Set<() => void>()
  function update(next: State) { state = Object.freeze(next); for (const notify of listeners) notify() }
  return {
    snapshot: () => state,
    generation: () => epoch,
    subscribe(notify: () => void) { listeners.add(notify); return () => { listeners.delete(notify) } },
    reset() { epoch++; update({ busy: false }) },
    clearError() { if (!state.busy && !state.pending) update({ busy: false }) },
    confirmReadAuthorization(prefix: string) {
      if (!state.busy && state.pending?.operation.startsWith(prefix)) {
        update({ busy: false, pending: state.pending })
      }
    },
    async run<K extends Operation>(operation: K, input: CoreInput<K>) {
      if (state.busy) throw new Error('WORKFLOW_BUSY')
      if (!coreOperations[operation].input.safeParse(input).success) throw new CoreAdminApiError('VALIDATION_ERROR', 422)
      const old = state.pending
      if (old && (old.operation !== operation || JSON.stringify(old.input) !== JSON.stringify(input))) {
        throw new Error('WORKFLOW_RECOVERY_REQUIRED')
      }
      // run() couples K with CoreInput<K>; retain that relation after cloning.
      const action = old ?? Object.freeze({ operation, input: Object.freeze(structuredClone(input)), key: crypto.randomUUID(), unknown: false }) as unknown as PendingWorkflow
      const current = epoch
      update({ busy: true, pending: action })
      try {
        await send(operation, action.input as CoreInput<K>, action.key)
        if (current !== epoch) throw new StaleAdminContext()
        update({ busy: false })
      } catch (error) {
        if (current !== epoch) throw new StaleAdminContext()
        if (current === epoch) {
          const unknown = action.unknown || !(error instanceof CoreAdminApiError) || error.needsStateConfirmation
          update({ busy: false, error, ...(unknown ? { pending: { ...action, unknown: true } } : {}) })
        }
        throw error
      }
    },
  }
}
