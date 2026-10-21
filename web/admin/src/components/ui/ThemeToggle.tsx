import { t, useLocale } from '../../lib/i18n'
import { Check, Monitor, Moon, Sun } from 'lucide-react'
import { useTheme } from '../../lib/useTheme'
import { Popover } from './Popover'

export function ThemeToggle() {
  useLocale()
  const { dark, theme, setTheme } = useTheme()
  return <Popover label={t("主题设置")} closeOnSelect triggerClassName="admin-toolbar-icon" panelClassName="ui-theme-panel" trigger={theme === 'system' ? <Monitor size={18} /> : dark ? <Moon size={18} /> : <Sun size={18} />}>
    <div className="ui-theme-options" role="group" aria-label={t("选择外观")}>
      {([{ value: 'light', label: t("浅色模式"), icon: Sun }, { value: 'dark', label: t("深色模式"), icon: Moon }, { value: 'system', label: t("跟随系统"), icon: Monitor }] as const).map(option =>
        <button type="button" key={option.value} aria-pressed={theme === option.value} onClick={() => setTheme(option.value)}><option.icon size={16} />{option.label}{theme === option.value ? <Check size={15} /> : null}</button>)}
    </div>
  </Popover>
}
