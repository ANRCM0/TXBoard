import { act, createRef } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CodeEditor, type CodeEditorHandle } from './CodeEditor'
import { loadLocalCodeEditor } from '../../lib/localCodeEditor'

vi.mock('../../lib/localCodeEditor', () => ({ loadLocalCodeEditor: vi.fn() }))
let host: HTMLDivElement, root: Root
let text: string, changed: (() => void) | undefined
const change = vi.fn()
const source = { getValue: () => text, setValue: vi.fn((value: string) => { text = value; changed?.() }),
  onDidChangeContent: vi.fn((callback: () => void) => { changed = callback; return { dispose: vi.fn() } }), dispose: vi.fn() }
const editor = { getSelection: vi.fn(() => ({ startLineNumber: 1, startColumn: 1, endLineNumber: 1, endColumn: 1 })),
  executeEdits: vi.fn((_name, edits) => { text = edits[0].text + text; changed?.() }), focus: vi.fn(), updateOptions: vi.fn(), dispose: vi.fn() }
const api = { editor: { createModel: vi.fn((value: string) => { text = value; return source }), create: vi.fn(() => editor), setModelLanguage: vi.fn(), setTheme: vi.fn() } }

beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  vi.clearAllMocks(); text = ''; changed = undefined
  vi.mocked(loadLocalCodeEditor).mockResolvedValue(api as never)
})
afterEach(() => { act(() => root.unmount()); host.remove() })
async function render(value = 'original', extra = {}) {
  await act(async () => root.render(<CodeEditor label="模板源码" language="html" value={value} onChange={change} {...extra} />))
}
async function click(text: string) {
  await act(async () => [...host.querySelectorAll('button')].find(button => button.textContent === text)!.click())
}

it('creates a local model once, updates props in place and disposes both resources', async () => {
  await render()
  expect(api.editor.create).toHaveBeenCalledTimes(1)
  expect(host.querySelector('[data-editor-state=ready]')).not.toBeNull()
  await render('external', { disabled: true, language: 'json' })
  expect(api.editor.create).toHaveBeenCalledTimes(1)
  expect(source.getValue()).toBe('external')
  expect(change).not.toHaveBeenCalled()
  expect(editor.updateOptions).toHaveBeenLastCalledWith({ readOnly: true, ariaLabel: '模板源码' })
  expect(api.editor.setModelLanguage).toHaveBeenLastCalledWith(source, 'json')
  await act(async () => root.render(null))
  expect(editor.dispose).toHaveBeenCalledTimes(1)
  expect(source.dispose).toHaveBeenCalledTimes(1)
})

it('shows an explicit local loading failure, retries and never discards a draft', async () => {
  vi.mocked(loadLocalCodeEditor).mockRejectedValueOnce(new Error('local chunk unavailable'))
  await render('unsaved {{ .Default }}')
  expect(host.textContent).toContain('源码草稿已保留')
  expect(api.editor.create).not.toHaveBeenCalled()
  await click('重新加载编辑器')
  expect(api.editor.createModel).toHaveBeenLastCalledWith('unsaved {{ .Default }}', 'html')
  expect(vi.mocked(loadLocalCodeEditor)).toHaveBeenCalledTimes(2)
})

it('offers deliberate plain text editing during loading and ignores a late load after disposal', async () => {
  let finish!: (value: never) => void
  vi.mocked(loadLocalCodeEditor).mockReturnValue(new Promise(resolve => { finish = resolve }))
  await render('draft')
  expect(host.querySelector('[aria-busy=true]')).not.toBeNull()
  await click('使用纯文本编辑')
  const input = host.querySelector('textarea')!
  expect(input.value).toBe('draft')
  await act(async () => { finish(api as never) })
  expect(api.editor.create).not.toHaveBeenCalled()
  expect(host.querySelector('textarea')!.value).toBe('draft')
})

it('bounds pasted code and prevents programmatic insertion into a disabled editor', async () => {
  const ref = createRef<CodeEditorHandle>()
  await render('saved', { maxLength: 8, ref })
  await act(async () => { text = 'too much pasted content'; changed?.() })
  expect(text).toBe('saved')
  expect(host.textContent).toContain('源码超过 8 字符限制')
  expect(change).not.toHaveBeenCalled()
  await render('saved', { maxLength: 8, disabled: true, ref })
  act(() => ref.current!.insertText('x'))
  expect(editor.executeEdits).not.toHaveBeenCalled()
  await render('saved', { maxLength: 8, disabled: false, ref })
  act(() => ref.current!.insertText('x'))
  expect(change).toHaveBeenCalledExactlyOnceWith('xsaved')
})
