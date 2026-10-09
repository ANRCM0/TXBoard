import { nativeApi, nativeRequest } from './client'

export type ServerNode = {
  id: number
  type: string
  version?: string | null
  name: string
  rate: number | string
  tags?: string[] | null
  is_online: boolean
  last_check_at?: number | null
}

export async function fetchServers(): Promise<ServerNode[]> {
  const nodes = await nativeRequest<ServerNode[]>(nativeApi.get('/me/nodes'))
  if (!Array.isArray(nodes) || !nodes.every(item =>
    item && Number.isSafeInteger(item.id) && item.id > 0 &&
    typeof item.type === 'string' && typeof item.name === 'string' &&
    typeof item.is_online === 'boolean' &&
    (typeof item.rate === 'number' && Number.isFinite(item.rate) ||
      typeof item.rate === 'string' && item.rate.length > 0) &&
    (item.tags == null || Array.isArray(item.tags) &&
      item.tags.every(tag => typeof tag === 'string')))) {
    throw new Error('Invalid TXAPI node list')
  }
  return nodes
}
