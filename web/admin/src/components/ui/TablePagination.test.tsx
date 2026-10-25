import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { TablePagination } from './TablePagination'

let host: HTMLDivElement, root: Root
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
})
afterEach(() => { act(() => root.unmount()); host.remove() })
function input(value: string) {
  const node = host.querySelector('input')!
  act(() => {
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(node, value)
    node.dispatchEvent(new Event('input', { bubbles: true }))
  })
  act(() => node.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })))
}
it('bounds page jumps and uses the backend total for first and last navigation', () => {
  const onPage = vi.fn()
  act(() => root.render(<TablePagination label="用户" page={5} meta={{ total: 401, last_page: 21 }} loading={false} onPage={onPage} />))
  input('22'); input('0'); input('1e1'); input('2.5')
  expect(onPage).not.toHaveBeenCalled()
  expect(host.querySelector('input')!.value).toBe('5')
  input('12')
  expect(onPage).toHaveBeenLastCalledWith(12)
  act(() => host.querySelector<HTMLButtonElement>('[aria-label="最后一页"]')!.click())
  expect(onPage).toHaveBeenLastCalledWith(21)
})
it('withdraws totals and disables every navigation control when authorization is unconfirmed', () => {
  act(() => root.render(<TablePagination label="用户" page={2} loading={false} onPage={vi.fn()} perPage={20} onPageSize={vi.fn()} />))
  expect([...host.querySelectorAll('button, input, select')].every(node => (node as HTMLButtonElement).disabled)).toBe(true)
  expect(host.textContent).not.toContain('共 ')
})
it.each([20, 50, 100])('offers only page sizes admitted by a maximum of %s', maximum => {
  act(() => root.render(<TablePagination label="内容" page={1} meta={{ total: 25, last_page: 2 }} loading={false} onPage={vi.fn()} perPage={20} onPageSize={vi.fn()} maxPageSize={maximum} />))
  const sizes = [...host.querySelectorAll('option')].map(option => Number(option.value))
  expect(sizes.every(size => size <= maximum)).toBe(true)
  expect(sizes.at(-1)).toBe(maximum)
})

it('does not navigate a draft page on blur before another pagination action', () => {
  const onPage = vi.fn()
  act(() => root.render(<TablePagination label="用户" page={5} meta={{ total: 401, last_page: 21 }} loading={false} onPage={onPage} />))
  const node = host.querySelector('input')!
  act(() => {
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(node, '12')
    node.dispatchEvent(new Event('input', { bubbles: true }))
    node.dispatchEvent(new FocusEvent('focusout', { bubbles: true }))
  })
  expect(onPage).not.toHaveBeenCalled()
  act(() => host.querySelector<HTMLButtonElement>('[aria-label="下一页"]')!.click())
  expect(onPage).toHaveBeenCalledExactlyOnceWith(6)
})

it('explains invalid jumps, supports an explicit jump action and cancels drafts with Escape', () => {
  const onPage = vi.fn()
  act(() => root.render(<TablePagination label="订单" page={5} meta={{ total: 401, last_page: 21 }} loading={false} onPage={onPage} />))
  input('22')
  const node = host.querySelector('input')!
  const message = host.querySelector('[role="status"]')!
  expect(message.textContent).toContain('1 至 21')
  expect(node.getAttribute('aria-invalid')).toBe('true')
  expect(node.getAttribute('aria-describedby')).toBe(message.id)
  act(() => {
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(node, '12')
    node.dispatchEvent(new Event('input', { bubbles: true }))
  })
  expect(node.hasAttribute('aria-invalid')).toBe(false)
  act(() => host.querySelector<HTMLButtonElement>('.native-page-jump-submit')!.click())
  expect(onPage).toHaveBeenCalledExactlyOnceWith(12)
  act(() => node.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })))
  expect(node.value).toBe('5')
  expect(host.querySelector('[role="status"]')).toBeNull()
})
