import { Suspense, useState } from 'react'
import { Outlet } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'

export function AdminLayout() {
  const [open, setOpen] = useState(false)

  return (
    <div className="admin-shell">
      <Sidebar open={open} onClose={() => setOpen(false)} />
      <main className="admin-main">
        <TopBar onOpenMenu={() => setOpen(true)} />
        <div className="admin-scroll">
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
