import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useEffect, useRef, useState, useSyncExternalStore, type FormEvent } from 'react'
import type { CoreOutput } from '@txboard/core-sdk'
import { Link } from 'react-router-dom'
import { Modal } from '../components/ui/Modal'
import { Field, Input } from '../components/ui/Field'
import { managementError } from './Feedback'
import { workflowActions } from './session'
import './list-rule-filter.css'

function useDraftGuard(dirty: boolean, navigationActive: boolean) {
  useDirtyDraft(dirty && navigationActive)
  useEffect(() => {
    if (!dirty) return
    const guard = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', guard)
    return () => window.removeEventListener('beforeunload', guard)
  }, [dirty])
  return () => !dirty || window.confirm(t("关闭会丢弃尚未提交的账户资料，确认关闭？"))
}
const emailValid = (email: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim()) && email.trim().length <= 254

export function BatchAccountDialog({ locked, onClose, onCreated }: { locked: boolean; onClose: () => void; onCreated: () => void }) {
  useLocale()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [rows, setRows] = useState([{ email: '', password: '' }]), [error, setError] = useState('')
  const [result, setResult] = useState<CoreOutput<'identity.accounts.batch-create'>>()
  const mounted = useRef(true), generation = workflowActions.generation()
  const draftGeneration = useRef(generation)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => { draftGeneration.current = generation; setRows([{ email: '', password: '' }]); setError(''); setResult(undefined) }, [generation])
  const canClose = useDraftGuard(rows.some(row => !!row.email || !!row.password), !locked && !state.pending && !result && draftGeneration.current === generation)
  function close() { if (!state.busy && canClose()) onClose() }
  async function submit(event: FormEvent) {
    event.preventDefault(); if (locked || state.busy || state.pending) return
    const accounts = rows.map(row => ({ email: row.email.trim().toLowerCase(), password: row.password }))
    if (accounts.some(row => !emailValid(row.email) || row.password.length < 8 || row.password.length > 255) || new Set(accounts.map(row => row.email)).size !== accounts.length) { setError('请输入不同的有效邮箱和 8–255 位密码；整个批次会一起提交。'); return }
    setError('')
    try {
      const response = await workflowActions.runResult('identity.accounts.batch-create', { accounts }) as { data: CoreOutput<'identity.accounts.batch-create'> }
      if (!mounted.current || generation !== workflowActions.generation()) return
      setRows([{ email: '', password: '' }]); setResult(response.data); onCreated()
    } catch (failure) {
      if (!mounted.current || generation !== workflowActions.generation()) return
      setError(managementError(failure, false))
      if (workflowActions.snapshot().pending) { setRows([{ email: '', password: '' }]); onClose() }
    }
  }
  return <Modal open wide title={t("批量创建用户")} subtitle={t("每批最多 20 个原生登录账户；任何冲突都会使整个批次回滚")} onClose={close}>
    {result ? <div role="status"><p>{t("已创建 {count} 个用户。初始密码不再显示。", { count: result.created_count })}</p><ul>{result.items.map(item => <li key={item.id}><Link to={'/user/' + item.id}>{item.email} · #{item.id}</Link></li>)}</ul><button className="button" onClick={onClose}>{t("完成")}</button></div> : <form onSubmit={submit} className="native-content-form">
      <div className="identity-batch-list">{rows.map((row, index) => <div className="identity-batch-row" key={index}>
        <Input type="email" aria-label={t("批量邮箱 {index}", { index: index + 1 })} placeholder="user@example.com" maxLength={254} value={row.email} disabled={state.busy} onChange={event => setRows(current => current.map((item, i) => i === index ? { ...item, email: event.target.value } : item))} />
        <Input type="password" aria-label={t("批量密码 {index}", { index: index + 1 })} placeholder={t("8–255 位初始密码")} autoComplete="new-password" minLength={8} maxLength={255} value={row.password} disabled={state.busy} onChange={event => setRows(current => current.map((item, i) => i === index ? { ...item, password: event.target.value } : item))} />
        <button type="button" className="button" disabled={state.busy || rows.length === 1} aria-label={t("删除账户 {index}", { index: index + 1 })} onClick={() => setRows(current => current.filter((_, i) => i !== index))}>{t("删除")}</button>
      </div>)}</div>
      <button type="button" className="button" disabled={state.busy || rows.length >= 20} onClick={() => setRows(current => [...current, { email: '', password: '' }])}>{t("添加账户（{count}/20）", { count: rows.length })}</button>
      {error ? <p role="alert">{t(error)}</p> : null}<div className="network-form-actions"><button className="button primary" disabled={locked || state.busy || !!state.pending}>{t("创建整个批次")}</button><button type="button" className="button" disabled={state.busy} onClick={close}>{t("取消")}</button></div>
    </form>}
  </Modal>
}

export function AccountProfileEditor({ user, locked, onClose, onChanged }: { user: CoreOutput<'identity.accounts.get'>; locked: boolean; onClose: () => void; onChanged: () => void }) {
  useLocale()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [email, setEmail] = useState(user.email), [password, setPassword] = useState(''), [error, setError] = useState('')
  const mounted = useRef(true), generation = workflowActions.generation()
  const draftScope = useRef(user.id + ':' + user.identity_revision + ':' + generation)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => { draftScope.current = user.id + ':' + user.identity_revision + ':' + generation; setPassword(''); setEmail(user.email); setError('') }, [generation, user.id, user.identity_revision, user.email])
  const canClose = useDraftGuard(email !== user.email || !!password, !locked && !state.pending && !!user.identity_editable && !!user.identity_revision
    && draftScope.current === user.id + ':' + user.identity_revision + ':' + generation)
  function close() { if (!state.busy && canClose()) onClose() }
  async function submit(event: FormEvent) {
    event.preventDefault()
    if (locked || state.busy || state.pending || !user.identity_revision || !user.identity_editable) return
    const normalized = email.trim().toLowerCase()
    if (!emailValid(normalized) || (password && password.length < 8)) { setError('请输入有效邮箱；新密码至少 8 位，留空表示不修改。'); return }
    if (normalized === user.email && !password) { setError('请先修改邮箱或填写新密码。'); return }
    setError('')
    try {
      await workflowActions.run('identity.accounts.profile.update', { user_id: user.id, expected_revision: user.identity_revision,
        ...(normalized !== user.email ? { email: normalized } : {}), ...(password ? { password } : {}) })
      if (!mounted.current || generation !== workflowActions.generation()) return
      setPassword(''); onChanged(); onClose()
    } catch (failure) {
      if (!mounted.current || generation !== workflowActions.generation()) return
      setPassword(''); setError(managementError(failure, false))
      if (workflowActions.snapshot().pending) onClose()
    }
  }
  return <Modal open title={t("编辑登录资料")} subtitle={t("修改邮箱或密码会撤销现有登录会话；代理连接凭据由凭据提供方管理")} onClose={close}>
    <form className="native-content-form" onSubmit={submit}>
      <Field label={t("登录邮箱")} required><Input type="email" value={email} maxLength={254} disabled={locked || state.busy} onChange={event => setEmail(event.target.value)} /></Field>
      <Field label={t("新登录密码")} hint={t("留空保留原密码；不会修改订阅、余额或代理凭据")}><Input type="password" value={password} maxLength={255} minLength={8} autoComplete="new-password" disabled={locked || state.busy} onChange={event => setPassword(event.target.value)} /></Field>
      {error ? <><p role="alert">{t(error)}</p><button type="button" className="button" disabled={state.busy} onClick={() => { if (canClose()) onChanged() }}>{t("重新读取资料")}</button></> : null}<div className="network-form-actions"><button className="button primary" disabled={locked || state.busy || !!state.pending}>{t("保存登录资料")}</button><button type="button" className="button" disabled={state.busy} onClick={close}>{t("取消")}</button></div>
    </form>
  </Modal>
}
