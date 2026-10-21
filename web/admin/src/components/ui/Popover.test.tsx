import { act, useState } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { Popover } from './Popover'
import { Modal } from './Modal'
import { ThemeToggle } from './ThemeToggle'

let host: HTMLDivElement, root: Root
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
})
afterEach(() => { act(() => root.unmount()); host.remove(); vi.restoreAllMocks() })
function click(label: string) { act(() => document.querySelector<HTMLButtonElement>(`button[aria-label="${label}"]`)!.click()) }
function escape() { act(() => document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))) }

it('dismisses the child popover before its parent modal and retains focus ownership', () => {
  function Fixture() {
    const [open, setOpen] = useState(true)
    return <Modal open={open} title="父弹窗" onClose={() => setOpen(false)}><Popover label="说明">帮助内容</Popover></Modal>
  }
  act(() => root.render(<Fixture />)); click('说明')
  expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(2)
  expect(document.querySelector('.modal-root')!.contains(document.querySelector('.ui-popover-panel'))).toBe(true)
  escape()
  expect(document.querySelector('.ui-popover-panel')).toBeNull()
  expect(document.querySelector('.modal-root')).not.toBeNull()
  expect(document.activeElement?.getAttribute('aria-label')).toBe('说明')
  expect(document.body.style.overflow).toBe('hidden')
  escape(); expect(document.querySelector('.modal-root')).toBeNull()
})

it('flips upward near the viewport bottom and clamps against the left edge', () => {
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
    return (this.classList.contains('ui-popover-panel') ? { top: 0, left: 0, right: 300, bottom: 200, width: 300, height: 200 }
      : { top: window.innerHeight - 60, left: 10, right: 42, bottom: window.innerHeight - 28, width: 32, height: 32 }) as DOMRect
  })
  act(() => root.render(<Popover label="说明">帮助内容</Popover>)); click('说明')
  const panel = document.querySelector<HTMLElement>('.ui-popover-panel')!
  expect(panel.dataset.side).toBe('top')
  expect(panel.style.left).toBe('12px')
  expect(Number.parseFloat(panel.style.top) + 200).toBeLessThan(window.innerHeight - 60)
})

it('closes the theme picker and restores its trigger after selecting a theme', () => {
  act(() => root.render(<ThemeToggle />)); click('主题设置')
  const choice = [...document.querySelectorAll<HTMLButtonElement>('.ui-theme-options button')].find(button => button.textContent?.includes('浅色模式'))!
  act(() => choice.click())
  expect(document.querySelector('.ui-theme-panel')).toBeNull()
  expect(document.activeElement?.getAttribute('aria-label')).toBe('主题设置')
})

it('dismisses when focus leaves without pulling focus back from the next control', () => {
  act(() => root.render(<><Popover label="说明">帮助内容</Popover><button id="next">下一个</button></>)); click('说明')
  act(() => document.getElementById('next')!.focus())
  expect(document.querySelector('.ui-popover-panel')).toBeNull()
  expect(document.activeElement?.id).toBe('next')
})
