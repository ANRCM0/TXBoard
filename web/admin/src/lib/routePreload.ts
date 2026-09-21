const preloaders: Record<string, () => Promise<unknown>> = {
  '/system/audit-log': () => import('../pages/system/AuditLogPage'),
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
  if (loader) void loader()
}
