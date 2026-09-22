import { Suspense, useEffect, useRef, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'

export function AdminLayout() {
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()
  const scrollRef = useRef<HTMLDivElement>(null)

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
    <div className="admin-shell">
      <Sidebar open={open} onClose={() => setOpen(false)} />
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
    </div>
  )
}
