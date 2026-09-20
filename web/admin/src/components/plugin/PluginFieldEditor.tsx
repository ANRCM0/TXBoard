import { useEffect, useState } from 'react'
import type {
  PluginAdminCrudFormField,
  PluginAdminCrudSchema,
  PluginConfigField,
} from '../../api/plugin'

export type NormalizedPluginField = {
  key: string
  field: PluginConfigField
}

export function normalizePluginFields(form?: PluginAdminCrudSchema['form']): NormalizedPluginField[] {
  if (!form) return []
  if (Array.isArray(form)) {
    return form
      .filter((item): item is PluginAdminCrudFormField => Boolean(item.name))
      .map(item => ({
        key: item.name,
        field: {
          type: item.type,
          label: item.label,
          placeholder: item.placeholder,
          description: item.description,
          value: item.value,
          options: item.options,
          required: item.required,
          hidden: item.hidden,
          readonly: item.readonly,
        },
      }))
  }
  return Object.entries(form).map(([key, field]) => ({ key, field }))
}

export function defaultPluginValues(fields: NormalizedPluginField[]) {
  const values: Record<string, unknown> = {}
  for (const { key, field } of fields) {
    values[key] = field.value ?? (field.type === 'boolean' ? false : '')
  }
  return values
}

export function PluginFieldEditor({
  fields,
  values,
  idField = 'id',
  onChange,
}: {
  fields: NormalizedPluginField[]
  values: Record<string, unknown>
  idField?: string
  onChange: (key: string, value: unknown) => void
}) {
  const visible = fields.filter(item => !item.field.hidden)
  return <div className="plugin-field-list">
    {visible.map(({ key, field }) => <PluginField
      key={key}
      fieldKey={key}
      field={field}
      value={values[key]}
      readOnly={Boolean(field.readonly || (key === idField && values[key] != null && values[key] !== ''))}
      onChange={value => onChange(key, value)}
    />)}
  </div>
}

function PluginField({
  fieldKey,
  field,
  value,
  readOnly,
  onChange,
}: {
  fieldKey: string
  field: PluginConfigField
  value: unknown
  readOnly: boolean
  onChange: (value: unknown) => void
}) {
  const type = field.type || 'string'
  const label = field.label || fieldKey

  if (type === 'boolean') {
    return <label className="plugin-switch-field">
      <span>
        <strong>{label}{field.required && <em> *</em>}</strong>
        {field.description && <small>{field.description}</small>}
      </span>
      <button
        type="button"
        className={Boolean(value) ? 'switch-control active' : 'switch-control'}
        role="switch"
        aria-checked={Boolean(value)}
        disabled={readOnly}
        onClick={() => !readOnly && onChange(!Boolean(value))}
      ><span/></button>
    </label>
  }

  if (type === 'select' && field.options?.length) {
    return <label className="field">
      <span>{label}{field.required && <em className="required-mark"> *</em>}</span>
      <select
        value={value == null ? '' : String(value)}
        disabled={readOnly}
        onChange={event => {
          const raw = event.target.value
          const matched = field.options?.find(option => String(option.value) === raw)
          onChange(matched?.value ?? raw)
        }}
      >
        {field.options.map(option => <option key={String(option.value)} value={String(option.value)}>
          {option.label ?? option.value}
        </option>)}
      </select>
      {field.description && <small className="field-help">{field.description}</small>}
    </label>
  }

  if (type === 'json') {
    return <JsonPluginField
      label={label}
      required={Boolean(field.required)}
      description={field.description}
      value={value}
      readOnly={readOnly}
      onChange={onChange}
    />
  }

  if (type === 'textarea' || type === 'text') {
    return <label className="field">
      <span>{label}{field.required && <em className="required-mark"> *</em>}</span>
      <textarea
        value={value == null ? '' : String(value)}
        placeholder={field.placeholder}
        readOnly={readOnly}
        onChange={event => onChange(event.target.value)}
      />
      {field.description && <small className="field-help">{field.description}</small>}
    </label>
  }

  return <label className="field">
    <span>{label}{field.required && <em className="required-mark"> *</em>}</span>
    <input
      type={type === 'number' ? 'number' : type === 'password' ? 'password' : 'text'}
      value={value == null ? '' : String(value)}
      placeholder={field.placeholder}
      readOnly={readOnly}
      onChange={event => onChange(
        type === 'number' && event.target.value !== ''
          ? Number(event.target.value)
          : event.target.value,
      )}
    />
    {field.description && <small className="field-help">{field.description}</small>}
  </label>
}

function JsonPluginField({
  label,
  required,
  description,
  value,
  readOnly,
  onChange,
}: {
  label: string
  required: boolean
  description?: string
  value: unknown
  readOnly: boolean
  onChange: (value: unknown) => void
}) {
  const serialize = (input: unknown) => {
    if (typeof input === 'string') return input
    try { return JSON.stringify(input ?? {}, null, 2) } catch { return '' }
  }
  const [text, setText] = useState(() => serialize(value))
  const [error, setError] = useState('')

  useEffect(() => setText(serialize(value)), [value])

  return <label className="field plugin-field-wide">
    <span>{label}{required && <em className="required-mark"> *</em>}</span>
    <textarea
      className="plugin-json-field"
      value={text}
      readOnly={readOnly}
      onChange={event => {
        const next = event.target.value
        setText(next)
        try {
          const parsed = JSON.parse(next)
          setError('')
          onChange(parsed)
        } catch {
          setError('JSON 格式有误')
        }
      }}
    />
    {error && <small className="field-error">{error}</small>}
    {!error && description && <small className="field-help">{description}</small>}
  </label>
}
