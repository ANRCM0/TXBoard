import { api, request } from './client'

export type TrafficLogItem = {
  u: number
  d: number
  record_at: number
  server_rate?: number | string
  rate?: number | string
}

export async function fetchTrafficLog() {
  return request<TrafficLogItem[]>(api.get('/user/stat/getTrafficLog'))
}
