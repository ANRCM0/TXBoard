import { useMutation, useQuery } from '@tanstack/react-query'
import { Braces } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { toast } from 'sonner'
import { fetchSettings, saveSettings } from '../../api/config'
import { ConfigSectionFrame } from '../../components/config/ConfigSectionFrame'
import {
  formatJsonTemplate,
  isValidJsonTemplate,
  normalizeSubscribeTemplates,
  subscribeTemplateTabs,
  type SubscribeTemplateKey,
  type SubscribeTemplateMap,
} from './subscribeTemplateModel'
import './SubscribeTemplatePage.css'

const defaultTemplates = normalizeSubscribeTemplates({})

export function SubscribeTemplatePage() {
  const query = useQuery({
    queryKey: ['settings', 'subscribe_template'],
    queryFn: () => fetchSettings('subscribe_template'),
  })
  const [activeKey, setActiveKey] = useState<SubscribeTemplateKey>(
    subscribeTemplateTabs[0].key,
  )
  const [saved, setSaved] = useState<SubscribeTemplateMap>(defaultTemplates)
  const [draft, setDraft] = useState('')
  const lineNumbersRef = useRef<HTMLPreElement>(null)
  const textareaRef = useRef<HTMLTextAreaElement>(null)

  useEffect(() => {
    if (!query.data) return
    const next = normalizeSubscribeTemplates(query.data)
    setSaved(next)
    setDraft(next[activeKey])
    // The settings request establishes the initial editor snapshot. Tab
    // changes are handled explicitly so switching never silently overwrites a
    // dirty draft.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query.data])

  const activeTab = subscribeTemplateTabs.find(tab => tab.key === activeKey)!
  const dirty = draft !== saved[activeKey]
  const invalidJson =
    activeTab.format === 'json' && !isValidJsonTemplate(draft)
  const lineCount = Math.max(1, draft.split('\n').length)
  const byteSize = new TextEncoder().encode(draft).length

  const lineNumbers = useMemo(
    () => Array.from({ length: lineCount }, (_, index) => index + 1).join('\n'),
    [lineCount],
  )

  const mutation = useMutation({
    mutationFn: ({
      key,
      content,
    }: {
      key: SubscribeTemplateKey
      content: string
    }) => saveSettings({ [key]: content }),
    onSuccess: (_result, variables) => {
      setSaved(current => ({ ...current, [variables.key]: variables.content }))
      toast.success(`${activeTab.label} 订阅模板已保存`)
    },
  })

  function selectTab(nextKey: SubscribeTemplateKey) {
    if (nextKey === activeKey) return

    if (
      dirty &&
      !window.confirm('当前模板有未保存的修改。切换后将丢弃这些修改，是否继续？')
    ) {
      return
    }

    setActiveKey(nextKey)
    setDraft(saved[nextKey])
  }

  function saveCurrent() {
    if (!dirty || mutation.isPending) return
    mutation.mutate({ key: activeKey, content: draft })
  }

  function formatCurrentJson() {
    try {
      setDraft(formatJsonTemplate(draft))
      textareaRef.current?.focus()
    } catch {
      toast.error('当前 Sing-box 模板不是有效的 JSON，无法格式化')
    }
  }

  function onEditorKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
      event.preventDefault()
      saveCurrent()
      return
    }

    if (event.key !== 'Tab') return

    event.preventDefault()
    const input = event.currentTarget
    const start = input.selectionStart
    const end = input.selectionEnd
    const next = draft.slice(0, start) + '  ' + draft.slice(end)
    setDraft(next)

    requestAnimationFrame(() => {
      input.selectionStart = input.selectionEnd = start + 2
    })
  }

  function syncLineNumbers() {
    if (!lineNumbersRef.current || !textareaRef.current) return
    lineNumbersRef.current.scrollTop = textareaRef.current.scrollTop
  }

  const editorStateClass = invalidJson
    ? 'invalid'
    : dirty
      ? 'dirty'
      : ''
  const editorStateText = invalidJson
    ? 'JSON 格式有误，可继续编辑或按原样保存'
    : dirty
      ? '有未保存修改'
      : '已保存'

  return (
    <ConfigSectionFrame
      title="订阅模板"
      description="分别编辑各客户端的原始订阅模板；保存后用于后续订阅输出。"
    >
      {query.isLoading ? (
        <p className="config-frame-loading">加载中…</p>
      ) : query.isError ? (
        <div className="query-feedback is-error">
          <span>订阅模板加载失败</span>
          <button className="button" type="button" onClick={() => query.refetch()}>
            重试
          </button>
        </div>
      ) : (
        <div className="subscribe-template-shell">
          <div className="subscribe-template-tabs-wrap">
            <div
              className="subscribe-template-tabs"
              role="tablist"
              aria-label="订阅模板类型"
            >
              {subscribeTemplateTabs.map(tab => (
                <button
                  type="button"
                  role="tab"
                  key={tab.key}
                  aria-selected={activeKey === tab.key}
                  className={`subscribe-template-tab ${activeKey === tab.key ? 'active' : ''}`}
                  disabled={mutation.isPending}
                  onClick={() => selectTab(tab.key)}
                >
                  {tab.label}
                </button>
              ))}
            </div>
          </div>

          <div className="subscribe-template-editor-head">
            <div className="subscribe-template-editor-title">
              <strong>{activeTab.label} 订阅模板</strong>
              <span>{activeTab.description}</span>
            </div>
            <div className="subscribe-template-editor-actions">
              <span className="subscribe-template-editor-meta">
                {lineCount} 行 · {(byteSize / 1024).toFixed(byteSize >= 1024 ? 1 : 2)} KB
              </span>
              {activeTab.format === 'json' ? (
                <button
                  type="button"
                  className="button"
                  onClick={formatCurrentJson}
                  disabled={!draft.trim() || mutation.isPending}
                >
                  <Braces size={14} />
                  格式化 JSON
                </button>
              ) : null}
            </div>
          </div>

          <div className="subscribe-template-code">
            <pre
              ref={lineNumbersRef}
              className="subscribe-template-lines"
              aria-hidden="true"
            >
              {lineNumbers}
            </pre>
            <textarea
              ref={textareaRef}
              className="subscribe-template-input"
              aria-label={`${activeTab.label} 订阅模板内容`}
              value={draft}
              wrap="off"
              spellCheck={false}
              autoCapitalize="off"
              autoCorrect="off"
              onChange={event => setDraft(event.target.value)}
              onScroll={syncLineNumbers}
              onKeyDown={onEditorKeyDown}
            />
          </div>

          <div className="subscribe-template-footer">
            <span
              className={`subscribe-template-state ${editorStateClass}`}
              aria-live="polite"
            >
              {editorStateText}
              {dirty ? ' · Ctrl/Cmd + S 保存' : ''}
            </span>
            <button
              type="button"
              className="button primary subscribe-template-save"
              disabled={!dirty || mutation.isPending}
              onClick={saveCurrent}
            >
              {mutation.isPending ? '保存中…' : '保存'}
            </button>
          </div>
        </div>
      )}
    </ConfigSectionFrame>
  )
}
