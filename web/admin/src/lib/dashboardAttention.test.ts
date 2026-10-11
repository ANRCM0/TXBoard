import { describe, expect, it } from 'vitest'
import { attentionCount, attentionMode, hasIncomeTrend } from './dashboardAttention'

describe('dashboard operational triage', () => {
  it('never mistakes missing or invalid values for healthy zeros', () => {
    for (const value of [undefined, null, '', ' ', -1, 'NaN', 2.5, Infinity]) {
      expect(attentionCount(value)).toBeNull()
    }
    expect(attentionCount(0)).toBe(0)
    expect(attentionCount('0')).toBe(0)
    expect(attentionCount('3')).toBe(3)
  })
  it('requires all sources to verify a healthy status', () => {
    expect(attentionMode([0, 0, 0, 0])).toBe('clear')
    expect(attentionMode([0, 2, 0, 0])).toBe('attention')
    expect(attentionMode([0, null, 0, 0])).toBe('checking')
    expect(attentionMode([3, null, 0, 0])).toBe('attention')
    expect(attentionMode([])).toBe('checking')
  })
  it('renders guidance for a revenue series with no positive values', () => {
    expect(hasIncomeTrend([])).toBe(false)
    expect(hasIncomeTrend([{ value: 0 }, { value: 0 }])).toBe(false)
    expect(hasIncomeTrend([{ value: NaN }])).toBe(false)
    expect(hasIncomeTrend([{ value: 0 }, { value: 3 }])).toBe(true)
  })
})
