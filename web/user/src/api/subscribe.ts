import { nativeApi, nativeRequest } from './client'

export type SubscribeInfo = {
  schema: 'core-entitlement/v1'
  authorized: boolean
  reason: string
  subscription_id?: string
  u: number
  d: number
  transfer_enable: number
  device_limit: number | null
  speed_limit: number | null
  expired_at: number | null
  next_reset_at: number | null
}

type NativeSubscription = {
  schema: SubscribeInfo['schema']
  authorized: boolean
  reason: string
  subscription_id?: string
  upload_bytes?: string
  download_bytes?: string
  quota_bytes?: string
  device_limit?: string | null
  speed_limit_mbps?: string | null
  expires_at?: string | null
  next_reset_at?: string | null
  credential_status: 'provider_managed'
}

export async function fetchSubscribe(): Promise<SubscribeInfo> {
  const data = await nativeRequest<NativeSubscription>(nativeApi.get('/me/subscription'))
  if (!data || data.schema !== 'core-entitlement/v1' ||
      typeof data.authorized !== 'boolean' || typeof data.reason !== 'string' ||
      data.credential_status !== 'provider_managed') throw new Error('Invalid TXAPI subscription')
  const decimal = (value: string | null | undefined, optional = false): number | null => {
    if (optional && value == null) return null
    if (typeof value !== 'string' || !/^(0|[1-9][0-9]*)$/.test(value) ||
        !Number.isSafeInteger(Number(value))) throw new Error('Invalid TXAPI subscription quantity')
    return Number(value)
  }
  return {
    schema: data.schema, authorized: data.authorized, reason: data.reason,
    subscription_id: data.subscription_id,
    u: data.authorized ? decimal(data.upload_bytes)! : 0,
    d: data.authorized ? decimal(data.download_bytes)! : 0,
    transfer_enable: data.authorized ? decimal(data.quota_bytes)! : 0,
    device_limit: decimal(data.device_limit, true),
    speed_limit: decimal(data.speed_limit_mbps, true),
    expired_at: decimal(data.expires_at, true),
    next_reset_at: decimal(data.next_reset_at, true),
  }
}
