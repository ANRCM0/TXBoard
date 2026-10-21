import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { ThemeToggle } from './ThemeToggle'

let host: HTMLDivElement, root: Root, media: { matches: boolean; addEventListener: ReturnType<typeof vi.fn>; removeEventListener: ReturnType<typeof vi.fn> }
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  localStorage.removeItem('theme')
  media = { matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }
  vi.stubGlobal('matchMedia', () => media)
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  act(() => root.render(<ThemeToggle />))
  act(() => host.querySelector('button')!.click())
})
afterEach(() => { act(() => root.unmount()); host.remove(); vi.restoreAllMocks(); vi.unstubAllGlobals(); localStorage.removeItem('theme') })
function choose(text: string) { act(() => [...host.querySelectorAll('button')].find(button => button.textContent === text)!.click()) }
it('tracks system color changes only while following the system and persists an explicit preference', () => {
  expect(document.documentElement.dataset.theme).toBe('light')
  const update = media.addEventListener.mock.calls[0][1] as () => void
  act(() => { media.matches = true; update() })
  expect(document.documentElement.dataset.theme).toBe('dark')
  choose('浅色模式')
  expect(host.querySelector('[role="dialog"]')).toBeNull()
  expect(document.activeElement).toBe(host.querySelector('button'))
  expect(localStorage.getItem('theme')).toBe('light')
  expect(document.documentElement.dataset.theme).toBe('light')
  act(() => update())
  expect(document.documentElement.dataset.theme).toBe('light')
  act(() => host.querySelector('button')!.click())
  choose('跟随系统')
  expect(document.documentElement.dataset.theme).toBe('dark')
})
it('keeps the requested appearance even when browser storage is blocked', () => {
  vi.spyOn(localStorage, 'setItem').mockImplementation(() => { throw new Error('blocked') })
  choose('深色模式')
  expect(document.documentElement.dataset.theme).toBe('dark')
})
