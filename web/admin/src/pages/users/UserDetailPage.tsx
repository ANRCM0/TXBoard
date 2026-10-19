import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { RefreshCw } from 'lucide-react'
import { admin } from '../../native/session'
import { ManagementFeedback } from '../../native/Feedback'
import { ReadFields } from '../../native/ReadList'
import { unixDate, validId } from '../../native/readPresentation'
import { PageHeader } from '../../components/ui/PageHeader'
import './UserDetailPage.css'

export function UserDetailPage() {
  const { userId = '' } = useParams()
  const valid = validId(userId)
  const query = useQuery({ queryKey: ['native-admin', 'account-detail', userId], enabled: valid,
    queryFn: ({ signal }) => admin.read('identity.accounts.get', { user_id: userId }, signal) })
  const user = query.isError ? undefined : query.data?.data
  const subscription = user?.subscription
  return <div className="native-management-page">
    <PageHeader title="用户详情" description="账户身份与当前订阅状态。"
      action={<><Link className="button" to="/user/manage">返回列表</Link>
        <button className="button" disabled={!valid || query.isFetching} onClick={() => void query.refetch()}><RefreshCw size={15} />刷新</button></>} />
    {!valid ? <p role="alert">用户 ID 无效。</p> : <ManagementFeedback error={query.isError ? query.error : undefined}
      loading={query.isFetching} notFoundMessage="未找到该用户。" onRetry={() => void query.refetch()} />}
    {user ? <section className="card" aria-label="账户信息"><ReadFields fields={[
      ['邮箱', user.email], ['用户 ID', user.id], ['账户状态', user.banned ? '已封禁' : '正常'],
      ['角色', user.is_admin ? '管理员' : '用户'], ['注册时间', unixDate(user.created_at)],
      ['最近登录', unixDate(user.last_login_at)],
      ['订单', <Link to={'/finance/order?user_id=' + user.id}>查看该用户订单</Link>],
    ]} /></section> : null}
    {subscription ? <section className="card" aria-label="当前订阅">
      <h2>当前订阅</h2>
      {subscription.authorized ? <ReadFields fields={[
        ['状态', '可用'], ['订阅 ID', subscription.subscription_id], ['套餐 ID', subscription.plan_id],
        ['到期时间', subscription.expires_at === null ? '长期有效' : unixDate(subscription.expires_at)],
        ['配额', subscription.quota_bytes + ' 字节'], ['已用', subscription.consumed_bytes + ' 字节'],
        ['剩余', subscription.remaining_bytes + ' 字节'],
      ]} /> : <p>当前没有可用订阅。</p>}
    </section> : null}
  </div>
}
