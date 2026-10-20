import { useQuery } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { RefreshCw, UserRound, CreditCard, ArrowUpRight, Ban, ShieldCheck, ReceiptText, Pause, Play, Clock } from 'lucide-react'
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
import { Modal } from '../../components/ui/Modal'
import { Field, Input } from '../../components/ui/Field'
import { NetworkRecovery, useNetworkManagement } from '../../native/NetworkManagement'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import './UserDetailPage.css'
import '../../native/detail-reference.css'

type Order = CoreOutput<'commerce.admin-orders.list'>[number]
type Lifecycle = NonNullable<CoreOutput<'subscription.admin.get'>['lifecycle']>
const lifecycleLabels: Record<string, string> = {
  active: '生效中', suspended: '已暂停', expired: '已到期', revoked: '已撤销', pending: '待生效',
}
const lifecycleTones: Record<string, 'success' | 'warning' | 'danger' | 'neutral'> = {
  active: 'success', suspended: 'warning', expired: 'danger', revoked: 'danger', pending: 'neutral',
}
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
  const lifecycle = useQuery({ queryKey: ['native-admin', 'user-lifecycle', userId],
    enabled: valid && !!user && !management.denied,
    queryFn: ({ signal }) => admin.read('subscription.admin.get', { user_id: userId }, signal) })
  const subscriptionManagement = useNetworkManagement('subscription.lifecycle.', [lifecycle.error], async () => {
    await Promise.all([query.refetch({ throwOnError: true }), lifecycle.refetch({ throwOnError: true })])
  })
  const lifecycleLoaded = !!lifecycle.data && !lifecycle.isError
  const lifecycleData = lifecycleLoaded && !subscriptionManagement.denied
    ? lifecycle.data!.data.lifecycle ?? undefined : undefined
  const lifecycleLocked = !lifecycleData || lifecycle.isFetching || lifecycle.isError
    || subscriptionManagement.state.busy || !!subscriptionManagement.state.pending || locked
  const orderRows = Array.isArray(orders.data?.data) ? orders.data.data : []
  const [suspendOpen, setSuspendOpen] = useState(false)
  const [suspendReason, setSuspendReason] = useState(''), [suspendError, setSuspendError] = useState('')
  async function refreshPage() {
    if (await management.refresh()) await subscriptionManagement.refresh()
  }

  function openSuspend() {
    if (!lifecycleData || lifecycleLocked) return
    setSuspendReason(''); setSuspendError(''); setSuspendOpen(true)
  }
  async function submitSuspend(event: FormEvent) {
    event.preventDefault()
    if (!lifecycleData || lifecycleLocked) return
    const reason = suspendReason.trim()
    if (reason && !/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/.test(reason)) {
      setSuspendError('原因仅支持字母、数字及 _ . : -，最多 64 字符')
      return
    }
    setSuspendError('')
    let succeeded = false
    await subscriptionManagement.perform(async () => {
      await workflowActions.run('subscription.lifecycle.suspend', {
        subscription_id: lifecycleData.subscription_id,
        expected_version: lifecycleData.subscription_version,
        ...(reason ? { reason } : {}),
      })
      succeeded = true
    }, () => { setSuspendOpen(false); setSuspendReason('') })
    if (!succeeded) setSuspendError(subscriptionManagement.error || '暂停失败，请重试')
  }
  const lifecycleFields: Array<[string, string]> = lifecycleData ? [
    ['订阅 ID', lifecycleData.subscription_id],
    ['状态', lifecycleLabels[lifecycleData.status] ?? lifecycleData.status],
    ['版本', lifecycleData.subscription_version],
    ['套餐 ID', lifecycleData.plan_id ?? '未记录'],
    ['条款 ID', lifecycleData.terms_id ?? '未记录'],
    ['周期 ID', lifecycleData.usage_period_id ?? '未记录'],
    ['周期版本', lifecycleData.period_version ?? '未记录'],
    ['到期时间', lifecycleData.expires_at === null ? '长期有效' : unixDate(lifecycleData.expires_at)],
    ['下次重置', lifecycleData.next_reset_at === null ? '未设置' : unixDate(lifecycleData.next_reset_at)],
    ['结束时间', lifecycleData.ended_at === null ? '未结束' : unixDate(lifecycleData.ended_at)],
  ] : []
  if (lifecycleData?.reason) lifecycleFields.push(['暂停原因', lifecycleData.reason])
  function confirmLifecycle(kind: 'resume' | 'revoke' | 'expire') {
    if (!lifecycleData || lifecycleLocked) return
    const label = kind === 'resume' ? '恢复订阅' : kind === 'revoke' ? '撤销订阅' : '标记到期'
    const message = kind === 'resume'
      ? `恢复 ${user?.email ?? '该用户'} 的订阅？`
      : kind === 'revoke'
        ? `撤销订阅后立即失效且不可恢复，确认撤销 ${user?.email ?? '该用户'} 的订阅？`
        : `标记到期会结束当前订阅，确认处理 ${user?.email ?? '该用户'} 的订阅？`
    requestConfirm({
      title: label, message, confirmLabel: label, danger: kind !== 'resume',
      action: () => {
        void subscriptionManagement.perform(async () => {
          const input = { subscription_id: lifecycleData.subscription_id, expected_version: lifecycleData.subscription_version }
          if (kind === 'resume') await workflowActions.run('subscription.lifecycle.resume', input)
          else if (kind === 'revoke') await workflowActions.run('subscription.lifecycle.revoke', input)
          else await workflowActions.run('subscription.lifecycle.expire', input)
        })
      },
    })
  }

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
        <button className="button" disabled={!valid || query.isFetching} onClick={() => void refreshPage()}><RefreshCw size={15} aria-hidden="true" />刷新</button></>} />
    {management.pending || management.state.error
      ? <NetworkRecovery management={management} blocked={!user || query.isFetching} /> : null}
    {subscriptionManagement.pending || subscriptionManagement.state.error
      ? <NetworkRecovery management={subscriptionManagement} blocked={!lifecycleData || lifecycle.isFetching || query.isFetching} /> : null}
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
    {user ? <DetailSection label="订阅生命周期" title="订阅生命周期" icon={<ShieldCheck size={17} />} className="reference-detail-card"
      actions={lifecycleData ? <StatusBadge tone={lifecycleTones[lifecycleData.status] ?? 'neutral'}>{lifecycleLabels[lifecycleData.status] ?? lifecycleData.status}</StatusBadge> : null}>
      {subscriptionManagement.denied
        ? <p role="alert">无法确认订阅生命周期权限，已隐藏生命周期数据。</p>
        : <>
          <ManagementFeedback error={lifecycle.isError ? lifecycle.error : undefined} loading={lifecycle.isFetching} onRetry={() => void subscriptionManagement.refresh()} />
          {lifecycleData ? <>
            <ReadFields fields={lifecycleFields} />
            <div className="native-list-toolbar user-lifecycle-actions">
              {lifecycleData.status === 'active' ? <button type="button" className="button" disabled={lifecycleLocked} onClick={openSuspend}><Pause size={15} aria-hidden="true" />暂停订阅</button> : null}
              {lifecycleData.status === 'suspended' ? <button type="button" className="button" disabled={lifecycleLocked} onClick={() => confirmLifecycle('resume')}><Play size={15} aria-hidden="true" />恢复订阅</button> : null}
              {['active', 'suspended'].includes(lifecycleData.status)
                ? <button type="button" className="button danger" disabled={lifecycleLocked} onClick={() => confirmLifecycle('revoke')}><Ban size={15} aria-hidden="true" />撤销订阅</button> : null}
              {['active', 'suspended'].includes(lifecycleData.status) && lifecycleData.expires_at !== null
                && lifecycleData.expires_at <= Math.floor(Date.now() / 1000)
                ? <button type="button" className="button" disabled={lifecycleLocked} onClick={() => confirmLifecycle('expire')}><Clock size={15} aria-hidden="true" />标记到期</button> : null}
              {['expired', 'revoked', 'pending'].includes(lifecycleData.status)
                ? <span className="reference-secondary">该订阅当前没有可执行的生命周期操作。</span> : null}
            </div>
          </> : lifecycleLoaded ? <p className="reference-detail-empty">暂无订阅生命周期记录。</p> : null}
        </>}
    </DetailSection> : null}
    {user ? <DetailSection label="最近订单" title="最近订单" icon={<ReceiptText size={17} />} className="reference-detail-card"
      actions={<Link className="reference-detail-link" to={'/finance/order?user_id=' + user.id}>全部订单<ArrowUpRight size={14} /></Link>}>
      <ManagementFeedback error={orders.isError ? orders.error : undefined} loading={orders.isFetching} onRetry={() => void orders.refetch()} />
      {orders.data && !orders.isError
        ? <DataTable rows={orderRows} columns={orderColumns} rowKey={row => row.id} label="最近订单" empty="该用户暂无订单" />
        : null}
    </DetailSection> : null}
    <Modal open={suspendOpen && !subscriptionManagement.denied} title="暂停订阅" subtitle="暂停后用户将无法继续使用订阅，直到恢复"
      onClose={() => { if (!subscriptionManagement.state.busy) { setSuspendOpen(false); setSuspendReason(''); setSuspendError('') } }}>
      <form className="native-content-form" onSubmit={submitSuspend}>
        <Field label="暂停原因（可选）" hint="字母、数字及 _ . : -，最多 64 字符" error={suspendError || subscriptionManagement.error}>
          <Input autoFocus value={suspendReason} maxLength={64} disabled={subscriptionManagement.state.busy}
            placeholder="例如 manual_review" onChange={event => setSuspendReason(event.target.value)} />
        </Field>
        <div className="network-form-actions">
          <button className="button primary" disabled={subscriptionManagement.state.busy}>确认暂停</button>
          <button type="button" className="button" disabled={subscriptionManagement.state.busy}
            onClick={() => { setSuspendOpen(false); setSuspendReason(''); setSuspendError('') }}>取消</button>
        </div>
      </form>
    </Modal>
  </div>
}
