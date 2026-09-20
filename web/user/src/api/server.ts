import { api } from './client'

export type ServerNode = {
  id: number
  type: string
  version?: string | null
  name: string
  rate: number | string
  tags?: string[] | null
  is_online: boolean
  cache_key?: string
  last_check_at?: number | null
}

export async function fetchServers(): Promise<ServerNode[]> {
  const { data } = await api.get<{ data?: ServerNode[] }>('/user/server/fetch')
  return Array.isArray(data?.data) ? data.data : []
}
