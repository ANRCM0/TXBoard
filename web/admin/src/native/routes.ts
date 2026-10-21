export const availableAdminPaths: readonly string[] = [
  '/config/system', '/system/sessions', '/server/manage', '/server/machine',
  '/user/manage', '/finance/order', '/user/ticket', '/config/notice',
  '/config/knowledge', '/finance/coupon', '/finance/gift-card', '/finance/commission',
  '/finance/plan', '/server/group', '/server/route', '/server/access-audit',
  '/system/audit-log', '/system/modules', '/config/plugin', '/config/theme',
  '/config/payment', '/user/traffic-reset', '/system/queue', '/config/email/templates',
  '/config/system/features', '/config/system/safe', '/config/system/subscribe', '/config/system/invite',
  '/config/server', '/config/email', '/config/telegram', '/config/app',
  '/config/system/subscribe-template',
  '/config/appearance',
]

export function workflowDestination(operation: string, input?: unknown): { path: string; label: string } | undefined {
  if (operation.startsWith('billing.admin-customer-policy.') || operation.startsWith('usage.admin-corrections.')) {
    const userId = input && typeof input === 'object' && 'user_id' in input && typeof input.user_id === 'string' ? input.user_id : ''
    return { path: userId ? '/user/' + userId : '/user/manage', label: operation.startsWith('usage.') ? '用户流量修正' : '用户购买与佣金政策' }
  }
  if (operation.startsWith('billing.admin-account-adjustments.')) {
    const userId = input && typeof input === 'object' && 'user_id' in input && typeof input.user_id === 'string' ? input.user_id : ''
    return { path: userId ? '/user/' + userId : '/user/manage', label: '用户资金调整' }
  }
  if (operation === 'identity.accounts.create' || operation === 'identity.accounts.batch-create') return { path: '/user/manage', label: '用户管理' }
  if (operation === 'identity.accounts.status.update' || operation === 'identity.accounts.profile.update') {
    const userId = input && typeof input === 'object' && 'user_id' in input && typeof input.user_id === 'string'
      ? input.user_id : ''
    return userId
      ? { path: '/user/' + userId, label: '用户详情' }
      : { path: '/user/manage', label: '用户管理' }
  }
  if (operation === 'subscription.lifecycle.reset') return { path: '/user/traffic-reset', label: '订阅流量重置' }
  if (operation.startsWith('subscription.admin-templates.')) return { path: '/config/system/subscribe-template', label: '订阅模板' }
  if (operation.startsWith('platform.theme-packages.')) return { path: '/config/theme', label: '主题管理' }
  if (operation.startsWith('platform.module-')) return { path: '/system/modules', label: '模块中心' }
  if (operation.startsWith('subscription.admin.amendment.')) {
    const userId = input && typeof input === 'object' && 'user_id' in input && typeof input.user_id === 'string' ? input.user_id : ''
    return { path: userId ? '/user/' + userId : '/user/manage', label: '用户订阅条款' }
  }
  if (operation.startsWith('subscription.lifecycle.')) return { path: '/user/manage', label: '用户管理' }
  if (operation.startsWith('network.nodes.')) return { path: '/server/manage', label: '节点管理' }
  if (operation.startsWith('network.machines.')) return { path: '/server/machine', label: '服务器管理' }
  if (operation.startsWith('settings.configuration.')) {
    const values = input && typeof input === 'object' && 'values' in input && input.values && typeof input.values === 'object'
      ? Object.keys(input.values) : []
    const appearance = ['admin_sidebar_style', 'admin_header_style', 'admin_color_scheme', 'admin_login_background_url']
    return values.length && values.every(key => appearance.includes(key))
      ? { path: '/config/appearance', label: '后台外观' }
      : { path: '/config/system', label: '系统配置' }
  }
  if (operation.startsWith('settings.telegram.')) return { path: '/config/telegram', label: 'Telegram' }
  if (operation.startsWith('notifications.mail-templates.')) return { path: '/config/email/templates', label: '邮件模板' }
  if (operation.startsWith('billing.admin-payment-methods.')) return { path: '/config/payment', label: '支付配置' }
  if (operation.startsWith('catalog.')) return { path: '/finance/plan', label: '套餐管理' }
  if (operation.startsWith('network.groups.')) return { path: '/server/group', label: '权限组管理' }
  if (operation.startsWith('network.routes.')) return { path: '/server/route', label: '路由管理' }
  if (operation.startsWith('network.access-audit.')) return { path: '/server/access-audit', label: '访问审计' }
  if (operation.startsWith('commerce.admin-orders.')) return { path: '/finance/order', label: '订单管理' }
  if (operation.startsWith('support.admin-tickets.')) return { path: '/user/ticket', label: '工单管理' }
  if (operation.startsWith('content.notices.')) return { path: '/config/notice', label: '公告管理' }
  if (operation.startsWith('content.knowledge.')) return { path: '/config/knowledge', label: '知识库管理' }
  if (operation.startsWith('billing.coupons.')) return { path: '/finance/coupon', label: '优惠券管理' }
  if (operation.startsWith('billing.admin-gift-cards.')) return { path: '/finance/gift-card', label: '礼品卡管理' }
  if (operation.startsWith('billing.admin-commissions.')) return { path: '/finance/commission', label: '邀请与佣金' }
}
