import type { CoreOutput } from '@txboard/core-sdk'
import { describe, expect, it } from 'vitest'
import { nodeTrafficHistory, orderStatusShare, incomeTrend } from './overviewCharts'
import { gigabytes } from '../lib/format'

type Order = CoreOutput<'commerce.admin-orders.list'>[number]
type TrafficHistory = CoreOutput<'network.nodes.traffic-history.get'>
const now = new Date('2026-10-20T12:00:00Z')
const order = (created_at: string, status = 0): Order => ({ created_at, status } as Order)

describe('overview sample income', () => {
  const paid = (paid_at: string, amount_minor: string, currency = 'CNY', status = 3): Order =>
    ({ paid_at, amount_minor, currency, status, created_at: '2026-10-01T00:00:00Z' } as Order)
  it('uses payment dates in UTC, excludes unpaid and future orders, and keeps currencies separate', () => {
    const result = incomeTrend([
      paid('2026-10-14T00:00:00Z', '1234'),
      paid('2026-10-15T00:30:00+08:00', '66', 'CNY', 1),
      paid('2026-10-20T11:00:00Z', '2500', 'USD'),
      paid('2026-10-20T11:00:00Z', '9999', 'CNY', 0),
      paid('2026-10-20T11:00:00Z', '9999', 'CNY', 2),
      paid('2026-10-20T12:00:01Z', '9999'),
      paid('2026-10-13T23:59:59Z', '9999'),
      paid('invalid', '9999'),
    ], 7, now)
    expect(result.timeZone).toBe('UTC')
    expect(result.sampleSize).toBe(8)
    expect(result.includedCount).toBe(3)
    expect(result.dates[0]).toBe('2026-10-14')
    expect(result.series.map(row => row.id)).toEqual(['CNY', 'USD'])
    expect(result.series[0].displayValues[0]).toBe('13.00 CNY')
    expect(result.series[0].summary).toBe('13.00 CNY')
    expect(result.series[1].displayValues.at(-1)).toBe('25.00 USD')
    expect(result.series[1].values.at(-1)).toBe(100)
    expect(result.scopeLabel).toContain('不代表全站统计')
  })
  it('aggregates exact minor-unit strings beyond the safe Number range', () => {
    const result = incomeTrend([paid('2026-10-20T00:00:00Z', '9007199254740993'), paid('2026-10-20T01:00:00Z', '7')], 7, now)
    expect(result.series[0].summary).toBe('90071992547410.00 CNY')
    expect(result.series[0].displayValues.at(-1)).toBe('90071992547410.00 CNY')
    expect(result.series[0].values.at(-1)).toBe(100)
  })
  it.each([7, 14, 30] as const)('fills %i UTC days and reports empty paid samples accurately', days => {
    const result = incomeTrend([order('2026-10-20T00:00:00Z')], days, now)
    expect(result.dates).toHaveLength(days)
    expect(result.dates.at(-1)).toBe('2026-10-20')
    expect(result.hasData).toBe(false)
    expect(result.series).toEqual([])
  })
  it('handles year boundaries and invalid chart dates', () => {
    expect(incomeTrend([], 7, new Date('2027-01-02T00:00:00Z')).dates[0]).toBe('2026-12-27')
    expect(() => incomeTrend([], 7, new Date('invalid'))).toThrow(RangeError)
  })
  it('preserves all five order states and derives percentages only from the supplied sample', () => {
    const result = orderStatusShare([0, 1, 2, 3, 4, 3].map(status => order('2026-10-20T00:00:00Z', status)))
    expect(result.sampleSize).toBe(6)
    expect(result.scope).toBe('sample')
    expect(result.data.map(item => item.value)).toEqual([1, 1, 1, 2, 1])
    expect(result.data.map(item => item.label)).toEqual(['待支付', '开通中', '已取消', '已完成', '已折抵'])
    expect(result.data.reduce((sum, item) => sum + item.basisPoints, 0)).toBe(10_000)
    expect(result.data.reduce((sum, item) => sum + item.value, 0)).toBe(6)
    expect(orderStatusShare([]).data.every(item => item.value === 0 && item.percentage === 0)).toBe(true)
    expect(orderStatusShare([]).hasData).toBe(false)
  })
})

describe('overview daily node total traffic', () => {
  const history = (nodes: TrafficHistory['nodes']): TrafficHistory => ({
    time_zone: 'UTC', basis: 'settled-raw-usage/v1', bucket: 'day',
    starts_at: Date.parse('2026-10-14T00:00:00Z') / 1000,
    ends_at: now.getTime() / 1000, nodes,
  })
  it('compares nodes across dates using one total series per node in GB', () => {
    const result = nodeTrafficHistory(history([
      { id: '9007199254740993', name: 'Tokyo', points: [{ date: '2026-10-14', total_bytes: '3000000000' }, { date: '2026-10-15', total_bytes: '6000000000' }] },
      { id: '2', name: 'Osaka', points: [{ date: '2026-10-14', total_bytes: '6000000000' }, { date: '2026-10-15', total_bytes: '0' }] },
    ]))
    expect(result.dates).toHaveLength(7)
    expect(result.dates.at(-1)).toBe('2026-10-20')
    expect(result.series.map(row => [row.id, row.label])).toEqual([['9007199254740993', 'Tokyo'], ['2', 'Osaka']])
    expect(result.series[0].values.slice(0, 3)).toEqual([50, 100, null])
    expect(result.series[0].displayValues.slice(0, 3)).toEqual(['3.00 GB', '6.00 GB', null])
    expect(result.series[1].displayValues.slice(0, 3)).toEqual(['6.00 GB', '0.00 GB', null])
    expect(result.series[0].summary).toBe('9.00 GB')
    expect(result.formatValue(50)).toBe('3.00 GB')
  })
  it('rounds to two decimal GB places using exact integer arithmetic', () => {
    const raw = '9999999999999999999999999999999999999999'
    const result = nodeTrafficHistory(history([{ id: '1', name: 'Large', points: [{ date: '2026-10-14', total_bytes: raw }] }]))
    expect(result.maxBytes).toBe(raw)
    expect(result.series[0].displayValues[0]).toBe('1' + '0'.repeat(31) + '.00 GB')
    expect(result.series[0].values[0]).toBe(100)
    expect(gigabytes(1n)).toBe('0.00 GB')
    expect(gigabytes(1_250_000_000n)).toBe('1.25 GB')
    expect(gigabytes(1_995_000_000n)).toBe('2.00 GB')
    expect(gigabytes(3_000_000_000n)).toBe('3.00 GB')
  })
  it('distinguishes zero from missing and withdraws absent history', () => {
    const result = nodeTrafficHistory(history([{ id: '1', name: 'Zero', points: [{ date: '2026-10-14', total_bytes: '0' }] }]))
    expect(result.series[0].values.slice(0, 2)).toEqual([0, null])
    expect(result.formatValue(100)).toBe('0.00 GB')
    expect(nodeTrafficHistory().series).toEqual([])
    expect(nodeTrafficHistory().labels).toEqual([])
  })
  it.each(['-1', '1.5', '01', '1e9', ' 1', '9'.repeat(41)])('rejects malformed history bytes: %s', total_bytes => {
    expect(() => nodeTrafficHistory(history([{ id: '1', name: 'Node', points: [{ date: '2026-10-14', total_bytes }] }]))).toThrow(TypeError)
  })
})
