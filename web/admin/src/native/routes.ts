export const availableAdminPaths: readonly string[] = [
  '/config/system', '/system/sessions', '/server/manage', '/server/machine',
  '/user/manage', '/finance/order', '/user/ticket', '/config/notice',
  '/config/knowledge', '/finance/coupon', '/finance/gift-card', '/finance/commission',
  '/finance/plan', '/server/group', '/server/route', '/server/access-audit',
]

export function workflowDestination(operation: string): { path: string; label: string } | undefined {
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
