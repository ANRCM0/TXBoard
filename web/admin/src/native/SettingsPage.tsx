import { NavLink } from 'react-router-dom'
import { ConfigurationPage, type ConfigurationGroup } from './ConfigurationPage'

const links = [
  ['/config/system', '站点'], ['/config/system/features', '功能'], ['/config/system/safe', '安全'],
  ['/config/system/subscribe', '订阅'], ['/config/server', '节点'], ['/config/email', '邮件'],
  ['/config/telegram', 'Telegram'], ['/config/app', '客户端'], ['/config/email/templates', '邮件模板'],
  ['/config/system/invite', '邀请与佣金'],
] as const

export function SettingsPage({ group = 'site' }: { group?: ConfigurationGroup }) {
  return <div><nav className="native-settings-navigation" aria-label="设置分类">
    {links.map(([path, label]) => <NavLink key={path} to={path} end>{label}</NavLink>)}
  </nav><ConfigurationPage group={group} /></div>
}
