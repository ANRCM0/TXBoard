import { useState, useSyncExternalStore } from 'react'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { workflowActions } from './session'
import { managementError } from './Feedback'

export function useNetworkManagement(prefix: string, readErrors: unknown[], refreshRead: () => Promise<unknown>) {
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [error, setError] = useState('')
  const denied = [...readErrors, state.error].some(value => value instanceof CoreAdminApiError && [401, 403].includes(value.status))
  const pending = state.pending?.operation.startsWith(prefix) ? state.pending : undefined
  async function refresh() {
    const generation = workflowActions.generation()
    try {
      await refreshRead()
      if (generation !== workflowActions.generation()) return
      workflowActions.confirmReadAuthorization(prefix); workflowActions.clearError(); setError('')
    } catch { /* A failed authorization read must keep retained commands blocked. */ }
  }
  async function perform(command: () => Promise<void>, after?: () => void) {
    const generation = workflowActions.generation(); setError('')
    try {
      await command()
      if (generation !== workflowActions.generation()) return
      after?.(); await refresh()
    } catch (failure) { if (generation === workflowActions.generation()) setError(managementError(failure)) }
  }
  return { state, denied, pending, refresh, perform, error: error || (state.error ? managementError(state.error) : '') }
}

export function NetworkRecovery({ management, blocked }: { management: ReturnType<typeof useNetworkManagement>; blocked: boolean }) {
  const { pending, state, denied, error, perform } = management
  return <>
    {pending ? <section className="list-card" role="status"><h2>原操作结果等待确认</h2><p>请核对原资源与操作。确认时复用原请求，不会自动重试。</p><code>{pending.operation}</code>{!denied ? <pre>{JSON.stringify(pending.input, null, 2)}</pre> : null}<button className="button" disabled={blocked || denied || state.busy} onClick={() => {
      if (window.confirm('确认继续查询或完成这项原操作？')) void perform(() => workflowActions.run(pending.operation, pending.input))
    }}>确认原操作</button></section> : null}
    {error ? <p role="alert">{error}</p> : null}
    {denied ? <p role="alert">当前账号没有此操作的权限，已隐藏资源数据。</p> : null}
  </>
}
