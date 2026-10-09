import { nativeApiClient, nativeAdminPath, type NativeApiEnvelope } from './client'

export type ContentPage<T> = {
  total: number
  current_page: number
  per_page: number
  last_page: number
  data: T[]
}

export type KnowledgeItem = {
  id?: number
  title?: string
  category?: string
  language?: string
  body?: string
  show?: boolean | number
  sort?: number
  updated_at?: number | string
  [key: string]: unknown
}

export type NoticeItem = {
  id?: number
  title?: string
  content?: string
  img_url?: string
  tags?: string[] | string
  show?: boolean | number
  popup?: boolean | number
  sort?: number
  [key: string]: unknown
}

type ContentDomain = 'content/knowledge' | 'content/notices'
type SearchParams = { current?: number; pageSize?: number; title?: string; category?: string }

function resource(domain: ContentDomain, id: number): string {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid content ID')
  return nativeAdminPath(domain) + '/' + id
}

async function page<T>(domain: ContentDomain, params: SearchParams = {}): Promise<ContentPage<T>> {
  const { data: envelope } = await nativeApiClient.get<NativeApiEnvelope<T[]>>(
    nativeAdminPath(domain), {
      params: {
        page: params.current ?? 1,
        per_page: params.pageSize ?? 20,
        ...(params.title ? { title: params.title } : {}),
        ...(params.category ? { category: params.category } : {}),
      },
    },
  )
  if (!envelope || !envelope.request_id || !Array.isArray(envelope.data) ||
    !envelope.meta ||
    !Number.isInteger(envelope.meta.page) || !Number.isInteger(envelope.meta.per_page) ||
    !Number.isInteger(envelope.meta.total) || !Number.isInteger(envelope.meta.last_page)) {
    throw new Error('Invalid native content pagination')
  }
  return {
    data: envelope.data,
    total: envelope.meta.total,
    current_page: envelope.meta.page,
    per_page: envelope.meta.per_page,
    last_page: envelope.meta.last_page,
  }
}

// Sorting needs the entire catalog. All requests remain capped at 100 records.
// Never silently return only the first page if there are additional entries.
async function all<T>(domain: ContentDomain): Promise<T[]> {
  const items: T[] = []
  for (let current = 1; current <= 100; current++) {
    const result = await page<T>(domain, { current, pageSize: 100 })
    items.push(...result.data)
    if (current >= result.last_page) return items
  }
  throw new Error('Native content sort exceeds safe pagination limit')
}

async function data<T>(promise: Promise<{ data: NativeApiEnvelope<T> }>): Promise<T> {
  const response = (await promise).data
  if (!response?.request_id || !('data' in response)) {
    throw new Error('Invalid native content response')
  }
  return response.data
}

async function write(promise: Promise<{ data: NativeApiEnvelope<unknown> }>): Promise<boolean> {
  await data(promise)
  return true
}

export function getKnowledgePage(params: SearchParams = {}) {
  return page<KnowledgeItem>('content/knowledge', params)
}
export function getKnowledgeAll() {
  return all<KnowledgeItem>('content/knowledge')
}
export function getKnowledgeDetail(id: number) {
  return data<KnowledgeItem>(nativeApiClient.get(resource('content/knowledge', id)))
}
export async function getKnowledgeCategories() {
  const categories = await data<string[]>(
    nativeApiClient.get(nativeAdminPath('content/knowledge') + '/categories'),
  )
  if (!Array.isArray(categories) || !categories.every(value => typeof value === 'string')) {
    throw new Error('Invalid native content categories')
  }
  return categories
}
export function saveKnowledge(payload: KnowledgeItem) {
  const url = payload.id
    ? resource('content/knowledge', Number(payload.id))
    : nativeAdminPath('content/knowledge')
  return write(payload.id
    ? nativeApiClient.put(url, payload)
    : nativeApiClient.post(url, payload))
}
export function toggleKnowledge(id: number) {
  return write(nativeApiClient.patch(resource('content/knowledge', id) + '/visibility'))
}
export function deleteKnowledge(id: number) {
  return write(nativeApiClient.delete(resource('content/knowledge', id)))
}
export function sortKnowledge(ids: number[]) {
  return write(nativeApiClient.put(nativeAdminPath('content/knowledge') + '/sort', { ids }))
}

export function getNoticePage(params: SearchParams = {}) {
  return page<NoticeItem>('content/notices', params)
}
export function getNoticeAll() {
  return all<NoticeItem>('content/notices')
}
export function saveNotice(payload: NoticeItem) {
  const url = payload.id
    ? resource('content/notices', Number(payload.id))
    : nativeAdminPath('content/notices')
  return write(payload.id
    ? nativeApiClient.put(url, payload)
    : nativeApiClient.post(url, payload))
}
export function toggleNotice(id: number) {
  return write(nativeApiClient.patch(resource('content/notices', id) + '/visibility'))
}
export function deleteNotice(id: number) {
  return write(nativeApiClient.delete(resource('content/notices', id)))
}
export function sortNotice(ids: number[]) {
  return write(nativeApiClient.put(nativeAdminPath('content/notices') + '/sort', { ids }))
}
