import type { CoreOutput } from '@txboard/core-sdk'
import { describe, expect, it } from 'vitest'
import { gigabytes, nodeTrafficHistory, orderStatusShare, orderTrend } from './overviewCharts'

type Order = CoreOutput<'commerce.admin-orders.list'>[number]
type TrafficHistory = CoreOutput<'network.nodes.traffic-history.get'>
const now = new Date('2026-10-20T12:00:00Z')
const order = (created_at: string, status = 0): Order => ({ created_at, status } as Order)

describe('overview sample order charts', () => {
  it('counts UTC creation dates across offsets and distinguishes current completion from payment date', () => {
    const rows = [
      order('2026-10-14T00:00:00Z', 0),
      order('2026-10-15T00:30:00+08:00', 3),
      order('2026-10-20T11:00:00Z', 1),
      order('2026-10-20T11:30:00Z', 2),
      order('2026-10-20T11:45:00Z', 4),
      order('2026-10-13T23:59:59Z', 3),
      order('2026-10-20T12:00:01Z', 0),
    ]
    const result = orderTrend(rows, 7, now)
    expect(result.scope).toBe('sample')
    expect(result.scopeLabel).toContain('不代表全站统计')
    expect(result.sampleSize).toBe(7)
    expect(result.includedCount).toBe(5)
    expect(result.excludedCount).toBe(2)
    expect(result.timeZone).toBe('UTC')
    expect(result.points[0]).toEqual({ date: '2026-10-14', label: '10/14', total: 2, completed: 1, pending: 1 })
    expect(result.points[6]).toEqual({ date: '2026-10-20', label: '10/20', total: 3, completed: 0, pending: 1 })
    expect(result.series.map(series => series.id)).toEqual(['total', 'completed', 'pending'])
    expect(result.series[0].values).toEqual([2, 0, 0, 0, 0, 0, 3])
    expect(result.labels).toEqual(result.points.map(point => point.label))
  })

  it.each([7, 14, 30] as const)('fills exactly %i days with zero sample counts without implying complete analytics', days => {
    const result = orderTrend([], days, now)
    expect(result.points).toHaveLength(days)
    expect(result.points.at(-1)?.date).toBe('2026-10-20')
    expect(result.series.every(series => series.values.every(value => value === 0))).toBe(true)
    expect(result.hasData).toBe(false)
    expect(result.sampleSize).toBe(0)
  })

  it('handles month and year boundaries and excludes invalid timestamps', () => {
    const result = orderTrend([order('not-a-date')], 7, new Date('2027-01-02T00:00:00Z'))
    expect(result.points[0].date).toBe('2026-12-27')
    expect(result.points.at(-1)?.date).toBe('2027-01-02')
    expect(result.includedCount).toBe(0)
    expect(result.excludedCount).toBe(1)
    expect(() => orderTrend([], 7, new Date('invalid'))).toThrow(RangeError)
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
    expect(result.series[0].displayValues.slice(0, 3)).toEqual(['3 GB', '6 GB', null])
    expect(result.series[1].displayValues.slice(0, 3)).toEqual(['6 GB', '0 GB', null])
    expect(result.series[0].summary).toBe('9 GB')
    expect(result.formatValue(50)).toBe('3 GB')
  })
  it('retains large decimal totals and sub-GB precision without floating-point loss', () => {
    const raw = '9999999999999999999999999999999999999999'
    const result = nodeTrafficHistory(history([{ id: '1', name: 'Large', points: [{ date: '2026-10-14', total_bytes: raw }] }]))
    expect(result.maxBytes).toBe(raw)
    expect(result.series[0].displayValues[0]).toBe('9999999999999999999999999999999.999999999 GB')
    expect(result.series[0].values[0]).toBe(100)
    expect(gigabytes(1n)).toBe('0.000000001 GB')
    expect(gigabytes(1_250_000_000n)).toBe('1.25 GB')
  })
  it('distinguishes zero from missing and withdraws absent history', () => {
    const result = nodeTrafficHistory(history([{ id: '1', name: 'Zero', points: [{ date: '2026-10-14', total_bytes: '0' }] }]))
    expect(result.series[0].values.slice(0, 2)).toEqual([0, null])
    expect(result.formatValue(100)).toBe('0 GB')
    expect(nodeTrafficHistory().series).toEqual([])
    expect(nodeTrafficHistory().labels).toEqual([])
  })
  it.each(['-1', '1.5', '01', '1e9', ' 1', '9'.repeat(41)])('rejects malformed history bytes: %s', total_bytes => {
    expect(() => nodeTrafficHistory(history([{ id: '1', name: 'Node', points: [{ date: '2026-10-14', total_bytes }] }]))).toThrow(TypeError)
  })
})
