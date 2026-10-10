import { Check, ChevronDown } from 'lucide-react'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import {
  customRangeError,
  EARLIEST_DASHBOARD_DATE,
  formatLocalDate,
  getPeriodDates,
  type DashboardPeriod,
} from '../../lib/dashboardPeriod'

type Props = {
  label: string
  value: DashboardPeriod
  onChange: (period: DashboardPeriod) => void
}

const choices = [
  { preset: 'today', label: '今天' },
  { preset: '7d', label: '最近7天' },
  { preset: '30d', label: '最近30天' },
] as const

export function DashboardPeriodPicker({ label, value, onChange }: Props) {
  const [open, setOpen] = useState(false)
  const [editingCustom, setEditingCustom] = useState(false)
  const [start, setStart] = useState('')
  const [end, setEnd] = useState('')
  const container = useRef<HTMLDivElement>(null)
  const selectedLabel = value.preset === 'custom'
    ? '自定义范围'
    : choices.find(item => item.preset === value.preset)?.label || '最近30天'
  const selectedDates = getPeriodDates(value)
  const today = formatLocalDate(new Date())
  const validationError = editingCustom ? customRangeError(start, end, today) : null

  useEffect(() => {
    if (!open) return
    function closeOnOutside(event: PointerEvent) {
      if (container.current && !container.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    function closeOnEscape(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false)
    }
    document.addEventListener('pointerdown', closeOnOutside)
    document.addEventListener('keydown', closeOnEscape)
    return () => {
      document.removeEventListener('pointerdown', closeOnOutside)
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [open])

  function choosePreset(preset: 'today' | '7d' | '30d') {
    onChange({ preset })
    setOpen(false)
    setEditingCustom(false)
  }

  function beginCustom() {
    setStart(selectedDates.start_date)
    setEnd(selectedDates.end_date)
    setEditingCustom(true)
  }

  function applyCustom(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (customRangeError(start, end, formatLocalDate(new Date()))) return
    onChange({ preset: 'custom', start, end })
    setOpen(false)
    setEditingCustom(false)
  }

  return (
    <div className="dashboard-period" ref={container}>
      <button
        type="button"
        className="dashboard-period-trigger"
        aria-label={label + '统计周期'}
        title={selectedDates.start_date + ' 至 ' + selectedDates.end_date}
        aria-expanded={open}
        onClick={() => { setOpen(previous => !previous); setEditingCustom(false) }}
      >
        <span>{selectedLabel}</span><ChevronDown size={15}/>
      </button>
      {open ? (
        <div className="dashboard-period-popover" role="group" aria-label={label + '时间范围'}>
          {choices.map(item => (
            <button
              type="button"
              className="dashboard-period-option"
              key={item.preset}
              aria-pressed={value.preset === item.preset}
              onClick={() => choosePreset(item.preset)}
            >
              <span>{item.label}</span>
              {value.preset === item.preset ? <Check size={16}/> : null}
            </button>
          ))}
          <button
            type="button"
            className="dashboard-period-option"
            aria-pressed={value.preset === 'custom'}
            aria-expanded={editingCustom}
            onClick={beginCustom}
          >
            <span>自定义范围</span>
            {value.preset === 'custom' ? <Check size={16}/> : null}
          </button>
          {editingCustom ? (
            <form className="dashboard-period-custom" onSubmit={applyCustom}>
              <label>开始日期
                <input aria-label={label + '开始日期'} type="date" min={EARLIEST_DASHBOARD_DATE}
                  max={today} value={start} onChange={event => setStart(event.target.value)}/>
              </label>
              <label>结束日期
                <input aria-label={label + '结束日期'} type="date" min={EARLIEST_DASHBOARD_DATE}
                  max={today} value={end} onChange={event => setEnd(event.target.value)}/>
              </label>
              {validationError ? <p className="dashboard-period-error" role="alert">{validationError}</p> : null}
              <div className="dashboard-period-custom-actions">
                <button type="button" onClick={() => setEditingCustom(false)}>取消</button>
                <button type="submit" className="primary" disabled={Boolean(validationError)}>应用</button>
              </div>
            </form>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
