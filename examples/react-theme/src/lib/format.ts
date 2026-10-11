// Formatting helpers. Amounts are integer minor units (cents); the SDK never
// converts them — display conversion is the theme's job.
export const formatMoney = (minor: number | null | undefined): string =>
  ((minor ?? 0) / 100).toFixed(2)

export const formatBytes = (value: number | null | undefined): string => {
  const n = Number(value ?? 0)
  if (!n) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const power = Math.min(4, Math.floor(Math.log(n) / Math.log(1024)))
  return `${(n / 1024 ** power).toFixed(power > 1 ? 2 : 0)} ${units[power]}`
}

export const formatDate = (epochSeconds: number | null | undefined): string => {
  if (!epochSeconds) return '—'
  const ms = new Date(epochSeconds * 1000)
  return Number.isFinite(ms.getTime()) ? ms.toLocaleDateString('zh-CN') : '—'
}
