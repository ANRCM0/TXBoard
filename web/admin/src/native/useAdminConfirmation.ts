import { useMemo, useSyncExternalStore } from 'react'
import { useConfirmation } from '../lib/useConfirmation'
import { workflowActions, useAdminSession } from './session'

export function useAdminConfirmation(allowed: () => boolean = () => true, scope?: unknown) {
  const generation = useSyncExternalStore(workflowActions.subscribe, workflowActions.generation)
  const session = useAdminSession()
  const context = useMemo(() => ({ generation, scope }), [generation, scope])
  return useConfirmation(context, () => session && !workflowActions.snapshot().busy && allowed())
}
