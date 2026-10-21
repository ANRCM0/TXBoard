import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CopyButton } from './CopyButton'

let host: HTMLDivElement, root: Root
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
})
afterEach(() => { act(() => root.unmount()); host.remove(); vi.unstubAllGlobals() })

it('copies the complete string and provides explicit success feedback', async () => {
  const writeText = vi.fn().mockResolvedValue(undefined)
  vi.stubGlobal('navigator', { clipboard: { writeText } })
  act(() => root.render(<CopyButton value="90071992547409931234" label="复制编号" />))
  expect(writeText).not.toHaveBeenCalled()
  await act(async () => host.querySelector('button')!.click())
  expect(writeText).toHaveBeenCalledExactlyOnceWith('90071992547409931234')
  expect(host.querySelector('[role=status]')?.textContent).toBe('已复制')
})

it('reports a denied clipboard write without claiming success', async () => {
  vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } })
  act(() => root.render(<CopyButton value="private@example.test" />))
  await act(async () => host.querySelector('button')!.click())
  expect(host.querySelector('[role=status]')?.textContent).toContain('复制失败')
  expect(host.querySelector('button')!.disabled).toBe(false)
})
