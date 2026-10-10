import { nativeApiClient, nativeAdminPath, unwrapNative, type NativeApiEnvelope } from './client'


export type PaymentOption =
  | string
  | number
  | { label?: string; value?: string | number }

export type PaymentFormField = {
  type?: string
  label?: string
  placeholder?: string
  description?: string
  value?: unknown
  options?: PaymentOption[] | Record<string, string | number>
}

export type PaymentItem = {
  id: number
  name: string
  icon?: string | null
  payment: string
  config?: Record<string, unknown>
  notify_domain?: string | null
  handling_fee_fixed?: number | null
  handling_fee_percent?: number | string | null
  enable?: boolean | number
  sort?: number
  uuid?: string
  notify_url?: string
  created_at?: number
  updated_at?: number
}

export type PaymentSavePayload = {
  id?: number
  name: string
  icon?: string | null
  payment: string
  config: Record<string, unknown>
  notify_domain?: string | null
  handling_fee_fixed?: number | null
  handling_fee_percent?: number | null
}

export async function getPayments() {
  return (await unwrapNative(nativeApiClient.get<NativeApiEnvelope<PaymentItem[]>>(nativeAdminPath('payment-methods')))) || []
}

export async function getPaymentMethods() {
  return (await unwrapNative(nativeApiClient.get<NativeApiEnvelope<string[]>>(nativeAdminPath('payment-methods') + '/providers'))) || []
}

export async function getPaymentForm(payment: string, id?: number) {
  return (await unwrapNative(nativeApiClient.post<NativeApiEnvelope<Record<string, PaymentFormField>>>(
    nativeAdminPath('payment-methods') + '/form', { payment, ...(id ? { id } : {}) },
  ))) || {}
}

export async function savePayment(payload: PaymentSavePayload) {
  const base = nativeAdminPath('payment-methods')
  const response = payload.id
    ? nativeApiClient.put<NativeApiEnvelope<{ id: number }>>(base + '/' + payload.id, payload)
    : nativeApiClient.post<NativeApiEnvelope<{ id: number }>>(base, payload)
  return unwrapNative(response)
}

export async function togglePayment(id: number) {
  return unwrapNative(nativeApiClient.patch<NativeApiEnvelope<{ enable: boolean }>>(
    nativeAdminPath('payment-methods') + '/' + id + '/toggle',
  ))
}

export async function deletePayment(id: number) {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid payment method ID')
  const { data } = await nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('payment-methods') + '/' + id + '/delete',
  )
  if (!data?.request_id || data.data?.ok !== true) {
    throw new Error('Native payment deletion not acknowledged')
  }
  return true
}

export async function sortPayments(ids: number[]) {
  return unwrapNative(nativeApiClient.put<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('payment-methods') + '/sort', { ids },
  ))
}
