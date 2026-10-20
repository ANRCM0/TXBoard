import { t, useLocale } from '../lib/i18n'
import { useLayoutEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { workflowActions } from './session'
import { managementError } from './Feedback'

export function useNetworkManagement(prefix: string, readErrors: unknown[], refreshRead: () => Promise<unknown>, viewKey = prefix) {
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const view = useMemo(() => ({}), [prefix, viewKey])
  const activeView = useRef<object>()
  useLayoutEffect(() => {
    activeView.current = view
    return () => { activeView.current = undefined }
  }, [view])
  const [error, setError] = useState('')
  const ownError = !state.errorOperation || state.errorOperation.startsWith(prefix) ? state.error : undefined
  const expired = state.error instanceof CoreAdminApiError && state.error.status === 401
  const denied = expired || [...readErrors, ownError].some(value => value instanceof CoreAdminApiError && [401, 403].includes(value.status))
  const pending = state.pending?.operation.startsWith(prefix) ? state.pending : undefined
  async function refresh(): Promise<boolean> {
    const generation = workflowActions.generation()
    const current = () => activeView.current === view && generation === workflowActions.generation()
    if (!current()) return false
    try {
      await refreshRead()
      if (!current()) return false
      workflowActions.confirmReadAuthorization(prefix); workflowActions.clearError(); setError('')
      return true
    } catch {
      // A failed authorization read must keep retained commands blocked.
      return false
    }
  }
  async function perform(command: () => Promise<void>, after?: () => void) {
    const generation = workflowActions.generation()
    const current = () => activeView.current === view && generation === workflowActions.generation()
    if (!current()) return
    setError('')
    try {
      await command()
      if (!current()) return
      after?.(); await refresh()
    } catch (failure) { if (current()) setError(managementError(failure, false)) }
  }
  return { state, denied, pending, refresh, perform, error: error || (ownError ? managementError(ownError, false) : '') }
}

export function NetworkRecovery({ management, blocked }: { management: ReturnType<typeof useNetworkManagement>; blocked: boolean }) {
  useLocale()
  const { pending, state, denied, error, perform } = management
  return <>
    {pending ? <section className="list-card" role="status"><h2>{t("原操作结果等待确认")}</h2><p>{t("请核对原资源与操作。确认时复用原请求，不会自动重试。")}</p><code>{pending.operation}</code>{!denied ? <pre>{JSON.stringify(pending.input, (key, value) => /(?:password|passwd|secret|token|private_key|authorization|credential|configuration_json|input_json)/i.test(key) ? t("已保留原请求，敏感内容不回显") : value, 2)}</pre> : null}<button className="button" disabled={blocked || denied || state.busy} onClick={() => {
      if (window.confirm(t("确认继续查询或完成这项原操作？"))) void perform(() => workflowActions.run(pending.operation, pending.input))
    }}>{t("确认原操作")}</button></section> : null}
    {error ? <p role="alert">{t(error)}</p> : null}
    {denied ? <p role="alert">{t("当前账号没有此操作的权限，已隐藏资源数据。")}</p> : null}
  </>
}
