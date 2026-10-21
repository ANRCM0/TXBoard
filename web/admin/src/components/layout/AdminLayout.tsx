import { t, useLocale } from '../../lib/i18n'
import { Suspense, useEffect, useRef, useState } from 'react'
import { ChevronRight, Menu } from 'lucide-react'
import { Outlet, useLocation } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'
import { RouteErrorBoundary } from './RouteErrorBoundary'
import { ConfirmDialogHost } from '../ui/ConfirmDialog'
import { PendingWorkflowNotice } from '../../native/PendingWorkflowNotice'
import { useSiteBranding } from '../../lib/useSiteBranding'
import { SiteAppearanceConsumer } from '../../lib/useSiteAppearance'
import { DirtyNavigationHost } from './DirtyNavigationHost'
import { coreNavigationGroups } from '../../navigation/core'

const SIDEBAR_EXPANDED_KEY = 'txboard:admin:sidebar:expanded'

function readSidebarExpanded() {
  try {
    // New visitors get the readable navigation; returning visitors keep their choice.
    return window.localStorage.getItem(SIDEBAR_EXPANDED_KEY) !== 'false'
  } catch {
    return true
  }
}

const extraSections: Record<string, readonly [string, string]> = {
  '/config/email': ['系统管理', '邮件设置'],
  '/config/telegram': ['系统管理', 'Telegram'],
  '/config/app': ['系统管理', 'APP 设置'],
  '/config/appearance': ['系统管理', '管理端外观'],
}

function currentSection(pathname: string) {
  if (pathname === '/') return { group: t('管理控制台'), page: t('仪表盘') }
  const extra = extraSections[pathname]
  if (extra) return { group: t(extra[0]), page: t(extra[1]) }
  for (const group of coreNavigationGroups) {
    for (const [path, label] of group.items) {
      if (pathname === path || pathname.startsWith(path + '/')) {
        return { group: t(group.title), page: t(label) }
      }
    }
  }
  if (pathname.startsWith('/server/')) return { group: t('节点管理'), page: '' }
  if (pathname.startsWith('/user/')) return { group: t('用户管理'), page: '' }
  return { group: t('管理控制台'), page: '' }
}

export function AdminLayout() {
  useLocale()
  useSiteBranding(t('管理控制台'))
  const [open, setOpen] = useState(false)
  const [expanded, setExpanded] = useState(readSidebarExpanded)
  const { pathname } = useLocation()
  const scrollRef = useRef<HTMLDivElement>(null)
  const mainRef = useRef<HTMLElement>(null)
  const firstRoute = useRef(true)
  const [announcement, setAnnouncement] = useState('')

  useEffect(() => {
    try { window.localStorage.setItem(SIDEBAR_EXPANDED_KEY, String(expanded)) } catch { /* storage is optional */ }
  }, [expanded])

  useEffect(() => {
    setOpen(false)
    if (scrollRef.current) scrollRef.current.scrollTop = 0
    if (firstRoute.current) { firstRoute.current = false; return }
    mainRef.current?.focus({ preventScroll: true })
    setAnnouncement('')
    let attempts = 0
    let timer = 0
    let interacted = false
    const preserveUserFocus = () => { interacted = true }
    document.addEventListener('pointerdown', preserveUserFocus, true)
    document.addEventListener('keydown', preserveUserFocus, true)
    const focusHeading = () => {
      const heading = document.querySelector<HTMLElement>('#admin-content h1')
      if (heading) {
        heading.tabIndex = -1
        // A delayed route focus must not dismiss a menu the user has just opened.
        if (!interacted) heading.focus({ preventScroll: true })
        setAnnouncement(t('已进入 {page}', { page: heading.textContent?.trim() || t('管理页面') }))
        return
      }
      if (attempts++ < 8) { timer = window.setTimeout(focusHeading, 50); return }
      setAnnouncement(t('页面已更新'))
    }
    timer = window.setTimeout(focusHeading, 80)
    return () => {
      window.clearTimeout(timer)
      document.removeEventListener('pointerdown', preserveUserFocus, true)
      document.removeEventListener('keydown', preserveUserFocus, true)
    }
  }, [pathname])

  useEffect(() => {
    const desktop = window.matchMedia('(min-width: 861px)')
    const closeOnDesktop = () => { if (desktop.matches) setOpen(false) }
    desktop.addEventListener('change', closeOnDesktop)
    return () => desktop.removeEventListener('change', closeOnDesktop)
  }, [])

  const section = currentSection(pathname)

  return (
    <div className={`admin-shell ${expanded ? 'is-sidebar-expanded' : 'is-sidebar-collapsed'}`}>
      <SiteAppearanceConsumer />
      <a className="admin-skip-link" href="#admin-content">{t("跳转到主要内容")}</a>
      <Sidebar open={open} onClose={() => setOpen(false)} collapsed={!expanded} onExpand={() => setExpanded(true)} onToggle={() => setExpanded(value => !value)} />
      <main ref={mainRef} className="admin-main" id="admin-content" tabIndex={-1}>
        <header className="admin-topbar">
          <button type="button" className="admin-topbar-menu" aria-label={t("打开导航")} onClick={() => setOpen(true)}>
            <Menu size={20} />
          </button>
          <div className="admin-breadcrumb">
            {section.page && section.page !== section.group
              ? <><span>{section.group}</span><ChevronRight size={14} aria-hidden="true" /><strong aria-current="page">{section.page}</strong></>
              : <strong aria-current="page">{section.page || section.group}</strong>}
          </div>
          <TopBar onOpenMenu={() => setOpen(false)} />
        </header>
        <div ref={scrollRef} className="admin-scroll">
          <div className='admin-page'>
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
