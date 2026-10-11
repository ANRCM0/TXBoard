import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { describe, expect, it, vi } from 'vitest'
import { ListSurface } from './ListSurface'
import { listHasPages, listSurfaceState } from './listSurfaceState'

describe('shared list surface', () => {
  it('keeps populated data during refresh and treats empty errors as errors', () => {
    expect(listSurfaceState({count:1,loading:true})).toBe('rows')
    expect(listSurfaceState({count:1,error:true})).toBe('rows')
    expect(listSurfaceState({count:0,loading:true})).toBe('loading')
    expect(listSurfaceState({count:0,error:true})).toBe('error')
    expect(listSurfaceState({count:0,placeholder:true})).toBe('loading')
    expect(listSurfaceState({count:0})).toBe('empty')
  })
  it('hides zero and one-page pagination but preserves out-of-range navigation', () => {
    expect(listHasPages(0, 10, 1)).toBe(false)
    expect(listHasPages(1, 10, 1)).toBe(false)
    expect(listHasPages(10, 10, 1)).toBe(false)
    expect(listHasPages(11, 10, 1)).toBe(true)
    expect(listHasPages(0, 10, 2)).toBe(true)
  })
  it('renders a light empty surface, full row content, and pagination only when passed', () => {
    ;(globalThis as {IS_REACT_ACT_ENVIRONMENT?:boolean}).IS_REACT_ACT_ENVIRONMENT = true
    const host = document.createElement('div')
    document.body.append(host)
    const root = createRoot(host)
    const retry = vi.fn()
    const render = (count:number, loading=false, error=false) => {
      act(()=>root.render(<ListSurface count={count} label="测试列表" loading={loading} error={error}
        onRetry={retry} emptyState={<div>没有匹配的记录</div>}
        pagination={<div className="pagination-bar">分页</div>}
      ><table><tbody><tr><td>记录内容</td></tr></tbody></table></ListSurface>))
    }
    render(0)
    expect(host.querySelector('.admin-v2-list-surface.is-empty')).not.toBeNull()
    expect(host.textContent).toContain('没有匹配的记录')
    expect(host.querySelector('table')).toBeNull()
    render(1)
    expect(host.querySelector('table')?.textContent).toContain('记录内容')
    expect(host.querySelector('.pagination-bar')).not.toBeNull()
    render(1, true, true)
    expect(host.querySelector('table')?.textContent).toContain('记录内容')
    expect(host.querySelector('[role="alert"]')).not.toBeNull()
    render(0,true)
    expect(host.querySelector('table')).toBeNull()
    expect(host.textContent).not.toContain('没有匹配的记录')
    render(0,false,true)
    expect(host.querySelector('[role="alert"]')).not.toBeNull()
    expect(host.querySelector('.pagination-bar')).toBeNull()
    act(()=>root.unmount())
    host.remove()
  })
})
