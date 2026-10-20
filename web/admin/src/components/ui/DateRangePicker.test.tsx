import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { DateRangePicker, validDateRange } from './DateRangePicker'

let host: HTMLDivElement, root: Root
const apply = vi.fn()
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host); apply.mockReset()
  act(() => root.render(<DateRangePicker value={{ start_date: '2026-03-01', end_date: '2026-03-08' }} maximum="2026-04-10" onApply={apply} />))
})
afterEach(() => { act(() => root.unmount()); host.remove() })
function click(label: string) {
  const button = [...host.querySelectorAll<HTMLButtonElement>('button')].find(button => button.getAttribute('aria-label') === label || button.textContent === label)!
  act(() => button.click())
}
it('selects inclusive UTC days across months without applying until confirmed, then discards an uncommitted draft', () => {
  click('选择日期范围'); click('2026-03-29'); click('2026-04-02')
  expect(apply).not.toHaveBeenCalled()
  expect(host.querySelector<HTMLInputElement>('[aria-label="开始日期"]')!.value).toBe('2026-03-29')
  expect(host.querySelector<HTMLInputElement>('[aria-label="结束日期"]')!.value).toBe('2026-04-02')
  click('应用日期范围')
  expect(apply).toHaveBeenCalledExactlyOnceWith({ start_date: '2026-03-29', end_date: '2026-04-02' })
  click('选择日期范围'); click('2026-03-15'); click('选择日期范围'); click('选择日期范围')
  expect(host.querySelector<HTMLInputElement>('[aria-label="开始日期"]')!.value).toBe('2026-03-01')
})
it('clears the local selection and blocks applying missing dates; future days remain disabled', () => {
  click('选择日期范围'); click('清除日期'); click('应用日期范围')
  expect(apply).not.toHaveBeenCalled()
  expect(host.querySelector('[role="alert"]')!.textContent).toContain('不超过 366 天')
  expect(host.querySelector<HTMLButtonElement>('[data-date="2026-04-11"]')!.disabled).toBe(true)
  click('2026-03-08'); click('2026-03-07'); click('应用日期范围')
  expect(apply).toHaveBeenCalledExactlyOnceWith({ start_date: '2026-03-07', end_date: '2026-03-08' })
})
it('rejects rollover dates, pre-epoch dates and overlong inclusive ranges while preserving leap days', () => {
  expect(validDateRange({ start_date: '2024-02-29', end_date: '2024-03-01' }, 2, '2026-04-10')).toBe(true)
  for (const value of [{ start_date: '2026-02-30', end_date: '2026-03-01' }, { start_date: '1969-12-31', end_date: '1970-01-01' }, { start_date: '2024-01-01', end_date: '2025-01-01' }])
    expect(validDateRange(value, 366, '2026-04-10')).toBe(false)
})
