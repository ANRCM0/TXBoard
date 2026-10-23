import { Settings, Server, Users, WalletCards, BookOpen, Boxes } from 'lucide-react'

// Host-owned navigation is shared by the header, resource breadcrumbs and mobile drawer.
export const adminNavigation = [
  { key: 'overview', title: '总览', items: [['/', '仪表盘']] },
  { key: 'network', title: '网络', items: [
    ['/server/manage', '节点管理'], ['/server/machine', '服务器管理'],
    ['/server/group', '权限组管理'], ['/server/route', '路由管理'], ['/server/access-audit', '访问审计'],
  ] },
  { key: 'users', title: '用户', items: [['/user/manage', '用户管理'], ['/user/traffic-reset', '流量重置']] },
  { key: 'finance', title: '财务', items: [
    ['/finance/order', '订单管理'], ['/finance/plan', '套餐管理'], ['/finance/coupon', '优惠券'],
    ['/finance/gift-card', '礼品卡'], ['/finance/commission', '佣金管理'],
  ] },
  { key: 'content', title: '内容与支持', items: [
    ['/user/ticket', '工单管理'], ['/config/notice', '公告管理'], ['/config/knowledge', '知识库管理'],
  ] },
  { key: 'settings', title: '设置', items: [
    ['/config/system', '系统配置'], ['/config/system/features', '功能入口'], ['/config/system/safe', '安全设置'],
    ['/config/system/subscribe', '订阅设置'], ['/config/system/subscribe-template', '订阅模板'],
    ['/config/system/invite', '邀请设置'], ['/config/server', '服务器配置'], ['/config/email', '邮件设置'],
    ['/config/email/templates', '邮件模板'], ['/config/telegram', 'Telegram'], ['/config/app', 'APP 设置'],
    ['/config/payment', '支付配置'], ['/config/appearance', '管理端外观'],
  ] },
  { key: 'system', title: '系统', items: [
    ['/system/modules', '模块中心'], ['/config/plugin', '插件管理'], ['/config/theme', '主题管理'],
    ['/system/queue', '队列监控'], ['/system/sessions', '登录会话'], ['/system/audit-log', '审计日志'],
  ] },
] as const

export function adminSection(pathname: string) {
  // Resolve the most specific setting path before its parent; details inherit their list.
  const matches = adminNavigation.flatMap(group => group.items.map(item => ({ group, item })))
    .filter(({ item: [path] }) => pathname === path || path !== '/' && pathname.startsWith(path + '/'))
    .sort((a, b) => b.item[0].length - a.item[0].length)
  const found = matches[0]
  if (found) return found
  const parent = pathname.startsWith('/server/node/') ? '/server/manage'
    : pathname.startsWith('/server/machine/') ? '/server/machine'
      : pathname.startsWith('/user/') ? '/user/manage' : undefined
  return parent ? adminSectionForPath(parent) : { group: adminNavigation[0], item: adminNavigation[0].items[0] }
}

function adminSectionForPath(path: string) {
  const group = adminNavigation.find(value => value.items.some(item => item[0] === path))!
  return { group, item: group.items.find(item => item[0] === path)! }
}

const drawerIcons = { network: Server, users: Users, finance: WalletCards, content: BookOpen, settings: Settings, system: Boxes }
export const coreNavigationGroups = adminNavigation.filter(group => group.key !== 'overview').map(group => ({
  ...group, icon: drawerIcons[group.key as keyof typeof drawerIcons],
  items: group.items.map(([path, label]) => [path, label, drawerIcons[group.key as keyof typeof drawerIcons]] as const),
}))