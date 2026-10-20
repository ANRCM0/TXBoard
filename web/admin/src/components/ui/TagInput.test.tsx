import { act, useState } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it } from 'vitest'
import { TagInput } from './TagInput'

let host: HTMLDivElement, root: Root
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
})
afterEach(() => { act(() => root.unmount()); host.remove() })
function type(value: string) {
  const input = host.querySelector('input')!
  act(() => {
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, value)
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })
}
function key(key: string, isComposing = false) { act(() => host.querySelector('input')!.dispatchEvent(new KeyboardEvent('keydown', { key, isComposing, bubbles: true, cancelable: true }))) }
function render(maxItems = 30) {
  function Harness() { const [tags, setTags] = useState(['维护']); return <TagInput id="tags" value={tags} onChange={setTags} maxItems={maxItems} /> }
  act(() => root.render(<Harness />))
}
it('deduplicates tags, supports comma entry and removal, and preserves IME confirmation', () => {
  render()
  type('维护,线路更新'); key('Enter', true)
  expect(host.querySelectorAll('.ui-tag-input-item')).toHaveLength(1)
  key('Enter')
  expect(host.querySelectorAll('.ui-tag-input-item')).toHaveLength(2)
  expect(host.querySelector('input')!.value).toBe('')
  key('Backspace')
  expect(host.querySelectorAll('.ui-tag-input-item')).toHaveLength(1)
  act(() => host.querySelector<HTMLButtonElement>('[aria-label="移除标签 维护"]')!.click())
  expect(host.querySelectorAll('.ui-tag-input-item')).toHaveLength(0)
})
it('keeps overflow text visible and invalid so saving cannot silently discard a tag', () => {
  render(1)
  type('新增标签'); key('Enter')
  expect(host.querySelector('input')!.value).toBe('新增标签')
  expect(host.querySelector('input')!.validity.customError).toBe(true)
  expect(host.querySelector('[role="status"]')?.textContent).toContain('最多添加 1 个')
  act(() => host.querySelector('button')!.click())
  expect(host.querySelector('input')!.validity.customError).toBe(false)
  key('Enter')
  expect(host.querySelector('.ui-tag-input-item')?.textContent).toBe('新增标签')
})

it('accepts long batches without truncating valid individual tags and rejects an overlong tag', () => {
  render()
  expect(host.querySelector('input')!.hasAttribute('maxlength')).toBe(false)
  type('a'.repeat(60) + ',' + 'b'.repeat(60)); key('Enter')
  expect([...host.querySelectorAll('.ui-tag-input-item')].map(node => node.textContent)).toEqual(['维护', 'a'.repeat(60), 'b'.repeat(60)])
  type('c'.repeat(81)); key('Enter')
  expect(host.querySelector('input')!.value).toBe('c'.repeat(81))
  expect(host.querySelector('input')!.validity.customError).toBe(true)
  expect(host.querySelector('[role=status]')?.textContent).toContain('每个标签最多 80')
})
