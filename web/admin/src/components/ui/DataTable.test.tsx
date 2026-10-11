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
  expect(host.querySelector('table')).toBeNull()
  act(() => host.querySelector('button')!.click())
  expect(retry).toHaveBeenCalledOnce()
  act(() => root.render(<DataTable rows={[]} columns={columns} loading />))
  expect(host.querySelector('[role="status"]')?.textContent).toContain('加载')
  expect(host.querySelector('table')).toBeNull()
  expect(host.textContent).not.toContain('暂无数据')
  act(() => root.render(<DataTable rows={[]} columns={columns} />))
  expect(host.textContent).toContain('暂无数据')
  act(() => root.unmount()); host.remove()
})

it('offers an intentional custom empty state while keeping populated tables intact', () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  const host = document.createElement('div'); document.body.append(host)
  const root = createRoot(host)
  const columns = [{ key: 'id', header: 'ID', render: (row: number) => row }]
  act(() => root.render(<DataTable rows={[]} columns={columns} emptyState={<button>创建第一条记录</button>} />))
  expect(host.querySelector('table')).toBeNull()
  expect(host.querySelector('.table-empty-region button')?.textContent).toBe('创建第一条记录')
  act(() => root.render(<DataTable rows={[5]} columns={columns} emptyState={<button>创建第一条记录</button>} />))
  expect(host.querySelector('table')?.textContent).toContain('5')
  expect(host.querySelector('.table-empty-region')).toBeNull()
  act(() => root.unmount()); host.remove()
})

it('preserves populated rows while showing a refresh error, with an accessible list label', () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  const host = document.createElement('div')
  document.body.append(host)
  const root = createRoot(host)
  const columns = [{key:'id',header:'编号',render:(row:number)=>row}]
  act(() => root.render(<DataTable label="节点列表" rows={[42]} columns={columns} error/>))
  expect(host.querySelector('[role="alert"]')).not.toBeNull()
  expect(host.querySelector('[role="region"]')?.getAttribute('aria-label')).toBe('节点列表')
  expect(host.querySelector('table')?.textContent).toContain('42')
  act(() => root.unmount())
  host.remove()
})
