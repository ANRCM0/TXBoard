import { api, request } from './client'

export type UserInfo = {
  email: string
  transfer_enable: number
  u: number
  d: number
  expired_at: number | null
  plan_id: number | null
  balance: number
  commission_balance: number
  uuid: string
  avatar_url?: string
  telegram_id?: number | null
}

export async function fetchUserInfo() {
  return request<UserInfo>(api.get('/user/info'))
}

export async function checkLogin() {
  return request<{ is_login: boolean }>(api.get('/user/checkLogin'))
}

export async function fetchUserStat() {
  return request<number[]>(api.get('/user/getStat'))
}
