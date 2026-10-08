import { useMutation } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import type { FieldValues, UseFormWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { saveSettings, type Settings } from '../../api/config'

export type AutosaveState =
  | { kind: 'idle' }
  | { kind: 'pending' }
  | { kind: 'saving' }
  | { kind: 'saved' }
  | { kind: 'invalid'; message: string }
  | { kind: 'error'; message: string }

type ValidationResult =
  | { success: true; data: Settings }
  | { success: false; message: string }

/**
 * Shared 1-second autosave for admin forms.
 *
 * Uses the stable mutate function (not the changing mutation result object)
 * so status rerenders cannot clear the active debounce timer.
 * Mutations are serialized per settings namespace to prevent slow responses
 * from overwriting newer edits; only the latest revision reports success.
 */
export function useSettingsAutosave<T extends FieldValues>({
  watch,
  validate,
  settingKey,
}: {
  watch: UseFormWatch<T>
  validate: (value: unknown) => ValidationResult
  settingKey: string
}) {
  const [state, setState] = useState<AutosaveState>({ kind: 'idle' })
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const scheduled = useRef<{ payload: Settings; revision: number } | null>(null)
  const revision = useRef(0)
  const latestPayload = useRef<Settings | null>(null)
  const validateRef = useRef(validate)
  validateRef.current = validate

  const { mutate } = useMutation({
    scope: { id: `settings-autosave-${settingKey}` },
    mutationFn: ({ payload }: { payload: Settings; revision: number }) => saveSettings(payload),
    onSuccess: (_, request) => {
      if (request.revision !== revision.current) return
      setState({ kind: 'saved' })
      toast.success('设置保存成功')
    },
    onError: (error, request) => {
      if (request.revision !== revision.current) return
      setState({ kind: 'error', message: error instanceof Error ? error.message : '请检查网络后重试' })
      toast.error('设置保存失败，修改尚未保存')
    },
  })

  useEffect(() => {
    const subscription = watch((value, info) => {
      // form.reset() hydration does not represent a user edit.
      if (!info.name && !info.type) return
      clearTimeout(timer.current)
      scheduled.current = null
      const current = ++revision.current
      const parsed = validateRef.current(value)
      if (!parsed.success) {
        latestPayload.current = null
        setState({ kind: 'invalid', message: parsed.message })
        return
      }

      latestPayload.current = parsed.data
      const request = { payload: parsed.data, revision: current }
      scheduled.current = request
      setState({ kind: 'pending' })
      timer.current = setTimeout(() => {
        scheduled.current = null
        setState({ kind: 'saving' })
        mutate(request)
      }, 1000)
    })
    return () => {
      subscription.unsubscribe()
      clearTimeout(timer.current)
      // A user can navigate away within the debounce window. Flush the
      // latest valid draft rather than silently dropping it on unmount.
      // The shared mutation scope still serializes this with older requests.
      const pending = scheduled.current
      scheduled.current = null
      if (pending) mutate(pending)
    }
  }, [watch, mutate])

  function retry() {
    const payload = latestPayload.current
    if (!payload) return
    clearTimeout(timer.current)
    setState({ kind: 'saving' })
    mutate({ payload, revision: revision.current })
  }

  return { state, retry }
}

export function SettingsAutosaveStatus({
  state,
  onRetry,
}: {
  state: AutosaveState
  onRetry: () => void
}) {
  const label = {
    idle: '修改后 1 秒自动保存',
    pending: '有待保存的修改…',
    saving: '正在保存…',
    saved: '设置保存成功，已生效',
    invalid: `数据校验失败：${state.kind === 'invalid' ? state.message : ''}`,
    error: `保存失败：${state.kind === 'error' ? state.message : ''}`,
  }[state.kind]

  return (
    <div
      className={`config-autosave config-autosave-${state.kind}`}
      role={state.kind === 'error' || state.kind === 'invalid' ? 'alert' : 'status'}
      aria-live="polite"
    >
      <span>{label}</span>
      {state.kind === 'error' && (
        <button type="button" className="button" onClick={onRetry}>重试保存</button>
      )}
    </div>
  )
}
