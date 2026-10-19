import { Receipt, UserRound } from 'lucide-react'
import './detail-reference.css'
import './commerce-reference.css'
import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Modal } from '../components/ui/Modal'
import { admin } from './session'
import { ManagementFeedback } from './Feedback'
import { ReadFields } from './ReadList'
import { isoDate, minorAmount, orderStatus } from './readPresentation'
import { OrderTimeline } from './OrderTimeline'
import { OrderAdministration } from './OrderAdministration'

export function OrderReadDialog({ tradeNo, onClose }: { tradeNo: string; onClose: () => void }) {
  const [eventError, setEventError] = useState<unknown>()
  useEffect(() => setEventError(undefined), [tradeNo])
  const query = useQuery({ queryKey: ['native-admin', 'order-detail', tradeNo],
    queryFn: ({ signal }) => admin.read('commerce.admin-orders.get', { trade_no: tradeNo }, signal) })
  const order = query.isError || eventError ? undefined : query.data?.data
  const refresh = async () => {
    const result = await query.refetch()
    if (!result.isError) setEventError(undefined)
  }
  return <Modal open wide title="订单详情" subtitle="查看订单金额、履约进度与管理员操作记录" className="reference-order-dialog commerce-order-dialog" headerAction={<button className="button" disabled={query.isFetching} onClick={() => void refresh()}>刷新详情</button>} onClose={onClose}>
    <ManagementFeedback error={query.isError ? query.error : eventError} loading={query.isFetching}
      notFoundMessage="未找到该原生订单。" onRetry={() => void refresh()} />
    {order ? <section className="reference-order-summary"><div className="reference-profile-header"><span className="reference-profile-avatar"><Receipt size={24} /></span><div className="reference-profile-identity"><h3>{order.plan?.name ?? '未记录'}</h3><p className="commerce-order-number">{order.trade_no}</p></div><span className={'reference-status ' + (order.status === 3 ? 'is-success' : order.status === 0 || order.status === 1 ? 'is-warning' : '')}>{orderStatus(order.status)}</span></div><div className="reference-order-amount"><span>订单金额</span><strong>{minorAmount(order.amount_minor, order.currency)}</strong><span className="reference-tag">{order.period}</span></div><div className="reference-detail-section-title"><UserRound size={16} /><h3>订单信息</h3></div><ReadFields fields={[
      ['订单号', order.trade_no], ['订单 ID', order.id],
      ['用户', <Link to={'/user/' + order.user_id} onClick={onClose}>#{order.user_id}</Link>],
      ['套餐', order.plan?.name ?? '未记录'], ['周期', order.period],
      ['创建时间', isoDate(order.created_at)], ['支付时间', isoDate(order.paid_at)],
    ]} /></section> : null}
    {order ? <OrderTimeline key={tradeNo} tradeNo={tradeNo}
      onAdvance={() => void query.refetch()} onDenied={setEventError} /> : null}
    {order ? <OrderAdministration key={tradeNo} tradeNo={tradeNo} status={order.status} onDenied={setEventError} /> : null}
  </Modal>
}
