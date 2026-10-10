import { nativeApi, nativeRequest } from './client'

export type SubscribeInfo = {
  subscribe_url: string
  reset_day: number | null
  plan: { name: string; transfer_enable: number } | null
  u: number
  d: number
  transfer_enable: number
  device_limit: number | null
  speed_limit: number | null
  expired_at: number | null
  next_reset_at: number | null
}

type NativeSubscription = {
  subscribe_url: string
  reset_day: number | null
  plan: { id: number; name: string; traffic_limit_bytes: number } | null
  upload_bytes: number
  download_bytes: number
  traffic_limit_bytes: number
  device_limit: number | null
  speed_limit_mbps: number | null
  expired_at: string | null
  next_reset_at: string | null
}

export async function fetchSubscribe(): Promise<SubscribeInfo> {
  const data = await nativeRequest<NativeSubscription>(nativeApi.get('/me/subscription'))
  const bytes = [data?.upload_bytes, data?.download_bytes, data?.traffic_limit_bytes,
    data?.plan?.traffic_limit_bytes ?? 0]
  if (!data || typeof data.subscribe_url !== 'string' ||
      !/^https?:\/\//i.test(data.subscribe_url) ||
      !bytes.every(value => Number.isSafeInteger(value) && value >= 0) ||
      (data.reset_day !== null && (!Number.isSafeInteger(data.reset_day) || data.reset_day < 0)) ||
      (data.plan !== null && (typeof data.plan?.name !== 'string' ||
        !Number.isSafeInteger(data.plan.traffic_limit_bytes))) ||
      (data.device_limit !== null && !Number.isSafeInteger(data.device_limit)) ||
      (data.speed_limit_mbps !== null && !Number.isFinite(data.speed_limit_mbps))) {
    throw new Error('Invalid TXAPI subscription')
  }
  const date = (value: string | null) => {
    if (!value) return null
    const millis = Date.parse(value)
    if (!Number.isFinite(millis)) throw new Error('Invalid TXAPI subscription date')
    return Math.floor(millis / 1000)
  }
  return {
    subscribe_url: data.subscribe_url,
    reset_day: data.reset_day,
    plan: data.plan ? {
      name: data.plan.name,
      transfer_enable: data.plan.traffic_limit_bytes / 1073741824,
    } : null,
    u: data.upload_bytes,
    d: data.download_bytes,
    transfer_enable: data.traffic_limit_bytes,
    device_limit: data.device_limit,
    speed_limit: data.speed_limit_mbps,
    expired_at: date(data.expired_at),
    next_reset_at: date(data.next_reset_at),
  }
}
