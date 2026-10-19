import type { CoreOutput } from '@txboard/core-sdk'

type Order = CoreOutput<'commerce.admin-orders.list'>[number]
type Node = CoreOutput<'network.nodes.list'>[number]
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

function bytes(value: Node['u']): bigint | null {
  if (value === null) return null
  // Preserve missing values, and reject malformed counters instead of silently plotting them as zero.
  if (!/^(0|[1-9][0-9]*)$/.test(value)) throw new TypeError('Invalid node byte counter')
  return BigInt(value)
}

/** Heights compare each direction with the largest directional counter in this sample. */
export function nodeTrafficBars(rows: readonly Node[]) {
  const parsed = rows.map(row => ({ row, upload: bytes(row.u), download: bytes(row.d) }))
  const maximum = parsed.reduce((max, item) => {
    const upload = item.upload ?? 0n, download = item.download ?? 0n
    return upload > max && upload >= download ? upload : download > max ? download : max
  }, 0n)
  return {
    ...sampleScope(rows.length), maxBytes: maximum.toString(),
    definition: '当前节点累计计数；条长相对于样本中最大的单向计数，不是流量占比或时段新增用量',
    hasData: parsed.some(item => item.upload !== null || item.download !== null),
    data: parsed.map(({ row, upload, download }) => ({
      id: row.id, label: row.name,
      raw: { upload: row.u, download: row.d },
      missing: [upload === null ? 'upload' : null, download === null ? 'download' : null].filter(value => value !== null),
      missingLabels: [upload === null ? '上传未上报' : null, download === null ? '下载未上报' : null].filter((value): value is string => value !== null),
      // Missing counters have no bar, so an unknown value is never converted into a zero counter.
      values: ([{ id: 'upload', label: '上传', value: upload, raw: row.u, color: 'var(--primary)' },
        { id: 'download', label: '下载', value: download, raw: row.d, color: 'var(--success)' }])
        .filter(item => item.value !== null).map(item => {
          const basisPoints = maximum ? Number(item.value! * 10_000n / maximum) : 0
          return { id: item.id, label: item.label, percent: basisPoints / 100, basisPoints,
            raw: item.raw, displayValue: `${item.raw} 字节`, color: item.color }
        }),
    })),
  }
}

/** Compare cumulative node counters; categorical node positions never imply elapsed time. */
export function nodeTrafficLines(rows: readonly Node[]) {
  const counters = rows.map(row => ({ upload: bytes(row.u), download: bytes(row.d) }))
  const maximum = counters.reduce((max, row) => [row.upload, row.download]
    .reduce<bigint>((value, counter) => counter !== null && counter > value ? counter : value, max), 0n)
  function formatValue(percent: number) {
    const value = maximum * BigInt(Math.round(Math.max(0, Math.min(100, percent)) * 100)) / 10_000n
    const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB']
    let divisor = 1n, unit = 0
    while (value >= divisor * 1024n && unit < units.length - 1) { divisor *= 1024n; unit++ }
    if (!unit) return value.toString() + ' B'
    const hundredths = (value * 100n / divisor).toString().padStart(3, '0')
    return hundredths.slice(0, -2) + '.' + hundredths.slice(-2) + ' ' + units[unit]
  }
  return {
    ...sampleScope(rows.length), maxBytes: maximum.toString(), formatValue,
    hasData: counters.some(row => row.upload !== null || row.download !== null),
    labels: rows.map(row => row.name),
    definition: '横轴为节点，纵轴为可重置的累计字节计数；不是时间趋势或时段新增流量',
    series: (['upload', 'download'] as const).map(direction => ({
      id: direction, label: direction === 'upload' ? '上传' : '下载',
      color: direction === 'upload' ? 'var(--primary)' : 'var(--success)',
      // Only bounded drawing ratios become numbers. Tooltips and data tables retain exact counters.
      values: counters.map(row => row[direction] === null ? null : maximum ? Number(row[direction]! * 10_000n / maximum) / 100 : 0),
      displayValues: counters.map(row => row[direction] === null ? null : row[direction]!.toString() + ' 字节'),
    })),
  }
}
