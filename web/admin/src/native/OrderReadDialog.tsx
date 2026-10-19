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
  return <Modal open title="订单详情" onClose={onClose}>
    <ManagementFeedback error={query.isError ? query.error : eventError} loading={query.isFetching}
      notFoundMessage="未找到该原生订单。" onRetry={() => void refresh()} />
    {order ? <ReadFields fields={[
      ['订单号', order.trade_no], ['订单 ID', order.id],
      ['用户', <Link to={'/user/' + order.user_id} onClick={onClose}>#{order.user_id}</Link>],
      ['套餐', order.plan?.name ?? '未记录'], ['周期', order.period],
      ['订单金额', minorAmount(order.amount_minor, order.currency)], ['状态', orderStatus(order.status)],
      ['创建时间', isoDate(order.created_at)], ['支付时间', isoDate(order.paid_at)],
    ]} /> : null}
    {order ? <OrderTimeline key={tradeNo} tradeNo={tradeNo}
      onAdvance={() => void query.refetch()} onDenied={setEventError} /> : null}
    {order ? <OrderAdministration key={tradeNo} tradeNo={tradeNo} status={order.status} onDenied={setEventError} /> : null}
  </Modal>
}
