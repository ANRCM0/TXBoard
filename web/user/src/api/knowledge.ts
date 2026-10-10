import { nativeApi, nativeRequest } from './client'

export type KnowledgeItem = {
  id: number
  title: string
  body: string
  category: string
  updated_at: number
}

type NativeArticle = {
  id: number
  title: string
  body: string
  category: string
  updated_at: string
}

function toPageArticle(article: NativeArticle): KnowledgeItem {
  const ts = Date.parse(article.updated_at)
  if (!Number.isFinite(ts)) throw new Error('Invalid TXAPI knowledge date')
  return {
    id: article.id,
    title: article.title,
    body: article.body,
    category: article.category,
    updated_at: Math.floor(ts / 1000),
  }
}

export async function fetchKnowledge(language?: string): Promise<KnowledgeItem[]> {
  const data = await nativeRequest<NativeArticle[]>(
    nativeApi.get('/knowledge', { params: language ? { language } : undefined }),
  )
  if (!Array.isArray(data)) throw new Error('Invalid TXAPI knowledge response')
  return data.map(toPageArticle)
}

export async function fetchKnowledgeCategories(language?: string): Promise<string[]> {
  const categories = await nativeRequest<string[]>(
    nativeApi.get('/knowledge/categories', { params: language ? { language } : undefined }),
  )
  if (!Array.isArray(categories) || !categories.every(c => typeof c === 'string')) {
    throw new Error('Invalid TXAPI knowledge categories')
  }
  return categories
}
