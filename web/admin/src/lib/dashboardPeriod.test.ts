import { afterEach, describe, expect, it } from 'vitest'
import {
  customRangeError,
  formatLocalDate,
  getPeriodDates,
  getPeriodTimestamps,
  readDashboardPeriod,
  saveDashboardPeriod,
} from './dashboardPeriod'

afterEach(() => localStorage.clear())

describe('dashboard independent date periods', () => {
  const today = new Date(2026, 9, 8, 16, 35, 0)

  it('uses inclusive calendar days for preset periods', () => {
    expect(getPeriodDates({ preset: 'today' }, today)).toEqual({ start_date: '2026-10-08', end_date: '2026-10-08' })
    expect(getPeriodDates({ preset: '7d' }, today)).toEqual({ start_date: '2026-10-02', end_date: '2026-10-08' })
    expect(getPeriodDates({ preset: '30d' }, today)).toEqual({ start_date: '2026-09-09', end_date: '2026-10-08' })
  })

  it('calculates local day boundaries without UTC or daylight saving drift', () => {
    const custom = { preset: 'custom' as const, start: '2026-09-21', end: '2026-09-25' }
    expect(getPeriodTimestamps(custom, today)).toEqual({
      start_time: Math.floor(new Date(2026, 8, 21).getTime() / 1000),
      end_time: Math.floor(new Date(2026, 8, 25, 23, 59, 59).getTime() / 1000),
    })
    const current = getPeriodTimestamps({ preset: 'today' }, today)
    expect(current.start_time).toBe(Math.floor(new Date(2026, 9, 8).getTime() / 1000))
    expect(current.end_time).toBe(Math.floor(today.getTime() / 1000))
  })

  it('handles year and month boundaries', () => {
    expect(getPeriodDates({ preset: '7d' }, new Date(2026, 0, 3)))
      .toEqual({ start_date: '2025-12-28', end_date: '2026-01-03' })
    expect(formatLocalDate(new Date(2026, 0, 3))).toBe('2026-01-03')
  })

  it('rejects inverted, malformed, future and impossible custom dates', () => {
    expect(customRangeError('2026-10-09', '2026-10-08', '2026-10-08')).toBeTruthy()
    expect(customRangeError('2026-10-01', '2026-10-09', '2026-10-08')).toBeTruthy()
    expect(customRangeError('2026-02-30', '2026-10-08', '2026-10-08')).toBeTruthy()
    expect(customRangeError('2000-01-01', '2026-10-08', '2026-10-08')).toBeTruthy()
    expect(customRangeError('2025-01-01', '2026-10-08', '2026-10-08')).toBe('统计范围不能超过366天')
    expect(customRangeError('2026-10-01', '2026-10-08', '2026-10-08')).toBeNull()
  })

  it('persists each filter independently and ignores invalid stored selections', () => {
    saveDashboardPeriod('income', { preset: '7d' })
    saveDashboardPeriod('user-rank', { preset: 'custom', start: '2026-09-01', end: '2026-09-10' })
    expect(readDashboardPeriod('income', today)).toEqual({ preset: '7d' })
    expect(readDashboardPeriod('user-rank', today)).toEqual({ preset: 'custom', start: '2026-09-01', end: '2026-09-10' })
    expect(readDashboardPeriod('node-rank', today)).toEqual({ preset: '30d' })
    localStorage.setItem('txboard:dashboard:period:income', '{bad')
    expect(readDashboardPeriod('income', today)).toEqual({ preset: '30d' })
  })
})
