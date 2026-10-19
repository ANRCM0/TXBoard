import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { RefreshCw, UserRound, CreditCard, ArrowUpRight } from 'lucide-react'
import { admin } from '../../native/session'
import { ManagementFeedback } from '../../native/Feedback'
import { ReadFields } from '../../native/ReadList'
import { unixDate, validId } from '../../native/readPresentation'
import { PageHeader } from '../../components/ui/PageHeader'
import './UserDetailPage.css'
import '../../native/detail-reference.css'

export function UserDetailPage() {
  const { userId = '' } = useParams()
  const valid = validId(userId)
  const query = useQuery({ queryKey: ['native-admin', 'account-detail', userId], enabled: valid,
    queryFn: ({ signal }) => admin.read('identity.accounts.get', { user_id: userId }, signal) })
  const user = query.isError ? undefined : query.data?.data
  const subscription = user?.subscription
  return <div className="native-management-page reference-detail-page reference-user-detail">
    <PageHeader title="用户详情" description="账户身份与当前订阅状态。"
      action={<><Link className="button" to="/user/manage">返回列表</Link>
        <button className="button" disabled={!valid || query.isFetching} onClick={() => void query.refetch()}><RefreshCw size={15} />刷新</button></>} />
    {!valid ? <p role="alert">用户 ID 无效。</p> : <ManagementFeedback error={query.isError ? query.error : undefined}
      loading={query.isFetching} notFoundMessage="未找到该用户。" onRetry={() => void query.refetch()} />}
    {user ? <section className="card reference-detail-card" aria-label="账户信息"><div className="reference-profile-header"><span className="reference-profile-avatar" aria-hidden="true"><UserRound size={25} /></span><div className="reference-profile-identity"><h2>{user.email}</h2><p>用户 #{user.id} · {user.is_admin ? '管理员' : '用户'}</p></div><span className={'reference-status ' + (user.banned ? 'is-danger' : 'is-success')}>{user.banned ? '已封禁' : '正常'}</span></div><div className="reference-detail-section-title"><UserRound size={16} /><h3>账户资料</h3></div><ReadFields fields={[
      ['用户 ID', user.id],
      ['角色', user.is_admin ? '管理员' : '用户'], ['注册时间', unixDate(user.created_at)],
      ['最近登录', user.last_login_at === null ? '尚未登录' : unixDate(user.last_login_at)],
      ['订单', <Link className="reference-detail-link" to={'/finance/order?user_id=' + user.id}>查看该用户订单<ArrowUpRight size={14} /></Link>],
    ]} /></section> : null}
    {subscription ? <section className="card reference-detail-card" aria-label="当前订阅">
      <div className="reference-detail-section-title"><CreditCard size={17} /><h2>当前订阅</h2><span className={'reference-status ' + (subscription.authorized ? 'is-success' : '')}>{subscription.authorized ? '可用' : '暂无可用订阅'}</span></div>
      {subscription.authorized ? <><div className="reference-detail-metrics"><div><span>配额</span><strong>{subscription.quota_bytes}<small>字节</small></strong></div><div><span>已用</span><strong>{subscription.consumed_bytes}<small>字节</small></strong></div><div><span>剩余</span><strong>{subscription.remaining_bytes}<small>字节</small></strong></div></div><ReadFields fields={[
        ['状态', '可用'], ['订阅 ID', subscription.subscription_id], ['套餐 ID', subscription.plan_id],
        ['到期时间', subscription.expires_at === null ? '长期有效' : unixDate(subscription.expires_at)],
      ]} /></> : <p className="reference-detail-empty">当前没有可用订阅。</p>}
    </section> : null}
  </div>
}
