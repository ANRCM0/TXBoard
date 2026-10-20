import { t, getLocale, formatNumber } from '../lib/i18n'
export function commerceMoney(minor: string, currency: string): string {
  // Only currencies with a known two-decimal scale are converted for display.
  if (!['CNY', 'USD'].includes(currency) || !/^-?\d+$/.test(minor)) return t('{amount} 最小单位 {currency}', { amount: minor, currency })
  const amount = BigInt(minor)
  const absolute = amount < 0n ? -amount : amount
  return (amount < 0n ? '-' : '') + (absolute / 100n).toLocaleString(getLocale()) + '.' + (absolute % 100n).toString().padStart(2, '0') + ' ' + currency
}

export function commerceDuration(seconds: number): string {
  if (seconds === 0) return t('无需等待')
  const units = [[86400, '天'], [3600, '小时'], [60, '分钟']] as const
  for (const [divisor, label] of units) if (seconds % divisor === 0) return t('{value} {unit}', { value: formatNumber(seconds / divisor), unit: t(label) })
  return t('{value} 秒', { value: formatNumber(seconds) })
}
