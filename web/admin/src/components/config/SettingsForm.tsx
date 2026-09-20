import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import { fetchSettings, saveSettings, type Settings } from '../../api/config'
import { ConfigSectionFrame } from './ConfigSectionFrame'

export type SettingOption = { label: string; value: string | number }
export type SettingField = {
  key: string
  label: string
  type?: 'text' | 'password' | 'number' | 'switch' | 'select' | 'textarea' | 'string-array'
  placeholder?: string
  description?: string
  options?: SettingOption[]
  min?: number
  max?: number
  step?: number
  section?: string
  visibleWhen?: (values: Settings) => boolean
}

export function SettingsForm({
  settingKey,
  title,
  description,
  fields,
}: {
  settingKey: string
  title: string
  description: string
  fields: SettingField[]
}) {
  const query = useQuery({ queryKey: ['settings', settingKey], queryFn: () => fetchSettings(settingKey) })
  const [values, setValues] = useState<Settings>({})
  const timer = useRef<number | undefined>()

  useEffect(() => {
    if (query.data) setValues(query.data)
  }, [query.data])

  const mutation = useMutation({
    mutationFn: (payload: Settings) => saveSettings(payload),
    onSuccess: () => toast.success('已自动保存'),
  })

  const sections = useMemo(() => {
    const order: string[] = []
    for (const field of fields) {
      const section = field.section || '基本设置'
      if (!order.includes(section)) order.push(section)
    }
    return order
  }, [fields])

  function patch(key: string, value: unknown) {
    setValues(prev => {
      const next = { ...prev, [key]: value }
      window.clearTimeout(timer.current)
      timer.current = window.setTimeout(() => mutation.mutate(next), 1000)
      return next
    })
  }

  return (
    <ConfigSectionFrame title={title} description={description}>
      {query.isLoading ? (
        <p className="config-frame-loading">加载中…</p>
      ) : (
        <div className="config-form-sections">
          {sections.map(section => {
            const items = fields.filter(
              field =>
                (field.section || '基本设置') === section &&
                (!field.visibleWhen || field.visibleWhen(values)),
            )
            if (!items.length) return null
            return (
              <section className="config-form-section" key={section}>
                <h3>{section}</h3>
                <div className="config-form-fields">
                  {items.map(field => (
                    <SettingInput
                      key={field.key}
                      field={field}
                      value={values[field.key]}
                      onChange={value => patch(field.key, value)}
                    />
                  ))}
                </div>
              </section>
            )
          })}
          <div className="config-autosave">
            {mutation.isPending ? '保存中…' : '修改后 1 秒自动保存'}
          </div>
        </div>
      )}
    </ConfigSectionFrame>
  )
}

function SettingInput({
  field,
  value,
  onChange,
}: {
  field: SettingField
  value: unknown
  onChange: (value: unknown) => void
}) {
  const type = field.type || 'text'

  if (type === 'switch') {
    return (
      <label className="config-switch-field">
        <div>
          <strong>{field.label}</strong>
          {field.description ? <small>{field.description}</small> : null}
        </div>
        <button
          type="button"
          role="switch"
          aria-checked={Boolean(value)}
          className={`config-switch ${Boolean(value) ? 'active' : ''}`}
          onClick={() => onChange(!Boolean(value))}
        >
          <span />
        </button>
      </label>
    )
  }

  if (type === 'select') {
    return (
      <label className="config-field">
        <span>{field.label}</span>
        <select
          value={String(value ?? '')}
          onChange={event => {
            const option = field.options?.find(item => String(item.value) === event.target.value)
            onChange(typeof option?.value === 'number' ? Number(event.target.value) : event.target.value)
          }}
        >
          {field.options?.map(option => (
            <option key={String(option.value)} value={String(option.value)}>
              {option.label}
            </option>
          ))}
        </select>
        {field.description ? <small>{field.description}</small> : null}
      </label>
    )
  }

  if (type === 'textarea' || type === 'string-array') {
    const text = type === 'string-array' && Array.isArray(value) ? value.join('\n') : String(value ?? '')
    return (
      <label className="config-field">
        <span>{field.label}</span>
        <textarea
          value={text}
          placeholder={field.placeholder}
          onChange={event =>
            onChange(
              type === 'string-array'
                ? event.target.value
                    .split(/[\n,]/)
                    .map(item => item.trim())
                    .filter(Boolean)
                : event.target.value,
            )
          }
        />
        {field.description ? <small>{field.description}</small> : null}
      </label>
    )
  }

  return (
    <label className="config-field">
      <span>{field.label}</span>
      <input
        type={type === 'password' ? 'password' : type === 'number' ? 'number' : 'text'}
        value={String(value ?? '')}
        placeholder={field.placeholder}
        min={field.min}
        max={field.max}
        step={field.step}
        onChange={event =>
          onChange(type === 'number' ? (event.target.value === '' ? '' : Number(event.target.value)) : event.target.value)
        }
      />
      {field.description ? <small>{field.description}</small> : null}
    </label>
  )
}
