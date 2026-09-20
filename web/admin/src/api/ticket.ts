import { apiClient } from './client'
import { unwrap } from '../lib/api'
import type { AdminUser } from './user-admin'

export type TicketMessage = {
  id?: number
  message?: string
  is_from_user?: boolean
  is_from_admin?: boolean
  created_at?: number
}

export type TicketItem = {
  id: number
  subject?: string
  level?: number
  status?: number
  reply_status?: number
  updated_at?: number
  created_at?: number
  user?: AdminUser | null
}

export type TicketDetail = TicketItem & {
  messages?: TicketMessage[]
}

export type TicketPageResult = {
  total: number
  current_page: number
  per_page: number
  last_page: number
  data: TicketItem[]
}

export async function getTickets(payload: {
  current?: number
  pageSize?: number
  email?: string
  status?: number
  reply_status?: number[]
}) {
  const current = payload.current ?? 1
  const pageSize = payload.pageSize ?? 10
  const { data } = await apiClient.post<{ data?: TicketItem[]; total?: number }>('/ticket/fetch', payload)
  const rows = Array.isArray(data?.data) ? data.data : []
  const total = Number(data?.total || 0)
  return {
    data: rows,
    total,
    current_page: current,
    per_page: pageSize,
    last_page: Math.max(1, Math.ceil(total / pageSize)),
  } satisfies TicketPageResult
}

export async function getTicketDetail(id: number) {
  const { data } = await apiClient.get('/ticket/fetch', { params: { id } })
  return unwrap<TicketDetail>(data)
}

export async function replyTicket(id: number, message: string) {
  const { data } = await apiClient.post('/ticket/reply', { id, message })
  return unwrap(data)
}

export async function closeTicket(id: number) {
  const { data } = await apiClient.post('/ticket/close', { id })
  return unwrap(data)
}
