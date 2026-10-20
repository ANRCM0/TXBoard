import { t, useLocale, formatNumber } from '../../lib/i18n'
import { useMemo, useRef, useState } from 'react'
import { Bold, Code, Heading2, Italic, Link, List, ListOrdered, Quote } from 'lucide-react'
import MarkdownIt from 'markdown-it'
import './markdown-editor.css'

// Content remains Markdown source; raw HTML is escaped in the local preview.
const parser = new MarkdownIt({ html: false, linkify: true, breaks: true })
const formats = [
  { label: '二级标题', icon: Heading2, before: '## ', after: '', sample: '标题', block: true },
  { label: '加粗', icon: Bold, before: '**', after: '**', sample: '加粗文字' },
  { label: '斜体', icon: Italic, before: '*', after: '*', sample: '斜体文字' },
  { label: '引用', icon: Quote, before: '> ', after: '', sample: '引用内容', block: true },
  { label: '无序列表', icon: List, before: '- ', after: '', sample: '列表项', block: true },
  { label: '有序列表', icon: ListOrdered, before: '1. ', after: '', sample: '列表项', block: true },
  { label: '链接', icon: Link, before: '[', after: '](https://example.com)', sample: '链接文字' },
  { label: '代码', icon: Code, before: '`', after: '`', sample: 'code' },
]

export function MarkdownEditor({ id, value, onChange, disabled, required = true, maxLength = 20000 }: {
  id: string; value: string; onChange: (value: string) => void; disabled?: boolean; required?: boolean; maxLength?: number
}) {
  useLocale()
  const [mode, setMode] = useState<'write' | 'split' | 'preview'>('write')
  const input = useRef<HTMLTextAreaElement>(null)
  const html = useMemo(() => mode === 'write' ? '' : parser.render(value), [value, mode])
  function insert(format: typeof formats[number]) {
    const textarea = input.current
    if (!textarea || disabled) return
    const start = textarea.selectionStart, end = textarea.selectionEnd
    const selected = value.slice(start, end) || t(format.sample)
    const leading = format.block && start > 0 && value[start - 1] !== '\n' ? '\n' : ''
    const before = leading + format.before
    const next = value.slice(0, start) + before + selected + format.after + value.slice(end)
    if (next.length > maxLength) return
    onChange(next)
    requestAnimationFrame(() => {
      textarea.focus()
      textarea.setSelectionRange(start + before.length, start + before.length + selected.length)
    })
  }
  return <div className="ui-markdown-editor" data-mode={mode}>
    <div className="ui-markdown-toolbar">
      <div className="ui-markdown-formatting" role="group" aria-label={t("Markdown 格式工具")}>
        {formats.map(format => <button type="button" key={format.label} aria-label={t(format.label)} title={t(format.label)}
          disabled={disabled || mode === 'preview'} onMouseDown={event => event.preventDefault()} onClick={() => insert(format)}><format.icon size={16} /></button>)}
      </div>
      <div className="ui-markdown-modes" role="group" aria-label={t("正文显示模式")}>
        {(['write', 'split', 'preview'] as const).map((value, index) => <button type="button" key={value} aria-pressed={mode === value} onClick={() => setMode(value)}>{[t("编辑"), t("分屏"), t("预览")][index]}</button>)}
      </div>
    </div>
    <div className="ui-markdown-body">
      <textarea ref={input} id={id} placeholder={t("支持 Markdown：标题、列表、链接和代码…")} rows={12} value={value}
        maxLength={maxLength} required={required} disabled={disabled} hidden={mode === 'preview'} onChange={event => onChange(event.target.value)}
        onInvalid={event => {
          if (mode !== 'preview') return
          event.preventDefault()
          setMode('write')
          requestAnimationFrame(() => input.current?.focus())
        }}
        onKeyDown={event => {
          if (event.nativeEvent.isComposing || !(event.ctrlKey || event.metaKey)) return
          const format = event.key.toLowerCase() === 'b' ? formats[1] : event.key.toLowerCase() === 'i' ? formats[2] : undefined
          if (format) { event.preventDefault(); insert(format) }
        }} />
      {mode !== 'write' ? <div className="ui-markdown-preview" role="region" aria-label={t("正文预览")} tabIndex={0}>
        {value ? <div dangerouslySetInnerHTML={{ __html: html }} /> : <p className="ui-markdown-placeholder">{t("输入正文后在此查看预览")}</p>}
      </div> : null}
    </div>
    <div className="ui-markdown-footer"><span>{t("Markdown · 预览不执行 HTML")}</span><span>{formatNumber(value.length)} / {formatNumber(maxLength)}</span></div>
  </div>
}
