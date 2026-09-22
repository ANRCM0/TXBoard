import { apiClient } from './client'
import { unwrap } from '../lib/api'

export type PlanPrices = Partial<Record<
  'monthly' | 'quarterly' | 'half_yearly' | 'yearly' | 'two_yearly' | 'three_yearly' | 'onetime' | 'reset_traffic',
  number
>>

export type PlanItem = {
  id: number
  name: string
  content?: string | null
  group_id?: number | null
  group?: { id: number; name: string } | null
  transfer_enable: number
  speed_limit?: number | null
  device_limit?: number | null
  capacity_limit?: number | null
  reset_traffic_method?: number | null
  prices?: PlanPrices | null
  tags?: string[] | null
  show?: boolean
  sell?: boolean
  renew?: boolean
  sort?: number
  users_count?: number
  active_users_count?: number
  [key: string]: unknown
}

export type PlanSavePayload = {
  id?: number
  name: string
  content?: string | null
  group_id?: number | null
  transfer_enable: number
  speed_limit?: number | null
  device_limit?: number | null
  capacity_limit?: number | null
  reset_traffic_method?: number | null
  prices?: PlanPrices
  tags?: string[]
  force_update?: boolean
}

export type OrderItem = {
  id: number
  trade_no: string
  user_id: number
  plan_id: number
  plan?: { id: number; name: string } | null
  user?: { id: number; email?: string } | null
  period?: string
  total_amount: number
  handling_amount?: number | null
  discount_amount?: number | null
  balance_amount?: number | null
  status: number
  type: number
  commission_status?: number | null
  commission_balance?: number | null
  actual_commission_balance?: number | null
  invite_user_id?: number | null
  paid_at?: number | null
  callback_no?: string | null
  created_at?: number | string
  updated_at?: number | string
  [key: string]: unknown
}

export type OrderPagination = {
  total: number
  current_page: number
  per_page: number
  last_page: number
  data: OrderItem[]
}

export type OrderDetail = OrderItem & {
  user?: { id: number; email?: string; [key: string]: unknown }
  invite_user?: { id: number; email?: string; [key: string]: unknown } | null
  commission_log?: unknown[]
  surplus_orders?: OrderItem[]
  [key: string]: unknown
}

export async function getPlans() {
  const { data } = await apiClient.get('/plan/fetch')
  return unwrap<PlanItem[]>(data) || []
}
export async function savePlan(payload: PlanSavePayload) {
  const { data } = await apiClient.post('/plan/save', payload)
  return unwrap(data)
}
export async function updatePlanFlags(id: number, payload: Pick<PlanItem, 'show' | 'sell' | 'renew'>) {
  const { data } = await apiClient.post('/plan/update', { id, ...payload })
  return unwrap(data)
}
export async function deletePlan(id: number) {
  const { data } = await apiClient.post('/plan/drop', { id })
  return unwrap(data)
}
export async function sortPlans(ids: number[]) {
  const { data } = await apiClient.post('/plan/sort', { ids })
  return unwrap(data)
}

export async function getOrders(params: Record<string, unknown> = {}) {
  const { data } = await apiClient.get<OrderPagination>('/order/fetch', { params })
  return data
}
export async function getOrderDetail(id: number) {
  const { data } = await apiClient.post('/order/detail', { id })
  return unwrap<OrderDetail>(data)
}
export async function assignOrder(payload: { email: string; plan_id: number; period: string; total_amount: number }) {
  const { data } = await apiClient.post('/order/assign', payload)
  return unwrap<string>(data)
}
export async function updateOrderCommission(tradeNo: string, commissionStatus: 0 | 1 | 3) {
  const { data } = await apiClient.post('/order/update', {
    trade_no: tradeNo,
    commission_status: commissionStatus,
  })
  return unwrap(data)
}
export async function markOrderPaid(tradeNo: string) {
  const { data } = await apiClient.post('/order/paid', { trade_no: tradeNo })
  return unwrap(data)
}
export async function cancelOrder(tradeNo: string) {
  const { data } = await apiClient.post('/order/cancel', { trade_no: tradeNo })
  return unwrap(data)
}
