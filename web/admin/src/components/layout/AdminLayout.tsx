import { t, useLocale } from '../../lib/i18n'
import { Suspense, useEffect, useRef, useState } from 'react'
import { ChevronRight, Menu } from 'lucide-react'
import { Link, Outlet, useLocation } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'
import { RouteErrorBoundary } from './RouteErrorBoundary'
import { ConfirmDialogHost } from '../ui/ConfirmDialog'
import { PendingWorkflowNotice } from '../../native/PendingWorkflowNotice'
import { useSiteBranding } from '../../lib/useSiteBranding'
import { SiteAppearanceConsumer } from '../../lib/useSiteAppearance'
import { DirtyNavigationHost } from './DirtyNavigationHost'
import { adminSection } from '../../navigation/core'
import { HeaderNavigation } from './HeaderNavigation'
import { SiteMark } from './SiteMark'
import { useRouteFocus } from '../../lib/useRouteFocus'

export function AdminLayout() {
  useLocale()
  const site = useSiteBranding(t('管理控制台'))
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()
  const scrollRef = useRef<HTMLDivElement>(null)
  const mainRef = useRef<HTMLElement>(null)
  const announcement = useRouteFocus(pathname, mainRef, scrollRef)

  useEffect(() => {
    setOpen(false)
  }, [pathname])

  useEffect(() => {
    const desktop = window.matchMedia('(min-width: 861px)')
    const closeOnDesktop = () => { if (desktop.matches) setOpen(false) }
    desktop.addEventListener('change', closeOnDesktop)
    return () => desktop.removeEventListener('change', closeOnDesktop)
  }, [])

  const section = adminSection(pathname)

  return (
    <div className="admin-shell admin-monochrome is-sidebar-expanded">
      <SiteAppearanceConsumer />
      <a className="admin-skip-link" href="#admin-content">{t("跳转到主要内容")}</a>
      {open ? <Sidebar open onClose={() => setOpen(false)} /> : null}
      <main ref={mainRef} className="admin-main" id="admin-content" tabIndex={-1}>
        <header className="admin-topbar">
          <Link className="admin-header-brand" to="/" aria-label={site.name}><SiteMark logo={site.logo} /><strong>{site.name}</strong></Link>
          <HeaderNavigation />
          <button type="button" className="admin-topbar-menu" aria-label={t("打开导航")} aria-expanded={open} aria-controls={open ? 'admin-mobile-navigation' : undefined} onClick={() => setOpen(true)}>
            <Menu size={20} />
          </button>
          <TopBar onOpenMenu={() => setOpen(false)} />
        </header>
        <div className="admin-contextbar"><div className="admin-breadcrumb"><span>{t(section.group.title)}</span><ChevronRight size={12} aria-hidden="true" /><strong aria-current="page">{t(section.item[1])}</strong></div></div>
        <div ref={scrollRef} className="admin-scroll">
          <div className='admin-page'>
            {section.group.key !== 'overview' ? <HeaderNavigation secondary /> : null}
            <p className="admin-route-announcer" role="status" aria-live="polite">{announcement}</p>
            <PendingWorkflowNotice />
            <RouteErrorBoundary>
              <Suspense fallback={<div className="route-panel-loading" role="status" aria-live="polite">{t("正在加载页面…")}</div>}>
                <Outlet />
              </Suspense>
            </RouteErrorBoundary>
          </div>
        </div>
      </main>
      {open ? (
        <button
          type="button"
          className="sidebar-backdrop"
          aria-label={t("关闭侧边栏")}
          onClick={() => setOpen(false)}
        />
      ) : null}
      <ConfirmDialogHost/>
      <DirtyNavigationHost />
    </div>
  )
}
