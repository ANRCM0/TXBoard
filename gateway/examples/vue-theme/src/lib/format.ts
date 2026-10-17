// Native catalog money is formatted by digit placement without numeric conversion.
export const formatOfferMoney = (minor: string): string => {
  if (!/^(0|[1-9][0-9]*)$/.test(minor)) return '—'
  const digits = minor.padStart(3, '0')
  return `${digits.slice(0, -2)}.${digits.slice(-2)}`
}

// Formatting helpers. TXBoard amounts are integer minor units (cents);
// conversion to display currency happens in the theme, never in the SDK.
export const formatMoney = (minor: number | null | undefined): string =>
  ((minor ?? 0) / 100).toFixed(2)

export const formatBytes = (value: string | number | null | undefined): string => {
  const n = Number(value ?? 0)
  if (!n) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const power = Math.min(4, Math.floor(Math.log(n) / Math.log(1024)))
  return `${(n / 1024 ** power).toFixed(power > 1 ? 2 : 0)} ${units[power]}`
}

export const formatDate = (epochSeconds: string | number | null | undefined): string => {
  if (!epochSeconds) return '—'
  const ms = new Date(Number(epochSeconds) * 1000)
  return Number.isFinite(ms.getTime()) ? ms.toLocaleDateString('zh-CN') : '—'
}
