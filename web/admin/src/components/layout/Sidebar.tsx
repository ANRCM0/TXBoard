import { t, useLocale } from '../../lib/i18n'
import {
  ChevronDown,
  LayoutDashboard,
  PanelLeftClose,
  PanelLeftOpen,
  X,
} from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { coreNavigationGroups } from '../../navigation/core'
import { preloadAdminRoute, scheduleAdminRouteWarmup } from '../../lib/routePreload'
import { useDialog } from '../../lib/useDialog'
import { availableAdminPaths } from '../../native/routes'
import { useSiteBranding } from '../../lib/useSiteBranding'
import { SiteMark } from './SiteMark'

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
  useLocale()
  const site = useSiteBranding()
  const { pathname } = useLocation()
  const dialogRef = useDialog(open, onClose)
  const navRef = useRef<HTMLElement>(null)
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

  useEffect(() => {
    const nav = navRef.current
    if (!nav) return
    const active = nav.querySelector<HTMLElement>('.admin-nav-sub.active, .admin-nav-root.active')
    if (!active || typeof active.scrollIntoView !== 'function') return
    const navBounds = nav.getBoundingClientRect(), itemBounds = active.getBoundingClientRect()
    if (itemBounds.top < navBounds.top || itemBounds.bottom > navBounds.bottom) {
      active.scrollIntoView({ block: 'nearest' })
    }
  }, [pathname, openGroups])

  function toggleGroup(key: string) {
    setOpenGroups(state => ({ ...state, [key]: !(state[key] ?? true) }))
  }

  function warmRoute(path: string) {
    void preloadAdminRoute(path)
  }

  return (
    <div ref={dialogRef} tabIndex={-1} role={open ? 'dialog' : undefined} aria-modal={open || undefined} aria-label={t("管理导航")} className={`admin-sidebar ${open ? 'is-open' : ''}`}>
      <div className="admin-sidebar-inner">
        <div className="admin-sidebar-brand">
          <div className="admin-sidebar-brand-main">
            <SiteMark logo={site.logo} />
            <div className="admin-brand-copy">
              <strong title={site.name}>{site.name}</strong>
              <small>{t("管理控制台")}</small>
            </div>
          </div>
          <button type="button" className="admin-mobile-menu admin-sidebar-close" onClick={onClose} aria-label={t("关闭导航")}>
            <X size={22} />
          </button>
        </div>

        <nav ref={navRef} className="admin-sidebar-nav" aria-label={t("主导航")}>
          <NavLink
            to="/"
            end
            title={t("仪表盘")}
            aria-label={t("仪表盘")}
            onClick={onClose}
            className={({ isActive }) => `admin-nav-root ${isActive ? 'active' : ''}`}
          >
            <LayoutDashboard size={18} />
            <span>{t("仪表盘")}</span>
          </NavLink>

          {coreNavigationGroups.map(group => {
            const GroupIcon = group.icon
            const expanded = openGroups[group.key] ?? true
            return (
              <section className="admin-nav-group" key={group.key}>
                <button
                  type="button"
                  className={`admin-nav-group-trigger ${group.items.some(([path]) => pathname === path || pathname.startsWith(path + '/')) ? 'active' : ''}`}
                  title={t(group.title)}
                  aria-label={t(group.title)}
                  aria-controls={`admin-nav-${group.key}`}
                  aria-expanded={expanded}
                  onClick={() => collapsed && !open ? onExpand() : toggleGroup(group.key)}
                >
                  <GroupIcon size={18} />
                  <span>{t(group.title)}</span>
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
                        <span>{t(label)}</span>
                        {!availablePaths.has(to) ? <small className="native-nav-pending">{t("待开放")}</small> : null}
                      </NavLink>
                    ))}
                  </div>
                ) : null}
              </section>
            )
          })}


        </nav>

        <div className="admin-sidebar-footer">
          <button type="button" className="admin-sidebar-collapse-toggle" aria-label={t(collapsed ? t('展开侧边栏') : t('收起侧边栏'))} title={t(collapsed ? t('展开侧边栏') : t('收起侧边栏'))} aria-expanded={!collapsed} onClick={onToggle ?? onExpand}>
            {collapsed ? <PanelLeftOpen size={19} /> : <PanelLeftClose size={19} />}
            <span>{t(collapsed ? '展开侧边栏' : '收起侧边栏')}</span>
          </button>
        </div>
      </div>
    </div>
  )
}
