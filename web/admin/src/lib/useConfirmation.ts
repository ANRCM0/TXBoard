import { useEffect, useMemo, useRef } from 'react'
import { requestConfirm, type ConfirmRequest } from './confirmationStore'
import { t } from './i18n'

type ConfirmationOptions = Partial<Pick<ConfirmRequest, 'title' | 'confirmLabel' | 'cancelLabel' | 'danger'>>

/** A pending child dialog belongs to the current editor and authorization context. */
export function useConfirmation(context: unknown, allowed: () => boolean = () => true) {
  const owner = useMemo(() => ({ controller: new AbortController() }), [context])
  const current = useRef({ context, allowed })
  current.current = { context, allowed }
  useEffect(() => {
    if (owner.controller.signal.aborted) owner.controller = new AbortController()
    return () => owner.controller.abort()
  }, [owner])
  useEffect(() => {
    if (!current.current.allowed()) owner.controller.abort()
    else if (owner.controller.signal.aborted) owner.controller = new AbortController()
  })
  return (message: string, options: ConfirmationOptions = {}) => new Promise<boolean>(resolve => {
    const valid = () => !owner.controller.signal.aborted && current.current.context === context && current.current.allowed()
    if (!valid()) { resolve(false); return }
    requestConfirm({ title: t('确认操作'), message, ...options, signal: owner.controller.signal,
      action: () => resolve(valid()), cancelAction: () => resolve(false) })
  })
}
