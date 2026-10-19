import { useEffect, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { ReadList } from './ReadList'
import { validId, unixDate } from './readPresentation'
import { DataTable, type Column } from '../components/ui/DataTable'
import { PageHeader } from '../components/ui/PageHeader'

type Rule = CoreOutput<'network.access-audit.rules.list'>[number]
type AuditEvent = CoreOutput<'network.access-audit.events.list'>[number]
const blank = () => ({ id: '', name: '', match_type: 'domain' as Rule['match_type'], match_value: '', enabled: true })
const filters = () => ({ server: '', user: '', matched: '', keyword: '' })
export function AccessAuditPage() {
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [draft, setDraft] = useState(blank), [editing, setEditing] = useState(false), [error, setError] = useState('')
  const [page, setPage] = useState(1), [filter, setFilter] = useState(filters), [applied, setApplied] = useState(filters)
  const input: CoreInput<'network.access-audit.events.list'> = { page, per_page: 20,
    ...(applied.server ? { server_id: applied.server } : {}), ...(applied.user ? { user_id: applied.user } : {}),
    ...(applied.matched ? { matched: applied.matched === 'yes' } : {}), ...(applied.keyword ? { keyword: applied.keyword } : {}) }
  const rules = useQuery({ queryKey: ['native-admin', 'network-access-audit', 'rules'], queryFn: ({ signal }) => admin.read('network.access-audit.rules.list', {}, signal) })
  const events = useQuery({ queryKey: ['native-admin', 'network-access-audit', 'events', input], queryFn: ({ signal }) => admin.read('network.access-audit.events.list', input, signal) })
  const pending = state.pending?.operation.startsWith('network.access-audit.') ? state.pending : undefined
  const denied = [rules.error, events.error, state.error].some(value => value instanceof CoreAdminApiError && [401, 403].includes(value.status))
  const locked = denied || state.busy || !!state.pending || rules.isFetching || rules.isError || !rules.data
  useEffect(() => { if (denied) { setEditing(false); setDraft(blank()) } }, [denied])
  async function refresh() {
    const generation = workflowActions.generation()
    try { await Promise.all([rules.refetch({ throwOnError: true }), events.refetch({ throwOnError: true })]); if (generation !== workflowActions.generation()) return; workflowActions.confirmReadAuthorization('network.access-audit.'); workflowActions.clearError(); setError('') } catch { /* Failed authorization remains blocked. */ }
  }
  async function perform(command: () => Promise<void>) {
    const generation = workflowActions.generation(); setError('')
    try { await command(); if (generation !== workflowActions.generation()) return; setEditing(false); setDraft(blank()); await refresh() }
    catch (failure) { if (generation === workflowActions.generation()) setError(managementError(failure)) }
  }
  async function save() {
    if (locked) return
    await perform(() => workflowActions.run('network.access-audit.rules.save', { ...(draft.id ? { id: draft.id } : {}), name: draft.name, match_type: draft.match_type, match_value: draft.match_value, enabled: draft.enabled }))
  }
  async function remove(row: Rule) {
    if (locked || !window.confirm('确定删除此访问审计规则？已有事件记录仍保留。')) return
    await perform(() => workflowActions.run('network.access-audit.rules.delete', { rule_id: row.id }))
  }
  function applyFilters() {
    if (filter.server && !validId(filter.server) || filter.user && !validId(filter.user)) { setError('节点和用户 ID 必须是有效的十进制字符串。'); return }
    setError(''); setPage(1); setApplied({ ...filter })
  }
  const columns: Column<Rule>[] = [
    { key: 'name', header: '规则', render: row => <div className="native-row-identity"><strong>{row.name || '未命名规则'}</strong><small>#{row.id}</small></div> },
    { key: 'match', header: '匹配条件', render: row => <span style={{ overflowWrap: 'anywhere' }}>{row.match_type} · {row.match_value}</span> },
    { key: 'enabled', header: '状态', render: row => row.enabled ? '启用' : '停用' },
    { key: 'actions', header: '操作', render: row => <><button className="button" disabled={locked} onClick={() => { setDraft({ ...row }); setEditing(true) }}>编辑</button><button className="button" disabled={locked} onClick={() => void remove(row)}>删除</button></> },
  ]
  const eventColumns: Column<AuditEvent>[] = [
    { key: 'identity', header: '事件', render: row => <div className="native-row-identity"><strong>#{row.id}</strong><small>节点 #{row.server_id} · 用户 #{row.user_id}</small></div> },
    { key: 'target', header: '目标', render: row => <span style={{ overflowWrap: 'anywhere' }}>{row.target}{row.target_ip ? ' · ' + row.target_ip : ''}</span> },
    { key: 'source', header: '来源 IP', render: row => row.source_ip ?? '未记录' },
    { key: 'matched', header: '规则命中', render: row => row.matched ? '已命中' : '未命中' },
    { key: 'time', header: '时间', render: row => unixDate(row.created_at) },
  ]
  return <div className="native-management-page">
    <PageHeader title="访问审计" description="维护审计规则并按节点、用户和目标读取事件。" action={<><button className="button" disabled={state.busy || rules.isFetching || events.isFetching} onClick={() => void refresh()}>刷新访问审计</button><button className="button" disabled={locked} onClick={() => { setDraft(blank()); setEditing(true) }}>新增审计规则</button></>} />
    {pending ? <section className="list-card" role="status"><h2>审计规则操作等待确认</h2><button className="button" disabled={denied || state.busy || rules.isFetching || rules.isError} onClick={() => void perform(() => workflowActions.run(pending.operation, pending.input))}>确认原审计规则操作</button></section> : null}
    {error || state.error ? <p role="alert">{error || managementError(state.error)}</p> : null}{denied ? <p role="alert">无法确认访问审计权限，已隐藏规则和事件。</p> : null}
    <section className="list-card native-read-list"><h2>审计规则</h2><ManagementFeedback error={rules.error} loading={rules.isFetching} onRetry={() => void refresh()} />{rules.data && !rules.isError && !denied ? <><p className="native-scroll-hint">左右滑动表格查看完整信息</p><DataTable rows={rules.data.data} columns={columns} rowKey={row => row.id} label="访问审计规则" /></> : null}</section>
    {editing && !denied ? <section className="list-card"><h2>{draft.id ? '编辑审计规则' : '新增审计规则'}</h2><p>保存会更新当前配置；多人协作时请先刷新并核对。</p><form className="native-content-form" onSubmit={event => { event.preventDefault(); void save() }}><label>规则名称<input maxLength={120} value={draft.name} disabled={locked} onChange={event => setDraft({ ...draft, name: event.target.value })} /></label><label>匹配类型<select value={draft.match_type} disabled={locked} onChange={event => setDraft({ ...draft, match_type: event.target.value as Rule['match_type'] })}>{['domain', 'domain_suffix', 'keyword', 'ip_cidr'].map(value => <option key={value}>{value}</option>)}</select></label><label>匹配内容<textarea aria-label="审计规则匹配内容" rows={5} maxLength={4000} value={draft.match_value} disabled={locked} onChange={event => setDraft({ ...draft, match_value: event.target.value })} /></label><label><input type="checkbox" checked={draft.enabled} disabled={locked} onChange={event => setDraft({ ...draft, enabled: event.target.checked })} />启用</label><button className="button" disabled={locked}>保存审计规则</button><button className="button" type="button" onClick={() => setEditing(false)}>取消</button></form></section> : null}
    <ReadList title="访问审计事件" rows={denied || events.isError ? [] : events.data?.data ?? []} columns={eventColumns} page={page} meta={events.data?.meta} ready={!!events.data && !denied} loading={events.isFetching} error={events.error} onRetry={() => void refresh()} onPage={setPage}>
      <form className="native-filters" onSubmit={event => { event.preventDefault(); applyFilters() }}><label>节点 ID<input value={filter.server} inputMode="numeric" onChange={event => setFilter({ ...filter, server: event.target.value })} /></label><label>用户 ID<input value={filter.user} inputMode="numeric" onChange={event => setFilter({ ...filter, user: event.target.value })} /></label><label>规则命中<select value={filter.matched} onChange={event => setFilter({ ...filter, matched: event.target.value })}><option value="">全部</option><option value="yes">已命中</option><option value="no">未命中</option></select></label><label>目标关键字<input maxLength={100} value={filter.keyword} onChange={event => setFilter({ ...filter, keyword: event.target.value })} /></label><button className="button">筛选审计事件</button></form>
    </ReadList>
  </div>
}
