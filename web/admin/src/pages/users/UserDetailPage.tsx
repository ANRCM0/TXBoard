import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { RefreshCw, UserRound, CreditCard, ArrowUpRight, Ban, ShieldCheck, ReceiptText } from 'lucide-react'
import type { CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from '../../native/session'
import { ManagementFeedback } from '../../native/Feedback'
import { ReadFields } from '../../native/ReadList'
import { isoDate, minorAmount, orderStatus, unixDate, validId } from '../../native/readPresentation'
import { PageHeader } from '../../components/ui/PageHeader'
import { DetailSection } from '../../components/ui/DetailSection'
import { StatusBadge } from '../../components/ui/StatusBadge'
import { QuotaBar } from '../../components/ui/QuotaBar'
import { DataTable, type Column } from '../../components/ui/DataTable'
import { NetworkRecovery, useNetworkManagement } from '../../native/NetworkManagement'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import './UserDetailPage.css'
import '../../native/detail-reference.css'

type Order = CoreOutput<'commerce.admin-orders.list'>[number]
const orderColumns: Column<Order>[] = [
  { key: 'trade', header: '订单号', render: row => <Link className="table-link" to={'/finance/order?trade_no=' + encodeURIComponent(row.trade_no)}>{row.trade_no}</Link> },
  { key: 'plan', header: '套餐', render: row => row.plan?.name ?? '未记录' },
  { key: 'amount', header: '金额', render: row => <span className="reference-number">{minorAmount(row.amount_minor, row.currency)}</span> },
  { key: 'status', header: '状态', render: row => <StatusBadge tone={row.status === 3 ? 'success' : row.status === 0 || row.status === 1 ? 'warning' : 'neutral'}>{orderStatus(row.status)}</StatusBadge> },
  { key: 'created', header: '创建时间', render: row => <span className="reference-secondary">{isoDate(row.created_at)}</span> },
]

export function UserDetailPage() {
  const { userId = '' } = useParams()
  const valid = validId(userId)
  const query = useQuery({ queryKey: ['native-admin', 'account-detail', userId], enabled: valid,
    queryFn: ({ signal }) => admin.read('identity.accounts.get', { user_id: userId }, signal) })
  const management = useNetworkManagement('identity.accounts.', [query.error], () => query.refetch({ throwOnError: true }))
  const user = management.denied || query.isError ? undefined : query.data?.data
  const subscription = user?.subscription
  const locked = !user || query.isFetching || management.state.busy || !!management.state.pending
  const orders = useQuery({ queryKey: ['native-admin', 'user-orders', userId],
    enabled: valid && !!user && !management.denied,
    queryFn: ({ signal }) => admin.read('commerce.admin-orders.list', { page: 1, per_page: 5, user_id: userId }, signal) })
  const orderRows = Array.isArray(orders.data?.data) ? orders.data.data : []

  function confirmStatus() {
    if (!user || locked) return
    const banned = !user.banned
    requestConfirm({
      title: banned ? '封禁用户' : '解除封禁',
      message: banned ? `封禁 ${user.email} 后，该账号将无法继续使用站点服务` : `解除 ${user.email} 的封禁状态？`,
      confirmLabel: banned ? '封禁用户' : '解除封禁',
      danger: banned,
      action: () => {
        void management.perform(() => workflowActions.run('identity.accounts.status.update', {
          user_id: user.id,
          expected_banned: user.banned,
          banned,
        }))
      },
    })
  }

  return <div className="native-management-page reference-detail-page reference-user-detail">
    <PageHeader title="用户详情" description="账户身份、订阅状态与访问控制"
      action={<><Link className="button" to="/user/manage">返回列表</Link>
        <button className="button" disabled={!valid || query.isFetching} onClick={() => void management.refresh()}><RefreshCw size={15} aria-hidden="true" />刷新</button></>} />
    <NetworkRecovery management={management} blocked={!user || query.isFetching} />
    {!valid ? <p role="alert">用户 ID 无效。</p> : <ManagementFeedback error={query.isError ? query.error : undefined}
      loading={query.isFetching} notFoundMessage="未找到该用户。" onRetry={() => void management.refresh()} />}
    {user ? <DetailSection label="账户信息" title="账户资料" icon={<UserRound size={17} />} className="reference-detail-card"
      actions={<button className={'button ' + (user.banned ? '' : 'danger')} disabled={locked} onClick={confirmStatus}>
        {user.banned ? <ShieldCheck size={15} aria-hidden="true" /> : <Ban size={15} aria-hidden="true" />}
        {user.banned ? '解除封禁' : '封禁用户'}
      </button>}>
      <div className="reference-profile-header">
        <span className="reference-profile-avatar" aria-hidden="true"><UserRound size={25} /></span>
        <div className="reference-profile-identity"><h3>{user.email}</h3><p>用户 #{user.id} · {user.is_admin ? '管理员' : '用户'}</p></div>
        <StatusBadge tone={user.banned ? 'danger' : 'success'}>{user.banned ? '已封禁' : '正常'}</StatusBadge>
      </div>
      <ReadFields fields={[
        ['用户 ID', user.id],
        ['角色', user.is_admin ? '管理员' : '用户'], ['注册时间', unixDate(user.created_at)],
        ['最近登录', user.last_login_at === null ? '尚未登录' : unixDate(user.last_login_at)],
        ['订单', <Link className="reference-detail-link" to={'/finance/order?user_id=' + user.id}>查看该用户订单<ArrowUpRight size={14} /></Link>],
      ]} />
    </DetailSection> : null}
    {subscription ? <DetailSection label="当前订阅" title="当前订阅" icon={<CreditCard size={17} />} className="reference-detail-card"
      actions={<StatusBadge tone={subscription.authorized ? 'success' : 'neutral'}>{subscription.authorized ? '可用' : '暂无可用订阅'}</StatusBadge>}>
      {subscription.authorized ? <>
        <div className="reference-detail-section-title"><CreditCard size={16} /><h3>流量配额</h3></div>
        <QuotaBar used={subscription.consumed_bytes} total={subscription.quota_bytes} label="订阅流量" />
        <ReadFields fields={[
          ['状态', '可用'], ['订阅 ID', subscription.subscription_id], ['套餐 ID', subscription.plan_id],
          ['配额（字节）', subscription.quota_bytes], ['已用（字节）', subscription.consumed_bytes],
          ['剩余（字节）', subscription.remaining_bytes],
          ['到期时间', subscription.expires_at === null ? '长期有效' : unixDate(subscription.expires_at)],
        ]} />
      </> : <p className="reference-detail-empty">当前没有可用订阅。</p>}
    </DetailSection> : null}
    {user ? <DetailSection label="最近订单" title="最近订单" icon={<ReceiptText size={17} />} className="reference-detail-card"
      actions={<Link className="reference-detail-link" to={'/finance/order?user_id=' + user.id}>全部订单<ArrowUpRight size={14} /></Link>}>
      <ManagementFeedback error={orders.isError ? orders.error : undefined} loading={orders.isFetching} onRetry={() => void orders.refetch()} />
      {orders.data && !orders.isError
        ? <DataTable rows={orderRows} columns={orderColumns} rowKey={row => row.id} label="最近订单" empty="该用户暂无订单" />
        : null}
    </DetailSection> : null}
  </div>
}
