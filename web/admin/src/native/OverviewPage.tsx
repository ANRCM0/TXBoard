import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Server, Network, Settings, ShieldCheck, Users, ReceiptText, MessageCircle, Bell, BookOpen, Tag, Gift, WalletCards, CreditCard, Boxes, Route } from 'lucide-react'
import { admin } from './session'
import { ManagementFeedback } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'

const entries = [
  { href: '/user/manage', title: '用户管理', description: '查询账户与当前订阅', icon: Users },
  { href: '/finance/order', title: '订单管理', description: '查询原生购买订单与详情', icon: ReceiptText },
  { href: '/finance/plan', title: '套餐管理', description: '查看完整发布历史、发布新修订与调整可用性', icon: CreditCard },
  { href: '/user/ticket', title: '工单管理', description: '认领工单、回复并处理私有附件', icon: MessageCircle },
  { href: '/config/notice', title: '公告管理', description: '编辑公告、发布时间与受众', icon: Bell },
  { href: '/config/knowledge', title: '知识库管理', description: '管理知识内容、分类与排序', icon: BookOpen },
  { href: '/finance/coupon', title: '优惠券', description: '创建优惠策略或停用后续使用', icon: Tag },
  { href: '/finance/gift-card', title: '礼品卡', description: '查看批次与逐卡状态、撤销未兑换卡片', icon: Gift },
  { href: '/finance/commission', title: '邀请与佣金', description: '查看邀请奖励、审核钱包转入申请', icon: WalletCards },
  { href: '/server/manage', title: '节点管理', description: '查看节点地址、启用状态与用量', icon: Network },
  { href: '/server/machine', title: '服务器管理', description: '查看机器、镜像通道与最近心跳', icon: Server },
  { href: '/server/group', title: '权限组管理', description: '维护用户、套餐和节点使用的分组', icon: Boxes },
  { href: '/server/route', title: '路由管理', description: '维护路由与完整排序、只读模拟节点匹配', icon: Route },
  { href: '/server/access-audit', title: '访问审计', description: '维护审计规则、筛选节点访问事件', icon: ShieldCheck },
  { href: '/config/system', title: '系统配置', description: '查看站点公开信息与注册状态', icon: Settings },
  { href: '/system/sessions', title: '登录会话', description: '查看并撤销管理员账户的会话', icon: ShieldCheck },
]
export function OverviewPage() {
  const identity = useQuery({ queryKey: ['native-admin', 'identity'],
    queryFn: ({ signal }) => admin.read('identity.admin.get', {}, signal) })
  return <div className="native-management-page">
    <PageHeader title="管理中心" description="管理站点资源与账户会话。" />
    <ManagementFeedback error={identity.isError ? identity.error : undefined} loading={identity.isFetching}
      onRetry={() => void identity.refetch()} />
    {!identity.isError && identity.data ? <p className="native-identity">当前管理员：{identity.data.data.email} · #{identity.data.data.id}</p> : null}
    <div className="native-overview-grid">
      {entries.map(({ href, title, description, icon: Icon }) => <Link className="card native-overview-card" to={href} key={href}>
        <Icon size={24} /><strong>{title}</strong><span>{description}</span>
      </Link>)}
    </div>
  </div>
}
