import { apiClient } from './client'
import { unwrap } from '../lib/api'

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

export async function getKnowledgePage(params: { current?: number; pageSize?: number; title?: string; category?: string } = {}) {
  const { data } = await apiClient.get('/knowledge/fetch', { params })
  return normalizePage<KnowledgeItem>(data, params.current, params.pageSize)
}

export async function getKnowledgeAll() {
  const { data } = await apiClient.get('/knowledge/fetch')
  const first = normalizePage<KnowledgeItem>(data)
  if (first.total <= first.data.length) return first.data
  const { data: full } = await apiClient.get('/knowledge/fetch', { params: { current: 1, pageSize: first.total } })
  return normalizePage<KnowledgeItem>(full, 1, first.total).data
}

export async function getKnowledgeDetail(id: number) {
  const { data } = await apiClient.get('/knowledge/fetch', { params: { id } })
  return unwrap<KnowledgeItem>(data)
}

export async function getKnowledgeCategories() {
  const { data } = await apiClient.get('/knowledge/getCategory')
  return unwrap<string[]>(data) || []
}

export async function saveKnowledge(payload: KnowledgeItem) {
  const { data } = await apiClient.post('/knowledge/save', payload)
  return unwrap(data)
}

export async function toggleKnowledge(id: number) {
  const { data } = await apiClient.post('/knowledge/show', { id })
  return unwrap(data)
}

export async function deleteKnowledge(id: number) {
  const { data } = await apiClient.post('/knowledge/drop', { id })
  return unwrap(data)
}

export async function sortKnowledge(ids: number[]) {
  const { data } = await apiClient.post('/knowledge/sort', { ids })
  return unwrap(data)
}

export async function getNoticePage(params: { current?: number; pageSize?: number; title?: string } = {}) {
  const { data } = await apiClient.get('/notice/fetch', { params })
  return normalizePage<NoticeItem>(data, params.current, params.pageSize)
}

export async function getNoticeAll() {
  const { data } = await apiClient.get('/notice/fetch')
  const first = normalizePage<NoticeItem>(data)
  if (first.total <= first.data.length) return first.data
  const { data: full } = await apiClient.get('/notice/fetch', { params: { current: 1, pageSize: first.total } })
  return normalizePage<NoticeItem>(full, 1, first.total).data
}

export async function saveNotice(payload: NoticeItem) {
  const { data } = await apiClient.post('/notice/save', payload)
  return unwrap(data)
}

export async function toggleNotice(id: number) {
  const { data } = await apiClient.post('/notice/show', { id })
  return unwrap(data)
}

export async function deleteNotice(id: number) {
  const { data } = await apiClient.post('/notice/drop', { id })
  return unwrap(data)
}

export async function sortNotice(ids: number[]) {
  const { data } = await apiClient.post('/notice/sort', { ids })
  return unwrap(data)
}

function normalizeList<T>(raw: unknown): T[] {
  if (Array.isArray(raw)) return raw as T[]
  const value = unwrap<unknown>(raw)
  return Array.isArray(value) ? value as T[] : []
}

function normalizePage<T>(raw: unknown, current = 1, pageSize = 20): ContentPage<T> {
  if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
    const record = raw as Record<string, unknown>
    if (Array.isArray(record.data) && ('total' in record || 'current_page' in record)) {
      return {
        total: Number(record.total ?? record.data.length),
        current_page: Number(record.current_page ?? current),
        per_page: Number(record.per_page ?? pageSize),
        last_page: Number(record.last_page ?? 1),
        data: record.data as T[],
      }
    }
  }

  const list = normalizeList<T>(raw)
  return {
    total: list.length,
    current_page: current,
    per_page: pageSize,
    last_page: Math.max(1, Math.ceil(list.length / Math.max(1, pageSize))),
    data: list,
  }
}
