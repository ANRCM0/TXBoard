import { t, useLocale, formatNumber } from '../lib/i18n'
import './subscription-templates.css'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'
import { CodeEditor } from '../components/ui/CodeEditor'
import { requestConfirm } from '../components/ui/ConfirmDialog'
import { useDirtyDraft } from '../lib/useDirtyDraft'

type Format = CoreInput<'subscription.admin-templates.save'>['format_id']
const formats: { id: Format; label: string; language: 'yaml' | 'json' | 'ini' | 'plaintext'; family: string }[] = [
  { id: 'clash/v1', label: 'Clash', language: 'yaml', family: 'Clash' },
  { id: 'clash-meta/v1', label: 'Clash Meta', language: 'yaml', family: 'Clash Meta' },
  { id: 'sing-box/v1', label: 'Sing-box', language: 'json', family: 'Sing-box' },
  { id: 'stash/v1', label: 'Stash', language: 'yaml', family: 'Stash' },
  { id: 'surfboard/v1', label: 'Surfboard', language: 'ini', family: 'Surfboard' },
  { id: 'surge-ios/v1', label: 'Surge iOS', language: 'ini', family: 'Surge' },
  { id: 'surge-mac/v1', label: 'Surge macOS', language: 'ini', family: 'Surge' },
  { id: 'shadowrocket/v1', label: 'Shadowrocket', language: 'plaintext', family: 'Shadowrocket' },
]
const denied = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)
const prefix = 'subscription.admin-templates.'

export function SubscriptionTemplatesPage() {
  useLocale()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const epoch = workflowActions.generation()
  const [format, setFormat] = useState<Format>('clash/v1')
  const [draft, setDraft] = useState(''), [version, setVersion] = useState(''), [provider, setProvider] = useState('')
  const [editing, setEditing] = useState(false), [owner, setOwner] = useState(epoch), [message, setMessage] = useState('')
  const baseline = useRef('')
  const view = useRef({ format, epoch }); view.current = { format, epoch }
  const list = useQuery({ queryKey: ['native-admin', 'subscription-templates', epoch], queryFn: ({ signal }) => admin.read('subscription.admin-templates.list', {}, signal) })
  const actionError = !state.errorOperation || state.errorOperation.startsWith(prefix) ? state.error : undefined
  const blocked = denied(list.error) || denied(actionError)
  const ready = owner === epoch && !blocked && !list.isError && !!list.data
  const selected = formats.find(row => row.id === format)!
  const row = ready ? list.data!.data.templates.find(item => item.format_id === format) : undefined
  const pending = state.pending?.operation.startsWith(prefix) ? state.pending : undefined
  const locked = !ready || list.isFetching || state.busy || !!state.pending
  const dirty = editing && draft !== baseline.current
  useDirtyDraft(ready && dirty)
  useEffect(() => { setDraft(''); setVersion(''); setEditing(false); setProvider(''); setMessage(''); setOwner(epoch) }, [epoch, format])
  useEffect(() => { if (blocked) { setDraft(''); setVersion(''); setEditing(false); setProvider('') } }, [blocked])
  useEffect(() => {
    if (!dirty) return
    const prevent = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', prevent)
    return () => window.removeEventListener('beforeunload', prevent)
  }, [dirty])
  function leave(next?: Format) {
    if (state.busy) return
    const origin = { format, epoch }
    const apply = () => {
      if (workflowActions.generation() !== origin.epoch || view.current.format !== origin.format) return
      setDraft(''); setVersion(''); setEditing(false)
      if (next) setFormat(next)
    }
    if (!dirty) { apply(); return }
    requestConfirm({ title: t("放弃未保存的订阅模板修改？"), message: t("继续将丢弃当前源码草稿。"), confirmLabel: t("放弃修改"), cancelLabel: t("继续编辑"), danger: true, action: apply })
  }
  async function refresh() {
    const result = await list.refetch()
    if (workflowActions.generation() === epoch && !result.isError) { workflowActions.clearError(); workflowActions.confirmReadAuthorization(prefix) }
  }
  async function perform(action: 'save' | 'reset' | 'recover') {
    if (!ready || !row || list.isFetching || state.busy || action !== 'recover' && state.pending) return
    const origin = { format, epoch }
    const current = () => workflowActions.generation() === origin.epoch && view.current.format === origin.format
    setMessage('')
    try {
      if (action === 'recover' && pending) await workflowActions.run(pending.operation, pending.input)
      else if (action === 'save') {
        if (!editing || !provider || !draft.trim() || new TextEncoder().encode(draft).length > 65536) return
        await workflowActions.run('subscription.admin-templates.save', { format_id: format, expected_version: version, source: draft, provider_id: provider })
      } else if (action === 'reset') await workflowActions.run('subscription.admin-templates.reset', { format_id: format, expected_version: row.version })
      if (!current()) return
      setEditing(false); setDraft(''); setVersion(''); setMessage('模板操作已确认'); await refresh()
    } catch (error) {
      if (!current()) return
      if (error instanceof CoreAdminApiError && error.code === 'SUBSCRIPTION_TEMPLATE_VERSION_CONFLICT' && !error.needsStateConfirmation) {
        setEditing(false); setDraft(''); setVersion('')
        await refresh()
        if (current()) setMessage('模板记录已变化，请重新编辑并核对修改。')
      } else if (error instanceof CoreAdminApiError && error.code === 'SUBSCRIPTION_TEMPLATE_ENGINE_UNSUPPORTED') setMessage('所选节点不支持当前模板引擎，请升级节点或选择其他校验节点。')
      else if (error instanceof CoreAdminApiError && error.code === 'SUBSCRIPTION_TEMPLATE_SOURCE_INVALID') setMessage('节点未通过模板语法或文档格式校验，请检查源码后重试。')
      else setMessage(managementError(error, false))
    }
  }
  return <div className="native-management-page subscription-templates-page">
    <PageHeader title={t("订阅模板")} description={t("为不同客户端维护完整订阅文档")} action={<button className="button" disabled={state.busy || list.isFetching} onClick={() => void refresh()}>{t("刷新模板")}</button>} />
    <ManagementFeedback error={list.isError ? list.error : undefined} loading={list.isFetching} onRetry={() => void refresh()} />
    {message || actionError ? <p role="alert">{message ? t(message) : managementError(actionError)}</p> : null}
    {blocked ? <p role="alert">{t("模板访问权限已失效，编辑内容已清空")}</p> : null}
    {pending ? <section className="list-card"><h2>{t("操作等待确认")}</h2><p>{t("使用原操作确认结果后，再继续编辑。")}</p><button className="button" disabled={!ready || state.busy || list.isFetching} onClick={() => void perform('recover')}>{t("使用原操作确认结果")}</button></section> : null}
    {ready && row ? <section className="list-card subscription-template-card">
      <nav aria-label={t("订阅客户端模板")} className="subscription-template-tabs">{formats.filter(item => item.id !== 'surge-mac/v1').map(item => <button type="button" key={item.id} className={'button' + (selected.family === item.family ? ' active' : '')} aria-pressed={selected.family === item.family} disabled={state.busy} onClick={() => leave(item.id)}>{item.family}</button>)}</nav>
      {selected.family === 'Surge' ? <label>{t("Surge 平台")}<select aria-label={t("Surge 平台")} value={format} disabled={state.busy} onChange={event => leave(event.target.value as Format)}><option value="surge-ios/v1">iOS</option><option value="surge-mac/v1">macOS</option></select></label> : null}
      <header className="subscription-template-heading"><div><h2>{selected.label}</h2><p>{row.custom ? t("已启用自定义模板") : t("使用节点内置默认模板")}</p></div><span>{editing ? dirty ? t("有未保存的修改") : t("正在编辑") : t("已保存")}</span></header>
      <details className="subscription-template-help"><summary>{t("模板语法与示例")}</summary><p>{t("模板使用 Go text/template 语法。支持 if、with、对 .Connections 的 range，以及 json、yaml、quote、urlquery、base64 辅助函数。保存前由所选节点使用合成连接校验，不会生成真实用户预览。")}</p><p><code>{'{{ .Default }}'}</code>{t("输出当前客户端的完整内置订阅文档。")}<code>.Format</code>{t("为目标格式，")}<code>.Connections</code>{t("包含 Name、Node、UUID；数据随每次授权订阅生成。")}</p><pre>{'{{ if .Connections }}\n{{ .Default }}\n{{ end }}'}</pre><p>{t("源码上限 64 KiB。每份文档需符合对应客户端格式；校验不会证明客户端支持所有自定义配置。Surfboard 2.35.2 仅提供受支持的 VMess 配置，Stash 目标版本为 3.4.0。")}</p></details>
      {editing ? <form onSubmit={event => { event.preventDefault(); void perform('save') }}>
        <label>{t("校验节点")}<select value={provider} required disabled={locked} onChange={event => setProvider(event.target.value)}><option value="">{t("请选择校验节点")}</option>{list.data!.data.providers.map(id => <option key={id} value={id}>{id}</option>)}</select></label>
        {!list.data!.data.providers.length ? <p role="status">{t("尚未配置可用节点，请先完成节点连接配置。")}</p> : null}
        <CodeEditor key={format} id="subscription-template-source" label={t('{client} 模板源码', { client: selected.label })} language={selected.language} value={draft} onChange={setDraft} disabled={locked} height={500} maxLength={65536} />
        <div className="subscription-template-actions"><span>{t('{used} / {limit} 字节', { used: formatNumber(new TextEncoder().encode(draft).length), limit: formatNumber(65536) })}</span><button className="button primary" disabled={locked || !dirty || !provider || !draft.trim() || new TextEncoder().encode(draft).length > 65536}>{t("校验并保存")}</button><button type="button" className="button" disabled={state.busy} onClick={() => leave()}>{t("取消编辑")}</button></div>
      </form> : <><pre className="subscription-template-source" tabIndex={0} aria-label={t('{client} 已保存源码', { client: selected.label })}>{row.source || '{{ .Default }}'}</pre><div className="subscription-template-actions"><button className="button primary" disabled={locked} onClick={() => { baseline.current = row.source; setDraft(row.source || '{{ .Default }}'); setVersion(row.version); setEditing(true) }}>{t("编辑模板")}</button><button className="button" disabled={locked || !row.custom} onClick={() => requestConfirm({ title: t("恢复默认订阅模板？"), message: t("将删除当前客户端的自定义源码，并对后续订阅使用节点内置模板。"), confirmLabel: t("恢复默认"), cancelLabel: t("取消"), danger: true, action: () => { if (workflowActions.generation() === epoch && view.current.format === format) void perform('reset') } })}>{t("恢复默认模板")}</button></div></>}
    </section> : null}
  </div>
}
