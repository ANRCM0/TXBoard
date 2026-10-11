import { describe, expect, it } from 'vitest'
import { formatTraffic, incomeAreaPath, normalizeIncomePoints } from './dashboardVisuals'

describe('Dashboard v3 chart and row displays', () => {
  it('rejects missing income points rather than inserting imaginary zeroes', () => {
    expect(normalizeIncomePoints([
      {date:'2026-10-01',paid_total:null},
      {date:'2026-10-02',paid_total:''},
      {date:'2026-10-03',paid_total:-3},
      {date:'2026-10-04',paid_total:0},
      {date:'2026-10-05',paid_total:'1250'},
      {date:'',paid_total:500},
    ])).toEqual([
      {date:'2026-10-04',value:0},
      {date:'2026-10-05',value:12.5},
    ])
  })

  it('closes the area at the baseline on both sides and retains the first point', () => {
    expect(incomeAreaPath([{x:10,y:20},{x:30,y:5}],100))
      .toBe('M 10 100 L 10 20 L 30 5 L 30 100 Z')
    expect(incomeAreaPath([],100)).toBe('')
  })

  it('formats small traffic accurately, without falsely showing 0.00 GB', () => {
    expect(formatTraffic(null)).toBe('—')
    expect(formatTraffic('')).toBe('—')
    expect(formatTraffic(-1)).toBe('—')
    expect(formatTraffic(0)).toBe('0 B')
    expect(formatTraffic(0.5)).toBe('0.5 B')
    expect(formatTraffic(300)).toBe('300 B')
    expect(formatTraffic(1024)).toBe('1 KB')
    expect(formatTraffic(1048576)).toBe('1 MB')
    expect(formatTraffic(1073741824)).toBe('1 GB')
  })
})
