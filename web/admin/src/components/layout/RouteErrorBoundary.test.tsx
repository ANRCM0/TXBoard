import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import { expect, it, vi } from 'vitest'
import { RouteErrorBoundary } from './RouteErrorBoundary'

it('isolates a page render failure and recovers on explicit retry', () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  const error = vi.spyOn(console, 'error').mockImplementation(() => {})
  let broken = true
  function Page() {
    if (broken) throw new Error('synthetic render failure')
    return <h1>恢复后的页面</h1>
  }
  const host = document.createElement('div'); document.body.append(host)
  const root = createRoot(host)
  act(() => root.render(<MemoryRouter><RouteErrorBoundary><Page /></RouteErrorBoundary></MemoryRouter>))
  expect(host.querySelector('[role="alert"]')?.textContent).toContain('页面加载失败')
  broken = false
  act(() => [...host.querySelectorAll<HTMLButtonElement>('button')].find(button => button.textContent === '重试当前页面')!.click())
  expect(host.textContent).toContain('恢复后的页面')
  expect(host.querySelector('[role="alert"]')).toBeNull()
  error.mockRestore()
  act(() => root.unmount()); host.remove()
})
