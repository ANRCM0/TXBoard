import { apiClient, nativeApiClient, nativeAdminPath, type NativeApiEnvelope } from './client'
import { unwrap } from '../lib/api'

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
  const { data } = await apiClient.get('/payment/fetch')
  return unwrap<PaymentItem[]>(data) || []
}

export async function getPaymentMethods() {
  const { data } = await apiClient.get('/payment/getPaymentMethods')
  return unwrap<string[]>(data) || []
}

export async function getPaymentForm(payment: string, id?: number) {
  const { data } = await apiClient.post('/payment/getPaymentForm', {
    payment,
    ...(id ? { id } : {}),
  })
  return unwrap<Record<string, PaymentFormField>>(data) || {}
}

export async function savePayment(payload: PaymentSavePayload) {
  const { data } = await apiClient.post('/payment/save', payload)
  return unwrap(data)
}

export async function togglePayment(id: number) {
  const { data } = await apiClient.post('/payment/show', { id })
  return unwrap(data)
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
  const { data } = await apiClient.post('/payment/sort', { ids })
  return unwrap(data)
}
