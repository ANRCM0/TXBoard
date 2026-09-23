import { useQuery } from '@tanstack/react-query'
import {
  ChevronDown,
  LayoutDashboard,
  Plug,
  X,
} from 'lucide-react'
import { useEffect, useMemo, useState, type KeyboardEvent } from 'react'
import { NavLink } from 'react-router-dom'
import { getModuleRegistry } from '../../api/module'
import { coreNavigationGroups } from '../../navigation/core'
import { buildModuleNavigationGroups } from '../../navigation/registry'
import { preloadAdminRoute, scheduleAdminRouteWarmup } from '../../lib/routePreload'
import { useDialog } from '../../lib/useDialog'

type SidebarProps = {
  open: boolean
  onClose: () => void
}

export function Sidebar({ open, onClose }: SidebarProps) {
  const dialogRef = useDialog(open, onClose)
  const modulesQuery = useQuery({ queryKey: ['moduleRegistry'], queryFn: getModuleRegistry })
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>(() =>
    Object.fromEntries(coreNavigationGroups.map(group => [group.key, true])),
  )

  const coreRoutePaths = useMemo(
    () => coreNavigationGroups.flatMap(group => group.items.map(([path]) => path)),
    [],
  )
  const moduleGroups = buildModuleNavigationGroups(modulesQuery.data?.modules || [])

  useEffect(
    () => scheduleAdminRouteWarmup(coreRoutePaths),
    [coreRoutePaths],
  )

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
                        onPointerEnter={() => warmRoute(to)}
                        onPointerDown={() => warmRoute(to)}
                        onFocus={() => warmRoute(to)}
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

          {moduleGroups.map(group => (
            <section className="admin-nav-group" key={group.moduleId}>
              <button type="button" className="admin-nav-group-trigger">
                <Plug size={18} />
                <span className="truncate">{group.title}</span>
                {group.version ? <small>v{group.version}</small> : null}
              </button>
              <div className="admin-nav-sublist">
                {group.items.map(item => (
                  <NavLink
                    key={item.id}
                    to={item.href}
                    onPointerEnter={() => warmRoute(item.href)}
                    onPointerDown={() => warmRoute(item.href)}
                    onFocus={() => warmRoute(item.href)}
                    onClick={onClose}
                    className={({ isActive }) => `admin-nav-sub ${isActive ? 'active' : ''}`}
                  >
                    <Plug size={16} />
                    <span>{item.title}</span>
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
    </div>
  )
}
