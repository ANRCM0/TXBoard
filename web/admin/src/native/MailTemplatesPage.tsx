import { t, useLocale } from '../lib/i18n'
import './settings-reference.css'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'
import { CopyButton } from '../components/ui/CopyButton'
import { MailPreview } from './MailPreview'
import { requestConfirm } from '../components/ui/ConfirmDialog'
import { mailVariableHelp } from './configurationPresentation'
import { CodeEditor, type CodeEditorHandle } from '../components/ui/CodeEditor'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useAdminConfirmation } from './useAdminConfirmation'

type Name = CoreInput<'notifications.mail-templates.get'>['name']
const denied = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)
export function MailTemplatesPage() {
  const confirm = useAdminConfirmation(() => !blocked)
  useLocale()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const epoch = workflowActions.generation()
  const [name, setName] = useState<Name>('verify'), [subject, setSubject] = useState(''), [content, setContent] = useState(''), [email, setEmail] = useState('')
  const [editing, setEditing] = useState(false), [message, setMessage] = useState(''), [owner, setOwner] = useState(epoch)
  const [preview, setPreview] = useState(false)
  const editor = useRef<HTMLFormElement>(null)
  const source = useRef<CodeEditorHandle>(null)
  const subjectField = useRef<HTMLInputElement>(null)
  const insertionTarget = useRef<'subject' | 'content'>('content')
  const [sample, setSample] = useState(false)
  const baseline = useRef({ subject: '', content: '' })
  useEffect(() => { if (editing) { editor.current?.scrollIntoView?.({ behavior: 'smooth', block: 'start' }); editor.current?.querySelector('input')?.focus({ preventScroll: true }) } }, [editing])
  const view = useRef({ name, epoch, editing }); view.current = { name, epoch, editing }
  const list = useQuery({ queryKey: ['native-admin', 'mail-templates', epoch, 'list'], queryFn: ({ signal }) => admin.read('notifications.mail-templates.list', {}, signal) })
  const detail = useQuery({ queryKey: ['native-admin', 'mail-templates', epoch, name], queryFn: ({ signal }) => admin.read('notifications.mail-templates.get', { name }, signal) })
  const actionError = !state.errorOperation || state.errorOperation.startsWith('notifications.mail-templates.') ? state.error : undefined
  const blocked = denied(list.error) || denied(detail.error) || denied(actionError)
  const ready = owner === epoch && !blocked && !list.isError && !detail.isError && !!list.data && !!detail.data
  const locked = !ready || list.isFetching || detail.isFetching || state.busy || !!state.pending
  const pending = state.pending?.operation.startsWith('notifications.mail-templates.') ? state.pending : undefined
  const dirty = editing && (subject !== baseline.current.subject || content !== baseline.current.content)
  useDirtyDraft(ready && dirty)
  useEffect(() => {
    if (!dirty) return
    const prevent = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', prevent)
    return () => window.removeEventListener('beforeunload', prevent)
  }, [dirty])
  function leaveEditor(next?: Name) {
    if (state.busy) return
    const origin = { name, epoch }
    const leave = () => {
      if (workflowActions.generation() !== origin.epoch || view.current.name !== origin.name) return
      if (next) setName(next)
      else { setEditing(false); setSubject(''); setContent('') }
    }
    if (!dirty) { leave(); return }
    requestConfirm({ title: t("放弃未保存的邮件修改？"), message: t("当前主题或正文尚未保存，继续将丢弃本次修改。"), confirmLabel: t("放弃修改"), cancelLabel: t("继续编辑"), danger: true,
      action: () => { if (view.current.editing) leave() } })
  }
  function insertVariable(variable: string) {
    const isSubject = insertionTarget.current === 'subject'
    if (locked || !editing) return
    const token = '{{' + variable + '}}'
    if (!isSubject) { source.current?.insertText(token); return }
    const textarea = subjectField.current
    if (!textarea || locked || !editing) return
    const text = isSubject ? subject : content
    const start = textarea.selectionStart ?? text.length, end = textarea.selectionEnd ?? text.length
    const next = text.slice(0, start) + token + text.slice(end)
    if (next.length > (isSubject ? 255 : 20000)) { setMessage(isSubject ? '插入后主题将超过 255 字符，请先精简内容' : '插入后正文将超过 20,000 字符，请先精简内容'); return }
    if (isSubject) setSubject(next)
    else setContent(next)
    requestAnimationFrame(() => { textarea.focus(); textarea.setSelectionRange(start + token.length, start + token.length) })
  }
  useEffect(() => { setSubject(''); setContent(''); setEmail(''); setEditing(false); setPreview(false); setSample(false); insertionTarget.current = 'content'; setMessage(''); setOwner(epoch) }, [name, epoch])
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
      const sentTest = action === 'test' || action === 'recover' && pending?.operation === 'notifications.mail-templates.test'
      if (!sentTest) { setEditing(false); setSubject(''); setContent('') }
      setMessage(sentTest ? '测试邮件发送操作已确认' : '操作已确认'); await refresh()
    } catch (error) { if (isCurrent()) setMessage(managementError(error, false)) }
  }
  return <div className="native-management-page settings-reference settings-mail-page">
    <PageHeader title={t("邮件模板")} description={t("管理通知主题与正文，保持每封邮件清晰一致")} action={<button className="button" disabled={state.busy || list.isFetching || detail.isFetching} onClick={() => void refresh()}>{t("刷新模板")}</button>} />
    <ManagementFeedback error={list.isError ? list.error : detail.isError ? detail.error : undefined} loading={list.isFetching || detail.isFetching} onRetry={() => void refresh()} />
    {message || actionError ? <p role="alert">{message ? t(message) : managementError(actionError)}</p> : null}
    {blocked ? <p role="alert">{t("当前无法确认模板访问权限，已清空编辑内容并隐藏数据")}</p> : null}
    {pending ? <section className="list-card settings-pending-card"><h2>{t("操作等待确认")}</h2><p>{t("邮件模板操作的结果尚未确认")}</p><button className="button" disabled={!ready || state.busy || list.isFetching || detail.isFetching} onClick={() => void perform('recover')}>{t("使用原操作确认结果")}</button></section> : null}
    {ready ? <section className="list-card settings-section settings-mail-editor">
      <div className="settings-section-heading"><div><h2>{t("通知模板")}</h2><p>{t("选择邮件场景，维护主题和正文")}</p></div><span className="settings-status">{list.data!.data.find(row => row.name === name)?.customized ? t("自定义模板") : t("默认模板")}</span></div><label className="settings-template-select">{t("模板")}<select value={name} disabled={state.busy} onChange={event => { const next = event.target.value as Name; if (next !== name) leaveEditor(next) }}>{list.data!.data.map(row => <option key={row.name} value={row.name}>{t('{label}（{status}）', { label: t(row.label), status: row.customized ? t('自定义') : t('默认') })}</option>)}</select></label>
      <details className="settings-help"><summary>{t("模板变量与填写说明")}</summary><p>{t("保留必需变量，邮件发送时会自动填入对应内容；点击复制可粘贴到主题或正文；插入按钮使用最近聚焦的输入框")}</p><div className="settings-variable-list">{detail.data!.data.required_vars.map(variable => <code key={variable} title={t("必需变量")}>{variable}<small>{t("必需")}</small><CopyButton value={'{{' + variable + '}}'} label={t("复制变量 ") + variable} />{editing ? <button type="button" className="button" aria-label={t("插入变量 ") + variable} disabled={locked} onMouseDown={event => event.preventDefault()} onClick={() => insertVariable(variable)}>{t("插入")}</button> : null}</code>)}{detail.data!.data.optional_vars.map(variable => <code key={variable} title={t("可选变量")}>{variable}<small>{t("可选")}</small><CopyButton value={'{{' + variable + '}}'} label={t("复制变量 ") + variable} />{editing ? <button type="button" className="button" aria-label={t("插入变量 ") + variable} disabled={locked} onMouseDown={event => event.preventDefault()} onClick={() => insertVariable(variable)}>{t("插入")}</button> : null}</code>)}</div><dl className="settings-variable-help">{[...detail.data!.data.required_vars, ...detail.data!.data.optional_vars].map(variable => <div key={variable}><dt>{variable}</dt><dd>{(mailVariableHelp[variable]?.description ? t(mailVariableHelp[variable].description) : undefined) ?? t("运行时提供的模板变量")}<small>{t("示例：")}{(mailVariableHelp[variable]?.example ? t(mailVariableHelp[variable].example) : undefined) ?? t("由实际发送场景提供")}</small></dd></div>)}</dl></details>
      <div className="settings-mail-workspace" data-preview={preview}><div className="settings-mail-toolbar"><span>{editing ? t("正在编辑源码 · 预览随内容更新") : t("当前已保存的模板")}</span><button type="button" className="button" aria-expanded={preview} aria-controls="mail-template-preview" onClick={() => setPreview(value => !value)}>{preview ? t("收起邮件预览") : t("预览邮件")}</button></div>
      {editing ? <form ref={editor} className="native-content-form settings-mail-form" onSubmit={event => { event.preventDefault(); void perform('save') }}>
        <label>{t("邮件主题")}<input ref={subjectField} value={subject} maxLength={255} required disabled={locked} onFocus={() => { insertionTarget.current = 'subject' }} onChange={event => setSubject(event.target.value)} /></label>
        <div className="settings-source-field" onFocusCapture={() => { insertionTarget.current = 'content' }}><label>{t("模板源码")}</label><CodeEditor ref={source} id="mail-template-content" label={t("模板源码")} language="html" value={content} maxLength={20000} height={450} disabled={locked} onChange={setContent} /></div>
        <div className="settings-form-actions"><span aria-live="polite">{dirty ? t("有未保存的修改") : t("尚未修改")}</span><button className="button primary" disabled={locked || !dirty}>{t("保存模板")}</button><button className="button" type="button" disabled={state.busy} onClick={() => leaveEditor()}>{t("取消编辑")}</button></div>
      </form> : <div className="settings-mail-current"><div className="settings-mail-subject"><small>{t("邮件主题")}</small><strong>{detail.data!.data.subject}</strong></div><pre className="settings-source-preview" tabIndex={0} aria-label={t("当前邮件模板源码")}>{detail.data!.data.content}</pre><div className="settings-mail-read-actions"><button className="button primary" disabled={locked} onClick={() => { baseline.current = { subject: detail.data!.data.subject, content: detail.data!.data.content }; setSubject(baseline.current.subject); setContent(baseline.current.content); setEditing(true) }}>{t("编辑源码")}</button></div></div>}
      {preview ? <div id="mail-template-preview"><label className="settings-sample-toggle"><input type="checkbox" checked={sample} onChange={event => setSample(event.target.checked)} />{t("使用示例变量预览")}</label><MailPreview content={editing ? content : detail.data!.data.content} subject={editing ? subject : detail.data!.data.subject} sample={sample} /></div> : null}
      </div><div className="settings-template-reset"><span>{t("恢复系统默认主题与正文")}</span><button className="button settings-danger-button" disabled={locked} onClick={async () => { if ((await confirm(t("确定删除自定义内容并恢复默认邮件模板？")))) void perform('reset') }}>{t("恢复默认模板")}</button></div>
      <section className="settings-test-mail"><header className="settings-section-heading"><div><h3>{t("发送测试邮件")}</h3><p>{t("测试使用当前已保存的模板")}</p></div></header><form className="native-filters" onSubmit={async event => { event.preventDefault(); if ((await confirm(t("确认向指定地址发送当前已保存模板的测试邮件？")))) void perform('test') }}>
        <label>{t("测试收件人")}<input placeholder={t("留空使用当前管理员邮箱")} type="email" value={email} maxLength={254} disabled={locked} onChange={event => setEmail(event.target.value)} /></label><button className="button" disabled={locked}>{t("发送测试邮件")}</button>
      </form></section>
    </section> : null}
  </div>
}
