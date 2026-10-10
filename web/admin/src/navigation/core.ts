import {
  Bell,
  Bot,
  BookOpen,
  Boxes,
  CreditCard,
  FileText,
  Gift,
  MessageCircle,
  Network,
  ShieldCheck,
  Package,
  Plug,
  RefreshCcw,
  Route,
  Server,
  Settings,
  Tag,
  Users,
  WalletCards,
} from 'lucide-react'

/**
 * Host-owned navigation for TXBoard Core and its first-party management
 * surfaces. Module-provided navigation remains separate and continues to flow
 * through Module Registry -> Admin Navigation Registry.
 */
export const coreNavigationGroups = [
  {
    key: 'system',
    title: '系统管理',
    icon: Settings,
    items: [
      ['/config/system', '系统配置', Settings],
      ['/system/audit-log', '审计日志', FileText],
    ],
  },
  {
    key: 'extensions',
    title: '扩展中心',
    icon: Boxes,
    items: [
      ['/system/modules', '模块中心', Boxes],
      ['/config/plugin', '插件管理', Plug],
      ['/config/theme', '主题管理', Package],
    ],
  },
  {
    key: 'operations',
    title: '智能运维',
    icon: Bot,
    items: [
      ['/system/agent-ops', 'Agent 运维', Bot],
    ],
  },
  {
    key: 'node',
    title: '节点管理',
    icon: Server,
    items: [
      ['/server/machine', '服务器管理', Server],
      ['/server/manage', '节点管理', Network],
      ['/server/group', '权限组管理', Boxes],
      ['/server/route', '路由管理', Route],
      ['/server/access-audit', '访问审计', ShieldCheck],
    ],
  },
  {
    key: 'commerce',
    title: '商业管理',
    icon: WalletCards,
    items: [
      ['/finance/plan', '套餐管理', CreditCard],
      ['/finance/order', '订单管理', FileText],
      ['/config/payment', '支付配置', WalletCards],
      ['/finance/coupon', '优惠券', Tag],
      ['/finance/gift-card', '礼品卡', Gift],
    ],
  },
  {
    key: 'user',
    title: '用户管理',
    icon: Users,
    items: [
      ['/user/manage', '用户管理', Users],
      ['/user/traffic-reset', '流量重置', RefreshCcw],
      ['/user/ticket', '工单管理', MessageCircle],
    ],
  },
  {
    key: 'content',
    title: '内容管理',
    icon: BookOpen,
    items: [
      ['/config/notice', '公告管理', Bell],
      ['/config/knowledge', '知识库管理', BookOpen],
    ],
  },
] as const
