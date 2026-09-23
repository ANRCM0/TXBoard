const preloaders: Record<string, () => Promise<unknown>> = {
  '/system/audit-log': () => import('../pages/system/AuditLogPage'),
  '/system/agent-ops': () => import('../pages/system/AgentOpsPage'),
  '/system/modules': () => import('../pages/system/ModuleCenterPage'),
  '/config/system': () => import('../pages/config/SystemSettingsPage'),
  '/config/system/safe': () => import('../pages/config/SafeSettingsPage'),
  '/config/system/subscribe': () => import('../pages/config/SubscribeSettingsPage'),
  '/config/system/invite': () => import('../pages/config/InviteSettingsPage'),
  '/config/frontend': () => import('../pages/config/FrontendSettingsPage'),
  '/config/server': () => import('../pages/config/ServerSettingsPage'),
  '/config/email': () => import('../pages/config/EmailSettingsPage'),
  '/config/telegram': () => import('../pages/config/TelegramSettingsPage'),
  '/config/APP': () => import('../pages/config/AppSettingsPage'),
  '/config/plugin': () => import('../pages/plugins/PluginsPage'),
  '/config/subscribe-template': () => import('../pages/config/SubscribeTemplatePage'),
  '/config/theme': () => import('../pages/config/ThemeSettingsPage'),
  '/config/notice': () => import('../pages/config/NoticeSettingsPage'),
  '/config/payment': () => import('../pages/config/PaymentSettingsPage'),
  '/config/knowledge': () => import('../pages/config/KnowledgeSettingsPage'),
  '/server/machine': () => import('../pages/server/MachinesPage'),
  '/server/manage': () => import('../pages/server/NodesPage'),
  '/server/group': () => import('../pages/server/GroupsPage'),
  '/server/route': () => import('../pages/server/RoutesPage'),
  '/finance/plan': () => import('../pages/finance/PlansPage'),
  '/finance/order': () => import('../pages/finance/OrdersPage'),
  '/finance/coupon': () => import('../pages/finance/CouponPage'),
  '/finance/gift-card': () => import('../pages/finance/GiftCardPage'),
  '/user/manage': () => import('../pages/users/UsersPage'),
  '/user/traffic-reset': () => import('../pages/users/TrafficResetPage'),
  '/user/ticket': () => import('../pages/users/TicketsPage'),
}

export function preloadAdminRoute(path: string) {
  const loader = path.startsWith('/plugins/')
    ? () => import('../pages/plugins/PluginRoutePage')
    : preloaders[path]

  return loader ? loader() : Promise.resolve()
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
