import type { ReactNode } from 'react'

export const dash = (value: ReactNode) => value == null || value === '' ? '—' : value
export function formatTimestamp(value: number | null): string {
  if (value == null) return '未上报'
  const date = new Date(value * 1000)
  return Number.isNaN(date.getTime()) ? '未知时间' : date.toLocaleString('zh-CN')
}
