import { api, request } from './client'

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
  balance_amount?: number
  handling_amount?: number | null
  discount_amount?: number
  surplus_amount?: number
  surplus_credit?: number
  surplus_orders?: OrderItem[]
  try_out_plan_id?: number
  payment?: PaymentMethod | null
  plan?: {
    name?: string
    transfer_enable?: number
    month_price?: number | null
    quarter_price?: number | null
    half_year_price?: number | null
    year_price?: number | null
    two_year_price?: number | null
    three_year_price?: number | null
    onetime_price?: number | null
    reset_price?: number | null
  }
}

export async function fetchOrders(params: { status?: number; page?: number; pageSize?: number } = {}) {
  const all = await request<OrderItem[]>(api.get('/user/order/fetch'))
  const filtered = params.status === undefined
    ? all
    : all.filter(order => order.status === params.status)
  const page = params.page ?? 1
  const pageSize = params.pageSize ?? 20
  const start = Math.max(0, (page - 1) * pageSize)

  return {
    data: filtered.slice(start, start + pageSize),
    total: filtered.length,
    current_page: page,
    page_size: pageSize,
  } satisfies OrderPageResult
}

export async function fetchFirstBlockingOrder(): Promise<OrderItem | null> {
  for (const status of [0, 1]) {
    const result = await fetchOrders({ status, page: 1, pageSize: 1 })
    if (result.data[0]) return result.data[0]
  }
  return null
}

export async function saveOrder(payload: { plan_id: number; period: string; coupon_code?: string }) {
  return request<string>(api.post('/user/order/save', payload))
}

export async function fetchOrderDetail(tradeNo: string) {
  return request<OrderDetail>(api.get('/user/order/detail', { params: { trade_no: tradeNo } }))
}

export async function fetchPaymentMethods() {
  return request<PaymentMethod[]>(api.get('/user/order/getPaymentMethod'))
}

export async function checkOrderStatus(tradeNo: string) {
  return request<number>(api.get('/user/order/check', { params: { trade_no: tradeNo } }))
}

export async function cancelOrder(tradeNo: string) {
  return request<null>(api.post('/user/order/cancel', { trade_no: tradeNo }))
}

export async function checkoutOrder(tradeNo: string) {
  return checkoutOrderWithMethod(tradeNo)
}

export async function checkoutOrderWithMethod(tradeNo: string, method?: number, token?: string) {
  const { data } = await api.post<{
    status?: string
    type?: number
    data?: string | boolean
    message?: string
  }>('/user/order/checkout', {
    trade_no: tradeNo,
    ...(method !== undefined ? { method } : {}),
    ...(token ? { token } : {}),
  })
  if (data.data !== undefined) return { type: data.type ?? 0, data: data.data }
  throw new Error(data.message || '支付请求失败')
}

export function orderStatus(status: number) {
  return ({ 0: '待支付', 1: '开通中', 2: '已取消', 3: '已完成', 4: '已折抵' } as Record<number,string>)[status] || String(status)
}

export function canCancelOrder(order: OrderItem) {
  return order.status === 0 && !order.paid_at
}
