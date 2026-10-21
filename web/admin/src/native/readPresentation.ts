export function validId(value: string): boolean {
  return /^[1-9][0-9]{0,18}$/.test(value) && (value.length < 19 || value <= '9223372036854775807')
}
export function validTradeNo(value: string): boolean {
  return /^[A-Za-z0-9_-]{1,128}$/.test(value)
}
export function readPage(value: string | null): number {
  return value && /^[1-9][0-9]{0,5}$/.test(value) && Number(value) <= 100000 ? Number(value) : 1
}
export function minorAmount(value: string, currency: string): string {
  // Decimal strings remain exact even when the amount exceeds Number.MAX_SAFE_INTEGER.
  const padded = value.padStart(3, '0')
  return padded.slice(0, -2) + '.' + padded.slice(-2) + ' ' + currency
}
export function unixDate(value: string | number | null): string {
  if (value === null) return t('未记录')
  const raw = String(value)
  if (!/^(0|[1-9][0-9]*)$/.test(raw) || BigInt(raw) > 8640000000000n) return t('时间超出显示范围')
  return formatDate(Number(raw) * 1000, { dateStyle: 'medium', timeStyle: 'medium' })
}
export function isoDate(value: string | null): string {
  return value === null ? t('未记录') : formatDate(value, { dateStyle: 'medium', timeStyle: 'medium' })
}
export const orderStatus = (status: number) => t(['待支付', '开通中', '已取消', '已完成', '已折抵'][status] ?? '未知')
import { formatDate, t } from '../lib/i18n'
