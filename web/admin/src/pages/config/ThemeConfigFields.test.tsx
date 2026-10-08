import { describe, expect, it } from 'vitest'
import { renderToStaticMarkup } from 'react-dom/server'
import { normalizeThemeFields, themeSelectOptions, ThemeConfigFields } from './ThemeConfigFields'

describe('theme manifest configuration form', () => {
  const manifest = [
    { field_name: 'theme_color', label: '主题色', field_type: 'select', select_options: { default: '默认', blue: '蓝色' }, default_value: 'default' },
    { field_name: 'background_url', label: '背景', field_type: 'input', placeholder: 'https://...' },
    { field_name: 'custom_html', label: '自定义页脚HTML', field_type: 'textarea' },
  ]

  it('uses only declared, unique theme fields in manifest order', () => {
    const fields = normalizeThemeFields([null, {}, ...manifest, { field_name: 'theme_color' }])
    expect(fields.map(field => field.field_name)).toEqual(['theme_color', 'background_url', 'custom_html'])
    expect(themeSelectOptions(fields[0])).toEqual([
      { value: 'default', label: '默认' },
      { value: 'blue', label: '蓝色' },
    ])
  })

  it('renders editable selects, inputs and text areas without exposing arbitrary config keys', () => {
    const fields = normalizeThemeFields(manifest)
    const html = renderToStaticMarkup(<ThemeConfigFields
      fields={fields}
      values={{ theme_color: 'blue', background_url: 'https://example.test/background.png', hidden_secret: 'should-not-show' }}
      onChange={() => {}}
    />)
    expect(html).toContain('主题色')
    expect(html).toContain('<select')
    expect(html).toContain('value="blue"')
    expect(html).toContain('https://example.test/background.png')
    expect(html).toContain('自定义页脚HTML')
    expect(html).toContain('<textarea')
    expect(html).not.toContain('hidden_secret')
    expect(html).not.toContain('should-not-show')
  })
})
