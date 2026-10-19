import type { CoreOutput } from '@txboard/core-sdk'
import { describe, expect, it } from 'vitest'
import { nodeTrafficBars, nodeTrafficLines, orderStatusShare, orderTrend } from './overviewCharts'

type Order = CoreOutput<'commerce.admin-orders.list'>[number]
type Node = CoreOutput<'network.nodes.list'>[number]
const now = new Date('2026-10-20T12:00:00Z')
const order = (created_at: string, status = 0): Order => ({ created_at, status } as Order)
const node = (id: string, u: string | null, d: string | null): Node => ({ id, name: 'Node ' + id, u, d } as Node)

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

describe('overview sample node counters', () => {
  it('plots upload and download across categorical nodes while preserving exact labels and gaps', () => {
    const rows = Object.freeze([node('1', '9223372036854775807', '4611686018427387903'), node('2', null, '0')])
    const result = nodeTrafficLines(rows)
    expect(result.labels).toEqual(['Node 1', 'Node 2'])
    expect(result.maxBytes).toBe('9223372036854775807')
    expect(result.series[0]).toMatchObject({ id: 'upload', values: [100, null], displayValues: ['9223372036854775807 字节', null] })
    expect(result.series[1]).toMatchObject({ id: 'download', values: [49.99, 0], displayValues: ['4611686018427387903 字节', '0 字节'] })
    expect(result.formatValue(100)).toBe('7.99 EiB')
    expect(result.definition).toContain('不是时间趋势')
    expect(nodeTrafficLines([node('1', null, null)]).hasData).toBe(false)
    expect(nodeTrafficLines([node('1', '0', '0')]).series[0].values).toEqual([0])
    expect(nodeTrafficLines([]).formatValue(0)).toBe('0 B')
  })
  it('normalizes full-precision counters with BigInt while preserving exact labels', () => {
    const large = '9223372036854775807'
    const half = '4611686018427387903'
    const rows = Object.freeze([Object.freeze(node('9007199254740993', large, half)), Object.freeze(node('2', '1', '0'))])
    const result = nodeTrafficBars(rows)
    expect(result.scopeLabel).toContain('不代表全站统计')
    expect(result.maxBytes).toBe(large)
    expect(result.data[0].id).toBe('9007199254740993')
    expect(result.data[0].values[0]).toMatchObject({ percent: 100, basisPoints: 10_000, raw: large, displayValue: large + ' 字节' })
    // Floor the ratio before converting the bounded 0..10000 number, never the raw counter.
    expect(result.data[0].values[1]).toMatchObject({ percent: 49.99, basisPoints: 4999, raw: half })
    expect(result.data[1].values[0]).toMatchObject({ percent: 0, displayValue: '1 字节' })
    expect(rows[0].u).toBe(large)
  })

  it('separates unavailable counters from observed zero and avoids division by zero', () => {
    const result = nodeTrafficBars([node('1', null, '0'), node('2', null, null)])
    expect(result.hasData).toBe(true)
    expect(result.maxBytes).toBe('0')
    expect(result.data[0].missing).toEqual(['upload'])
    expect(result.data[0].missingLabels).toEqual(['上传未上报'])
    expect(result.data[0].raw.upload).toBeNull()
    expect(result.data[0].values).toHaveLength(1)
    expect(result.data[0].values[0]).toMatchObject({ id: 'download', percent: 0, displayValue: '0 字节' })
    expect(result.data[1].values).toEqual([])
    expect(result.data[1].missingLabels).toEqual(['上传未上报', '下载未上报'])
    expect(nodeTrafficBars([node('1', null, null)]).hasData).toBe(false)
    expect(nodeTrafficBars([]).hasData).toBe(false)
  })

  it.each(['-1', '1.5', '01', '1e9', ' 1', 'NaN'])('rejects malformed raw counters: %s', value => {
    expect(() => nodeTrafficBars([node('1', value, '0')])).toThrow(TypeError)
  })
})
