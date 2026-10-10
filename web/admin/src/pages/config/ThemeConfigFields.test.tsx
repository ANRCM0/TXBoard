import { describe, expect, it } from 'vitest'
import { renderToStaticMarkup } from 'react-dom/server'
import { normalizeThemeFields, themeSelectOptions, ThemeConfigFields, parseThemeNavOrder, stringifyThemeNavOrder } from './ThemeConfigFields'

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
  it('groups only manifests that declare groups and keeps older theme forms flat', () => {
    const grouped = normalizeThemeFields([
      { ...manifest[0], group: '品牌与外观' },
      { ...manifest[1], group: '品牌与外观' },
      { field_name: 'layout_mode', group: '页面布局', field_type: 'select',
        select_options: { top: '顶部导航', sidebar: '侧边栏' }, default_value: 'top' },
      { ...manifest[2], group: '扩展内容' },
    ])
    const html = renderToStaticMarkup(<ThemeConfigFields fields={grouped} values={{}} onChange={() => {}}/>)
    expect(html).toContain('品牌与外观')
    expect(html).toContain('页面布局')
    expect(html).toContain('扩展内容')
    expect(html).toContain('theme-config-groups')
    const legacyHtml = renderToStaticMarkup(<ThemeConfigFields fields={normalizeThemeFields(manifest)} values={{}} onChange={() => {}}/>)
    expect(legacyHtml).not.toContain('theme-config-groups')
  })

  it('renders reorderable, hideable navigation without permitting mandatory items to vanish', () => {
    const options = [
      { value: 'dashboard', label: '我的面板' }, { value: 'shop', label: '购买套餐' },
      { value: 'menu', label: '全部菜单' }, { value: 'orders', label: '我的订单' },
    ]
    const parsed = parseThemeNavOrder('!dashboard,!shop,orders,!menu,orders', options)
    expect(parsed.map(i => [i.id, i.visible])).toEqual([
      ['dashboard', true], ['shop', false], ['orders', true], ['menu', true]
      ] )
    expect(stringifyThemeNavOrder(parsed)).toBe('dashboard,!shop,orders,menu')
    const field = { group: '菜单与导航', label: '菜单显示与排序', field_name: 'nav_items',
      field_type: 'navigation', select_options: Object.fromEntries(options.map(o => [o.value, o.label])),
      default_value: 'dashboard,shop,menu,!orders' }
    const html = renderToStaticMarkup(<ThemeConfigFields fields={[field]} values={{}} onChange={() => {}}/>)
    expect(html).toContain('theme-nav-editor')
    expect(html).toContain('我的面板')
    expect(html).toContain('全部菜单')
    expect(html).toContain('我的订单')
    expect(html).toContain('显示购买套餐')
    expect(html).toContain('上移')
    expect(html).toContain('下移')
  })

})
