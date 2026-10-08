export type DashboardPeriod =
  | { preset: 'today' | '7d' | '30d' }
  | { preset: 'custom'; start: string; end: string }

export type PeriodDates = { start_date: string; end_date: string }

export const DEFAULT_DASHBOARD_PERIOD: DashboardPeriod = { preset: '30d' }
export const EARLIEST_DASHBOARD_DATE = '2001-09-10'

export function formatLocalDate(date: Date): string {
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
  ].join('-')
}

function isValidDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const [year, month, day] = value.split('-').map(Number)
  return formatLocalDate(new Date(year, month - 1, day)) === value
}

export function customRangeError(start: string, end: string, today = formatLocalDate(new Date())): string | null {
  if (!isValidDate(start) || !isValidDate(end)) return '请选择有效的开始和结束日期'
  if (start < EARLIEST_DASHBOARD_DATE) return '开始日期不能早于 2001-09-10'
  if (start > end) return '开始日期不能晚于结束日期'
  if (end > today) return '结束日期不能晚于今天'
  return null
}

export function getPeriodDates(period: DashboardPeriod, today = new Date()): PeriodDates {
  if (period.preset === 'custom') return { start_date: period.start, end_date: period.end }
  const end = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  const start = new Date(end)
  const days = period.preset === 'today' ? 1 : period.preset === '7d' ? 7 : 30
  start.setDate(start.getDate() - days + 1)
  return { start_date: formatLocalDate(start), end_date: formatLocalDate(end) }
}

function localDay(value: string, endOfDay = false): Date {
  const [year, month, day] = value.split('-').map(Number)
  return endOfDay
    ? new Date(year, month - 1, day, 23, 59, 59)
    : new Date(year, month - 1, day)
}

export function getPeriodTimestamps(period: DashboardPeriod, now = new Date()) {
  const { start_date, end_date } = getPeriodDates(period, now)
  // A past custom range ends at 23:59:59; a range ending today stops at now.
  const end = end_date === formatLocalDate(now) ? now : localDay(end_date, true)
  return {
    start_time: Math.floor(localDay(start_date).getTime() / 1000),
    end_time: Math.floor(end.getTime() / 1000),
  }
}

export function readDashboardPeriod(key: string, today = new Date()): DashboardPeriod {
  try {
    const saved: unknown = JSON.parse(window.localStorage.getItem('txboard:dashboard:period:' + key) || 'null')
    if (!saved || typeof saved !== 'object' || Array.isArray(saved)) return DEFAULT_DASHBOARD_PERIOD
    const value = saved as Record<string, unknown>
    if (value.preset === 'today' || value.preset === '7d' || value.preset === '30d') {
      return { preset: value.preset }
    }
    if (value.preset === 'custom' && typeof value.start === 'string' && typeof value.end === 'string'
      && !customRangeError(value.start, value.end, formatLocalDate(today))) {
      return { preset: 'custom', start: value.start, end: value.end }
    }
  } catch {
    // Storage may be blocked, or contain malformed/obsolete preferences.
  }
  return DEFAULT_DASHBOARD_PERIOD
}

export function saveDashboardPeriod(key: string, period: DashboardPeriod): void {
  try {
    window.localStorage.setItem('txboard:dashboard:period:' + key, JSON.stringify(period))
  } catch {
    // Storage failures must not break dashboard filters.
  }
}
