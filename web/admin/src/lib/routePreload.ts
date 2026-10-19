const preloaders: Record<string, () => Promise<unknown>> = {
  '/': () => import('../native/OverviewPage'),
  '/config/system': () => import('../native/SettingsPage'),
  '/config/system/features': () => import('../native/SettingsPage'),
  '/config/system/safe': () => import('../native/SettingsPage'),
  '/config/system/subscribe': () => import('../native/SettingsPage'),
  '/config/system/invite': () => import('../native/CommissionsPage'),
  '/config/server': () => import('../native/SettingsPage'),
  '/config/email': () => import('../native/SettingsPage'),
  '/config/telegram': () => import('../native/SettingsPage'),
  '/config/app': () => import('../native/SettingsPage'),
  '/config/payment': () => import('../native/PaymentMethodsPage'),
  '/config/plugin': () => import('../native/ModulesPage'),
  '/config/theme': () => import('../native/ModulesPage'),
  '/system/modules': () => import('../native/ModulesPage'),
  '/system/audit-log': () => import('../native/AuditLogsPage'),
  '/system/queue': () => import('../native/QueuePage'),
  '/config/email/templates': () => import('../native/MailTemplatesPage'),
  '/user/traffic-reset': () => import('../native/TrafficResetPage'),
  '/server/manage': () => import('../native/NodesPage'),
  '/server/machine': () => import('../native/MachinesPage'),
  '/system/sessions': () => import('../native/SessionsPage'),
  '/user/manage': () => import('../pages/users/UsersPage'),
  '/finance/order': () => import('../pages/finance/OrdersPage'),
  '/finance/plan': () => import('../native/CatalogPage'),
  '/server/group': () => import('../native/GroupsPage'),
  '/server/route': () => import('../native/RoutesPage'),
  '/server/access-audit': () => import('../native/AccessAuditPage'),
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
