import { t, useLocale } from '../lib/i18n'
import { Receipt, UserRound } from 'lucide-react'
import './detail-reference.css'
import './commerce-reference.css'
import '../pages/finance/orders-reference.css'
import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Modal } from '../components/ui/Modal'
import { CopyButton } from '../components/ui/CopyButton'
import { StatusBadge } from '../components/ui/StatusBadge'
import { orderPeriod, orderType } from './orderPresentation'
import { admin } from './session'
import { ManagementFeedback } from './Feedback'
import { ReadFields } from './ReadList'
import { isoDate, minorAmount, orderStatus } from './readPresentation'
import { OrderTimeline } from './OrderTimeline'
import { OrderAdministration } from './OrderAdministration'

export function OrderReadDialog({ tradeNo, onClose }: { tradeNo: string; onClose: () => void }) {
  useLocale()
  const [eventError, setEventError] = useState<unknown>()
  useEffect(() => setEventError(undefined), [tradeNo])
  const query = useQuery({ queryKey: ['native-admin', 'order-detail', tradeNo],
    queryFn: ({ signal }) => admin.read('commerce.admin-orders.get', { trade_no: tradeNo }, signal) })
  const order = query.isError || eventError ? undefined : query.data?.data
  const refresh = async () => {
    const result = await query.refetch()
    if (!result.isError) setEventError(undefined)
  }
  return <Modal open wide placement="right" title={t("订单详情")} subtitle={t("查看订单金额、履约进度与管理员操作记录")} className="reference-order-dialog commerce-order-dialog" headerAction={<button className="button" disabled={query.isFetching} onClick={() => void refresh()}>{t("刷新详情")}</button>} onClose={onClose}>
    <ManagementFeedback error={query.isError ? query.error : eventError} loading={query.isFetching}
      notFoundMessage={t("未找到该原生订单。")} onRetry={() => void refresh()} />
    {order ? <section className="reference-order-summary">
      <div className="reference-profile-header">
        <span className="reference-profile-avatar" aria-hidden="true"><Receipt size={24} /></span>
        <div className="reference-profile-identity"><h3>{order.plan?.name ?? t("未记录")}</h3><div className="order-copy-line commerce-order-number"><code>{order.trade_no}</code><CopyButton value={order.trade_no} label={t("复制订单号")} /></div></div>
        <StatusBadge tone={order.status === 3 ? 'success' : order.status === 0 || order.status === 1 ? 'warning' : 'neutral'}>{orderStatus(order.status)}</StatusBadge>
      </div>
      <div className="reference-order-amount"><span>{t("订单金额")}</span><strong>{minorAmount(order.amount_minor, order.currency)}</strong><span className="reference-tag">{orderPeriod(order.period)}</span></div>
      {order.list_amount_minor !== undefined || order.coupon_discount_minor !== undefined ? <dl className="order-amount-breakdown">
        {order.list_amount_minor !== undefined ? <div><dt>{t("商品原价")}</dt><dd>{minorAmount(order.list_amount_minor, order.currency)}</dd></div> : null}
        {order.coupon_discount_minor !== undefined ? <div><dt>{t("优惠券抵扣")}</dt><dd>{minorAmount(order.coupon_discount_minor, order.currency)}</dd></div> : null}
      </dl> : null}
      <div className="reference-detail-section-title"><UserRound size={16} /><h3>{t("订单信息")}</h3></div><ReadFields fields={[
        [t("订单号"), <div className="order-copy-line"><code>{order.trade_no}</code><CopyButton value={order.trade_no} label={t("复制订单号")} /></div>],
        [t("订单 ID"), <div className="order-copy-line"><code>{order.id}</code><CopyButton value={order.id} label={t("复制订单 ID")} /></div>],
        [t("用户"), <Link to={'/user/' + order.user_id} onClick={onClose}>#{order.user_id}</Link>],
        [t("套餐"), order.plan?.name ?? t("未记录")], [t("订单类型"), orderType(order.type)], [t("购买周期"), orderPeriod(order.period)],
        [t("创建时间"), isoDate(order.created_at)], [t("支付时间"), order.paid_at ? isoDate(order.paid_at) : t("暂无支付记录")],
      ]} />
    </section> : null}
    {order ? <OrderTimeline key={'timeline:' + tradeNo} tradeNo={tradeNo}
      onAdvance={() => void query.refetch()} onDenied={setEventError} /> : null}
    {order ? <OrderAdministration key={'administration:' + tradeNo} tradeNo={tradeNo} status={order.status} onDenied={setEventError} /> : null}
  </Modal>
}
