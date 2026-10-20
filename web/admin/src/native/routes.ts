export const availableAdminPaths: readonly string[] = [
  '/config/system', '/system/sessions', '/server/manage', '/server/machine',
  '/user/manage', '/finance/order', '/user/ticket', '/config/notice',
  '/config/knowledge', '/finance/coupon', '/finance/gift-card', '/finance/commission',
  '/finance/plan', '/server/group', '/server/route', '/server/access-audit',
  '/system/audit-log', '/system/modules', '/config/plugin', '/config/theme',
  '/config/payment', '/user/traffic-reset', '/system/queue', '/config/email/templates',
  '/config/system/features', '/config/system/safe', '/config/system/subscribe', '/config/system/invite',
  '/config/server', '/config/email', '/config/telegram', '/config/app',
]

export function workflowDestination(operation: string, input?: unknown): { path: string; label: string } | undefined {
  if (operation === 'identity.accounts.create') return { path: '/user/manage', label: '用户管理' }
  if (operation === 'identity.accounts.status.update') {
    const userId = input && typeof input === 'object' && 'user_id' in input && typeof input.user_id === 'string'
      ? input.user_id : ''
    return userId
      ? { path: '/user/' + userId, label: '用户详情' }
      : { path: '/user/manage', label: '用户管理' }
  }
  if (operation === 'subscription.lifecycle.reset') return { path: '/user/traffic-reset', label: '订阅流量重置' }
  if (operation.startsWith('subscription.lifecycle.')) return { path: '/user/manage', label: '用户管理' }
  if (operation.startsWith('network.nodes.')) return { path: '/server/manage', label: '节点管理' }
  if (operation.startsWith('network.machines.')) return { path: '/server/machine', label: '服务器管理' }
  if (operation.startsWith('settings.configuration.')) return { path: '/config/system', label: '系统配置' }
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
