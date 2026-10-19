export const availableAdminPaths: readonly string[] = [
  '/config/system', '/system/sessions', '/server/manage', '/server/machine',
  '/user/manage', '/finance/order', '/user/ticket', '/config/notice',
  '/config/knowledge', '/finance/coupon', '/finance/gift-card', '/finance/commission',
]

export function workflowDestination(operation: string): { path: string; label: string } | undefined {
  if (operation.startsWith('commerce.admin-orders.')) return { path: '/finance/order', label: '订单管理' }
  if (operation.startsWith('support.admin-tickets.')) return { path: '/user/ticket', label: '工单管理' }
  if (operation.startsWith('content.notices.')) return { path: '/config/notice', label: '公告管理' }
  if (operation.startsWith('content.knowledge.')) return { path: '/config/knowledge', label: '知识库管理' }
  if (operation.startsWith('billing.coupons.')) return { path: '/finance/coupon', label: '优惠券管理' }
  if (operation.startsWith('billing.admin-gift-cards.')) return { path: '/finance/gift-card', label: '礼品卡管理' }
  if (operation.startsWith('billing.admin-commissions.')) return { path: '/finance/commission', label: '邀请与佣金' }
}
