import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import { fetchSettings, saveSettings, type Settings } from '../../api/config'
import { setAdminSecurePath } from '../../api/client'
import { resolveBasePath } from '../../lib/basePath'
import { ConfigSectionFrame } from './ConfigSectionFrame'
import { QueryFeedback } from '../ui/QueryFeedback'

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
  saveMode?: 'auto' | 'blur'
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
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: ['settings', settingKey], queryFn: () => fetchSettings(settingKey) })
  const [values, setValues] = useState<Settings>({})
  const latestValues = useRef<Settings>({})
  const dirty = useRef(false)
  const [unsaved, setUnsaved] = useState(false)
  const timer = useRef<number | undefined>()

  useEffect(() => {
    if (query.data && !dirty.current) {
      latestValues.current = query.data
      setValues(query.data)
    }
  }, [query.data])

  useEffect(() => () => window.clearTimeout(timer.current), [])

  const mutation = useMutation({
    scope: { id: `settings-${settingKey}` },
    mutationFn: (payload: Settings) => saveSettings(payload),
    onSuccess: (_data, payload) => {
      if (payload === latestValues.current) {
        dirty.current = false
        setUnsaved(false)
      }
      queryClient.setQueryData(['settings', settingKey], payload)
      toast.success('已自动保存')

      const nextSecurePath =
        typeof payload.secure_path === 'string'
          ? payload.secure_path.trim().replace(/^\/+|\/+$/g, '')
          : ''
      const currentBase = resolveBasePath()
      const currentSecurePath = currentBase.replace(/^\/+|\/+$/g, '')

      // The router basename is created once at application startup. After the
      // backend accepts a secure_path rotation, persist the new API prefix and
      // perform one full navigation to the same admin sub-route under the new
      // mount. The old URL becomes a 404 immediately.
      if (
        nextSecurePath &&
        currentSecurePath &&
        nextSecurePath !== currentSecurePath &&
        import.meta.env.VITE_STATIC_PREVIEW !== '1'
      ) {
        setAdminSecurePath(nextSecurePath)
        const pathname = window.location.pathname
        const suffix = pathname.startsWith(currentBase)
          ? pathname.slice(currentBase.length) || '/'
          : '/'
        window.location.replace(
          `/${nextSecurePath}${suffix}${window.location.search}${window.location.hash}`,
        )
      }
    },
  })

  const sections = useMemo(() => {
    const order: string[] = []
    for (const field of fields) {
      const section = field.section || '基本设置'
      if (!order.includes(section)) order.push(section)
    }
    return order
  }, [fields])

  function patch(field: SettingField, value: unknown) {
    const next = { ...latestValues.current, [field.key]: value }
    latestValues.current = next
    dirty.current = true
    setUnsaved(true)
    setValues(next)
    window.clearTimeout(timer.current)
    if ((field.saveMode || 'auto') === 'auto') {
      timer.current = window.setTimeout(() => mutation.mutate(next), 1000)
    }
  }

  function commitBlurField(field: SettingField) {
    if (field.saveMode !== 'blur') return
    window.clearTimeout(timer.current)
    mutation.mutate(latestValues.current)
  }

  return (
    <ConfigSectionFrame title={title} description={description}>
      {query.isLoading || (query.isError && !query.data) ? (
        <QueryFeedback loading={query.isFetching} error={query.isError} onRetry={() => query.refetch()} />
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
                      onChange={value => patch(field, value)}
                      onCommit={() => commitBlurField(field)}
                    />
                  ))}
                </div>
              </section>
            )
          })}
          <div className="config-autosave" role="status" aria-live="polite">
            {mutation.isPending ? '保存中…' : mutation.isError ? '保存失败，修改仍保留在当前页面' : unsaved ? '有待保存的修改…' : mutation.isSuccess ? '所有修改已保存' : '修改后自动保存，部分设置在离开输入框后保存'}
            {mutation.isError && <button type="button" className="button" onClick={() => {
              window.clearTimeout(timer.current)
              mutation.mutate(latestValues.current)
            }}>重试保存</button>}
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
  onCommit,
}: {
  field: SettingField
  value: unknown
  onChange: (value: unknown) => void
  onCommit: () => void
}) {
  const type = field.type || 'text'

  if (type === 'switch') {
    return (
      <label className="config-switch-field config-field-wide">
        <div>
          <strong>{field.label}</strong>
          {field.description ? <small>{field.description}</small> : null}
        </div>
        <button
          type="button"
          role="switch"
          aria-label={field.label}
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
          onBlur={onCommit}
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

  if (type === 'string-array') {
    return <StringArrayInput field={field} value={value} onChange={onChange} onCommit={onCommit} />
  }

  if (type === 'textarea') {
    return (
      <label className="config-field config-field-wide">
        <span>{field.label}</span>
        <textarea
          value={String(value ?? '')}
          placeholder={field.placeholder}
          onBlur={onCommit}
          onChange={event => onChange(event.target.value)}
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
        onBlur={onCommit}
        onChange={event =>
          onChange(type === 'number' ? (event.target.value === '' ? '' : Number(event.target.value)) : event.target.value)
        }
      />
      {field.description ? <small>{field.description}</small> : null}
    </label>
  )
}

function StringArrayInput({
  field,
  value,
  onChange,
  onCommit,
}: {
  field: SettingField
  value: unknown
  onChange: (value: unknown) => void
  onCommit: () => void
}) {
  const serialize = (input: unknown) => (Array.isArray(input) ? input.join('\n') : String(input ?? ''))
  const [draft, setDraft] = useState(() => serialize(value))

  useEffect(() => setDraft(serialize(value)), [value])

  return (
    <label className="config-field config-field-wide">
      <span>{field.label}</span>
      <textarea
        value={draft}
        placeholder={field.placeholder}
        onBlur={() => {
          onChange(
            draft
              .split(/[\n,]/)
              .map(item => item.trim())
              .filter(Boolean),
          )
          onCommit()
        }}
        onChange={event => setDraft(event.target.value)}
      />
      {field.description ? <small>{field.description}</small> : null}
    </label>
  )
}
