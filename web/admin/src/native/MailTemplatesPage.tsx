import './settings-reference.css'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'

type Name = CoreInput<'notifications.mail-templates.get'>['name']
const denied = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)
export function MailTemplatesPage() {
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const epoch = workflowActions.generation()
  const [name, setName] = useState<Name>('verify'), [subject, setSubject] = useState(''), [content, setContent] = useState(''), [email, setEmail] = useState('')
  const [editing, setEditing] = useState(false), [message, setMessage] = useState(''), [owner, setOwner] = useState(epoch)
  const view = useRef({ name, epoch }); view.current = { name, epoch }
  const list = useQuery({ queryKey: ['native-admin', 'mail-templates', epoch, 'list'], queryFn: ({ signal }) => admin.read('notifications.mail-templates.list', {}, signal) })
  const detail = useQuery({ queryKey: ['native-admin', 'mail-templates', epoch, name], queryFn: ({ signal }) => admin.read('notifications.mail-templates.get', { name }, signal) })
  const blocked = denied(list.error) || denied(detail.error) || denied(state.error)
  const ready = owner === epoch && !blocked && !list.isError && !detail.isError && !!list.data && !!detail.data
  const locked = !ready || list.isFetching || detail.isFetching || state.busy || !!state.pending
  const pending = state.pending?.operation.startsWith('notifications.mail-templates.') ? state.pending : undefined
  useEffect(() => { setSubject(''); setContent(''); setEmail(''); setEditing(false); setMessage(''); setOwner(epoch) }, [name, epoch])
  useEffect(() => { if (blocked) { setSubject(''); setContent(''); setEmail(''); setEditing(false) } }, [blocked])
  async function refresh() {
    const results = await Promise.all([list.refetch(), detail.refetch()])
    if (workflowActions.generation() === epoch && results.every(result => !result.isError)) { workflowActions.clearError(); workflowActions.confirmReadAuthorization('notifications.mail-templates.') }
  }
  async function perform(action: 'save' | 'reset' | 'test' | 'recover') {
    if (state.busy || !ready || list.isFetching || detail.isFetching || action !== 'recover' && !!state.pending) return
    const origin = { name, epoch }
    const isCurrent = () => view.current.name === origin.name && view.current.epoch === origin.epoch && workflowActions.generation() === origin.epoch
    setMessage('')
    try {
      if (action === 'recover' && pending) await workflowActions.run(pending.operation, pending.input)
      else if (action === 'save') await workflowActions.run('notifications.mail-templates.save', { name, subject, content })
      else if (action === 'reset') await workflowActions.run('notifications.mail-templates.reset', { name })
      else if (action === 'test') await workflowActions.run('notifications.mail-templates.test', { name, ...(email ? { email } : {}) })
      if (!isCurrent()) return
      setEditing(false); setSubject(''); setContent(''); setMessage(action === 'test' ? '测试邮件发送操作已确认' : '操作已确认'); await refresh()
    } catch (error) { if (isCurrent()) setMessage(managementError(error)) }
  }
  return <div className="native-management-page settings-reference">
    <PageHeader title="邮件模板" description="管理通知主题与正文，保持每封邮件清晰一致" action={<button className="button" disabled={state.busy || list.isFetching || detail.isFetching} onClick={() => void refresh()}>刷新模板</button>} />
    <ManagementFeedback error={list.isError ? list.error : detail.isError ? detail.error : undefined} loading={list.isFetching || detail.isFetching} onRetry={() => void refresh()} />
    {message || state.error ? <p role="alert">{message || managementError(state.error)}</p> : null}
    {blocked ? <p role="alert">当前无法确认模板访问权限，已清空编辑内容并隐藏数据</p> : null}
    {pending ? <section className="list-card"><h2>操作等待确认</h2><p>邮件模板操作的结果尚未确认</p><button className="button" disabled={!ready || state.busy || list.isFetching || detail.isFetching} onClick={() => void perform('recover')}>使用原操作确认结果</button></section> : null}
    {ready ? <section className="list-card settings-section settings-mail-editor">
      <div className="settings-section-heading"><div><h2>通知模板</h2><p>选择邮件场景，维护主题和正文</p></div><span className="settings-status">{list.data!.data.find(row => row.name === name)?.customized ? '自定义模板' : '默认模板'}</span></div><label className="settings-template-select">模板<select value={name} disabled={state.busy} onChange={event => setName(event.target.value as Name)}>{list.data!.data.map(row => <option key={row.name} value={row.name}>{row.label}（{row.customized ? '自定义' : '默认'}）</option>)}</select></label>
      <details className="settings-help"><summary>模板变量与填写说明</summary><p>保留必需变量，邮件发送时会自动填入对应内容</p><div className="settings-variable-list">{detail.data!.data.required_vars.map(variable => <code key={variable} title="必需变量">{variable}<small>必需</small></code>)}{detail.data!.data.optional_vars.map(variable => <code key={variable} title="可选变量">{variable}<small>可选</small></code>)}</div></details>
      {editing ? <form className="native-content-form settings-mail-form" onSubmit={event => { event.preventDefault(); void perform('save') }}>
        <label>邮件主题<input value={subject} maxLength={255} required disabled={locked} onChange={event => setSubject(event.target.value)} /></label>
        <div className="settings-source-field"><label htmlFor="mail-template-content">模板源码</label><textarea id="mail-template-content" rows={18} value={content} maxLength={20000} required disabled={locked} onChange={event => setContent(event.target.value)} /><small className="settings-character-count">{content.length.toLocaleString()} / 20,000 字符</small></div>
        <div className="settings-form-actions"><span>保存后应用于后续邮件</span><button className="button primary" disabled={locked}>保存模板</button><button className="button" type="button" disabled={state.busy} onClick={() => { setSubject(''); setContent(''); setEditing(false) }}>取消编辑</button></div>
      </form> : <><div className="settings-mail-subject"><small>邮件主题</small><strong>{detail.data!.data.subject}</strong></div><pre className="settings-source-preview">{detail.data!.data.content}</pre><button className="button primary" disabled={locked} onClick={() => { setSubject(detail.data!.data.subject); setContent(detail.data!.data.content); setEditing(true) }}>编辑源码</button></>}
      <button className="button" disabled={locked} onClick={() => { if (window.confirm('确定删除自定义内容并恢复默认邮件模板？')) void perform('reset') }}>恢复默认模板</button>
      <section className="settings-test-mail"><header className="settings-section-heading"><div><h3>发送测试邮件</h3><p>测试使用当前已保存的模板</p></div></header><form className="native-filters" onSubmit={event => { event.preventDefault(); if (window.confirm('确认向指定地址发送当前已保存模板的测试邮件？')) void perform('test') }}>
        <label>测试收件人<input placeholder="留空使用当前管理员邮箱" type="email" value={email} maxLength={254} disabled={locked} onChange={event => setEmail(event.target.value)} /></label><button className="button" disabled={locked}>发送测试邮件</button>
      </form></section>
    </section> : null}
  </div>
}
