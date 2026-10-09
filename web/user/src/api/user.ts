import { api, nativeApi, nativeRequest, request } from './client'

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

type NativeAccount = {
  id: number
  email: string
  uuid: string
  plan_id: number | null
  balance_minor: number
  commission_balance_minor: number
  expired_at: string | null
  telegram_id: number | null
  traffic: {
    upload_bytes: number
    download_bytes: number
    limit_bytes: number
  }
}

export async function fetchUserInfo(): Promise<UserInfo> {
  const item = await nativeRequest<NativeAccount>(nativeApi.get('/me'))
  if (!item || !item.traffic || typeof item.email !== 'string' ||
      typeof item.uuid !== 'string' ||
      !Number.isSafeInteger(item.balance_minor) ||
      !Number.isSafeInteger(item.commission_balance_minor)) {
    throw new Error('Invalid TXAPI account response')
  }
  const expires = item.expired_at ? Date.parse(item.expired_at) : null
  if (expires !== null && !Number.isFinite(expires)) {
    throw new Error('Invalid TXAPI account expiry')
  }
  return {
    email: item.email,
    uuid: item.uuid,
    plan_id: item.plan_id,
    balance: item.balance_minor,
    commission_balance: item.commission_balance_minor,
    expired_at: expires === null ? null : Math.floor(expires / 1000),
    telegram_id: item.telegram_id,
    u: item.traffic.upload_bytes,
    d: item.traffic.download_bytes,
    transfer_enable: item.traffic.limit_bytes,
  }
}

export async function fetchUserStat() {
  return request<number[]>(api.get('/user/getStat'))
}
