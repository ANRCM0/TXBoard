import { Suspense, useEffect, useRef, useState } from 'react'
import { PanelLeftClose, PanelLeftOpen } from 'lucide-react'
import { Outlet, useLocation } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'
import { ConfirmDialogHost } from '../ui/ConfirmDialog'

const SIDEBAR_EXPANDED_KEY = 'txboard:admin:sidebar:expanded'

function readSidebarExpanded() {
  try {
    // New visitors start with a compact icon rail; returning visitors keep their preference.
    return window.localStorage.getItem(SIDEBAR_EXPANDED_KEY) === 'true'
  } catch {
    return false
  }
}

export function AdminLayout() {
  const [open, setOpen] = useState(false)
  const [expanded, setExpanded] = useState(readSidebarExpanded)
  const { pathname } = useLocation()
  const scrollRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    try { window.localStorage.setItem(SIDEBAR_EXPANDED_KEY, String(expanded)) } catch { /* storage is optional */ }
  }, [expanded])

  useEffect(() => {
    setOpen(false)
    if (scrollRef.current) scrollRef.current.scrollTop = 0
  }, [pathname])

  useEffect(() => {
    const desktop = window.matchMedia('(min-width: 861px)')
    const closeOnDesktop = () => { if (desktop.matches) setOpen(false) }
    desktop.addEventListener('change', closeOnDesktop)
    return () => desktop.removeEventListener('change', closeOnDesktop)
  }, [])

  return (
    <div className={`admin-shell ${expanded ? 'is-sidebar-expanded' : 'is-sidebar-collapsed'}`}>
      <Sidebar open={open} onClose={() => setOpen(false)} collapsed={!expanded} onExpand={() => setExpanded(true)} />
      <button
        type="button"
        className="admin-sidebar-collapse-toggle"
        aria-label={expanded ? '收起侧边栏' : '展开侧边栏'}
        title={expanded ? '收起侧边栏' : '展开侧边栏'}
        aria-expanded={expanded}
        onClick={() => setExpanded(value => !value)}
      >
        {expanded ? <PanelLeftClose size={17} /> : <PanelLeftOpen size={17} />}
      </button>
      <main className="admin-main">
        <TopBar onOpenMenu={() => setOpen(true)} />
        <div ref={scrollRef} className="admin-scroll">
          <div className='admin-page'>
            <Suspense fallback={<div className="route-panel-loading" role="status" aria-live="polite">正在加载页面…</div>}>
              <Outlet />
            </Suspense>
          </div>
        </div>
      </main>
      {open ? (
        <button
          type="button"
          className="sidebar-backdrop"
          aria-label="关闭侧边栏"
          onClick={() => setOpen(false)}
        />
      ) : null}
      <ConfirmDialogHost/>
    </div>
  )
}
