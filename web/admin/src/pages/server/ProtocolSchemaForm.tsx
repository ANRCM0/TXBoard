import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import type { ProtocolFormCondition, ProtocolFormField } from '../../api/server'

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function getAt(source: Record<string, unknown>, path: string): unknown {
  let current: unknown = source
  for (const key of path.split('.')) {
    if (!isRecord(current) || !(key in current)) return undefined
    current = current[key]
  }
  return current
}

function conditionsFor(field: ProtocolFormField): ProtocolFormCondition[] {
  if (!field.visible_when) return []
  return Array.isArray(field.visible_when) ? field.visible_when : [field.visible_when]
}

function isVisible(field: ProtocolFormField, value: Record<string, unknown>) {
  return conditionsFor(field).every((condition) => Object.is(getAt(value, condition.field), condition.equals))
}

function scalarValue(value: unknown) {
  return value === null || value === undefined ? '' : String(value)
}

function JsonSchemaField({
  field,
  value,
  onChange,
}: {
  field: ProtocolFormField
  value: unknown
  onChange: (value: unknown) => void
}) {
  const arrayOnly = field.type === 'json-array'
  const format = (input: unknown) => JSON.stringify(input ?? (arrayOnly ? [] : {}), null, 2)
  const [text, setText] = useState(format(value))

  useEffect(() => setText(format(value)), [value])

  const commit = () => {
    try {
      const parsed = text.trim() ? JSON.parse(text) : (arrayOnly ? [] : {})
      if (arrayOnly && !Array.isArray(parsed)) {
        toast.error(field.label + ' 必须是 JSON 数组')
        return
      }
      if (!arrayOnly && !isRecord(parsed) && !Array.isArray(parsed)) {
        toast.error(field.label + ' 必须是 JSON 对象或数组')
        return
      }
      onChange(parsed)
    } catch {
      toast.error(field.label + ' JSON 格式不正确')
    }
  }

  return <label className={'field' + (field.full ? ' full' : '')}>
    <span>{field.label}</span>
    <textarea
      value={text}
      onChange={(event) => setText(event.target.value)}
      onBlur={commit}
      spellCheck={false}
    />
  </label>
}

export function ProtocolSchemaForm({
  fields,
  value,
  onChange,
}: {
  fields: ProtocolFormField[]
  value: Record<string, unknown>
  onChange: (path: string, value: unknown) => void
}) {
  return <div className="node-form-grid">
    {fields.filter((field) => isVisible(field, value)).map((field) => {
      const current = getAt(value, field.key)
      const className = field.full ? ' full' : ''

      if (field.type === 'checkbox') {
        return <label key={field.key} className={'check-field' + className}>
          <input
            type="checkbox"
            checked={Boolean(current)}
            onChange={(event) => onChange(field.key, event.target.checked)}
          />
          {field.label}
        </label>
      }

      if (field.type === 'json' || field.type === 'json-array') {
        return <JsonSchemaField
          key={field.key}
          field={field}
          value={current}
          onChange={(next) => onChange(field.key, next)}
        />
      }

      if (field.type === 'string-list') {
        const separator = field.separator === 'newline' ? '\n' : ', '
        const splitPattern = field.separator === 'newline' ? /\r?\n/ : /,/
        const items = Array.isArray(current) ? current.map(String) : []
        return <label key={field.key} className={'field' + className}>
          <span>{field.label}</span>
          {field.separator === 'newline'
            ? <textarea
                value={items.join(separator)}
                placeholder={field.placeholder}
                onChange={(event) => onChange(
                  field.key,
                  event.target.value.split(splitPattern).map((item) => item.trim()).filter(Boolean),
                )}
              />
            : <input
                value={items.join(separator)}
                placeholder={field.placeholder}
                onChange={(event) => onChange(
                  field.key,
                  event.target.value.split(splitPattern).map((item) => item.trim()).filter(Boolean),
                )}
              />}
        </label>
      }

      if (field.type === 'select') {
        return <label key={field.key} className={'field' + className}>
          <span>{field.label}</span>
          <select
            value={scalarValue(current)}
            onChange={(event) => {
              const option = field.options?.find((item) => String(item.value) === event.target.value)
              onChange(field.key, option ? option.value : event.target.value)
            }}
          >
            {(field.options || []).map((option) => (
              <option key={String(option.value)} value={String(option.value)}>{option.label}</option>
            ))}
          </select>
        </label>
      }

      if (field.type === 'textarea') {
        return <label key={field.key} className={'field' + className}>
          <span>{field.label}</span>
          <textarea
            value={scalarValue(current)}
            placeholder={field.placeholder}
            onChange={(event) => onChange(field.key, event.target.value)}
          />
        </label>
      }

      if (field.type === 'number') {
        return <label key={field.key} className={'field' + className}>
          <span>{field.label}</span>
          <input
            type="number"
            min={field.min}
            max={field.max}
            step={field.step}
            value={scalarValue(current)}
            placeholder={field.placeholder}
            onChange={(event) => onChange(
              field.key,
              event.target.value.trim() === '' ? null : Number(event.target.value),
            )}
          />
        </label>
      }

      return <label key={field.key} className={'field' + className}>
        <span>{field.label}</span>
        <input
          value={scalarValue(current)}
          placeholder={field.placeholder}
          onChange={(event) => onChange(field.key, event.target.value)}
        />
      </label>
    })}
  </div>
}
