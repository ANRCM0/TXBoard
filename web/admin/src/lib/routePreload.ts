const preloaders: Record<string, () => Promise<unknown>> = {
  '/': () => import('../native/OverviewPage'),
  '/config/system': () => import('../native/SiteSettingsPage'),
  '/server/manage': () => import('../native/NodesPage'),
  '/server/machine': () => import('../native/MachinesPage'),
  '/system/sessions': () => import('../native/SessionsPage'),
  '/user/manage': () => import('../pages/users/UsersPage'),
  '/finance/order': () => import('../pages/finance/OrdersPage'),
  '/user/ticket': () => import('../native/TicketsPage'),
  '/config/notice': () => import('../native/ContentPage'),
  '/config/knowledge': () => import('../native/ContentPage'),
  '/finance/coupon': () => import('../native/CouponsPage'),
  '/finance/gift-card': () => import('../native/GiftCardsPage'),
  '/finance/commission': () => import('../native/CommissionsPage'),
}

export function preloadAdminRoute(path: string) {
  return preloaders[path]?.() ?? Promise.resolve()
}

type IdleWindow = Window & typeof globalThis & {
  requestIdleCallback?: (
    callback: () => void,
    options?: { timeout: number },
  ) => number
  cancelIdleCallback?: (id: number) => void
}

/**
 * Warm host route chunks after the first paint so later sidebar navigation
 * usually resolves from the browser module cache instead of suspending on a
 * network fetch. Work is serialized to avoid a burst of chunk requests.
 */
export function scheduleAdminRouteWarmup(paths: readonly string[]) {
  if (typeof window === 'undefined') return () => {}

  const queue = [...new Set(paths.filter(path => Boolean(preloaders[path])))]
  let cancelled = false
  let timer: number | undefined

  const loadNext = () => {
    if (cancelled || !queue.length) return
    const next = queue.shift()!
    void preloadAdminRoute(next).finally(() => {
      if (cancelled || !queue.length) return
      timer = window.setTimeout(loadNext, 24)
    })
  }

  const browser = window as IdleWindow
  let idleId: number | undefined
  if (browser.requestIdleCallback) {
    idleId = browser.requestIdleCallback(loadNext, { timeout: 1200 })
  } else {
    timer = window.setTimeout(loadNext, 500)
  }

  return () => {
    cancelled = true
    if (timer !== undefined) window.clearTimeout(timer)
    if (idleId !== undefined && browser.cancelIdleCallback) {
      browser.cancelIdleCallback(idleId)
    }
  }
}
