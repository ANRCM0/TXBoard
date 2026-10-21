import { t, useLocale, formatDate } from '../../lib/i18n'
import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react'
import { Popover } from './Popover'
import './date-range-picker.css'

export type DateRange = { start_date: string; end_date: string }
const day = 86_400_000
const iso = (date: Date) => date.toISOString().slice(0, 10)
function utc(value: string) {
  const time = /^\d{4}-\d{2}-\d{2}$/.test(value) ? Date.parse(value + 'T00:00:00Z') : NaN
  return Number.isFinite(time) && iso(new Date(time)) === value ? time : NaN
}
export function validDateRange(value: DateRange, maxDays: number, maximum: string) {
  const first = utc(value.start_date), last = utc(value.end_date)
  return first >= 0 && last >= first && last - first < maxDays * day && value.end_date <= maximum
}
function monthOf(value: string) {
  const time = utc(value)
  const date = Number.isFinite(time) ? new Date(time) : new Date()
  return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1))
}
const monthAfter = (month: Date, offset: number) => new Date(Date.UTC(month.getUTCFullYear(), month.getUTCMonth() + offset, 1))

/** UTC calendar days stay strings until the owning query chooses its timestamp boundary. */
export function DateRangePicker({ value, onApply, onClear, maxDays = 366, maximum = iso(new Date()), label = t("选择日期范围"), disabled = false }: {
  value: DateRange; onApply: (value: DateRange) => void; onClear?: () => void
  maxDays?: number; maximum?: string; label?: string; disabled?: boolean
}) {
  useLocale()
  const [open, setOpen] = useState(false), [draft, setDraft] = useState(value)
  const [month, setMonth] = useState(() => monthOf(value.start_date))
  const [choosingEnd, setChoosingEnd] = useState(false), [focused, setFocused] = useState(value.start_date)
  const [error, setError] = useState('')
  const panel = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (!open) { setDraft(value); setMonth(monthOf(value.start_date)); setFocused(value.start_date); setChoosingEnd(false); setError('') }
  }, [open, value.start_date, value.end_date])
  useEffect(() => { if (disabled) setOpen(false) }, [disabled])
  function select(date: string) {
    if (!choosingEnd) { setDraft({ start_date: date, end_date: '' }); setChoosingEnd(true) }
    else { setDraft({ start_date: date < draft.start_date ? date : draft.start_date, end_date: date < draft.start_date ? draft.start_date : date }); setChoosingEnd(false) }
    setFocused(date); setError('')
  }
  function moveFocus(event: KeyboardEvent<HTMLButtonElement>, date: string) {
    const time = utc(date), current = new Date(time)
    const offset = ({ ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7, Home: -((current.getUTCDay() + 6) % 7), End: 6 - ((current.getUTCDay() + 6) % 7) } as Record<string, number>)[event.key]
    let next: Date
    if (offset !== undefined) next = new Date(time + offset * day)
    else if (event.key === 'PageUp' || event.key === 'PageDown') {
      const nextMonth = monthAfter(monthOf(date), event.key === 'PageUp' ? -1 : 1)
      next = new Date(Date.UTC(nextMonth.getUTCFullYear(), nextMonth.getUTCMonth(), Math.min(current.getUTCDate(), new Date(Date.UTC(nextMonth.getUTCFullYear(), nextMonth.getUTCMonth() + 1, 0)).getUTCDate())))
    } else return
    event.preventDefault()
    const nextDate = iso(next)
    if (next.getTime() < 0 || nextDate > maximum) return
    setFocused(nextDate); setMonth(monthOf(nextDate))
    requestAnimationFrame(() => panel.current?.querySelector<HTMLButtonElement>(`button[data-date="${nextDate}"]`)?.focus())
  }
  function apply() {
    if (!validDateRange(draft, maxDays, maximum)) { setError('invalid'); return }
    onApply({ ...draft }); setOpen(false)
  }
  return <Popover label={label} open={open && !disabled} onOpenChange={next => { if (!disabled) setOpen(next) }} trigger={<><CalendarDays size={15} /><span>{value.start_date && value.end_date ? value.start_date + ' — ' + value.end_date : label}</span></>} triggerClassName="button date-range-trigger" panelClassName="date-range-panel">
    <div ref={panel} className="date-range-content">
      <div className="date-range-heading"><strong>{t("UTC 日期范围")}</strong><span>{choosingEnd ? t("请选择结束日期") : t("请选择开始日期")}</span></div>
      <div className="date-range-month-nav"><button type="button" className="icon-button" aria-label={t("上个月")} disabled={month.getTime() <= 0} onClick={() => { const next = monthAfter(month, -1); setMonth(next); setFocused(iso(next)) }}><ChevronLeft size={16} /></button><span>{t("选择日期后应用")}</span><button type="button" className="icon-button" aria-label={t("下个月")} disabled={iso(monthAfter(month, 1)) > maximum} onClick={() => { const next = monthAfter(month, 1); setMonth(next); setFocused(iso(next)) }}><ChevronRight size={16} /></button></div>
      <div className="date-range-months">{[month, monthAfter(month, 1)].map((current, index) => {
        const heading = formatDate(current, { year: 'numeric', month: 'long', timeZone: 'UTC' })
        const count = new Date(Date.UTC(current.getUTCFullYear(), current.getUTCMonth() + 1, 0)).getUTCDate()
        const leading = (current.getUTCDay() + 6) % 7
        return <section key={iso(current)} className={index ? 'date-range-second-month' : ''} aria-label={heading}>
          <h3>{heading}</h3><div className="date-range-weekdays" aria-hidden="true">{Array.from({ length: 7 }, (_, day) => <span key={day}>{formatDate(new Date(Date.UTC(2024, 0, 1 + day)), { weekday: 'short', timeZone: 'UTC' })}</span>)}</div>
          <div role="group" aria-label={t('{month}日期', { month: heading })} className="date-range-days">{Array.from({ length: leading }, (_, index) => <span key={'empty-' + index} />)}{Array.from({ length: count }, (_, index) => {
            const date = iso(new Date(current.getTime() + index * day)), selected = date === draft.start_date || date === draft.end_date
            return <button key={date} type="button" data-date={date} aria-label={date} aria-pressed={selected} aria-current={date === iso(new Date()) ? 'date' : undefined} className={selected ? 'is-endpoint' : draft.start_date && draft.end_date && date > draft.start_date && date < draft.end_date ? 'is-between' : ''} disabled={date > maximum || utc(date) < 0} tabIndex={date === focused || !Number.isFinite(utc(focused)) && index === 0 ? 0 : -1} onFocus={() => setFocused(date)} onKeyDown={event => moveFocus(event, date)} onClick={() => select(date)}>{index + 1}</button>
          })}</div>
        </section>
      })}</div>
      <div className="date-range-inputs"><label>{t("开始日期")}<input aria-label={t("开始日期")} type="date" value={draft.start_date} min="1970-01-01" max={maximum} onChange={event => { setDraft(value => ({ ...value, start_date: event.target.value })); setChoosingEnd(false); setError('') }} /></label><label>{t("结束日期")}<input aria-label={t("结束日期")} type="date" value={draft.end_date} min="1970-01-01" max={maximum} onChange={event => { setDraft(value => ({ ...value, end_date: event.target.value })); setChoosingEnd(false); setError('') }} /></label></div>
      {error ? <p role="alert">{t('请选择不超过 {days} 天、结束于 {maximum} 或更早的 UTC 日期范围', { days: maxDays, maximum })}</p> : null}
      <div className="date-range-actions"><button type="button" className="button" onClick={() => { setDraft({ start_date: '', end_date: '' }); setChoosingEnd(false); setError(''); if (onClear) { onClear(); setOpen(false) } }}>{t("清除日期")}</button><button type="button" className="button button-primary" onClick={apply}>{t("应用日期范围")}</button></div>
    </div>
  </Popover>
}
