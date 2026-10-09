import { apiClient, nativeApiClient, nativeAdminPath, type NativeApiEnvelope } from './client'
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

// Admin SPA needs the complete plan catalog for sorting/editing. Fetch
// bounded native pages; never silently return a truncated first page.
export async function getPlans() {
  const plans: PlanItem[] = []
  for (let page = 1; page <= 100; page++) {
    const { data: envelope } = await nativeApiClient.get<NativeApiEnvelope<PlanItem[]>>(
      nativeAdminPath('plans'), { params: { page, per_page: 100 } },
    )
    if (!envelope?.request_id || !Array.isArray(envelope.data) || !envelope.meta ||
        !Number.isInteger(envelope.meta.last_page)) {
      throw new Error('Invalid TXAPI plan catalog response')
    }
    plans.push(...envelope.data)
    if (page >= envelope.meta.last_page) return plans
  }
  throw new Error('Plan catalog exceeds native pagination safety limit')
}
export async function savePlan(payload: PlanSavePayload) {
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ id: number }>>(
    nativeAdminPath('plans'), payload,
  )
  if (!data?.request_id || !Number.isSafeInteger(data.data?.id)) {
    throw new Error('Native plan save not acknowledged')
  }
  return data.data.id
}
export async function updatePlanFlags(id: number, payload: Pick<PlanItem, 'show' | 'sell' | 'renew'>) {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid plan ID')
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('plans') + '/' + id + '/flags', payload,
  )
  if (!data?.request_id || data.data?.ok !== true) throw new Error('Native plan flag update not acknowledged')
  return true
}
export async function deletePlan(id: number) {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid plan ID')
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('plans') + '/' + id + '/delete',
  )
  if (!data?.request_id || data.data?.ok !== true) throw new Error('Native plan delete not acknowledged')
  return true
}
export async function sortPlans(ids: number[]) {
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('plans') + '/sort', { ids },
  )
  if (!data?.request_id || data.data?.ok !== true) throw new Error('Native plan sort not acknowledged')
  return true
}

export async function getOrders(params: Record<string, unknown> = {}) {
  const query: Record<string, unknown> = {
    page: params.current ?? 1,
    per_page: params.pageSize ?? 20,
  }
  if (params.is_commission) query.is_commission = true

  const filters = Array.isArray(params.filter) ? params.filter : []
  const allowed = new Set(['trade_no', 'email', 'user_id', 'callback_no', 'status', 'commission_status'])
  for (const item of filters) {
    if (!item || typeof item !== 'object' || !('id' in item) || !('value' in item)) {
      throw new Error('Malformed native order filter')
    }
    const { id, value } = item as { id: string; value: unknown }
    if (!allowed.has(id)) throw new Error('Unsupported native order filter: ' + id)
    if (Array.isArray(value)) {
      if (value.length !== 1) throw new Error('Native order filter must have one value')
      query[id] = value[0]
    } else {
      query[id] = value
    }
  }

  const { data: envelope } = await nativeApiClient.get<NativeApiEnvelope<OrderItem[]>>(
    nativeAdminPath('orders'), { params: query },
  )
  if (!envelope?.request_id || !Array.isArray(envelope.data) || !envelope.meta ||
      !Number.isInteger(envelope.meta.total) || !Number.isInteger(envelope.meta.page) ||
      !Number.isInteger(envelope.meta.per_page) || !Number.isInteger(envelope.meta.last_page)) {
    throw new Error('Invalid TXAPI order list response')
  }
  return {
    data: envelope.data,
    total: envelope.meta.total,
    current_page: envelope.meta.page,
    per_page: envelope.meta.per_page,
    last_page: envelope.meta.last_page,
  } satisfies OrderPagination
}
export async function getOrderDetail(id: number) {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid order ID')
  const { data } = await nativeApiClient.get<NativeApiEnvelope<OrderDetail>>(
    nativeAdminPath('orders') + '/' + id + '/detail',
  )
  if (!data?.request_id || data.data?.id !== id) throw new Error('Invalid native order detail response')
  return data.data
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
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('orders') + '/' + encodeURIComponent(tradeNo) + '/paid',
  )
  if (!data?.request_id || data.data?.ok !== true) throw new Error('Native order settlement not acknowledged')
  return true
}
export async function cancelOrder(tradeNo: string) {
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('orders') + '/' + encodeURIComponent(tradeNo) + '/cancel',
  )
  if (!data?.request_id || data.data?.ok !== true) throw new Error('Native order cancellation not acknowledged')
  return true
}
