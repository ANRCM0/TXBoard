import { nativeApi, nativeRequest, type NativeEnvelope } from './client'
import { fetchPaymentMethods, type PaymentMethod } from './order'

export type Recharge = {
  trade_no: string
  payment_method_id: number
  amount_minor: number
  fee_minor: number
  total_minor: number
  status: number
  created_at: number
  paid_at: number | null
}

export type RechargePage = {
  data: Recharge[]
  total: number
  page: number
  per_page: number
  last_page: number
}

export function moneyToMinor(value: string): number {
  const trimmed = value.trim()
  if (!/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?$/.test(trimmed)) {
    throw new Error('请输入正确的充值金额，最多保留两位小数')
  }
  const [whole, fraction = ''] = trimmed.split('.')
  const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
  if (!Number.isSafeInteger(cents) || cents < 100 || cents > 500000) {
    throw new Error('单次充值金额应在 ¥1.00 至 ¥5,000.00 之间')
  }
  return cents
}

export async function getWalletBalance(): Promise<{ balance_minor: number; commission_balance_minor: number }> {
  const data = await nativeRequest<{ balance_minor: number; commission_balance_minor: number }>(
    nativeApi.get('/billing/wallet'),
  )
  if (!Number.isSafeInteger(data.balance_minor) || !Number.isSafeInteger(data.commission_balance_minor)) {
    throw new Error('Invalid TXAPI wallet response')
  }
  return data
}

export async function getRechargeMethods(): Promise<PaymentMethod[]> {
  return fetchPaymentMethods()
}

export async function createRecharge(amountMinor: number, methodId: number, idempotencyKey: string): Promise<Recharge> {
  if (!Number.isSafeInteger(amountMinor) || amountMinor < 100 || amountMinor > 500000 ||
      !Number.isSafeInteger(methodId) || methodId < 1 || !/^[a-f\d-]{36}$/i.test(idempotencyKey)) {
    throw new Error('Invalid wallet recharge request')
  }
  const result = await nativeRequest<Recharge>(nativeApi.post(
    '/billing/recharges',
    { amount_minor: amountMinor, payment_method_id: methodId },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  ))
  if (!result || typeof result.trade_no !== 'string' || !result.trade_no.startsWith('WR') ||
      result.amount_minor !== amountMinor || result.payment_method_id !== methodId ||
      !Number.isSafeInteger(result.fee_minor) || !Number.isSafeInteger(result.total_minor)) {
    throw new Error('Invalid TXAPI recharge response')
  }
  return result
}

export async function fetchRecharge(tradeNo: string): Promise<Recharge> {
  const item = await nativeRequest<Recharge>(
    nativeApi.get('/billing/recharges/' + encodeURIComponent(tradeNo)),
  )
  if (!item || item.trade_no !== tradeNo || ![0, 1].includes(item.status)) {
    throw new Error('Invalid TXAPI recharge state')
  }
  return item
}

export async function fetchRechargeHistory(page = 1): Promise<RechargePage> {
  const response = await nativeApi.get<NativeEnvelope<Recharge[]>>('/billing/recharges', {
    params: { page, per_page: 20 },
  })
  const envelope = response.data
  if (!envelope?.request_id || !Array.isArray(envelope.data) || !envelope.meta ||
      !Number.isInteger(envelope.meta.total) || !Number.isInteger(envelope.meta.last_page)) {
    throw new Error('Invalid TXAPI recharge history')
  }
  return { data: envelope.data, total: envelope.meta.total,
    page: envelope.meta.page, per_page: envelope.meta.per_page,
    last_page: envelope.meta.last_page }
}

export async function checkoutRecharge(tradeNo: string, token?: string): Promise<{ type: number; data: string | boolean }> {
  const response = await nativeRequest<{ type: number; data: string | boolean }>(
    nativeApi.post('/billing/recharges/' + encodeURIComponent(tradeNo) + '/checkout', token ? { token } : {}),
  )
  if (!response || !Number.isInteger(response.type) ||
      (typeof response.data !== 'string' && typeof response.data !== 'boolean')) {
    throw new Error('Invalid TXAPI recharge checkout response')
  }
  return response
}
