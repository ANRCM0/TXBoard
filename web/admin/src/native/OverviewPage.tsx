import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Server, Network, Settings, ShieldCheck, Users, ReceiptText } from 'lucide-react'
import { admin } from './session'
import { ManagementFeedback } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'

const entries = [
  { href: '/user/manage', title: '用户管理', description: '查询账户与当前订阅', icon: Users },
  { href: '/finance/order', title: '订单管理', description: '查询原生购买订单与详情', icon: ReceiptText },
  { href: '/server/manage', title: '节点管理', description: '查看节点地址、启用状态与用量', icon: Network },
  { href: '/server/machine', title: '服务器管理', description: '查看机器、镜像通道与最近心跳', icon: Server },
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
