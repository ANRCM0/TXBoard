import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft,
  Pencil,
  RefreshCcw,
} from 'lucide-react'
import { useState } from 'react'
import './UserDetailPage.css'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { getOrders, getPlans } from '../../api/finance'
import { getTickets, type TicketItem } from '../../api/ticket'
import { getUserTrafficResetHistory, resetUserTraffic } from '../../api/traffic-reset'
import { getUserDetail } from '../../api/user-admin'
import { OrderDetailModal } from '../../components/finance/OrderDetailModal'
import { UserEditorModal } from '../../components/users/UserEditorModal'
import { PageHeader } from '../../components/ui/PageHeader'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

const GB = 1024 * 1024 * 1024

export function UserDetailPage() {
  const navigate = useNavigate()
  const params = useParams()
  const qc = useQueryClient()
  const userId = Number(params.userId)
  const validUserId = Number.isSafeInteger(userId) && userId > 0
  const [editorOpen, setEditorOpen] = useState(false)
  const [orderId, setOrderId] = useState<number | null>(null)

  const userQuery = useQuery({
    queryKey: ['adminUserDetail', userId],
    queryFn: () => getUserDetail(userId),
    enabled: validUserId,
  })

  const plansQuery = useQuery({
    queryKey: ['plans'],
    queryFn: getPlans,
  })

  const ordersQuery = useQuery({
    queryKey: ['userDetailOrders', userId],
    queryFn: () => getOrders({
      current: 1,
      pageSize: 8,
      filter: [{ id: 'user_id', value: [userId] }],
    }),
    enabled: validUserId,
  })

  const ticketsQuery = useQuery({
    queryKey: ['userDetailTickets', userQuery.data?.email],
    queryFn: () => getTickets({
      current: 1,
      pageSize: 8,
      email: userQuery.data!.email,
    }),
    enabled: Boolean(userQuery.data?.email),
  })

  const resetHistoryQuery = useQuery({
    queryKey: ['userTrafficResetHistory', userId],
    queryFn: () => getUserTrafficResetHistory(userId, 8),
    enabled: validUserId,
  })


  const resetTraffic = useMutation({
    mutationFn: () => resetUserTraffic(userId, '管理员从用户详情中心手动重置'),
    onSuccess: async () => {
      toast.success('用户流量已重置')
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['adminUserDetail', userId] }),
        qc.invalidateQueries({ queryKey: ['adminUsers'] }),
        qc.invalidateQueries({ queryKey: ['userTrafficResetHistory', userId] }),
        qc.invalidateQueries({ queryKey: ['trafficResetLogs'] }),
        qc.invalidateQueries({ queryKey: ['trafficResetStats'] }),
      ])
    },
  })

  const user = userQuery.data
  const plans = Array.isArray(plansQuery.data) ? plansQuery.data : []
  const orders = ordersQuery.data?.data || []
  const tickets = ticketsQuery.data?.data || []
  const resetHistory = resetHistoryQuery.data?.history || []



  if (!validUserId) {
    return <div className="user-detail-v4-error" role="alert"><p>无效的用户 ID。</p><Link className="button" to="/user/manage">返回用户管理</Link></div>
  }

  if (userQuery.isLoading) {
    return <QueryFeedback loading />
  }

  if (userQuery.isError || !user) {
    return <div className="user-detail-v4-error">
      <QueryFeedback error onRetry={() => userQuery.refetch()} />
      <button className="button" type="button" onClick={() => navigate('/user/manage')}><ArrowLeft size={15}/>返回用户管理</button>
    </div>
  }

  const used = Number(user.total_used ?? ((user.u || 0) + (user.d || 0)))
  const total = Number(user.transfer_enable || 0)
  const remaining = Math.max(0, total - used)
  const trafficPercent = total > 0 ? Math.min(100, used / total * 100) : 0

  return <>
    <PageHeader
      title={user.email}
      description={`UID #${user.id} · 用户详情中心`}
      action={<div className="actions">
        <button className="button" type="button" onClick={() => navigate('/user/manage')}><ArrowLeft size={15}/>返回用户管理</button>
        <button className="button" type="button" onClick={() => setEditorOpen(true)}><Pencil size={15}/>编辑用户</button>
      </div>}
    />

    <div className="user-detail-v4-status-row">
      <span className={Boolean(user.banned) ? 'status off' : 'status ok'}>{user.banned ? '已封禁' : '账户正常'}</span>
      {(Boolean(user.is_admin) || Boolean(user.is_staff) || user.group?.name) ? <span className="user-detail-v4-roles">
        {[Boolean(user.is_admin) && 'Admin', Boolean(user.is_staff) && 'Staff', user.group?.name].filter(Boolean).join(' · ')}
      </span> : null}
      <span className="muted">注册于 {formatTime(user.created_at)}</span>
    </div>

    <dl className="user-detail-v4-summary" aria-label="用户账户概览">
      <SummaryMetric label="当前套餐" value={user.plan?.name || (user.plan_id ? `Plan #${user.plan_id}` : '无套餐')} sub={formatExpire(user.expired_at)} />
      <SummaryMetric label="剩余流量" value={total > 0 ? formatBytes(remaining) : '∞'} sub={`已用 ${formatBytes(used)} / ${total > 0 ? formatBytes(total) : '不限'}`} />
      <SummaryMetric label="账户余额" value={formatUserMoney(user.balance)} sub={`佣金 ${formatUserMoney(user.commission_balance)}`} />
      <SummaryMetric label="流量重置" value={formatTime(user.next_reset_at)} sub={`历史 ${Number(resetHistoryQuery.data?.user?.reset_count ?? user.reset_count ?? 0)} 次`} />
    </dl>

    <section className="user-detail-v4-section user-detail-v4-account">
      <div className="user-detail-v4-section-head">
        <div>
          <h2>账户与套餐</h2>
          <p>管理员处理订阅、限速、设备和邀请关系时需要的核心状态。</p>
        </div>
        <div className="actions">
          <button
            className="button danger"
            type="button"
            disabled={resetTraffic.isPending}
            onClick={() => requestConfirm({
              title: '重置用户流量',
              message: `确认清零 ${user.email} 当前已用流量 ${formatBytes(used)}？该操作不可恢复。`,
              danger: true,
              confirmLabel: '执行重置',
              action: () => resetTraffic.mutate(),
            })}
          ><RefreshCcw size={15}/>{resetTraffic.isPending ? '重置中…' : '重置已用流量'}</button>
        </div>
      </div>

      <div className="user-detail-v4-meter">
        <div role={total > 0 ? 'progressbar' : undefined} aria-label={total > 0 ? '已用流量比例' : undefined}
          aria-valuemin={total > 0 ? 0 : undefined} aria-valuemax={total > 0 ? 100 : undefined}
          aria-valuenow={total > 0 ? Math.round(trafficPercent) : undefined}>
          <span style={{ width: trafficPercent + '%' }} />
        </div>
        <strong>{total > 0 ? trafficPercent.toFixed(1) + '%' : '不限流量'}</strong>
        <small>{formatBytes(used)} 已用 · {total > 0 ? formatBytes(remaining) + ' 剩余' : '无配额上限'}</small>
      </div>

      <dl className="user-detail-v4-meta">
        <Meta label="权限组" value={user.group?.name || (user.group_id ? `Group #${user.group_id}` : '未指定')} />
        <Meta label="限速" value={user.speed_limit ? `${user.speed_limit} Mbps` : '不限'} />
        <Meta label="设备限制" value={user.device_limit ? String(user.device_limit) : '不限'} />
        <Meta label="到期时间" value={formatExpire(user.expired_at)} />
        <Meta label="下次流量重置" value={formatTime(user.next_reset_at)} />
        <Meta label="最后登录" value={formatTime(user.last_login_at)} />
        <Meta label="邀请人" value={user.invite_user?.email || '无'} />
        <Meta label="备注" value={user.remarks || '无'} />
      </dl>
    </section>

    <div className="user-detail-v4-two-col">
      <section className="user-detail-v4-section user-detail-v4-activity">
        <div className="user-detail-v4-section-head">
          <div><h2>最近订单</h2><p>快速核对购买、续费、升级和流量重置订单。</p></div>
          <Link className="button" to={`/finance/order?field=user_id&keyword=${user.id}`}>订单管理</Link>
        </div>
        <QueryFeedback loading={ordersQuery.isFetching && !ordersQuery.data} error={ordersQuery.isError} onRetry={() => ordersQuery.refetch()} />
        {orders.length > 0 ? (
        <div className="table-wrap">
          <table className="data-table" aria-label="最近订单">
            <thead><tr><th scope="col">订单号</th><th scope="col">套餐</th><th scope="col">金额</th><th scope="col">状态</th><th scope="col">时间</th></tr></thead>
            <tbody>
              {orders.map(order => <tr key={order.id}>
                <td><button className="link-button" type="button" onClick={() => setOrderId(order.id)}>{order.trade_no}</button></td>
                <td>{order.plan?.name || `Plan #${order.plan_id}`}</td>
                <td>{formatOrderMoney(order.total_amount)}</td>
                <td><span className={order.status === 3 ? 'status ok' : order.status === 2 ? 'status off' : 'badge'}>{orderStatus(order.status)}</span></td>
                <td>{formatTime(order.created_at)}</td>
              </tr>)}
              
            </tbody>
          </table>
        </div>
        ) : !ordersQuery.isLoading && !ordersQuery.isError ? (
          <p className="user-detail-v4-empty" role="status">该用户暂无订单记录</p>
        ) : null}
      </section>

      <section className="user-detail-v4-section user-detail-v4-activity">
        <div className="user-detail-v4-section-head">
          <div><h2>最近工单</h2><p>查看该用户最近的客服请求与回复状态。</p></div>
          <Link className="button" to={`/user/ticket?email=${encodeURIComponent(user.email)}`}>工单管理</Link>
        </div>
        <QueryFeedback loading={ticketsQuery.isFetching && !ticketsQuery.data} error={ticketsQuery.isError} onRetry={() => ticketsQuery.refetch()} />
        {tickets.length > 0 ? (
        <div className="table-wrap">
          <table className="data-table" aria-label="最近工单">
            <thead><tr><th scope="col">主题</th><th scope="col">状态</th><th scope="col">回复</th><th scope="col">时间</th></tr></thead>
            <tbody>
              {tickets.map(ticket => <tr key={ticket.id}>
                <td><Link className="link-button" to={`/user/ticket?email=${encodeURIComponent(user.email)}&ticket=${ticket.id}`}>{cleanTicketSubject(ticket)}</Link></td>
                <td><span className={ticket.status === 1 ? 'status off' : 'status ok'}>{ticket.status === 1 ? '已关闭' : '处理中'}</span></td>
                <td>{ticket.reply_status === 0 ? '待回复' : '已回复'}</td>
                <td>{formatTime(ticket.updated_at)}</td>
              </tr>)}
              
            </tbody>
          </table>
        </div>
        ) : !ticketsQuery.isLoading && !ticketsQuery.isError ? (
          <p className="user-detail-v4-empty" role="status">该用户暂无工单记录</p>
        ) : null}
      </section>
    </div>

    <section className="user-detail-v4-section user-detail-v4-activity">
      <div className="user-detail-v4-section-head">
        <div><h2>流量重置记录</h2><p>最近的自动、订单、礼品卡或管理员手动重置。</p></div>
        <Link className="button" to="/user/traffic-reset">流量重置管理</Link>
      </div>
      <QueryFeedback loading={resetHistoryQuery.isFetching && !resetHistoryQuery.data} error={resetHistoryQuery.isError} onRetry={() => resetHistoryQuery.refetch()} />
        {resetHistory.length > 0 ? (
      <div className="table-wrap">
        <table className="data-table" aria-label="流量重置记录">
          <thead><tr><th scope="col">类型</th><th scope="col">来源</th><th scope="col">清除流量</th><th scope="col">时间</th><th scope="col">原因</th></tr></thead>
          <tbody>
            {resetHistory.map(row => <tr key={row.id}>
              <td>{row.reset_type_name || row.reset_type || '-'}</td>
              <td>{row.trigger_source_name || row.trigger_source || '-'}</td>
              <td>{row.old_traffic?.formatted || '-'}</td>
              <td>{formatTime(row.reset_time)}</td>
              <td>{String(row.metadata?.reason || '-')}</td>
            </tr>)}
            
          </tbody>
        </table>
      </div>
        ) : !resetHistoryQuery.isLoading && !resetHistoryQuery.isError ? (
          <p className="user-detail-v4-empty" role="status">暂无流量重置记录</p>
        ) : null}
    </section>

    <UserEditorModal
      open={editorOpen}
      user={user}
      plans={plans}
      onClose={() => setEditorOpen(false)}
      onSaved={() => {
        qc.invalidateQueries({ queryKey: ['adminUserDetail', userId] })
        qc.invalidateQueries({ queryKey: ['adminUsers'] })
      }}
    />

    <OrderDetailModal orderId={orderId} onClose={() => setOrderId(null)} />
  </>
}

function SummaryMetric({ label, value, sub }: { label: string; value: string; sub: string }) {
  return <div className="user-detail-v4-metric">
    <dt>{label}</dt>
    <dd className="user-detail-v4-value">{value}</dd>
    <dd className="user-detail-v4-sub">{sub}</dd>
  </div>
}

function Meta({ label, value }: { label: string; value: string }) {
  return <div><dt>{label}</dt><dd>{value}</dd></div>
}

function formatBytes(value: number) {
  if (!Number.isFinite(value) || value <= 0) return '0 GB'
  const gb = value / GB
  if (gb >= 1024) return (gb / 1024).toFixed(2) + ' TB'
  return gb.toFixed(gb >= 10 ? 1 : 2) + ' GB'
}

function formatUserMoney(value: unknown) {
  const n = Number(value)
  return Number.isFinite(n) ? `¥ ${n.toFixed(2)}` : '—'
}

function formatOrderMoney(value: unknown) {
  const n = Number(value)
  return Number.isFinite(n) ? `¥ ${(n / 100).toFixed(2)}` : '—'
}

function formatExpire(value?: number | null) {
  if (!value) return '长期有效'
  return new Date(value * 1000).toLocaleString()
}

function formatTime(value: unknown) {
  if (!value) return '-'
  const n = Number(value)
  if (Number.isFinite(n) && n > 0) {
    return new Date(n < 10_000_000_000 ? n * 1000 : n).toLocaleString()
  }
  if (typeof value === 'string') {
    const parsed = Date.parse(value)
    if (Number.isFinite(parsed)) return new Date(parsed).toLocaleString()
  }
  return '-'
}

function orderStatus(status: number) {
  return ({ 0: '待支付', 1: '开通中', 2: '已取消', 3: '已完成', 4: '已折抵' } as Record<number, string>)[status] || String(status)
}

function cleanTicketSubject(ticket: TicketItem) {
  return (ticket.subject || '-').replace('[withdraw_ticket]', '').trim()
}
