import { api, request } from './client'

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

export async function fetchTickets(page = 1, pageSize = 20) {
  const all = await request<TicketItem[]>(api.get('/user/ticket/fetch'))
  const total = all.length
  const start = Math.max(0, (page - 1) * pageSize)
  return {
    data: all.slice(start, start + pageSize),
    total,
    current_page: page,
    page_size: pageSize,
  }
}

export async function fetchTicketById(id: number) {
  return request<TicketItem>(api.get('/user/ticket/fetch', { params: { id } }))
}

export async function saveTicket(payload: {
  subject: string
  level: number
  message: string
}) {
  return request<boolean>(api.post('/user/ticket/save', payload))
}

export async function replyTicket(payload: { id: number; message: string }) {
  return request<boolean>(api.post('/user/ticket/reply', payload))
}

export async function closeTicket(id: number) {
  return request<boolean>(api.post('/user/ticket/close', { id }))
}
