import type { ThemeConfigField } from '../../api/theme'

export function normalizeThemeFields(raw: unknown): ThemeConfigField[] {
  if (!Array.isArray(raw)) return []
  const used = new Set<string>()
  return raw.filter((item): item is ThemeConfigField => {
    if (!item || typeof item !== 'object') return false
    const field = item as Partial<ThemeConfigField>
    if (typeof field.field_name !== 'string' || !field.field_name.trim()) return false
    if (used.has(field.field_name)) return false
    used.add(field.field_name)
    return true
  })
}

export function themeSelectOptions(field: ThemeConfigField) {
  const raw = field.select_options
  if (Array.isArray(raw)) {
    return raw.filter(option => option && (typeof option.value === 'string' || typeof option.value === 'number'))
      .map(option => ({ value: option.value, label: String(option.label ?? option.value) }))
  }
  if (raw && typeof raw === 'object') {
    return Object.entries(raw).map(([value, label]) => ({ value, label: String(label) }))
  }
  return []
}

export function ThemeConfigFields({
  fields,
  values,
  onChange,
}: {
  fields: ThemeConfigField[]
  values: Record<string, unknown>
  onChange: (name: string, value: unknown) => void
}) {
  return <div className="config-form-fields">
    {fields.map(field => {
      const value = values[field.field_name] ?? field.default_value ?? ''
      const type = (field.field_type || 'input').toLowerCase()
      const label = field.label || field.field_name
      const options = themeSelectOptions(field)
      if (type === 'switch' || type === 'checkbox' || type === 'boolean') {
        const checked = value === true || value === 1 || value === '1' || value === 'true'
        return <label className="config-switch-field" key={field.field_name}>
          <strong>{label}</strong>
          <input
            type="checkbox"
            aria-label={label}
            checked={checked}
            onChange={event => onChange(field.field_name, event.target.checked ? '1' : '0')}
          />
        </label>
      }
      return <label className="config-field" key={field.field_name}>
        <span>{label}</span>
        {type === 'textarea' ? (
          <textarea
            placeholder={field.placeholder}
            value={String(value)}
            onChange={event => onChange(field.field_name, event.target.value)}
          />
        ) : type === 'select' && options.length ? (
          <select
            value={String(value)}
            onChange={event => {
              const option = options.find(item => String(item.value) === event.target.value)
              onChange(field.field_name, option?.value ?? event.target.value)
            }}
          >
            {options.map(option => <option value={String(option.value)} key={String(option.value)}>{option.label}</option>)}
          </select>
        ) : (
          <input
            type={type === 'number' ? 'number' : 'text'}
            placeholder={field.placeholder}
            value={String(value)}
            onChange={event => onChange(field.field_name, event.target.value)}
          />
        )}
      </label>
    })}
  </div>
}
