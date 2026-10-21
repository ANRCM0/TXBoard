import { act, useState } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it } from 'vitest'
import { MarkdownEditor } from './MarkdownEditor'

let host: HTMLDivElement, root: Root
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
})
afterEach(() => { act(() => root.unmount()); host.remove() })
function button(label: string) { return [...host.querySelectorAll<HTMLButtonElement>('button')].find(node => node.getAttribute('aria-label') === label || node.textContent === label)! }
function render(value: string, disabled = false) {
  function Harness() {
    const [text, setText] = useState(value)
    return <MarkdownEditor id="body" value={text} disabled={disabled} onChange={setText} />
  }
  act(() => root.render(<Harness />))
}
it('previews Markdown while escaping raw markup and refusing executable links', () => {
  render('## 维护公告\n\n**新线路**\n\n<img src=x onerror=alert(1)><script>alert(1)</script>\n\n[x](javascript:alert(1))\n\n[帮助](https://example.test/help)')
  act(() => button('分屏').click())
  const preview = host.querySelector('[aria-label="正文预览"]')!
  expect(preview.querySelector('h2')?.textContent).toBe('维护公告')
  expect(preview.querySelector('strong')?.textContent).toBe('新线路')
  expect(preview.querySelector('script, img, [onerror], a[href^="javascript:"]')).toBeNull()
  expect(preview.textContent).toContain('<img')
  expect(preview.querySelector('a')?.getAttribute('href')).toBe('https://example.test/help')
  expect(host.querySelector('textarea')?.value).toContain('<script>')
})
it('formats the selection without submitting a form and keeps source through view changes', () => {
  render('hello world')
  const textarea = host.querySelector('textarea')!
  textarea.setSelectionRange(6, 11)
  act(() => button('加粗').click())
  expect(textarea.value).toBe('hello **world**')
  expect(button('加粗').type).toBe('button')
  act(() => button('预览').click())
  expect(host.querySelector('[aria-label="正文预览"] strong')?.textContent).toBe('world')
  act(() => button('编辑').click())
  expect(textarea.value).toBe('hello **world**')
})
it('retains preview access while disabling formatting and input for a locked write', () => {
  render('read only', true)
  expect(host.querySelector('textarea')?.disabled).toBe(true)
  expect(button('加粗').disabled).toBe(true)
  act(() => button('预览').click())
  expect(host.querySelector('[aria-label="正文预览"]')?.textContent).toContain('read only')
})
