import { api, nativeApi, nativeRequest, request, type NativeEnvelope } from './client'

export type OrderItem = {
  trade_no: string
  plan_id: number
  period: string
  total_amount: number
  status: number
  type?: number
  payment_id?: number | null
  created_at: number
  paid_at?: number | null
  plan?: { name: string }
}

export type OrderPageResult = {
  data: OrderItem[]
  total: number
  current_page: number
  page_size: number
}

export type PaymentMethod = {
  id: number
  name: string
  payment: string
  icon?: string
  handling_fee_fixed?: number
  handling_fee_percent?: number
}

export type OrderDetail = OrderItem & {
  payment_id: number | null
  balance_amount: number
  discount_amount: number
  plan: { name: string; transfer_enable: number }
}

type NativeOrder = {
  id: number
  trade_no: string
  plan_id: number
  plan: { id: number; name: string } | null
  period: string
  type: number
  status: number
  amount_minor: number
  created_at: string
  paid_at: string | null
}

type NativeOrderDetail = Omit<NativeOrder, 'plan'> & {
  plan: { id: number; name: string; traffic_limit_bytes: number }
  payment_id: number | null
  balance_amount_minor: number
  discount_amount_minor: number
}

const LEGACY_PERIOD: Record<string, string> = {
  monthly: 'month_price',
  quarterly: 'quarter_price',
  half_yearly: 'half_year_price',
  yearly: 'year_price',
  two_yearly: 'two_year_price',
  three_yearly: 'three_year_price',
  onetime: 'onetime_price',
  reset_traffic: 'reset_price',
}

function toLegacyOrder(order: NativeOrder): OrderItem {
  const created = Date.parse(order.created_at)
  const paid = order.paid_at ? Date.parse(order.paid_at) : null
  if (!Number.isFinite(created) || (paid !== null && !Number.isFinite(paid))) {
    throw new Error('Invalid TXAPI order timestamp')
  }
  return {
    trade_no: order.trade_no,
    plan_id: order.plan_id,
    plan: order.plan ?? undefined,
    period: LEGACY_PERIOD[order.period] ?? order.period,
    type: order.type,
    status: order.status,
    total_amount: order.amount_minor,
    created_at: Math.floor(created / 1000),
    paid_at: paid === null ? null : Math.floor(paid / 1000),
  }
}

export async function fetchOrders(
  params: { status?: number; page?: number; pageSize?: number } = {},
): Promise<OrderPageResult> {
  const page = params.page ?? 1
  const pageSize = params.pageSize ?? 20
  const response = await nativeApi.get<NativeEnvelope<NativeOrder[]>>('/orders', {
    params: {
      page,
      per_page: pageSize,
      ...(params.status !== undefined ? { status: params.status } : {}),
    },
  })
  const envelope = response.data
  const meta = envelope?.meta
  if (!envelope?.request_id || !Array.isArray(envelope.data) || !meta ||
      !Number.isInteger(meta.total) || !Number.isInteger(meta.page) ||
      !Number.isInteger(meta.per_page)) {
    throw new Error('Invalid TXAPI order pagination response')
  }

  return {
    data: envelope.data.map(toLegacyOrder),
    total: meta.total,
    current_page: meta.page,
    page_size: meta.per_page,
  }
}

export async function fetchFirstBlockingOrder(): Promise<OrderItem | null> {
  for (const status of [0, 1]) {
    const result = await fetchOrders({ status, page: 1, pageSize: 1 })
    if (result.data[0]) return result.data[0]
  }
  return null
}

export async function saveOrder(payload: { plan_id: number; period: string; coupon_code?: string }) {
  const response = await nativeRequest<{ trade_no: string }>(nativeApi.post('/orders', payload))
  if (!response || typeof response.trade_no !== 'string' || !response.trade_no) {
    throw new Error('Invalid TXAPI created order')
  }
  return response.trade_no
}

export async function fetchOrderDetail(tradeNo: string): Promise<OrderDetail> {
  const item = await nativeRequest<NativeOrderDetail>(
    nativeApi.get('/orders/' + encodeURIComponent(tradeNo) + '/detail'),
  )
  if (!item || !item.plan || typeof item.plan.name !== 'string' ||
      !Number.isSafeInteger(item.plan.traffic_limit_bytes) ||
      item.plan.traffic_limit_bytes < 0 ||
      (item.payment_id !== null && !Number.isSafeInteger(item.payment_id)) ||
      !Number.isSafeInteger(item.balance_amount_minor) || item.balance_amount_minor < 0 ||
      !Number.isSafeInteger(item.discount_amount_minor) || item.discount_amount_minor < 0) {
    throw new Error('Invalid TXAPI order detail')
  }
  return {
    ...toLegacyOrder(item),
    payment_id: item.payment_id,
    balance_amount: item.balance_amount_minor,
    discount_amount: item.discount_amount_minor,
    plan: { name: item.plan.name, transfer_enable: item.plan.traffic_limit_bytes / 1073741824 },
  }
}

export async function fetchPaymentMethods() {
  const methods = await nativeRequest<Array<{
    id: number; name: string; provider: string; icon?: string | null
    fee_fixed_minor: number; fee_percent: number
  }>>(nativeApi.get('/billing/payment-methods'))
  if (!Array.isArray(methods)) throw new Error('Invalid TXAPI payment methods')
  return methods.map(m => ({
    id: m.id, name: m.name, payment: m.provider,
    icon: m.icon ?? undefined,
    handling_fee_fixed: m.fee_fixed_minor,
    handling_fee_percent: m.fee_percent,
  }))
}

export async function checkOrderStatus(tradeNo: string): Promise<number> {
  // Read from the owner-scoped native endpoint; the legacy order detail still
  // serves extra pricing fields and must not be removed in this batch.
  const order = await nativeRequest<{ status: number }>(
    nativeApi.get('/orders/' + encodeURIComponent(tradeNo)),
  )
  if (!order || !Number.isInteger(order.status) || order.status < 0 || order.status > 4) {
    throw new Error('Invalid TXAPI order status')
  }
  return order.status
}

export async function cancelOrder(tradeNo: string) {
  return nativeRequest<{ ok: boolean }>(nativeApi.post('/orders/' + encodeURIComponent(tradeNo) + '/cancel'))
}

export async function checkoutOrder(tradeNo: string) {
  return checkoutOrderWithMethod(tradeNo)
}

export async function checkoutOrderWithMethod(tradeNo: string, method?: number, token?: string) {
  const result = await nativeRequest<{ type: number; data: string | boolean }>(
    nativeApi.post('/orders/' + encodeURIComponent(tradeNo) + '/checkout', {
      ...(method !== undefined ? { method } : {}),
      ...(token ? { token } : {}),
    }),
  )
  if (!result || !Number.isInteger(result.type) ||
      (typeof result.data !== 'string' && typeof result.data !== 'boolean')) {
    throw new Error('Invalid TXAPI checkout response')
  }
  return result
}

export function orderStatus(status: number) {
  return ({ 0: '待支付', 1: '开通中', 2: '已取消', 3: '已完成', 4: '已折抵' } as Record<number,string>)[status] || String(status)
}

export function canCancelOrder(order: OrderItem) {
  return order.status === 0 && !order.paid_at
}
