import { nativeApi, nativeRequest, type NativeEnvelope } from './client'

export type TicketMessage = {
  id: number
  message: string
  created_at: number
  is_me: boolean
}

export type TicketItem = {
  id: number
  subject: string
  level: number
  status: number
  reply_status?: number
  created_at?: number
  updated_at: number
  message?: TicketMessage[]
}

type NativeTicket = {
  id: number
  subject: string
  level: number
  status: number
  reply_status: number
  created_at: string
  updated_at: string
  messages?: { id: number; message: string; is_me: boolean; created_at: string }[]
}

function seconds(value: string) {
  const time = Date.parse(value)
  if (!Number.isFinite(time)) throw new Error('Invalid TXAPI ticket date')
  return Math.floor(time / 1000)
}

function toTicket(item: NativeTicket): TicketItem {
  return {
    id: item.id,
    subject: item.subject,
    level: item.level,
    status: item.status,
    reply_status: item.reply_status,
    created_at: seconds(item.created_at),
    updated_at: seconds(item.updated_at),
    message: item.messages?.map(m => ({
      id: m.id, is_me: m.is_me, message: m.message, created_at: seconds(m.created_at),
    })),
  }
}

export async function fetchTickets(page = 1, pageSize = 20) {
  const { data: payload } = await nativeApi.get<NativeEnvelope<NativeTicket[]>>('/tickets', {
    params: { page, per_page: pageSize },
  })
  if (!payload?.request_id || !Array.isArray(payload.data) ||
      !payload.meta || !Number.isInteger(payload.meta.total)) {
    throw new Error('Invalid TXAPI tickets response')
  }
  return {
    data: payload.data.map(toTicket),
    total: payload.meta.total,
    current_page: payload.meta.page,
    page_size: payload.meta.per_page,
  }
}

export async function fetchTicketById(id: number): Promise<TicketItem> {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid ticket id')
  return toTicket(await nativeRequest<NativeTicket>(nativeApi.get('/tickets/' + id)))
}

export async function saveTicket(payload: {
  subject: string
  level: number
  message: string
}) {
  const data = await nativeRequest<{ id: number }>(nativeApi.post('/tickets', payload))
  return Number.isSafeInteger(data?.id) && data.id > 0
}

export async function replyTicket(payload: { id: number; message: string }) {
  const data = await nativeRequest<{ ok: boolean }>(
    nativeApi.post('/tickets/' + payload.id + '/messages', { message: payload.message }),
  )
  return data.ok
}

export async function closeTicket(id: number) {
  const data = await nativeRequest<{ ok: boolean }>(nativeApi.post('/tickets/' + id + '/close'))
  return data.ok
}
