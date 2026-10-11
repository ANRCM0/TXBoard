export type IncomePoint = { date: string; value: number }

export function normalizeIncomePoints(raw: readonly Record<string, unknown>[]): IncomePoint[] {
  return raw.flatMap(row => {
    const date = typeof row.date === 'string' ? row.date.trim() : ''
    const amount = row.paid_total
    if (!date || amount === null || amount === undefined || typeof amount === 'boolean' ||
      (typeof amount === 'string' && !amount.trim())) return []
    const cents = Number(amount)
    if (!Number.isFinite(cents) || cents < 0) return []
    return [{date, value:cents / 100}]
  })
}

/** Keep real zero, but do not turn null or malformed rankings into zero traffic. */
export function formatTraffic(value: unknown): string {
  if (value === null || value === undefined || typeof value === 'boolean' ||
      (typeof value === 'string' && !value.trim())) return '—'
  const bytes = Number(value)
  if (!Number.isFinite(bytes) || bytes < 0) return '—'
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const exponent = Math.max(0, Math.min(4, Math.floor(Math.log(bytes) / Math.log(1024))))
  const number = bytes / 1024 ** exponent
  return new Intl.NumberFormat('zh-CN', {maximumFractionDigits: exponent === 0 && bytes >= 1 ? 0 : number >= 10 ? 1 : 2}).format(number) + ' ' + units[exponent]
}

export function incomeAreaPath(coords: readonly {x:number;y:number}[], baseline: number): string {
  if (!coords.length) return ''
  const first = coords[0]
  const last = coords[coords.length-1]
  return 'M ' + first.x + ' ' + baseline + ' L ' +
    coords.map(point => point.x + ' ' + point.y).join(' L ') +
    ' L ' + last.x + ' ' + baseline + ' Z'
}
