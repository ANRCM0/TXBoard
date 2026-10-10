import { nativeApiClient, nativeAdminPath, type NativeApiEnvelope } from './client'
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
  if (payload.reply_status && payload.reply_status.length !== 1) {
    throw new Error('Native tickets require a single reply status filter')
  }
  const { data: envelope } = await nativeApiClient.get<NativeApiEnvelope<TicketItem[]>>(
    nativeAdminPath('tickets'),
    { params: {
      page: current, per_page: pageSize,
      ...(payload.status !== undefined ? { status: payload.status } : {}),
      ...(payload.email ? { email: payload.email } : {}),
      ...(payload.reply_status?.length ? { reply_status: payload.reply_status[0] } : {}),
    } },
  )
  if (!envelope?.request_id || !Array.isArray(envelope.data) || !envelope.meta ||
      !Number.isInteger(envelope.meta.total) || !Number.isInteger(envelope.meta.last_page)) {
    throw new Error('Invalid native ticket list response')
  }
  return {
    data: envelope.data,
    total: envelope.meta.total,
    current_page: envelope.meta.page,
    per_page: envelope.meta.per_page,
    last_page: envelope.meta.last_page,
  } satisfies TicketPageResult
}

function ticketResource(id: number) {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid ticket ID')
  return `${nativeAdminPath('tickets')}/${id}`
}

export async function getTicketDetail(id: number) {
  const { data } = await nativeApiClient.get<NativeApiEnvelope<TicketDetail>>(ticketResource(id))
  if (!data?.request_id || !data.data || !Array.isArray(data.data.messages)) {
    throw new Error('Invalid native ticket detail response')
  }
  return data.data
}

export async function replyTicket(id: number, message: string) {
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    ticketResource(id) + '/reply', { message },
  )
  if (!data?.request_id || data.data?.ok !== true) throw new Error('Native ticket reply not acknowledged')
  return true
}

export async function closeTicket(id: number) {
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    ticketResource(id) + '/close',
  )
  if (!data?.request_id || data.data?.ok !== true) throw new Error('Native ticket closure not acknowledged')
  return true
}
