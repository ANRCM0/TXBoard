import { SlidersHorizontal } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Checkbox } from './Field'
import { Popover } from './Popover'

export type ColumnChoice = { key: string; header: string }

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
  const [hidden, setHidden] = useState<ReadonlySet<string>>(() => readHidden(storageKey))
  useEffect(() => { setHidden(readHidden(storageKey)) }, [storageKey])
  function onChange(next: Set<string>) {
    const valid = new Set(columns.map(column => column.key))
    const cleaned = new Set([...next].filter(key => valid.has(key)))
    setHidden(cleaned)
    writeHidden(storageKey, cleaned)
  }
  return { hidden, onChange }
}

export function ColumnSettings({ columns, hidden, onChange, label = '列设置' }: {
  columns: readonly ColumnChoice[]
  hidden: ReadonlySet<string>
  onChange: (next: Set<string>) => void
  label?: string
}) {
  const visibleCount = columns.filter(column => !hidden.has(column.key)).length
  return <Popover label={label} trigger={<SlidersHorizontal size={15} aria-hidden="true" />}>
    <div className="ui-column-settings">
      <strong>显示列</strong>
      {columns.map(column => {
        const visible = !hidden.has(column.key)
        return <Checkbox key={column.key} label={column.header} checked={visible}
          disabled={visible && visibleCount <= 1}
          onChange={event => {
            const next = new Set(hidden)
            if (event.target.checked) next.delete(column.key)
            else next.add(column.key)
            onChange(next)
          }} />
      })}
    </div>
  </Popover>
}
