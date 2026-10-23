import { useEffect, useRef } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { t, useLocale } from '../../lib/i18n'
import { adminNavigation, adminSection } from '../../navigation/core'
import { preloadAdminRoute } from '../../lib/routePreload'

export function HeaderNavigation({ secondary = false }: { secondary?: boolean }) {
  useLocale()
  const { pathname } = useLocation()
  const section = adminSection(pathname)
  const ref = useRef<HTMLElement>(null)
  useEffect(() => {
    const nav = ref.current, active = nav?.querySelector<HTMLElement>('[aria-current]')
    if (nav && active) nav.scrollLeft = Math.max(0, active.offsetLeft - nav.offsetLeft - 16)
  }, [pathname])
  return <nav ref={ref} className={secondary ? 'admin-section-nav' : 'admin-primary-nav'} aria-label={t(secondary ? '页面导航' : '业务导航')}>
    {secondary ? section.group.items.map(([path, label]) => <Link key={path} to={path}
      aria-current={section.item[0] === path ? 'page' : undefined}
      onPointerEnter={() => void preloadAdminRoute(path)} onFocus={() => void preloadAdminRoute(path)}>{t(label)}</Link>)
      : adminNavigation.map(group => <Link key={group.key} to={group.items[0][0]}
        aria-current={section.group.key === group.key ? 'true' : undefined}
        onPointerEnter={() => void preloadAdminRoute(group.items[0][0])} onFocus={() => void preloadAdminRoute(group.items[0][0])}>{t(group.title)}</Link>)}
  </nav>
}
