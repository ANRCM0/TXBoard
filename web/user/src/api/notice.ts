import { api } from './client'

export type NoticeItem = {
  id: number
  title: string
  content: string
  img_url?: string | null
  tags?: string[]
  created_at: number
}

export async function fetchNotices(pageSize = 20): Promise<NoticeItem[]> {
  const { data } = await api.get<any>('/user/notice/fetch', { params: { current: 1, pageSize } })
  const payload = data?.status === 'success' ? data.data : data
  if (Array.isArray(payload)) return payload
  if (payload && Array.isArray(payload.data)) return payload.data
  return []
}
