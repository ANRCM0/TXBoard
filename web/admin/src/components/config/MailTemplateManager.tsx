import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { RefreshCw, RotateCcw, Save, Send } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import {
  getMailTemplate,
  listMailTemplates,
  resetMailTemplate,
  saveMailTemplate,
  testMailTemplate,
} from '../../api/mail'

export function MailTemplateManager() {
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['mailTemplates'], queryFn: listMailTemplates })
  const templates = Array.isArray(list.data) ? list.data : []
  const [selected, setSelected] = useState('')
  const [subject, setSubject] = useState('')
  const [content, setContent] = useState('')
  const [testEmail, setTestEmail] = useState('')

  useEffect(() => {
    if (!selected && templates[0]?.name) setSelected(templates[0].name)
  }, [selected, templates])

  const detail = useQuery({
    queryKey: ['mailTemplate', selected],
    queryFn: () => getMailTemplate(selected),
    enabled: Boolean(selected),
  })

  const lastSelected = useRef<string | null>(null)
  useEffect(() => {
    if (!detail.data || lastSelected.current === selected) return
    lastSelected.current = selected
    setSubject(detail.data.subject || '')
    setContent(detail.data.content || '')
  }, [detail.data, selected])

  const save = useMutation({
    mutationFn: () => saveMailTemplate({ name: selected, subject, content }),
    onSuccess: () => {
      toast.success('邮件模板已保存')
      qc.invalidateQueries({ queryKey: ['mailTemplates'] })
      if (detail.data) {
        qc.setQueryData(['mailTemplate', selected], { ...detail.data, subject, content, customized: true })
      }
    },
  })

  const reset = useMutation({
    mutationFn: () => resetMailTemplate(selected),
    onSuccess: () => {
      toast.success('已恢复默认模板')
      lastSelected.current = null
      qc.invalidateQueries({ queryKey: ['mailTemplates'] })
      qc.invalidateQueries({ queryKey: ['mailTemplate', selected] })
    },
  })

  const test = useMutation({
    mutationFn: () => testMailTemplate(selected, testEmail || undefined),
    onSuccess: () => toast.success('测试邮件已发送'),
  })

  const vars = useMemo(() => [
    ...(detail.data?.required_vars || []).map(name => ({ name, required: true })),
    ...(detail.data?.optional_vars || []).map(name => ({ name, required: false })),
  ], [detail.data])

  return <div className="mail-template-layout">
    <aside className="template-list">
      <div className="template-list-head">
        <strong>模板</strong>
        <button className="icon-button" onClick={() => list.refetch()} title="刷新"><RefreshCw size={15}/></button>
      </div>
      {templates.map(item => <button
        key={item.name}
        className={selected === item.name ? 'template-item active' : 'template-item'}
        onClick={() => setSelected(item.name)}
      >
        <span>{item.label}</span>
        <small>{item.customized ? '已自定义' : '默认'}</small>
      </button>)}
      {!templates.length && <div className="template-empty">暂无模板</div>}
    </aside>

    <section className="template-editor">
      {!selected ? <div className="empty-state">请选择模板。</div> : detail.isLoading ? <div className="empty-state">加载模板…</div> : <>
        <div className="template-editor-head">
          <div>
            <h3>{detail.data?.label || selected}</h3>
            <p>{detail.data?.customized ? '当前使用自定义模板' : '当前使用系统默认模板'}</p>
          </div>
          <div className="actions">
            <button className="button" disabled={reset.isPending || !detail.data?.customized} onClick={() => confirm('确认恢复系统默认模板？') && reset.mutate()}><RotateCcw size={15}/>恢复默认</button>
            <button className="button primary" disabled={save.isPending || !subject || !content} onClick={() => save.mutate()}><Save size={15}/>{save.isPending ? '保存中…' : '保存'}</button>
          </div>
        </div>

        <label className="field">
          <span>邮件主题</span>
          <input value={subject} onChange={e => setSubject(e.target.value)}/>
        </label>

        {vars.length > 0 && <div className="template-vars">
          <span className="muted">可用变量：</span>
          {vars.map(item => <code key={item.name} className={item.required ? 'var-pill required' : 'var-pill'}>{'{{'}{item.name}{'}}'}</code>)}
        </div>}

        <label className="field">
          <span>HTML 内容</span>
          <textarea className="template-content" value={content} onChange={e => setContent(e.target.value)} spellCheck={false}/>
          <small className="field-help">模板使用 {'{{variable}}'} 占位符；后端会校验必需变量。</small>
        </label>

        <div className="template-test">
          <input value={testEmail} onChange={e => setTestEmail(e.target.value)} placeholder="测试收件地址（留空则发给当前管理员）"/>
          <button className="button" disabled={test.isPending} onClick={() => test.mutate()}><Send size={15}/>{test.isPending ? '发送中…' : '测试模板'}</button>
        </div>
      </>}
    </section>
  </div>
}
