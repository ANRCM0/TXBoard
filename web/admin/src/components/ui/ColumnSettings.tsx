import { t, useLocale } from '../../lib/i18n'
import { SlidersHorizontal } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Checkbox } from './Field'
import { Popover } from './Popover'
import './experience-lists.css'

export type ColumnChoice = { key: string; header: string; hideable?: boolean }

const storagePrefix = 'txboard:admin:columns:'

function readHidden(storageKey?: string): ReadonlySet<string> {
  if (!storageKey || typeof window === 'undefined') return new Set()
  try {
    const parsed: unknown = JSON.parse(window.localStorage.getItem(storagePrefix + storageKey) ?? '[]')
    return new Set(Array.isArray(parsed) ? parsed.filter((value): value is string => typeof value === 'string') : [])
  } catch {
    return new Set()
  }
}

function writeHidden(storageKey: string | undefined, hidden: ReadonlySet<string>) {
  if (!storageKey || typeof window === 'undefined') return
  try {
    window.localStorage.setItem(storagePrefix + storageKey, JSON.stringify([...hidden]))
  } catch {
    // A blocked storage write must not break column selection.
  }
}

export function useColumnVisibility(storageKey: string | undefined, columns: readonly ColumnChoice[]) {
  const [preferences, setPreferences] = useState(() => ({ key: storageKey, hidden: readHidden(storageKey) }))
  const hidden = preferences.key === storageKey ? preferences.hidden : readHidden(storageKey)
  useEffect(() => {
    const sync = () => setPreferences({ key: storageKey, hidden: readHidden(storageKey) })
    sync()
    if (!storageKey) return
    const onStorage = (event: StorageEvent) => {
      if (event.storageArea && event.storageArea !== window.localStorage) return
      if (event.key === null || event.key === storagePrefix + storageKey) sync()
    }
    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [storageKey])
  function onChange(next: Set<string>) {
    const valid = new Set(columns.filter(column => column.hideable !== false).map(column => column.key))
    const cleaned = new Set([...next].filter(key => valid.has(key)))
    setPreferences({ key: storageKey, hidden: cleaned })
    writeHidden(storageKey, cleaned)
  }
  const validHidden = new Set(columns.filter(column => column.hideable !== false && hidden.has(column.key)).map(column => column.key))
  if (validHidden.size === columns.length && columns.length) validHidden.delete(columns[0].key)
  return { hidden: validHidden, onChange }
}

export function ColumnSettings({ columns, hidden, onChange, label = t("列设置") }: {
  columns: readonly ColumnChoice[]
  hidden: ReadonlySet<string>
  onChange: (next: Set<string>) => void
  label?: string
}) {
  useLocale()
  const visibleCount = columns.filter(column => !hidden.has(column.key)).length
  return <Popover label={label} trigger={<SlidersHorizontal size={15} aria-hidden="true" />}>
    <div className="ui-column-settings">
      <strong>{t("显示列")}</strong>
      <span className="ui-column-summary" role="status" aria-atomic="true">{t('已显示 {visible} / {total} 列', { visible: visibleCount, total: columns.length })}</span>
      {columns.map(column => {
        const visible = !hidden.has(column.key)
        return <Checkbox key={column.key} label={t(column.header)} checked={visible}
          disabled={column.hideable === false || visible && visibleCount <= 1}
          onChange={event => {
            const next = new Set(hidden)
            if (event.target.checked) next.delete(column.key)
            else next.add(column.key)
            onChange(next)
          }} />
      })}
      <p className="ui-column-hint">{t("至少保留一列，固定列始终显示。")}</p>
      <button type="button" className="button" disabled={hidden.size === 0} onClick={() => onChange(new Set())}>{t("恢复默认列")}</button>
    </div>
  </Popover>
}
