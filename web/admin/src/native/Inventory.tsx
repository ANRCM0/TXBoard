import { t, formatTimestamp as localizedTimestamp } from '../lib/i18n'
import type { ReactNode } from 'react'

export const dash = (value: ReactNode) => value == null || value === '' ? '—' : value
export function formatTimestamp(value: number | null): string {
  if (value == null) return t('未上报')
  const date = new Date(value * 1000)
  return Number.isNaN(date.getTime()) ? t('未知时间') : localizedTimestamp(value)
}
