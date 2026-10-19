import './settings-reference.css'
import { NavLink } from 'react-router-dom'
import { ConfigurationPage, type ConfigurationGroup } from './ConfigurationPage'

const links = [
  ['/config/system', '站点'], ['/config/system/features', '功能'], ['/config/system/safe', '安全'],
  ['/config/system/subscribe', '订阅'], ['/config/server', '节点'], ['/config/email', '邮件'],
  ['/config/telegram', 'Telegram'], ['/config/app', '客户端'], ['/config/email/templates', '邮件模板'],
  ['/config/system/invite', '邀请与佣金'],
] as const

export function SettingsPage({ group = 'site' }: { group?: ConfigurationGroup }) {
  return <div className="settings-reference settings-hub"><div className="settings-category-heading"><span>控制台设置</span><small>按分类管理站点偏好</small></div><nav className="native-settings-navigation" aria-label="设置分类">
    {links.map(([path, label]) => <NavLink key={path} to={path} end>{label}</NavLink>)}
  </nav><p className="settings-category-note">选择分类查看当前配置，编辑和保存仅作用于当前分类。</p><ConfigurationPage group={group} /></div>
}
