import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { expect, it, vi } from 'vitest'
import { BulkActionBar } from './BulkActionBar'

it('exposes current-page selection and its action group without invoking a business action when cleared', () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  const host = document.createElement('div'); document.body.append(host)
  const root = createRoot(host), clear = vi.fn(), write = vi.fn()
  try {
    act(() => root.render(<BulkActionBar count={3} onClear={clear}><button onClick={write}>批量启用</button></BulkActionBar>))
    expect(host.querySelector('[role="region"]')?.getAttribute('aria-label')).toBe('批量操作')
    expect(host.querySelector('[role="status"]')?.textContent).toContain('当前页 3')
    expect(host.querySelector('[role="group"]')?.textContent).toContain('批量启用')
    expect(host.querySelector<HTMLButtonElement>('[aria-label="清除当前页选择"]')!.textContent).toBe('清除当前页选择')
    act(() => host.querySelector<HTMLButtonElement>('[aria-label="清除当前页选择"]')!.click())
    expect(clear).toHaveBeenCalledOnce()
    expect(write).not.toHaveBeenCalled()
    act(() => root.render(<BulkActionBar count={0} onClear={clear}><button onClick={write}>批量启用</button></BulkActionBar>))
    expect(host.querySelector('[role="region"]')).toBeNull()
  } finally { act(() => root.unmount()); host.remove() }
})
