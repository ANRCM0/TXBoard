import { nativeApi, type NativeEnvelope } from './client'

export type NoticeItem = {
  id: number
  title: string
  content: string
  img_url?: string | null
  tags?: string[]
  created_at: number
}

type NativeNotice = {
  id: number
  title: string
  content: string
  img_url: string | null
  tags: string[]
  created_at: string
}

export async function fetchNotices(pageSize = 20): Promise<NoticeItem[]> {
  const { data: payload } = await nativeApi.get<NativeEnvelope<NativeNotice[]>>('/notices', {
    params: { page: 1, per_page: pageSize },
  })
  if (!payload?.request_id || !Array.isArray(payload.data)) {
    throw new Error('Invalid TXAPI notices response')
  }
  return payload.data.map(item => {
    const timestamp = Date.parse(item.created_at)
    if (!Number.isFinite(timestamp)) throw new Error('Invalid TXAPI notice date')
    return {
      id: item.id, title: item.title, content: item.content,
      img_url: item.img_url, tags: item.tags, created_at: Math.floor(timestamp / 1000),
    }
  })
}
