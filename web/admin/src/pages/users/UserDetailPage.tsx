import { useQuery } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
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
import { CopyButton } from '../../components/ui/CopyButton'
import { orderPeriod, orderType } from '../../native/orderPresentation'
import { QuotaBar } from '../../components/ui/QuotaBar'
import { DataTable, type Column } from '../../components/ui/DataTable'
import { Modal } from '../../components/ui/Modal'
import { Field, Input } from '../../components/ui/Field'
import { NetworkRecovery, useNetworkManagement } from '../../native/NetworkManagement'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { AccountProfileEditor } from '../../native/IdentityEditors'
import { AccountMetadataPanel } from '../../native/AccountMetadataPanel'
import { SubscriptionAmendmentPanel } from '../../native/SubscriptionAmendmentPanel'
import { AccountFundsPanel } from '../../native/AccountFundsPanel'
import { CustomerPolicyPanel } from '../../native/CustomerPolicyPanel'
import { UsageCorrectionPanel } from '../../native/UsageCorrectionPanel'
import { t, useLocale } from '../../lib/i18n'
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
function orderColumns(): Column<Order>[] { return [
  { key: 'trade', header: t('订单号'), render: row => <Link className="table-link" to={'/finance/order?trade_no=' + encodeURIComponent(row.trade_no)}>{row.trade_no}</Link> },
  { key: 'plan', header: t('套餐 / 周期'), render: row => <div className="user-recent-plan"><strong>{row.plan?.name ?? t('未记录')}</strong><small>{orderType(row.type)} · {orderPeriod(row.period)}</small></div> },
  { key: 'amount', header: t('金额'), render: row => <span className="reference-number">{minorAmount(row.amount_minor, row.currency)}</span> },
  { key: 'status', header: t('状态'), render: row => <StatusBadge tone={row.status === 3 ? 'success' : row.status === 0 || row.status === 1 ? 'warning' : 'neutral'}>{orderStatus(row.status)}</StatusBadge> },
  { key: 'created', header: t('创建时间'), render: row => <span className="reference-secondary">{isoDate(row.created_at)}</span> },
] }

export function UserDetailPage() {
  useLocale()
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
  const [profileOpen, setProfileOpen] = useState(false)
  useEffect(() => { setProfileOpen(false) }, [userId, management.denied])
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
    [t('订阅 ID'), lifecycleData.subscription_id],
    [t('状态'), t(lifecycleLabels[lifecycleData.status] ?? lifecycleData.status)],
    [t('版本'), lifecycleData.subscription_version],
    [t('套餐 ID'), lifecycleData.plan_id ?? t('未记录')],
    [t('条款 ID'), lifecycleData.terms_id ?? t('未记录')],
    [t('周期 ID'), lifecycleData.usage_period_id ?? t('未记录')],
    [t('周期版本'), lifecycleData.period_version ?? t('未记录')],
    [t('到期时间'), lifecycleData.expires_at === null ? t('长期有效') : unixDate(lifecycleData.expires_at)],
    [t('下次重置'), lifecycleData.next_reset_at === null ? t('未设置') : unixDate(lifecycleData.next_reset_at)],
    [t('结束时间'), lifecycleData.ended_at === null ? t('未结束') : unixDate(lifecycleData.ended_at)],
  ] : []
  if (lifecycleData?.reason) lifecycleFields.push([t('暂停原因'), lifecycleData.reason])
  function confirmLifecycle(kind: 'resume' | 'revoke' | 'expire') {
    if (!lifecycleData || lifecycleLocked) return
    const label = kind === 'resume' ? t('恢复订阅') : kind === 'revoke' ? t('撤销订阅') : t('标记到期')
    const message = kind === 'resume'
      ? t('恢复 {email} 的订阅？', { email: user?.email ?? t('该用户') })
      : kind === 'revoke'
        ? t('撤销订阅后立即失效且不可恢复，确认撤销 {email} 的订阅？', { email: user?.email ?? t('该用户') })
        : t('标记到期会结束当前订阅，确认处理 {email} 的订阅？', { email: user?.email ?? t('该用户') })
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
      title: banned ? t('封禁用户') : t('解除封禁'),
      message: banned ? t('封禁 {email} 后，该账号将无法继续使用站点服务', { email: user.email }) : t('解除 {email} 的封禁状态？', { email: user.email }),
      confirmLabel: banned ? t('封禁用户') : t('解除封禁'),
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
    <PageHeader title={t("用户详情")} description={t("账户身份、订阅状态与访问控制")}
      action={<><Link className="button" to="/user/manage">{t("返回列表")}</Link>
        <button className="button" disabled={!valid || query.isFetching} onClick={() => void refreshPage()}><RefreshCw size={15} aria-hidden="true" />{t("刷新")}</button></>} />
    {management.pending || management.state.error
      ? <NetworkRecovery management={management} blocked={!user || query.isFetching} /> : null}
    {subscriptionManagement.pending || subscriptionManagement.state.error
      ? <NetworkRecovery management={subscriptionManagement} blocked={!lifecycleData || lifecycle.isFetching || query.isFetching} /> : null}
    {!valid ? <p role="alert">{t("用户 ID 无效。")}</p> : <ManagementFeedback error={query.isError ? query.error : undefined}
      loading={query.isFetching} notFoundMessage={t("未找到该用户。")} onRetry={() => void management.refresh()} />}
    {user ? <DetailSection label={t("账户信息")} title={t("账户资料")} description={t("身份与访问状态")} icon={<UserRound size={17} />} className="reference-detail-card"
      actions={<>{user.identity_editable && user.identity_revision ? <button className="button" disabled={locked} onClick={() => setProfileOpen(true)}>{t("编辑登录资料")}</button> : null}<button className={'button ' + (user.banned ? '' : 'danger')} disabled={locked || !user.identity_editable && user.identity_editable !== undefined} onClick={confirmStatus}>
        {user.banned ? <ShieldCheck size={15} aria-hidden="true" /> : <Ban size={15} aria-hidden="true" />}
        {user.banned ? t('解除封禁') : t('封禁用户')}
      </button></>}>
      <div className="reference-profile-header">
        <span className="reference-profile-avatar" aria-hidden="true">{user.email.slice(0, 1).toUpperCase()}</span>
        <div className="reference-profile-identity"><div className="user-copy-line"><h3>{user.email}</h3><CopyButton value={user.email} label={t("复制用户邮箱")} /></div><p>{t("用户 #")}{user.id} · {user.is_admin ? t('管理员') : t('用户')}</p></div>
        <StatusBadge tone={user.banned ? 'danger' : 'success'}>{user.banned ? t('已封禁') : t('正常')}</StatusBadge>
      </div>
      <ReadFields fields={[
        [t('用户 ID'), <div className="user-copy-line"><code>{user.id}</code><CopyButton value={user.id} label={t("复制用户 ID")} /></div>],
        [t('角色'), user.is_admin ? t('管理员') : t('用户')], [t('注册时间'), unixDate(user.created_at)],
        [t('最近登录'), user.last_login_at === null ? t('尚未登录') : unixDate(user.last_login_at)],
        [t('订单'), <Link className="reference-detail-link" to={'/finance/order?user_id=' + user.id}>{t("查看该用户订单")}<ArrowUpRight size={14} /></Link>],
      ]} />
    </DetailSection> : null}
    {user?.metadata_revision ? <AccountMetadataPanel user={user} locked={locked} onChanged={() => void query.refetch()} /> : null}
    {user ? <SubscriptionAmendmentPanel key={user.id} userId={user.id} locked={locked} onChanged={() => { void query.refetch(); void lifecycle.refetch() }} /> : null}
    {user ? <AccountFundsPanel key={'funds-' + user.id} userId={user.id} locked={locked} onChanged={() => void query.refetch()} /> : null}
    {user ? <CustomerPolicyPanel key={'policy-' + user.id} userId={user.id} locked={locked} onChanged={() => void query.refetch()} /> : null}
    {user ? <UsageCorrectionPanel key={'usage-correction-' + user.id} userId={user.id} locked={locked} onChanged={() => { void query.refetch(); void lifecycle.refetch() }} /> : null}
    {subscription ? <DetailSection label={t("当前订阅")} title={t("当前订阅")} description={t("当前可用流量与服务期限")} icon={<CreditCard size={17} />} className="reference-detail-card"
      actions={<StatusBadge tone={subscription.authorized ? 'success' : 'neutral'}>{subscription.authorized ? t('可用') : t('暂无可用订阅')}</StatusBadge>}>
      {subscription.authorized ? <>
        <div className="reference-detail-section-title"><CreditCard size={16} /><h3>{t("流量配额")}</h3></div>
        <QuotaBar used={subscription.consumed_bytes} total={subscription.quota_bytes} label={t("订阅流量")} />
        <div className="user-subscription-summary"><div><span>{t("服务期限")}</span><strong>{subscription.expires_at === null ? t('长期有效') : unixDate(subscription.expires_at)}</strong></div><div><span>{t("订阅编号")}</span><div className="user-copy-line"><code>{subscription.subscription_id}</code><CopyButton value={subscription.subscription_id} label={t("复制订阅 ID")} /></div></div></div>
        <ReadFields fields={[
          [t('状态'), t('可用')], [t('订阅 ID'), subscription.subscription_id], [t('套餐 ID'), subscription.plan_id],
          [t('配额（字节）'), subscription.quota_bytes], [t('已用（字节）'), subscription.consumed_bytes],
          [t('剩余（字节）'), subscription.remaining_bytes],
          [t('到期时间'), subscription.expires_at === null ? t('长期有效') : unixDate(subscription.expires_at)],
        ]} />
      </> : <p className="reference-detail-empty">{t("当前没有可用订阅。")}</p>}
    </DetailSection> : null}
    {user ? <DetailSection label={t("订阅生命周期")} title={t("订阅生命周期")} icon={<ShieldCheck size={17} />} className="reference-detail-card"
      actions={lifecycleData ? <StatusBadge tone={lifecycleTones[lifecycleData.status] ?? 'neutral'}>{t(lifecycleLabels[lifecycleData.status] ?? lifecycleData.status)}</StatusBadge> : null}>
      {subscriptionManagement.denied
        ? <p role="alert">{t("无法确认订阅生命周期权限，已隐藏生命周期数据。")}</p>
        : <>
          <ManagementFeedback error={lifecycle.isError ? lifecycle.error : undefined} loading={lifecycle.isFetching} onRetry={() => void subscriptionManagement.refresh()} />
          {lifecycleData ? <>
            <ReadFields fields={lifecycleFields} />
            <div className="native-list-toolbar user-lifecycle-actions">
              {lifecycleData.status === 'active' ? <button type="button" className="button" disabled={lifecycleLocked} onClick={openSuspend}><Pause size={15} aria-hidden="true" />{t("暂停订阅")}</button> : null}
              {lifecycleData.status === 'suspended' ? <button type="button" className="button" disabled={lifecycleLocked} onClick={() => confirmLifecycle('resume')}><Play size={15} aria-hidden="true" />{t("恢复订阅")}</button> : null}
              {['active', 'suspended'].includes(lifecycleData.status)
                ? <button type="button" className="button danger" disabled={lifecycleLocked} onClick={() => confirmLifecycle('revoke')}><Ban size={15} aria-hidden="true" />{t("撤销订阅")}</button> : null}
              {['active', 'suspended'].includes(lifecycleData.status) && lifecycleData.expires_at !== null
                && lifecycleData.expires_at <= Math.floor(Date.now() / 1000)
                ? <button type="button" className="button" disabled={lifecycleLocked} onClick={() => confirmLifecycle('expire')}><Clock size={15} aria-hidden="true" />{t("标记到期")}</button> : null}
              {['expired', 'revoked', 'pending'].includes(lifecycleData.status)
                ? <span className="reference-secondary">{t("该订阅当前没有可执行的生命周期操作。")}</span> : null}
            </div>
          </> : lifecycleLoaded ? <p className="reference-detail-empty">{t("暂无订阅生命周期记录。")}</p> : null}
        </>}
    </DetailSection> : null}
    {user ? <DetailSection label={t("最近订单")} title={t("最近订单")} description={t("最近 5 笔购买与履约记录")} icon={<ReceiptText size={17} />} className="reference-detail-card"
      actions={<Link className="reference-detail-link" to={'/finance/order?user_id=' + user.id}>{t("全部订单")}<ArrowUpRight size={14} /></Link>}>
      <ManagementFeedback error={orders.isError ? orders.error : undefined} loading={orders.isFetching} onRetry={() => void orders.refetch()} />
      {orders.data && !orders.isError
        ? <DataTable rows={orderRows} columns={orderColumns()} rowKey={row => row.id} label={t("最近订单")} empty={t("该用户暂无订单")} />
        : null}
    </DetailSection> : null}
    <Modal open={suspendOpen && !subscriptionManagement.denied} title={t("暂停订阅")} subtitle={t("暂停后用户将无法继续使用订阅，直到恢复")}
      onClose={() => { if (!subscriptionManagement.state.busy) { setSuspendOpen(false); setSuspendReason(''); setSuspendError('') } }}>
      <form className="native-content-form" onSubmit={submitSuspend}>
        <Field label={t("暂停原因（可选）")} hint={t("字母、数字及 _ . : -，最多 64 字符")} error={t(suspendError || subscriptionManagement.error)}>
          <Input autoFocus value={suspendReason} maxLength={64} disabled={subscriptionManagement.state.busy}
            placeholder={t("例如 manual_review")} onChange={event => setSuspendReason(event.target.value)} />
        </Field>
        <div className="network-form-actions">
          <button className="button primary" disabled={subscriptionManagement.state.busy}>{t("确认暂停")}</button>
          <button type="button" className="button" disabled={subscriptionManagement.state.busy}
            onClick={() => { setSuspendOpen(false); setSuspendReason(''); setSuspendError('') }}>{t("取消")}</button>
        </div>
      </form>
    </Modal>
    {profileOpen && user?.identity_editable && user.identity_revision && !management.pending ? <AccountProfileEditor key={user.id + ':' + user.identity_revision} user={user} locked={locked} onClose={() => setProfileOpen(false)} onChanged={() => void query.refetch()} /> : null}
  </div>
}
