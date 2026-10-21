import { formatNumber, t } from '../lib/i18n'
import type { CoreOutput } from '@txboard/core-sdk'
import { gigabytes } from '../lib/format'
import { minorAmount } from './readPresentation'

type Order = CoreOutput<'commerce.admin-orders.list'>[number]
type TrafficHistory = CoreOutput<'network.nodes.traffic-history.get'>
export type OverviewRange = 7 | 14 | 30
const dayMilliseconds = 86_400_000
const statusLabels = ['待支付', '开通中', '已取消', '已完成', '已折抵'] as const
const statusColors = ['var(--warning)', 'var(--primary)', 'var(--muted)', 'var(--success)', 'var(--muted)'] as const

function sampleScope(size: number) {
  return { scope: 'sample' as const, sampleSize: size, scopeLabel: t('当前接口样本 {count} 项，不代表全站统计', { count: size }) }
}

/** Paid-order gross amounts in the current sample, grouped by payment date and currency. */
export function incomeTrend(rows: readonly Order[], days: OverviewRange = 7, now: Date = new Date()) {
  if (![7, 14, 30].includes(days)) throw new RangeError('Unsupported overview range')
  const nowMilliseconds = now.getTime()
  if (!Number.isFinite(nowMilliseconds)) throw new RangeError('Invalid overview date')
  const today = new Date(nowMilliseconds).toISOString().slice(0, 10)
  const endDay = Date.parse(today + 'T00:00:00Z')
  const startDay = endDay - (days - 1) * dayMilliseconds
  const dates = Array.from({ length: days }, (_, index) => new Date(startDay + index * dayMilliseconds).toISOString().slice(0, 10))
  const currencies = new Map<string, bigint[]>()
  let includedCount = 0
  for (const row of rows) {
    if (![1, 3].includes(row.status) || row.paid_at === null) continue
    const time = Date.parse(row.paid_at)
    if (!Number.isFinite(time) || time < startDay || time > nowMilliseconds) continue
    if (!/^(0|[1-9][0-9]*)$/.test(row.amount_minor) || !/^[A-Z]{3}$/.test(row.currency)) throw new TypeError('Invalid income amount')
    const amounts = currencies.get(row.currency) ?? Array<bigint>(days).fill(0n)
    amounts[Math.floor((time - startDay) / dayMilliseconds)] += BigInt(row.amount_minor)
    currencies.set(row.currency, amounts)
    includedCount++
  }
  const maximum = Array.from(currencies.values()).flat().reduce((max, value) => value > max ? value : max, 0n)
  return {
    ...sampleScope(rows.length), days, timeZone: 'UTC' as const, includedCount,
    hasData: includedCount > 0, dates, labels: dates.map(date => date.slice(5).replace('-', '/')),
    formatValue: (percent: number) => minorAmount(((maximum * BigInt(Math.round(Math.max(0, Math.min(100, percent)) * 10_000)) + 500_000n) / 1_000_000n).toString(), '').trim(),
    series: Array.from(currencies).sort(([left], [right]) => left.localeCompare(right)).map(([currency, amounts]) => ({
      id: currency, label: t('{currency} 收入', { currency }), summary: minorAmount(amounts.reduce((sum, amount) => sum + amount, 0n).toString(), currency),
      values: amounts.map(amount => maximum ? Number(amount * 1_000_000n / maximum) / 10_000 : 0),
      displayValues: amounts.map(amount => minorAmount(amount.toString(), currency)),
    })),
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
      id: String(status), status, label: t(label), value: counts[status], color: statusColors[status],
      basisPoints: shares[status], percentage: shares[status] / 100,
      displayValue: t('{count} 单 · {percent}%', { count: counts[status], percent: (shares[status] / 100).toFixed(2) }),
    })),
  }
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
      color: ['var(--primary)', 'var(--data-cyan)', 'var(--data-green)', 'var(--data-orange)', 'var(--data-purple)', 'var(--data-red)'][index % 6],
      summary: gigabytes(values.reduce<bigint>((sum, value) => sum + (value ?? 0n), 0n)),
      values: values.map(value => value === null ? null : maximum ? Number(value * 1_000_000n / maximum) / 10_000 : 0),
      displayValues: values.map(value => value === null ? null : gigabytes(value)),
    })),
  }
}

export type DashboardTrendKind = 'income' | 'earned_commission' | 'commission_transfer'
export type DashboardMeasure = 'amount' | 'count'
export type DashboardMoney = { currency: string; amount_minor: string; count: string }
export type DashboardPoint = { date: string; income: DashboardMoney[]; earned_commission: DashboardMoney[]; commission_transfer: DashboardMoney[] }
export const dashboardRanges = [7, 30, 90, 180, 365] as const

export function dashboardDates(days: number, now = new Date()) {
  if (!dashboardRanges.includes(days as typeof dashboardRanges[number]) || !Number.isFinite(now.getTime())) throw new RangeError('Invalid dashboard range')
  const end_date = now.toISOString().slice(0, 10)
  const start_date = new Date(Date.parse(end_date + 'T00:00:00Z') - (days - 1) * dayMilliseconds).toISOString().slice(0, 10)
  return { start_date, end_date }
}

export function validDashboardDates(start: string, end: string, now = new Date()) {
  const first = Date.parse(start + 'T00:00:00Z'), last = Date.parse(end + 'T00:00:00Z')
  return /^\d{4}-\d{2}-\d{2}$/.test(start) && /^\d{4}-\d{2}-\d{2}$/.test(end)
    && Number.isFinite(first) && Number.isFinite(last)
    && new Date(first).toISOString().slice(0, 10) === start && new Date(last).toISOString().slice(0, 10) === end
    && first >= 0 && first <= last && last - first < 366 * dayMilliseconds && end <= now.toISOString().slice(0, 10)
}

export function nativeDashboardTrend(points: readonly DashboardPoint[], kind: DashboardTrendKind, measure: DashboardMeasure, currency: string) {
  const labels = { income: '已支付订单', earned_commission: '已入账佣金', commission_transfer: '已转入钱包佣金' }
  const values = points.map(point => {
    const entry = point[kind].find(value => value.currency === currency)
    const raw = entry ? entry[measure === 'amount' ? 'amount_minor' : 'count'] : '0'
    if (!/^(0|[1-9][0-9]{0,39})$/.test(raw)) throw new TypeError('Invalid native dashboard value')
    return BigInt(raw)
  })
  const maximum = values.reduce((max, value) => value > max ? value : max, 0n)
  const display = (value: bigint) => measure === 'amount' ? minorAmount(value.toString(), currency) : t('{count} 笔', { count: formatNumber(value) })
  return {
    labels: points.map(point => point.date.slice(5).replace('-', '/')),
    formatValue: (percent: number) => display(maximum * BigInt(Math.round(Math.max(0, Math.min(100, percent)) * 10_000)) / 1_000_000n),
    series: [{ id: kind + '-' + currency, label: t('{kind} · {measure}', { kind: t(labels[kind]), measure: t(measure === 'amount' ? '金额' : '笔数') }),
      summary: display(values.reduce((sum, value) => sum + value, 0n)),
      values: values.map(value => maximum ? Number(value * 1_000_000n / maximum) / 10_000 : 0),
      displayValues: values.map(display),
    }],
  }
}
