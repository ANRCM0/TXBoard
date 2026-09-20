import type { ReactNode } from 'react'
import { useState } from 'react'
import {
  AppWindow,
  Cable,
  Check,
  ChevronDown,
  Mail,
  Send,
  Server,
  Settings,
  ShieldCheck,
  Smartphone,
  Users,
} from 'lucide-react'
import { NavLink, useLocation, useNavigate } from 'react-router-dom'

const sections = [
  { to: '/config/system', label: '站点设置', icon: Settings, end: true },
  { to: '/config/system/safe', label: '安全设置', icon: ShieldCheck },
  { to: '/config/system/subscribe', label: '订阅设置', icon: Cable },
  { to: '/config/system/invite', label: '邀请&佣金设置', icon: Users },
  { to: '/config/server', label: '节点配置', icon: Server },
  { to: '/config/email', label: '邮件设置', icon: Mail },
  { to: '/config/telegram', label: 'Telegram 设置', icon: Send },
  { to: '/config/APP', label: 'APP 设置', icon: Smartphone },
  { to: '/config/subscribe-template', label: '订阅模板', icon: AppWindow },
] as const

function matches(pathname: string, section: (typeof sections)[number]) {
  if ('end' in section && section.end) return pathname === section.to
  return pathname === section.to || pathname.startsWith(section.to + '/')
}

export function ConfigSectionFrame({
  title,
  description,
  children,
}: {
  title: string
  description?: string
  children: ReactNode
}) {
  const location = useLocation()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)
  const current = sections.find(section => matches(location.pathname, section)) || sections[0]
  const CurrentIcon = current.icon

  function go(to: string) {
    setMobileOpen(false)
    navigate(to)
  }

  return (
    <div className="config-frame-page">
      <div className="config-frame-heading">
        <h1>系统设置</h1>
        <p>管理系统核心配置，包括站点、安全、订阅、邀请佣金、节点、邮件和通知等设置。</p>
      </div>

      <div className="config-frame-divider" />

      <div className="config-frame-mobile-nav">
        <button
          type="button"
          className={`config-frame-mobile-trigger ${mobileOpen ? 'open' : ''}`}
          onClick={() => setMobileOpen(value => !value)}
          aria-expanded={mobileOpen}
        >
          <span className="config-frame-mobile-current">
            <CurrentIcon size={20} />
            <strong>{current.label}</strong>
          </span>
          <ChevronDown size={18} />
        </button>

        {mobileOpen ? (
          <div className="config-frame-mobile-menu">
            {sections.map(section => {
              const Icon = section.icon
              const active = matches(location.pathname, section)
              return (
                <button
                  type="button"
                  key={section.to}
                  className={active ? 'active' : ''}
                  onClick={() => go(section.to)}
                >
                  <span>
                    <Icon size={20} />
                    <strong>{section.label}</strong>
                  </span>
                  {active ? <Check size={17} /> : null}
                </button>
              )
            })}
          </div>
        ) : null}
      </div>

      <div className="config-frame-layout">
        <aside className="config-frame-sidebar">
          <nav className="config-frame-nav">
            {sections.map(section => {
              const Icon = section.icon
              return (
                <NavLink
                  key={section.to}
                  to={section.to}
                  end={'end' in section ? section.end : false}
                  className={({ isActive }) => `config-frame-nav-link ${isActive ? 'active' : ''}`}
                >
                  <Icon size={18} />
                  <span>{section.label}</span>
                </NavLink>
              )
            })}
          </nav>
        </aside>

        <section className="config-frame-content">
          <div className="config-frame-section-heading">
            <h2>{title}</h2>
            {description ? <p>{description}</p> : null}
          </div>
          <div className="config-frame-divider compact" />
          {children}
        </section>
      </div>
    </div>
  )
}
