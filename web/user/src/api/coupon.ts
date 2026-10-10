import { nativeApi, nativeRequest } from './client'

export type CouponInfo = {
  id: number
  name: string
  code: string
  type: number
  value: number
}

type NativeCoupon = {
  id: number
  name: string
  code: string
  type: number
  value_minor: number | null
  percent: number | null
}

export async function checkCoupon(payload: { code: string; plan_id: number; period: string }): Promise<CouponInfo> {
  const item = await nativeRequest<NativeCoupon>(nativeApi.post('/billing/coupons/check', payload))
  if (!item || typeof item.name !== 'string' ||
      (item.type !== 1 && item.type !== 2) ||
      (item.type === 1 && !Number.isSafeInteger(item.value_minor)) ||
      (item.type === 2 && (item.percent === null || !Number.isFinite(item.percent)))) {
    throw new Error('Invalid TXAPI coupon')
  }
  return {
    id: item.id, name: item.name, code: item.code,
    type: item.type, value: item.type === 1 ? item.value_minor! : item.percent!,
  }
}
