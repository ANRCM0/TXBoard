import { Suspense, useEffect, useRef, useState } from 'react'
import { Menu } from 'lucide-react'
import { Outlet, useLocation } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { ConfirmDialogHost } from '../ui/ConfirmDialog'
import { PendingWorkflowNotice } from '../../native/PendingWorkflowNotice'

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
      <a className="admin-skip-link" href="#admin-content">跳转到主要内容</a>
      <Sidebar open={open} onClose={() => setOpen(false)} collapsed={!expanded} onExpand={() => setExpanded(true)} onToggle={() => setExpanded(value => !value)} />
      <button
        type="button"
        className="admin-floating-menu"
        aria-label="打开导航"
        onClick={() => setOpen(true)}
      >
        <Menu size={20} />
      </button>
      <main className="admin-main" id="admin-content" tabIndex={-1}>
        <div ref={scrollRef} className="admin-scroll">
          <div className='admin-page'>
            <PendingWorkflowNotice />
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
