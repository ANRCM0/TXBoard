/** Missing or failed statistics must not appear as zero. */
export function attentionCount(value: unknown): number | null {
  if (typeof value !== 'number' && typeof value !== 'string') return null
  if (typeof value === 'string' && !value.trim()) return null
  const n = Number(value)
  return Number.isFinite(n) && n >= 0 && Number.isInteger(n) ? n : null
}

export type AttentionMode = 'clear' | 'attention' | 'checking'

export function attentionMode(counts: readonly (number | null)[]): AttentionMode {
  if (counts.some(value => value !== null && value > 0)) return 'attention'
  if (!counts.length || counts.some(value => value === null)) return 'checking'
  return 'clear'
}

export function hasIncomeTrend(points: readonly { value: number }[]): boolean {
  return points.some(point => Number.isFinite(point.value) && point.value > 0)
}
