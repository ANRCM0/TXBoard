import { t, useLocale } from '../lib/i18n'
import { Clock3, Check, Circle } from 'lucide-react'
import './detail-reference.css'
import { useEffect, useRef, useState } from 'react'
import { appendOrderEventPage, type OrderEventHistory } from '@txboard/core-sdk'
import { CoreAdminApiError } from './api'
import { managementError } from './Feedback'
import { isoDate } from './readPresentation'
import { admin } from './session'

const labels = { created: '订单已创建', payment_accepted: '支付已确认', fulfilled: '履约已完成', cancelled: '订单已取消' }

/** Keep each mounted order's cursor private; failed reads never advance it. */
export function OrderTimeline({ tradeNo, onAdvance, onDenied }: { tradeNo: string;
  onAdvance?: () => void; onDenied?: (error: unknown) => void }) {
  useLocale()
  const [history, setHistory] = useState<OrderEventHistory>()
  const [error, setError] = useState<unknown>()
  const [loading, setLoading] = useState(true)
  const retry = useRef<() => void>(() => {})
  const callbacks = useRef({ onAdvance, onDenied })
  callbacks.current = { onAdvance, onDenied }
  useEffect(() => {
    let active = true, pending = false, denied = false, terminal = false
    let current: OrderEventHistory | undefined
    let timer: ReturnType<typeof setTimeout> | undefined
    const controller = new AbortController()
    setHistory(undefined); setError(undefined); setLoading(true)
    async function read() {
      if (!active || pending) return
      if (timer) clearTimeout(timer)
      pending = true
      setLoading(true)
      try {
        do {
          const page = await admin.read('commerce.admin-orders.events.list', {
            trade_no: tradeNo, after_sequence: current?.afterSequence ?? '0', limit: 100,
          }, controller.signal)
          if (!active) return
          const previousCursor = current?.afterSequence ?? '0'
          current = appendOrderEventPage(current, page)
          setHistory(current); setError(undefined); denied = false
          if (current.afterSequence !== previousCursor) callbacks.current.onAdvance?.()
        } while (current.hasMore)
        const last = current.events.at(-1)
        terminal = last?.type === 'fulfilled' || last?.type === 'cancelled'
      } catch (failure) {
        if (!active) return
        setError(failure)
        denied = failure instanceof CoreAdminApiError && [401, 403, 404].includes(failure.status)
        if (denied) { current = undefined; setHistory(undefined); callbacks.current.onDenied?.(failure) }
      } finally {
        pending = false
        if (active) {
          setLoading(false)
          if (!denied && !terminal) timer = setTimeout(() => void read(), 5000)
        }
      }
    }
    const reconnect = () => { if (!denied && !terminal) void read() }
    const visible = () => { if (document.visibilityState === 'visible') reconnect() }
    retry.current = () => void read()
    window.addEventListener('online', reconnect)
    document.addEventListener('visibilitychange', visible)
    void read()
    return () => {
      active = false; controller.abort()
      if (timer) clearTimeout(timer)
      window.removeEventListener('online', reconnect)
      document.removeEventListener('visibilitychange', visible)
    }
  }, [tradeNo])
  return <section className="native-order-timeline reference-order-timeline" aria-label={t("订单状态时间线")} aria-busy={loading}>
    <div className="reference-detail-section-title"><Clock3 size={17} /><h3>{t("状态时间线")}</h3><span className="reference-tag">{history ? t('{count} 个事件', { count: history.events.length }) : loading ? t("读取中") : t("未读取")}</span></div>
    {loading && !history ? <p role="status">{t("正在读取时间线…")}</p> : null}
    {error ? <div role="alert"><p>{managementError(error)}{t("时间线尚未更新。")}</p>
      <button className="button" disabled={loading} onClick={() => retry.current()}>{t("重新加载时间线")}</button></div> : null}
    {history && !history.historyComplete ? <p className="native-timeline-notice">{t("历史记录不完整，仅展示已记录的事件。")}</p> : null}
    {history?.events.length === 0 ? <p>{t("暂无事件记录。")}</p> : null}
    {history && history.events.length > 0 ? <ol>{history.events.map(event => <li key={event.sequence} className={'reference-timeline-event ' + (event.type === 'cancelled' ? 'is-cancelled' : '')}><span className="reference-timeline-marker">{event.type === 'cancelled' ? <Circle size={12} /> : <Check size={12} />}</span><div className="reference-timeline-content">
      <span className="commerce-timeline-event-name"><strong>{t(labels[event.type])}</strong><small>{t('事件 #{id}', { id: event.sequence })}</small></span><time dateTime={event.occurred_at}>{isoDate(event.occurred_at)}</time></div>
    </li>)}</ol> : null}
  </section>
}
