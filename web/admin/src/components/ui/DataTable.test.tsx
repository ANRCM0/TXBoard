import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { expect, it, vi } from 'vitest'
import { DataTable } from './DataTable'
it('distinguishes failure, loading and empty results, and offers retry', () => {
  ;(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true
  const host = document.createElement('div'); document.body.append(host)
  const root = createRoot(host)
  const columns = [{ key: 'id', header: 'ID', render: (row: number) => row }]
  const retry = vi.fn()
  act(() => root.render(<DataTable rows={[]} columns={columns} error onRetry={retry} />))
  expect(host.querySelector('[role="alert"]')).not.toBeNull()
  expect(host.textContent).not.toContain('暂无数据')
  act(() => host.querySelector('button')!.click())
  expect(retry).toHaveBeenCalledOnce()
  act(() => root.render(<DataTable rows={[]} columns={columns} loading />))
  expect(host.querySelector('[role="status"]')?.textContent).toContain('加载')
  expect(host.textContent).not.toContain('暂无数据')
  act(() => root.render(<DataTable rows={[]} columns={columns} />))
  expect(host.textContent).toContain('暂无数据')
  act(() => root.unmount()); host.remove()
})
