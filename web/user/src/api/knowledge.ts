import { api, request } from './client'

export type KnowledgeItem = {
  id: number
  title: string
  body: string
  category: string
  updated_at: number
}

type GroupedKnowledge = Record<string, KnowledgeItem[]>

export async function fetchKnowledge(language?: string) {
  const data = await request<KnowledgeItem[] | GroupedKnowledge>(
    api.get('/user/knowledge/fetch', { params: language ? { language } : undefined }),
  )
  return Array.isArray(data) ? data : Object.values(data).flat()
}

export async function fetchKnowledgeCategories(language?: string) {
  return request<string[]>(
    api.get('/user/knowledge/getCategory', { params: language ? { language } : undefined }),
  )
}
