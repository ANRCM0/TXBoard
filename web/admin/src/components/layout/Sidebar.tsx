import { useQuery } from '@tanstack/react-query'
import {
  Bell,
  BookOpen,
  Boxes,
  ChevronDown,
  CreditCard,
  FileText,
  Gift,
  LayoutDashboard,
  MessageCircle,
  Network,
  Package,
  Plug,
  RefreshCcw,
  Route,
  Server,
  Settings,
  Tag,
  Users,
  WalletCards,
  X,
} from 'lucide-react'
import { useState, type KeyboardEvent } from 'react'
import { NavLink } from 'react-router-dom'
import { getPlugins, normalizePluginPath } from '../../api/plugin'
import { preloadAdminRoute } from '../../lib/routePreload'

const groups = [
  {
    key: 'system',
    title: '系统管理',
    icon: Settings,
    items: [
      ['/config/system', '系统配置', Settings],
      ['/config/plugin', '插件管理', Plug],
      ['/config/theme', '主题配置', Package],
      ['/config/notice', '公告管理', Bell],
      ['/config/payment', '支付配置', WalletCards],
      ['/config/knowledge', '知识库管理', BookOpen],
    ],
  },
  {
    key: 'node',
    title: '节点管理',
    icon: Server,
    items: [
      ['/server/machine', '服务器管理', Server],
      ['/server/manage', '节点管理', Network],
      ['/server/group', '权限组管理', Boxes],
      ['/server/route', '路由管理', Route],
    ],
  },
  {
    key: 'subscription',
    title: '订阅管理',
    icon: CreditCard,
    items: [
      ['/finance/plan', '套餐管理', CreditCard],
      ['/finance/order', '订单管理', FileText],
      ['/finance/coupon', '优惠券', Tag],
      ['/finance/gift-card', '礼品卡', Gift],
    ],
  },
  {
    key: 'user',
    title: '用户管理',
    icon: Users,
    items: [
      ['/user/manage', '用户管理', Users],
      ['/user/traffic-reset', '流量重置', RefreshCcw],
      ['/user/ticket', '工单管理', MessageCircle],
    ],
  },
] as const

type SidebarProps = {
  open: boolean
  onClose: () => void
}

export function Sidebar({ open, onClose }: SidebarProps) {
  const pluginsQuery = useQuery({ queryKey: ['pluginList'], queryFn: () => getPlugins() })
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({
    system: true,
    node: true,
    subscription: true,
    user: true,
  })

  const pluginGroups = (Array.isArray(pluginsQuery.data) ? pluginsQuery.data : [])
    .filter(plugin => plugin.is_installed && plugin.is_enabled && plugin.admin_menus?.length)
    .map(plugin => ({
      code: plugin.code,
      title: plugin.name || plugin.code,
      version: plugin.version,
      items: (plugin.admin_menus || [])
        .map(menu => {
          const path = normalizePluginPath(menu.path)
          return path ? { path: `/plugins/${plugin.code}/${path}`, label: menu.title || menu.label || path } : null
        })
        .filter((item): item is { path: string; label: string } => item !== null),
    }))
    .filter(group => group.items.length)

  function toggleGroup(key: string) {
    setOpenGroups(state => ({ ...state, [key]: !(state[key] ?? true) }))
  }

  function preventSpaceScroll(event: KeyboardEvent<HTMLButtonElement>) {
    if (event.key === ' ') event.preventDefault()
  }

  function toggleGroupWithSpace(event: KeyboardEvent<HTMLButtonElement>, key: string) {
    if (event.key !== ' ') return
    event.preventDefault()
    toggleGroup(key)
  }

  return (
    <aside className={`admin-sidebar ${open ? 'is-open' : ''}`}>
      <div className="admin-sidebar-inner">
        <div className="admin-sidebar-brand">
          <div className="admin-sidebar-brand-main">
            <div className="admin-brand-mark">TX</div>
            <div className="admin-brand-copy">
              <strong>TXBoard</strong>
            </div>
          </div>
          <button type="button" className="admin-mobile-menu admin-sidebar-close" onClick={onClose} aria-label="关闭导航">
            <X size={22} />
          </button>
        </div>

        <nav className="admin-sidebar-nav">
          <NavLink
            to="/"
            end
            onClick={onClose}
            className={({ isActive }) => `admin-nav-root ${isActive ? 'active' : ''}`}
          >
            <LayoutDashboard size={18} />
            <span>仪表盘</span>
          </NavLink>

          {groups.map(group => {
            const GroupIcon = group.icon
            const expanded = openGroups[group.key] ?? true
            return (
              <section className="admin-nav-group" key={group.key}>
                <button
                  type="button"
                  className="admin-nav-group-trigger"
                  aria-controls={`admin-nav-${group.key}`}
                  aria-expanded={expanded}
                  onClick={() => toggleGroup(group.key)}
                  onKeyDown={preventSpaceScroll}
                  onKeyUp={event => toggleGroupWithSpace(event, group.key)}
                >
                  <GroupIcon size={18} />
                  <span>{group.title}</span>
                  <ChevronDown size={16} className={expanded ? 'open' : ''} />
                </button>

                {expanded ? (
                  <div className="admin-nav-sublist" id={`admin-nav-${group.key}`}>
                    {group.items.map(([to, label, Icon]) => (
                      <NavLink
                        key={to}
                        to={to}
                        onMouseEnter={() => preloadAdminRoute(to)}
                        onFocus={() => preloadAdminRoute(to)}
                        onClick={onClose}
                        className={({ isActive }) => `admin-nav-sub ${isActive ? 'active' : ''}`}
                      >
                        <Icon size={17} />
                        <span>{label}</span>
                      </NavLink>
                    ))}
                  </div>
                ) : null}
              </section>
            )
          })}

          {pluginGroups.map(group => (
            <section className="admin-nav-group" key={group.code}>
              <button type="button" className="admin-nav-group-trigger">
                <Plug size={18} />
                <span className="truncate">{group.title}</span>
                {group.version ? <small>v{group.version}</small> : null}
              </button>
              <div className="admin-nav-sublist">
                {group.items.map(item => (
                  <NavLink
                    key={item.path}
                    to={item.path}
                    onMouseEnter={() => preloadAdminRoute(item.path)}
                    onFocus={() => preloadAdminRoute(item.path)}
                    onClick={onClose}
                    className={({ isActive }) => `admin-nav-sub ${isActive ? 'active' : ''}`}
                  >
                    <Plug size={16} />
                    <span>{item.label}</span>
                  </NavLink>
                ))}
              </div>
            </section>
          ))}
        </nav>

        <div className="admin-sidebar-footer">
          <span className="admin-version-dot" />
          <span>TXBoard</span>
        </div>
      </div>
    </aside>
  )
}
