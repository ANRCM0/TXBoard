import { apiClient } from './client'
import { unwrap } from '../lib/api'

export type AdminUser = {
  id: number
  email: string
  plan_id?: number | null
  plan?: { id?: number; name?: string } | null
  group?: { id?: number; name?: string } | null
  invite_user?: { id?: number; email?: string } | null
  balance?: number
  commission_balance?: number
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

export async function getUsers(params: {
  current?: number
  pageSize?: number
  filter?: UserFilter[]
  sort?: UserSort[]
}) {
  const { data } = await apiClient.post<UserPageResult>('/user/fetch', params)
  return data
}

export async function getUserDetail(id: number) {
  const { data } = await apiClient.get('/user/getUserInfoById', { params: { id } })
  return unwrap<AdminUser>(data)
}

export async function updateUser(payload: UserUpdatePayload) {
  const { data } = await apiClient.post('/user/update', payload)
  return unwrap(data)
}

export async function resetUserSecret(id: number) {
  const { data } = await apiClient.post('/user/resetSecret', { id })
  return unwrap(data)
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
  const { data } = await apiClient.post('/user/generate', payload)
  return unwrap(data)
}

export async function destroyUser(id: number) {
  const { data } = await apiClient.post('/user/destroy', { id })
  return unwrap(data)
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
  const { data } = await apiClient.post('/user/ban', payload)
  return unwrap(data)
}
