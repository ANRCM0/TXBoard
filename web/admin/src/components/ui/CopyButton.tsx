import { t, useLocale } from '../../lib/i18n'
import { useEffect, useRef, useState } from 'react'
import { Check, Copy, AlertCircle } from 'lucide-react'

export function CopyButton({ value, label = t("复制") }: { value: string; label?: string }) {
  useLocale()
  const [status, setStatus] = useState<'idle' | 'copied' | 'failed'>('idle')
  const [busy, setBusy] = useState(false)
  const mounted = useRef(false)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => {
    if (status === 'idle') return
    const timer = window.setTimeout(() => setStatus('idle'), 2500)
    return () => window.clearTimeout(timer)
  }, [status])
  async function copy() {
    if (busy) return
    setBusy(true)
    try {
      if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable')
      await navigator.clipboard.writeText(value)
      if (mounted.current) setStatus('copied')
    } catch {
      if (mounted.current) setStatus('failed')
    } finally { if (mounted.current) setBusy(false) }
  }
  const feedback = status === 'copied' ? t("已复制") : status === 'failed' ? t("复制失败，请手动选择文本复制") : ''
  return <span className="ui-copy-control">
    <button type="button" className={'ui-copy-button is-' + status} aria-label={label} title={feedback || label}
      disabled={busy || !value} onClick={event => { event.stopPropagation(); void copy() }}>
      {status === 'copied' ? <Check size={13} /> : status === 'failed' ? <AlertCircle size={13} /> : <Copy size={13} />}
    </button>
    <span className="admin-route-announcer" role="status" aria-live="polite">{feedback}</span>
  </span>
}
