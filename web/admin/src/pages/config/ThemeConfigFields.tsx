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

// The nav-order control is schema-driven; no vv-theme-specific configuration
// is hardcoded into TXBoard. Hidden entries remain in the stored sort order.
export function parseThemeNavOrder(value: unknown, options: Array<{ value: string | number; label: string }>) {
  const valid = options.map(item => String(item.value))
  const mandatory = new Set(['dashboard', 'menu'])
  const tokens = typeof value === 'string' ? value.split(',').map(item => item.trim()).filter(Boolean) : []
  const result: Array<{ id: string; visible: boolean }> = []
  const seen = new Set<string>()
  for (const token of tokens) {
    const hidden = token.startsWith('!')
    const id = hidden ? token.slice(1) : token
    if (!valid.includes(id) || seen.has(id)) continue
    seen.add(id)
    result.push({ id, visible: mandatory.has(id) || !hidden })
  }
  // An empty setting uses the declared manifest order; omitted non-core
  // settings remain hidden to respect the operator's previous choice.
  for (const id of valid) {
    if (!seen.has(id)) result.push({ id, visible: tokens.length === 0 || mandatory.has(id) })
  }
  return result
}

export function stringifyThemeNavOrder(items: Array<{ id: string; visible: boolean }>) {
  return items.map(item => (item.visible || ['dashboard', 'menu'].includes(item.id) ? '' : '!') + item.id).join(',')
}

function NavigationOrderField({
  field,
  value,
  onChange,
}: {
  field: ThemeConfigField
  value: unknown
  onChange: (value: string) => void
}) {
  const options = themeSelectOptions(field)
  const items = parseThemeNavOrder(value, options)
  const names = new Map(options.map(option => [String(option.value), option.label]))
  const patch = (next: Array<{ id: string; visible: boolean }>) => onChange(stringifyThemeNavOrder(next))
  function move(index: number, delta: number) {
    const next = [...items]
    const other = index + delta
    if (other < 0 || other >= next.length) return
    ;[next[index], next[other]] = [next[other], next[index]]
    patch(next)
  }
  return <fieldset className="theme-nav-editor">
    <legend>{field.label || '菜单顺序'}</legend>
    <p>拖动排序可用上移、下移按钮；取消勾选可隐藏菜单。我的面板与全部菜单作为导航兜底始终显示。</p>
    <ol>
      {items.map((item, index) => {
        const locked = item.id === 'dashboard' || item.id === 'menu'
        return <li key={item.id}>
          <label>
            <input type="checkbox" aria-label={`显示${names.get(item.id) || item.id}`}
              checked={item.visible} disabled={locked}
              onChange={event => patch(items.map(current => current.id === item.id
                ? { ...current, visible: event.target.checked } : current))}/>
            <span>{names.get(item.id) || item.id}</span>
            {locked && <small>必备</small>}
          </label>
          <div className="theme-nav-move">
            <button type="button" aria-label={`${names.get(item.id)}上移`} disabled={index === 0}
              onClick={() => move(index, -1)}>↑</button>
            <button type="button" aria-label={`${names.get(item.id)}下移`} disabled={index === items.length - 1}
              onClick={() => move(index, 1)}>↓</button>
          </div>
        </li>
      })}
    </ol>
  </fieldset>
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
  function renderField(field: ThemeConfigField) {
    const value = values[field.field_name] ?? field.default_value ?? ''
    const type = (field.field_type || 'input').toLowerCase()
    const label = field.label || field.field_name
    const options = themeSelectOptions(field)
    if (type === 'navigation') {
      return <NavigationOrderField key={field.field_name} field={field} value={value}
        onChange={next => onChange(field.field_name, next)}/>
    }
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
        {field.description && <small>{field.description}</small>}
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
      {field.description && <small>{field.description}</small>}
    </label>
  }

  // Preserve the original flat form for existing theme manifests.
  if (!fields.some(field => typeof field.group === 'string' && field.group.trim())) {
    return <div className="config-form-fields">{fields.map(renderField)}</div>
  }

  const grouped = new Map<string, ThemeConfigField[]>()
  for (const field of fields) {
    const group = typeof field.group === 'string' && field.group.trim() ? field.group.trim() : '其他设置'
    if (!grouped.has(group)) grouped.set(group, [])
    grouped.get(group)!.push(field)
  }
  return <div className="theme-config-groups">
    {Array.from(grouped, ([group, items]) => (
      <section className="theme-config-group" aria-label={group} key={group}>
        <h3>{group}</h3>
        <div className="config-form-fields">{items.map(renderField)}</div>
      </section>
    ))}
  </div>
}
