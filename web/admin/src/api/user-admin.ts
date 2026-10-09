import { apiClient, nativeApiClient, nativeAdminPath, type NativeApiEnvelope } from './client'
import { unwrap } from '../lib/api'

export type AdminUser = {
  id: number
  email: string
  plan_id?: number | null
  group_id?: number | null
  plan?: { id?: number; name?: string } | null
  group?: { id?: number; name?: string } | null
  invite_user?: { id?: number; email?: string } | null
  balance?: number
  commission_balance?: number
  expected_balance_minor?: number
  expected_commission_balance_minor?: number
  commission_rate?: number | null
  commission_type?: number | null
  discount?: number | null
  expired_at?: number | null
  created_at?: number | null
  banned?: boolean | number
  transfer_enable?: number
  u?: number
  d?: number
  total_used?: number
  online_count?: number
  next_reset_at?: number | null
  last_reset_at?: number | null
  reset_count?: number
  last_login_at?: number | null
  speed_limit?: number | null
  device_limit?: number | null
  is_admin?: boolean | number
  is_staff?: boolean | number
  remarks?: string | null
  subscribe_url?: string
  [key: string]: unknown
}

export type UserPageResult = {
  total: number
  current_page: number
  per_page: number
  last_page: number
  data: AdminUser[]
}

export type UserFilter = {
  id: string
  value: string | number | boolean | number[]
  logic?: 'and' | 'or'
}

export type UserSort = {
  id: string
  desc: boolean
}

export type UserUpdatePayload = {
  id: number
  email?: string
  password?: string
  plan_id?: number | null
  transfer_enable?: number
  u?: number
  d?: number
  expired_at?: number | null
  banned?: boolean
  balance?: number
  commission_balance?: number
  expected_balance_minor?: number
  expected_commission_balance_minor?: number
  expected_transfer_enable?: number | null
  expected_u?: number | null
  expected_d?: number | null
  expected_plan_id?: number | null
  expected_expired_at?: number | null
  commission_rate?: number | null
  commission_type?: number
  discount?: number | null
  speed_limit?: number | null
  device_limit?: number | null
  remarks?: string | null
  invite_user_email?: string
  is_admin?: boolean
  is_staff?: boolean
}

function adminUserResource(id: number) {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid admin user ID')
  return nativeAdminPath('users') + '/' + id
}

export async function getUsers(params: {
  current?: number
  pageSize?: number
  filter?: UserFilter[]
  sort?: UserSort[]
}) {
  const query: Record<string, unknown> = {
    page: params.current ?? 1, per_page: params.pageSize ?? 10,
  }
  for (const { id, value } of params.filter || []) {
    if (id === 'email' && typeof value === 'string') query.email = value
    else if ((id === 'plan_id' || id === 'banned') && typeof value === 'string' &&
             /^eq:[0-9]+$/.test(value)) query[id] = Number(value.slice(3))
    else throw new Error('Unsupported native user filter')
  }
  if (params.sort && params.sort.length) {
    if (params.sort.length !== 1) throw new Error('Only one native user sort is supported')
    const { id, desc } = params.sort[0]
    if (!new Set(['id', 'email', 'balance', 'total_used', 'expired_at', 'created_at']).has(id)) {
      throw new Error('Unsupported native user sort')
    }
    query.sort = id
    query.descending = desc
  }
  const { data: envelope } = await nativeApiClient.get<NativeApiEnvelope<AdminUser[]>>(
    nativeAdminPath('users'), { params: query },
  )
  if (!envelope?.request_id || !Array.isArray(envelope.data) || !envelope.meta ||
      !Number.isInteger(envelope.meta.total) || !Number.isInteger(envelope.meta.last_page)) {
    throw new Error('Invalid native user page response')
  }
  return {
    data: envelope.data,
    total: envelope.meta.total,
    current_page: envelope.meta.page,
    per_page: envelope.meta.per_page,
    last_page: envelope.meta.last_page,
  } satisfies UserPageResult
}

export async function getUserDetail(id: number) {
  const { data } = await nativeApiClient.get<NativeApiEnvelope<AdminUser>>(adminUserResource(id))
  if (!data?.request_id || !data.data || typeof data.data.id !== 'number') {
    throw new Error('Invalid native user detail response')
  }
  return data.data
}

export async function getUserSubscriptionLink(id: number) {
  const { data } = await nativeApiClient.get<NativeApiEnvelope<{ subscribe_url: string }>>(
    adminUserResource(id) + '/subscription-link',
  )
  if (!data?.request_id || typeof data.data?.subscribe_url !== 'string') {
    throw new Error('Invalid native user subscription response')
  }
  return data.data.subscribe_url
}

export async function updateUser(payload: UserUpdatePayload) {
  if (payload.balance !== undefined &&
      !Number.isSafeInteger(payload.expected_balance_minor)) {
    throw new Error('Expected balance snapshot required for native edits')
  }
  if (payload.commission_balance !== undefined &&
      !Number.isSafeInteger(payload.expected_commission_balance_minor)) {
    throw new Error('Expected commission snapshot required for native edits')
  }
  const { id, ...changes } = payload
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ ok: boolean; id: number }>>(
    adminUserResource(id) + '/update', changes,
  )
  if (!data?.request_id || data.data?.ok !== true || data.data?.id !== id) {
    throw new Error('Native user update not acknowledged')
  }
  return true
}

export async function resetUserSecret(id: number) {
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    adminUserResource(id) + '/subscription-credentials/rotate',
  )
  if (!data?.request_id || data.data?.ok !== true) throw new Error('Native credential rotation not acknowledged')
  return true
}

export async function generateUser(payload: {
  email_prefix?: string
  email_suffix: string
  password?: string
  plan_id?: number | null
  expired_at?: number | null
  generate_count?: number
  return_credentials?: boolean
}) {
  if (!payload.password || payload.password.length < 8) {
    throw new Error('New users need an explicit password of at least 8 characters')
  }
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ id: number }>>(
    nativeAdminPath('users'), payload,
  )
  if (!data?.request_id || !Number.isSafeInteger(data.data?.id)) {
    throw new Error('Native user creation not acknowledged')
  }
  return data.data.id
}

export async function destroyUser(id: number) {
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    adminUserResource(id) + '/delete',
  )
  if (!data?.request_id || data.data?.ok !== true) throw new Error('Native account deletion not acknowledged')
  return true
}

export async function sendUsersMail(payload:
  | { scope: 'selected'; user_ids: number[]; subject: string; content: string }
  | { scope: 'filtered'; filter: UserFilter[]; subject: string; content: string; sort?: string; sort_type?: 'ASC' | 'DESC' }
  | { scope: 'all'; subject: string; content: string }
) {
  const { data } = await apiClient.post('/user/sendMail', payload)
  return unwrap(data)
}

export async function banUsers(payload:
  | { scope: 'selected'; user_ids: number[] }
  | { scope: 'filtered'; filter: UserFilter[] }
) {
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ updated: number }>>(
    nativeAdminPath('users') + '/ban', payload,
  )
  if (!data?.request_id || !Number.isInteger(data.data?.updated)) {
    throw new Error('Native ban operation not acknowledged')
  }
  return data.data.updated
}
