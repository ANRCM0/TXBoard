import {
  ChevronDown,
  LayoutDashboard,
  PanelLeftClose,
  PanelLeftOpen,
  X,
} from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { TopBar } from './TopBar'
import { coreNavigationGroups } from '../../navigation/core'
import { preloadAdminRoute, scheduleAdminRouteWarmup } from '../../lib/routePreload'
import { useDialog } from '../../lib/useDialog'
import { availableAdminPaths } from '../../native/routes'

const availablePaths = new Set(availableAdminPaths)

type SidebarProps = {
  open: boolean
  onClose: () => void
  collapsed: boolean
  onExpand: () => void
  onToggle?: () => void
}

const OPEN_GROUPS_STORAGE_KEY = 'txboard:admin:sidebar:open-groups'

// Read preferences before the first paint, so a refresh does not briefly expand every group.
function readOpenGroups(): Record<string, boolean> {
  const groups: Record<string, boolean> = Object.fromEntries(
    coreNavigationGroups.map(group => [group.key, true]),
  )

  try {
    const stored = window.localStorage.getItem(OPEN_GROUPS_STORAGE_KEY)
    if (!stored) return groups
    const saved: unknown = JSON.parse(stored)
    if (!saved || typeof saved !== 'object' || Array.isArray(saved)) return groups

    for (const group of coreNavigationGroups) {
      const expanded = (saved as Record<string, unknown>)[group.key]
      if (typeof expanded === 'boolean') groups[group.key] = expanded
    }
  } catch {
    // Private browsing, blocked storage and invalid old values use the defaults.
  }

  return groups
}

export function Sidebar({ open, onClose, collapsed, onExpand, onToggle }: SidebarProps) {
  const { pathname } = useLocation()
  const dialogRef = useDialog(open, onClose)
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>(readOpenGroups)

  const coreRoutePaths = useMemo(
    () => coreNavigationGroups.flatMap(group => group.items.map(([path]) => path)),
    [],
  )

  useEffect(
    () => scheduleAdminRouteWarmup(coreRoutePaths),
    [coreRoutePaths],
  )

  useEffect(() => {
    try {
      window.localStorage.setItem(OPEN_GROUPS_STORAGE_KEY, JSON.stringify(openGroups))
    } catch {
      // A storage write failure must never break navigation.
    }
  }, [openGroups])

  function toggleGroup(key: string) {
    setOpenGroups(state => ({ ...state, [key]: !(state[key] ?? true) }))
  }

  function warmRoute(path: string) {
    void preloadAdminRoute(path)
  }

  return (
    <div ref={dialogRef} tabIndex={-1} role={open ? 'dialog' : undefined} aria-modal={open || undefined} aria-label="管理导航" className={`admin-sidebar ${open ? 'is-open' : ''}`}>
      <div className="admin-sidebar-inner">
        <div className="admin-sidebar-brand">
          <div className="admin-sidebar-brand-main">
            <div className="admin-brand-mark">TX</div>
            <div className="admin-brand-copy">
              <strong>TXBoard</strong>
              <small>管理控制台</small>
            </div>
          </div>
          <button type="button" className="admin-mobile-menu admin-sidebar-close" onClick={onClose} aria-label="关闭导航">
            <X size={22} />
          </button>
        </div>

        <nav className="admin-sidebar-nav" aria-label="主导航">
          <NavLink
            to="/"
            end
            title="仪表盘"
            aria-label="仪表盘"
            onClick={onClose}
            className={({ isActive }) => `admin-nav-root ${isActive ? 'active' : ''}`}
          >
            <LayoutDashboard size={18} />
            <span>仪表盘</span>
          </NavLink>

          {coreNavigationGroups.map(group => {
            const GroupIcon = group.icon
            const expanded = openGroups[group.key] ?? true
            return (
              <section className="admin-nav-group" key={group.key}>
                <button
                  type="button"
                  className={`admin-nav-group-trigger ${group.items.some(([path]) => pathname === path || pathname.startsWith(path + '/')) ? 'active' : ''}`}
                  title={group.title}
                  aria-label={group.title}
                  aria-controls={`admin-nav-${group.key}`}
                  aria-expanded={expanded}
                  onClick={() => collapsed && !open ? onExpand() : toggleGroup(group.key)}
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
                        onPointerEnter={() => warmRoute(to)}
                        onPointerDown={() => warmRoute(to)}
                        onFocus={() => warmRoute(to)}
                        onClick={onClose}
                        className={({ isActive }) => `admin-nav-sub ${isActive ? 'active' : ''}`}
                      >
                        <Icon size={17} />
                        <span>{label}</span>
                        {!availablePaths.has(to) ? <small className="native-nav-pending">待开放</small> : null}
                      </NavLink>
                    ))}
                  </div>
                ) : null}
              </section>
            )
          })}


        </nav>

        <div className="admin-sidebar-footer">
          <TopBar onOpenMenu={onClose} />
          <button type="button" className="admin-sidebar-collapse-toggle" aria-label={collapsed ? '展开侧边栏' : '收起侧边栏'} title={collapsed ? '展开侧边栏' : '收起侧边栏'} aria-expanded={!collapsed} onClick={onToggle ?? onExpand}>
            {collapsed ? <PanelLeftOpen size={19} /> : <PanelLeftClose size={19} />}
          </button>
        </div>
      </div>
    </div>
  )
}
