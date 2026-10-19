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
  if (value === null) return '未记录'
  const raw = String(value)
  if (!/^(0|[1-9][0-9]*)$/.test(raw) || BigInt(raw) > 8640000000000n) return '时间超出显示范围'
  return new Date(Number(raw) * 1000).toLocaleString('zh-CN')
}
export function isoDate(value: string | null): string {
  return value === null ? '未记录' : new Date(value).toLocaleString('zh-CN')
}
export const orderStatus = (status: number) => ['待支付', '开通中', '已取消', '已完成', '已折抵'][status] ?? '未知'
