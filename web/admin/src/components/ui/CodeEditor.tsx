import { t, useLocale, formatNumber } from '../../lib/i18n'
import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react'
import type * as Monaco from 'monaco-editor/editor'
import { loadLocalCodeEditor } from '../../lib/localCodeEditor'
import './code-editor.css'

export type CodeEditorHandle = { focus(): void; insertText(text: string): void }
export type CodeEditorProps = {
  id?: string
  label: string
  language: 'json' | 'yaml' | 'ini' | 'html' | 'plaintext'
  value: string
  onChange(value: string): void
  disabled?: boolean
  readOnly?: boolean
  height?: number
  maxLength?: number
  theme?: 'light' | 'dark'
}

export const CodeEditor = forwardRef<CodeEditorHandle, CodeEditorProps>(function CodeEditor({
  id, label, language, value, onChange, disabled = false, readOnly = false, height = 450, maxLength, theme,
}, ref) {
  useLocale()
  const host = useRef<HTMLDivElement>(null), fallback = useRef<HTMLTextAreaElement>(null)
  const instance = useRef<Monaco.editor.IStandaloneCodeEditor>()
  const model = useRef<Monaco.editor.ITextModel>()
  const monaco = useRef<typeof Monaco>()
  const current = useRef({ value, onChange, disabled, readOnly, maxLength })
  current.current = { value, onChange, disabled, readOnly, maxLength }
  const [attempt, setAttempt] = useState(0), [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading')
  const [plain, setPlain] = useState(false), [limitError, setLimitError] = useState('')
  const [dark, setDark] = useState(() => document.documentElement.dataset.theme === 'dark')
  useEffect(() => {
    const observer = new MutationObserver(() => setDark(document.documentElement.dataset.theme === 'dark'))
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] })
    return () => observer.disconnect()
  }, [])
  useEffect(() => {
    if (plain) return
    let alive = true
    setStatus('loading')
    let dispose: (() => void) | undefined
    loadLocalCodeEditor().then(api => {
      if (!alive || !host.current) return
      monaco.current = api
      const source = api.editor.createModel(current.current.value, language)
      model.current = source
      const editor = api.editor.create(host.current, { model: source, ariaLabel: label,
        readOnly: current.current.disabled || current.current.readOnly,
        theme: (theme === 'dark' || !theme && dark) ? 'vs-dark' : 'vs',
        automaticLayout: true, minimap: { enabled: false }, fontSize: 14, wordWrap: 'on',
        scrollBeyondLastLine: false, tabSize: 2, links: false, contextmenu: false,
        accessibilitySupport: 'auto', fixedOverflowWidgets: true,
      })
      instance.current = editor
      const changed = source.onDidChangeContent(() => {
        const next = source.getValue()
        if (current.current.maxLength !== undefined && next.length > current.current.maxLength) {
          setLimitError('limit')
          source.setValue(current.current.value)
          return
        }
        if (next !== current.current.value) { setLimitError(''); current.current.onChange(next) }
      })
      dispose = () => { changed.dispose(); editor.dispose(); source.dispose(); if (instance.current === editor) { instance.current = undefined; model.current = undefined } }
      setStatus('ready')
    }).catch(() => { if (alive) setStatus('error') })
    return () => { alive = false; dispose?.() }
    // The model survives ordinary prop changes; draft updates do not recreate it.
  }, [attempt, plain])
  useEffect(() => { if (model.current && model.current.getValue() !== value) model.current.setValue(value) }, [value])
  useEffect(() => { if (model.current && monaco.current) monaco.current.editor.setModelLanguage(model.current, language) }, [language, status])
  useEffect(() => { instance.current?.updateOptions({ readOnly: disabled || readOnly, ariaLabel: label }) }, [disabled, readOnly, label, status])
  useEffect(() => { monaco.current?.editor.setTheme((theme === 'dark' || !theme && dark) ? 'vs-dark' : 'vs') }, [theme, dark, status])
  useImperativeHandle(ref, () => ({
    focus() { if (plain) fallback.current?.focus(); else instance.current?.focus() },
    insertText(text) {
      if (current.current.disabled || current.current.readOnly) return
      const editor = instance.current, input = fallback.current
      if (!plain && editor) {
        const selection = editor.getSelection()
        if (!selection) return
        editor.executeEdits('insert-variable', [{ range: selection, text, forceMoveMarkers: true }]); editor.focus()
      } else if (plain && input) {
        const next = current.current.value.slice(0, input.selectionStart) + text + current.current.value.slice(input.selectionEnd)
        if (current.current.maxLength !== undefined && next.length > current.current.maxLength) return
        const caret = input.selectionStart + text.length
        current.current.onChange(next)
        requestAnimationFrame(() => { input.focus(); input.setSelectionRange(caret, caret) })
      }
    },
  }), [plain])
  return <div className="ui-code-editor" data-editor-state={plain ? 'plain' : status}>
    {!plain && status === 'loading' ? <div className="ui-code-editor-message" role="status" aria-busy="true">{t("正在加载本地代码编辑器…")}</div> : null}
    {!plain && status === 'error' ? <div className="ui-code-editor-message" role="alert"><p>{t("本地编辑器加载失败，源码草稿已保留。")}</p><button className="button" type="button" onClick={() => setAttempt(value => value + 1)}>{t("重新加载编辑器")}</button></div> : null}
    {!plain ? <div ref={host} id={id} className="ui-code-editor-host" style={{ height }} hidden={status !== 'ready'} />
      : <textarea ref={fallback} id={id} aria-label={label} value={value} disabled={disabled} readOnly={readOnly}
        maxLength={maxLength} spellCheck={false} style={{ minHeight: height }} onChange={event => onChange(event.target.value)} />}
    {limitError ? <p role="alert">{t('源码超过 {limit} 字符限制', { limit: formatNumber(maxLength ?? 0) })}</p> : null}
    <footer><span>{formatNumber(value.length)}{maxLength ? ' / ' + formatNumber(maxLength) : ''}{t("字符")}</span><button className="ui-code-editor-mode" type="button" onClick={() => { setPlain(value => !value); setLimitError('') }}>{plain ? t("使用代码编辑器") : t("使用纯文本编辑")}</button></footer>
  </div>
})
