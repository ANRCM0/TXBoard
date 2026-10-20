import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useListPagination } from './useListPagination'
import { Modal } from '../components/ui/Modal'
import { RefreshCw, Plus, Pencil, Trash2, ShieldCheck } from 'lucide-react'
import './network-reference.css'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { ReadList } from './ReadList'
import { validId, unixDate } from './readPresentation'
import { DataTable, type Column } from '../components/ui/DataTable'
import { PageHeader } from '../components/ui/PageHeader'
import { Checkbox } from '../components/ui/Field'

type Rule = CoreOutput<'network.access-audit.rules.list'>[number]
type AuditEvent = CoreOutput<'network.access-audit.events.list'>[number]
const blank = () => ({ id: '', name: '', match_type: 'domain' as Rule['match_type'], match_value: '', enabled: true })
const matchLabels = { domain: '域名', domain_suffix: '域名后缀', keyword: '关键词', ip_cidr: 'IP 网段' }
const filters = () => ({ server: '', user: '', matched: '', keyword: '' })
export function AccessAuditPage() {
  useLocale()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const generation = workflowActions.generation(), observed = useRef(generation)
  const [draft, setDraft] = useState(blank), [editing, setEditing] = useState(false), [error, setError] = useState('')
  const baseline = useRef(JSON.stringify(blank()))
  const dirty = editing && JSON.stringify(draft) !== baseline.current
  useEffect(() => {
    if (!dirty) return
    const prevent = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', prevent)
    return () => window.removeEventListener('beforeunload', prevent)
  }, [dirty])
  function closeEditor() {
    if (state.busy || dirty && !window.confirm(t("审计规则尚未保存，放弃当前修改？"))) return
    setEditing(false); setDraft(blank())
  }
  function edit(next = blank()) { baseline.current = JSON.stringify(next); setDraft(next); setEditing(true) }
  const { page, setPage, perPage, setPerPage } = useListPagination()
  const [filter, setFilter] = useState(filters), [applied, setApplied] = useState(filters)
  const input: CoreInput<'network.access-audit.events.list'> = { page, per_page: perPage,
    ...(applied.server ? { server_id: applied.server } : {}), ...(applied.user ? { user_id: applied.user } : {}),
    ...(applied.matched ? { matched: applied.matched === 'yes' } : {}), ...(applied.keyword ? { keyword: applied.keyword } : {}) }
  const rules = useQuery({ queryKey: ['native-admin', 'network-access-audit', 'rules', generation], queryFn: ({ signal }) => admin.read('network.access-audit.rules.list', {}, signal) })
  const events = useQuery({ queryKey: ['native-admin', 'network-access-audit', 'events', generation, input], queryFn: ({ signal }) => admin.read('network.access-audit.events.list', input, signal) })
  const pending = state.pending?.operation.startsWith('network.access-audit.') ? state.pending : undefined
  const ownError = state.errorOperation?.startsWith('network.access-audit.') ? state.error : undefined
  const denied = [rules.error, events.error, ownError].some(value => value instanceof CoreAdminApiError && [401, 403].includes(value.status))
  const locked = denied || state.busy || !!state.pending || rules.isFetching || rules.isError || !rules.data
  useDirtyDraft(dirty && !denied && !state.pending && observed.current === generation)
  useEffect(() => { if (observed.current !== generation || denied) { observed.current = generation; setEditing(false); setDraft(blank()); setError('') } }, [generation, denied])
  async function refresh() {
    const generation = workflowActions.generation()
    try { await Promise.all([rules.refetch({ throwOnError: true }), events.refetch({ throwOnError: true })]); if (generation !== workflowActions.generation()) return; workflowActions.confirmReadAuthorization('network.access-audit.'); workflowActions.clearError(); setError('') } catch { /* Failed authorization remains blocked. */ }
  }
  async function perform(command: () => Promise<void>) {
    const generation = workflowActions.generation(); setError('')
    try { await command(); if (generation !== workflowActions.generation()) return; setEditing(false); setDraft(blank()); await refresh() }
    catch (failure) { if (generation === workflowActions.generation()) setError(managementError(failure, false)) }
  }
  async function save() {
    if (locked) return
    await perform(() => workflowActions.run('network.access-audit.rules.save', { ...(draft.id ? { id: draft.id } : {}), name: draft.name, match_type: draft.match_type, match_value: draft.match_value, enabled: draft.enabled }))
  }
  async function remove(row: Rule) {
    if (locked || !window.confirm(t("确定删除此访问审计规则？已有事件记录仍保留。"))) return
    await perform(() => workflowActions.run('network.access-audit.rules.delete', { rule_id: row.id }))
  }
  function applyFilters() {
    if (filter.server && !validId(filter.server) || filter.user && !validId(filter.user)) { setError('节点和用户 ID 必须是有效的十进制字符串。'); return }
    setError(''); setPage(1); setApplied({ ...filter })
  }
  const columns: Column<Rule>[] = [
    { key: 'name', header: t("规则"), render: row => <div className="native-row-identity"><strong>{row.name || t("未命名规则")}</strong><small>#{row.id}</small></div> },
    { key: 'match', header: t("匹配条件"), render: row => <div className="network-match-condition"><span className="network-tag">{t(matchLabels[row.match_type])}</span><code>{row.match_value}</code></div> },
    { key: 'enabled', header: t("状态"), render: row => <span className={'network-tag ' + (row.enabled ? 'is-success' : '')}>{row.enabled ? t("启用") : t("停用")}</span> },
    { key: 'actions', header: t("操作"), pin: 'right', hideable: false, render: row => <><button className="button" disabled={locked} onClick={() => edit({ id: row.id, name: row.name, match_type: row.match_type, match_value: row.match_value, enabled: row.enabled })} title={t("编辑")} aria-label={t("编辑")}><Pencil size={15} aria-hidden="true" /><span className="network-action-label">{t("编辑")}</span></button><button className="button" disabled={locked} onClick={() => void remove(row)} title={t("删除")} aria-label={t("删除")} data-danger="true"><Trash2 size={15} aria-hidden="true" /><span className="network-action-label">{t("删除")}</span></button></> },
  ]
  const eventColumns: Column<AuditEvent>[] = [
    { key: 'identity', header: t("事件"), render: row => <div className="native-row-identity"><strong>#{row.id}</strong><small>{t("节点 #{node} · 用户 #{user}", { node: row.server_id, user: row.user_id })}</small></div> },
    { key: 'target', header: t("目标"), render: row => <div className="network-target"><strong>{row.target}</strong>{row.target_ip ? <code>{row.target_ip}</code> : null}</div> },
    { key: 'source', header: t("来源 IP"), render: row => <code>{row.source_ip ?? t("未记录")}</code> },
    { key: 'matched', header: t("规则命中"), render: row => <span className={'network-tag ' + (row.matched ? 'is-warning' : '')}>{row.matched ? t("已命中") : t("未命中")}</span> },
    { key: 'time', header: t("时间"), render: row => <span className="network-timestamp">{unixDate(row.created_at)}</span> },
  ]
  return <div className="native-management-page network-reference-page network-audit-page">
    <PageHeader title={t("访问审计")} description={t("维护审计规则并按节点、用户和目标读取事件。")} action={<><button className="button" disabled={state.busy || rules.isFetching || events.isFetching} onClick={() => void refresh()}><RefreshCw size={15} aria-hidden="true" />{t("刷新访问审计")}</button><button className="button" data-primary="true" disabled={locked} onClick={() => edit()}><Plus size={15} aria-hidden="true" />{t("新增审计规则")}</button></>} />
    {pending ? <section className="list-card network-recovery-card" role="status"><h2>{t("审计规则操作等待确认")}</h2><button className="button" disabled={denied || state.busy || rules.isFetching || rules.isError} onClick={() => void perform(() => workflowActions.run(pending.operation, pending.input))}>{t("确认原审计规则操作")}</button></section> : null}
    {!editing && (error || ownError) ? <p role="alert">{error ? t(error) : managementError(ownError)}</p> : null}{denied ? <p role="alert">{t("无法确认访问审计权限，已隐藏规则和事件。")}</p> : null}
    <section className="list-card native-read-list"><div className="network-section-title"><ShieldCheck size={18} /><h2>{t("审计规则")}</h2></div><p className="network-help">{t("按域名、关键词或 IP 网段标记访问记录，历史事件会在规则删除后保留。")}</p><ManagementFeedback error={rules.error} loading={rules.isFetching} onRetry={() => void refresh()} />{rules.data && !rules.isError && !denied ? <><p className="native-scroll-hint">{t("左右滑动表格查看完整信息")}</p><DataTable rows={rules.data.data} columns={columns} rowKey={row => row.id} label={t("访问审计规则")} mobilePrimaryKey="name" /></> : null}</section>
    <Modal open={editing && !denied} title={draft.id ? t("编辑审计规则") : t("新增审计规则")} subtitle={t("保存会更新当前配置；多人协作时请先刷新并核对。")} className="network-reference-page network-editor-modal" onClose={closeEditor}><div className="network-editor-body">{error || ownError ? <p role="alert">{error ? t(error) : managementError(ownError)}</p> : null}<form className="native-content-form" onSubmit={event => { event.preventDefault(); void save() }}><label>{t("规则名称")}<input data-autofocus placeholder={t("为规则设置便于识别的名称")} maxLength={120} value={draft.name} disabled={locked} onChange={event => setDraft({ ...draft, name: event.target.value })} /></label><label>{t("匹配类型")}<select value={draft.match_type} disabled={locked} onChange={event => setDraft({ ...draft, match_type: event.target.value as Rule['match_type'] })}>{(['domain', 'domain_suffix', 'keyword', 'ip_cidr'] as const).map(value => <option key={value} value={value}>{t(matchLabels[value])}</option>)}</select></label><label>{t("匹配内容")}<textarea aria-label={t("审计规则匹配内容")} rows={5} maxLength={4000} value={draft.match_value} disabled={locked} onChange={event => setDraft({ ...draft, match_value: event.target.value })} /></label><Checkbox label={t("启用")} checked={draft.enabled} disabled={locked} onChange={event => setDraft({ ...draft, enabled: event.target.checked })} /><div className="network-form-actions"><button className="button" disabled={locked}>{t("保存审计规则")}</button><button className="button" type="button" disabled={state.busy} onClick={closeEditor}>{t("取消")}</button></div></form></div></Modal>
    <ReadList title={"访问审计事件"} rows={denied || events.isError ? [] : events.data?.data ?? []} columns={eventColumns} perPage={perPage} onPageSize={setPerPage} page={page} meta={events.data?.meta} ready={!!events.data && !denied} loading={events.isFetching} error={events.error} mobilePrimaryKey="target" onRetry={() => void refresh()} onPage={setPage}>
      <form className="native-filters network-audit-filters" onSubmit={event => { event.preventDefault(); applyFilters() }}><label>{t("节点 ID")}<input value={filter.server} inputMode="numeric" onChange={event => setFilter({ ...filter, server: event.target.value })} /></label><label>{t("用户 ID")}<input value={filter.user} inputMode="numeric" onChange={event => setFilter({ ...filter, user: event.target.value })} /></label><label>{t("规则命中")}<select value={filter.matched} onChange={event => setFilter({ ...filter, matched: event.target.value })}><option value="">{t("全部")}</option><option value="yes">{t("已命中")}</option><option value="no">{t("未命中")}</option></select></label><label>{t("目标关键字")}<input maxLength={100} value={filter.keyword} onChange={event => setFilter({ ...filter, keyword: event.target.value })} /></label><div className="network-filter-actions"><button className="button" data-primary="true">{t("筛选审计事件")}</button><button type="button" className="button" onClick={() => { setFilter(filters()); setApplied(filters()); setPage(1); setError('') }}>{t("重置筛选")}</button></div></form>
    </ReadList>
  </div>
}
