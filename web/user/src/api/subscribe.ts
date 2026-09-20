import { api, request } from './client'

export type SubscribeInfo = {
  subscribe_url: string
  reset_day: number
  plan: { name: string; transfer_enable: number } | null
  token: string
  u?: number
  d?: number
  transfer_enable?: number
  device_limit?: number | null
  speed_limit?: number | null
  expired_at?: number | null
  next_reset_at?: number | null
}

export async function fetchSubscribe() {
  return request<SubscribeInfo>(api.get('/user/getSubscribe'))
}
