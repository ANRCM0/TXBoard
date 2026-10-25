import { act, useRef } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { useRouteFocus } from '../../lib/useRouteFocus'

let host: HTMLDivElement, root: Root
function Page({ path, title }: { path: string; title?: string }) {
  const main = useRef<HTMLElement>(null), scroll = useRef<HTMLDivElement>(null)
  const announcement = useRouteFocus(path, main, scroll)
  return <><button>Account menu</button><main ref={main} tabIndex={-1}>
    <div ref={scroll}>{title ? <h1>{title}</h1> : <p>Loading</p>}</div>
    <p role="status">{announcement}</p>
  </main></>
}
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  vi.useFakeTimers()
  vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  act(() => root.render(<Page path="/" title="Overview" />))
})
afterEach(() => { act(() => root.unmount()); host.remove(); vi.restoreAllMocks(); vi.useRealTimers() })

it('focuses and announces a lazy heading arriving after the former retry window', async () => {
  act(() => root.render(<Page path="/users" />))
  act(() => vi.advanceTimersByTime(900))
  expect(document.activeElement).toBe(host.querySelector('main'))
  await act(async () => root.render(<Page path="/users" title="Users" />))
  expect(document.activeElement).toBe(host.querySelector('h1'))
  expect(host.querySelector('[role="status"]')?.textContent).toBe('已进入 Users')
})

it('announces the lazy page without taking focus back from a user action', async () => {
  act(() => root.render(<Page path="/users" />))
  const button = host.querySelector('button')!
  act(() => { button.dispatchEvent(new Event('pointerdown', { bubbles: true })); button.focus() })
  await act(async () => root.render(<Page path="/users" title="Users" />))
  expect(document.activeElement).toBe(button)
  expect(host.querySelector('[role="status"]')?.textContent).toBe('已进入 Users')
})

it('cancels the old observation when another route takes over', async () => {
  act(() => root.render(<Page path="/users" />))
  act(() => vi.advanceTimersByTime(900))
  await act(async () => root.render(<Page path="/orders" title="Orders" />))
  act(() => vi.advanceTimersByTime(80))
  expect(host.querySelector('[role="status"]')?.textContent).toBe('已进入 Orders')
  act(() => vi.advanceTimersByTime(10_000))
  expect(host.querySelector('[role="status"]')?.textContent).toBe('已进入 Orders')
})

it('bounds observation when a route never supplies a heading', () => {
  act(() => root.render(<Page path="/users" />))
  act(() => vi.advanceTimersByTime(10_000))
  expect(host.querySelector('[role="status"]')?.textContent).toBe('页面已更新')
  act(() => root.render(<Page path="/users" title="Late heading" />))
  expect(document.activeElement).toBe(host.querySelector('main'))
})

it('ignores a retained hidden title and waits for the new title to become visible', async () => {
  act(() => root.render(<Page path="/users" />))
  const region = host.querySelector('main > div')!
  await act(async () => { region.innerHTML = '<div style="display:none"><h1>Previous page</h1></div><h1 hidden>Users</h1>' })
  act(() => vi.advanceTimersByTime(900))
  expect(host.querySelector('[role="status"]')?.textContent).toBe('')
  await act(async () => region.querySelector<HTMLElement>('h1[hidden]')!.removeAttribute('hidden'))
  expect(document.activeElement?.textContent).toBe('Users')
  expect(host.querySelector('[role="status"]')?.textContent).toBe('已进入 Users')
})
