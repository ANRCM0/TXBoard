import { act, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { expect, it, vi } from 'vitest'
import { DataTable, type Column } from './DataTable'
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

it('selects only the current page and exposes an indeterminate header state', () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  const host = document.createElement('div'); document.body.append(host)
  const root = createRoot(host)
  const columns = [{ key: 'id', header: 'ID', render: (row: number) => row }]
  function Harness() {
    const [selected, setSelected] = useState<Set<string>>(new Set(['2']))
    return <DataTable rows={[1, 2]} columns={columns} selection={{
      selected, onChange: setSelected, getKey: row => String(row), getLabel: row => '行 ' + row,
    }} />
  }
  act(() => root.render(<Harness />))
  expect(host.querySelector('input[aria-label="选择当前页全部行"]')).not.toBeNull()
  expect(host.querySelectorAll('tbody input[type="checkbox"]')).toHaveLength(2)
  expect(host.querySelector<HTMLInputElement>('input[aria-label="选择当前页全部行"]')!.indeterminate).toBe(true)
  act(() => host.querySelector<HTMLInputElement>('input[aria-label="选择 行 1"]')!.click())
  expect(host.querySelectorAll('tbody input[type="checkbox"]:checked')).toHaveLength(2)
  act(() => host.querySelector<HTMLInputElement>('input[aria-label="选择当前页全部行"]')!.click())
  expect(host.querySelectorAll('tbody input[type="checkbox"]:checked')).toHaveLength(0)
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

it('locks both page and row selection while a populated table refreshes', () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  const host = document.createElement('div'); document.body.append(host)
  const root = createRoot(host), onChange = vi.fn()
  const columns = [{ key: 'id', header: 'ID', width: '120px', render: (row: number) => row }]
  act(() => root.render(<DataTable rows={[42]} columns={columns} loading selection={{ selected: new Set(), onChange, getKey: String }} />))
  expect([...host.querySelectorAll<HTMLInputElement>('input')].every(input => input.disabled)).toBe(true)
  expect(host.querySelector<HTMLTableCellElement>('tbody td:last-child')!.style.width).toBe('120px')
  act(() => root.unmount()); host.remove()
})

it('identifies retained data during refresh and failure, then removes the stale-data description after success', () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  const host = document.createElement('div'); document.body.append(host)
  const root = createRoot(host), onChange = vi.fn(), retry = vi.fn()
  const columns = [{ key: 'id', header: 'ID', render: (row: number) => row }]
  const selection = { selected: new Set<string>(), onChange, getKey: String }
  try {
    act(() => root.render(<DataTable rows={[42]} columns={columns} loading selection={selection} />))
    const status = host.querySelector<HTMLElement>('[role="status"]')!
    expect(status.textContent).toContain('上次加载的数据')
    expect(host.querySelector('[role="region"]')?.getAttribute('aria-describedby')).toBe(status.id)
    act(() => root.render(<DataTable rows={[42]} columns={columns} error onRetry={retry} selection={selection} />))
    expect(host.querySelector('table')?.textContent).toContain('42')
    expect(host.querySelector('[role="alert"]')?.textContent).toContain('更新失败')
    expect([...host.querySelectorAll<HTMLInputElement>('input')].every(node => node.disabled)).toBe(true)
    act(() => host.querySelector<HTMLButtonElement>('.query-feedback button')!.click())
    expect(retry).toHaveBeenCalledOnce()
    expect(onChange).not.toHaveBeenCalled()
    act(() => root.render(<DataTable rows={[43]} columns={columns} selection={selection} />))
    expect(host.querySelector('[role="region"]')?.hasAttribute('aria-describedby')).toBe(false)
    expect(host.querySelector('.query-feedback')).toBeNull()
  } finally { act(() => root.unmount()); host.remove() }
})

it('renders a single mobile card view with shared selection and switches back to the desktop table', () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  const previous = window.matchMedia
  let compact = true, changed = () => {}
  window.matchMedia = vi.fn(() => ({ get matches() { return compact }, addEventListener: (_event: string, listener: () => void) => { changed = listener }, removeEventListener: vi.fn() }) as unknown as MediaQueryList)
  const host = document.createElement('div'); document.body.append(host)
  const root = createRoot(host), action = vi.fn()
  const columns: Column<number>[] = [
    { key: 'identity', header: '编号', render: String, pin: 'left' },
    { key: 'state', header: '状态', render: () => '正常', description: '状态帮助' },
    { key: 'action', header: '管理', pin: 'right', render: () => <button onClick={action}>详情</button> },
  ]
  function Harness() {
    const [selected, onChange] = useState<Set<string>>(new Set())
    return <DataTable rows={[1, 2]} columns={columns} mobilePrimaryKey="identity" selection={{ selected, onChange, getKey: String }} />
  }
  try {
    act(() => root.render(<Harness />))
    expect(host.querySelector('table')).toBeNull()
    expect(host.querySelectorAll('article')).toHaveLength(2)
    expect(host.querySelectorAll('[aria-label="选择当前页全部行"]')).toHaveLength(1)
    act(() => host.querySelector<HTMLInputElement>('[aria-label="选择 1"]')!.click())
    expect(host.querySelector<HTMLInputElement>('[aria-label="选择当前页全部行"]')!.indeterminate).toBe(true)
    act(() => host.querySelector('footer button')!.dispatchEvent(new MouseEvent('click', { bubbles: true })))
    expect(action).toHaveBeenCalledOnce()
    act(() => { compact = false; changed() })
    expect(host.querySelectorAll('table')).toHaveLength(1)
    expect(host.querySelector('article')).toBeNull()
    expect(host.querySelector<HTMLInputElement>('[aria-label="选择 1"]')!.checked).toBe(true)
    expect(host.querySelector<HTMLInputElement>('[aria-label="选择当前页全部行"]')!.indeterminate).toBe(true)
    expect(host.querySelector('th:last-child')?.className).toContain('ui-sticky-last')
    act(() => host.querySelector<HTMLButtonElement>('[aria-label="状态说明"]')!.click())
    expect(host.textContent).toContain('状态帮助')
  } finally { act(() => root.unmount()); host.remove(); window.matchMedia = previous }
})
