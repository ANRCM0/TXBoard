import type { CoreOutput } from '@txboard/core-sdk'

type Order = CoreOutput<'commerce.admin-orders.list'>[number]
type TrafficHistory = CoreOutput<'network.nodes.traffic-history.get'>
export type OverviewRange = 7 | 14 | 30
const dayMilliseconds = 86_400_000
const statusLabels = ['待支付', '开通中', '已取消', '已完成', '已折抵'] as const
const statusColors = ['var(--warning)', 'var(--primary)', 'var(--muted)', 'var(--success)', 'var(--muted)'] as const

function sampleScope(size: number) {
  return { scope: 'sample' as const, sampleSize: size, scopeLabel: `当前接口样本 ${size} 项，不代表全站统计` }
}

/** Counts orders by their creation date, using the current status of each sampled order. */
export function orderTrend(rows: readonly Order[], days: OverviewRange = 7, now: Date = new Date()) {
  if (![7, 14, 30].includes(days)) throw new RangeError('Unsupported overview range')
  const nowMilliseconds = now.getTime()
  if (!Number.isFinite(nowMilliseconds)) throw new RangeError('Invalid overview date')
  const today = new Date(nowMilliseconds).toISOString().slice(0, 10)
  const endDay = Date.parse(today + 'T00:00:00Z')
  const startDay = endDay - (days - 1) * dayMilliseconds
  const points = Array.from({ length: days }, (_, index) => {
    const date = new Date(startDay + index * dayMilliseconds).toISOString().slice(0, 10)
    return { date, label: date.slice(5).replace('-', '/'), total: 0, completed: 0, pending: 0 }
  })
  let includedCount = 0
  for (const row of rows) {
    const time = Date.parse(row.created_at)
    if (!Number.isFinite(time) || time < startDay || time > nowMilliseconds) continue
    const index = Math.floor((time - startDay) / dayMilliseconds)
    const point = points[index]
    if (!point) continue
    point.total++
    if (row.status === 3) point.completed++
    if (row.status === 0 || row.status === 1) point.pending++
    includedCount++
  }
  return {
    ...sampleScope(rows.length), days, timeZone: 'UTC' as const,
    definition: '按创建日期分组，已完成和待处理表示样本订单的当前状态',
    includedCount, excludedCount: rows.length - includedCount, hasData: includedCount > 0,
    points, labels: points.map(point => point.label),
    series: [
      { id: 'total', label: '全部订单', values: points.map(point => point.total), color: 'var(--primary)' },
      { id: 'completed', label: '已完成', values: points.map(point => point.completed), color: 'var(--success)' },
      { id: 'pending', label: '待处理', values: points.map(point => point.pending), color: 'var(--warning)' },
    ],
  }
}

/** Donut values are counts; basis-point labels always sum to 100% for a nonempty sample. */
export function orderStatusShare(rows: readonly Order[]) {
  const counts = statusLabels.map((_, status) => rows.filter(row => row.status === status).length)
  const denominator = BigInt(rows.length)
  const shares = counts.map(count => denominator ? Number(BigInt(count) * 10_000n / denominator) : 0)
  if (denominator) {
    const ranked = counts.map((count, status) => ({ status, remainder: BigInt(count) * 10_000n % denominator }))
      .sort((left, right) => left.remainder === right.remainder ? left.status - right.status : left.remainder > right.remainder ? -1 : 1)
    const remaining = 10_000 - shares.reduce((sum, value) => sum + value, 0)
    for (let index = 0; index < remaining; index++) shares[ranked[index].status]++
  }
  return {
    ...sampleScope(rows.length), hasData: rows.length > 0,
    data: statusLabels.map((label, status) => ({
      id: String(status), status, label, value: counts[status], color: statusColors[status],
      basisPoints: shares[status], percentage: shares[status] / 100,
      displayValue: `${counts[status]} 单 · ${(shares[status] / 100).toFixed(2)}%`,
    })),
  }
}

/** Decimal GB labels retain all byte precision without coercing raw counters to Number. */
export function gigabytes(value: bigint) {
  const whole = value / 1_000_000_000n
  const fraction = (value % 1_000_000_000n).toString().padStart(9, '0').replace(/0+$/, '')
  return whole.toString() + (fraction ? '.' + fraction : '') + ' GB'
}

/** One series per node, sharing UTC dates and the same bounded drawing scale. */
export function nodeTrafficHistory(history?: TrafficHistory) {
  const dates: string[] = []
  if (history) {
    const start = history.starts_at * 1000
    const end = history.ends_at * 1000
    if (!Number.isSafeInteger(start) || !Number.isSafeInteger(end) || end < start || end - start > 31 * dayMilliseconds)
      throw new RangeError('Invalid traffic history range')
    const endDay = Date.parse(new Date(end).toISOString().slice(0, 10) + 'T00:00:00Z')
    for (let time = start; time <= endDay; time += dayMilliseconds) dates.push(new Date(time).toISOString().slice(0, 10))
  }
  const rows = (history?.nodes ?? []).map(node => {
    const points = new Map<string, bigint>()
    for (const point of node.points) {
      if (!/^(0|[1-9][0-9]{0,39})$/.test(point.total_bytes) || points.has(point.date)) throw new TypeError('Invalid traffic history point')
      points.set(point.date, BigInt(point.total_bytes))
    }
    return { node, values: dates.map(date => points.get(date) ?? null) }
  })
  const maximum = rows.reduce((max, row) => row.values.reduce<bigint>((value, point) => point !== null && point > value ? point : value, max), 0n)
  return {
    nodeCount: rows.length, dates, labels: dates.map(date => date.slice(5).replace('-', '/')), maxBytes: maximum.toString(),
    // The axis reverses the shared drawing scale; exact tooltip labels come directly from BigInt.
    formatValue: (percent: number) => {
      const value = maximum * BigInt(Math.round(Math.max(0, Math.min(100, percent)) * 10_000)) / 1_000_000n
      return gigabytes(value >= 10_000_000n ? (value + 5_000_000n) / 10_000_000n * 10_000_000n : value)
    },
    series: rows.map(({ node, values }, index) => ({
      id: node.id, label: node.name,
      color: ['var(--primary)', 'var(--success)', 'var(--warning)', 'var(--danger)', '#06b6d4'][index % 5],
      summary: gigabytes(values.reduce<bigint>((sum, value) => sum + (value ?? 0n), 0n)),
      values: values.map(value => value === null ? null : maximum ? Number(value * 1_000_000n / maximum) / 10_000 : 0),
      displayValues: values.map(value => value === null ? null : gigabytes(value)),
    })),
  }
}
