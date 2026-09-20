import { SettingsForm, type SettingField } from '../../components/config/SettingsForm'

const fields: SettingField[] = [
  { key: 'frontend_theme_sidebar', label: '侧边栏主题', type: 'select', options: [{ label: 'Light', value: 'light' }, { label: 'Dark', value: 'dark' }] },
  { key: 'frontend_theme_header', label: '顶栏主题', type: 'select', options: [{ label: 'Light', value: 'light' }, { label: 'Dark', value: 'dark' }] },
  { key: 'frontend_theme_color', label: '主题配色', type: 'select', options: [
    { label: 'Default', value: 'default' },
    { label: 'Dark Blue', value: 'darkblue' },
    { label: 'Black', value: 'black' },
    { label: 'Green', value: 'green' },
  ] },
  { key: 'frontend_background_url', label: '背景图 URL', placeholder: 'https://...' },
]

export function FrontendSettingsPage() {
  return <SettingsForm settingKey="frontend" title="前端设置" description="主题本体在“主题管理”切换；此页管理侧栏、顶栏、颜色和背景图。" fields={fields}/>
}
