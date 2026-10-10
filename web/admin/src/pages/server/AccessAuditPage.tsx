import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import {
  deleteAccessAuditRule, getAccessAuditEvents, getAccessAuditRules,
  saveAccessAuditRule, type AuditRule, type AuditEvent,
} from '../../api/access-audit'
import { DataTable, type Column } from '../../components/ui/DataTable'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

const MATCH_TYPES = [
  ['domain', '精确域名'],
  ['domain_suffix', '域名后缀'],
  ['keyword', '关键词'],
  ['ip_cidr', 'IP / CIDR'],
] as const

export function AccessAuditPage() {
  const qc = useQueryClient()
  const [tab, setTab] = useState<'events' | 'rules'>('events')
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [keyword, setKeyword] = useState('')
  const [serverId, setServerId] = useState('')
  const [userId, setUserId] = useState('')
  const [onlyMatched, setOnlyMatched] = useState(false)
  const [editor, setEditor] = useState<AuditRule | null>(null)
  const [editing, setEditing] = useState(false)
  const [name, setName] = useState('')
  const [matchType, setMatchType] = useState<AuditRule['match_type']>('domain_suffix')
  const [matchValue, setMatchValue] = useState('')
  const [enabled, setEnabled] = useState(true)
  const rules = useQuery({ queryKey: ['access-audit-rules'], queryFn: getAccessAuditRules })
  const events = useQuery({
    queryKey: ['access-audit-events', page, keyword, serverId, userId, onlyMatched],
    queryFn: () => getAccessAuditEvents({
      page,
      ...(keyword ? { keyword } : {}),
      ...(Number(serverId) > 0 ? { server_id: Number(serverId) } : {}),
      ...(Number(userId) > 0 ? { user_id: Number(userId) } : {}),
      ...(onlyMatched ? { matched: true } : {}),
    }),
    enabled: tab === 'events',
  })
  const save = useMutation({
    mutationFn: saveAccessAuditRule,
    onSuccess: async () => {
      toast.success('审计规则已保存')
      setEditing(false)
      await qc.invalidateQueries({ queryKey: ['access-audit-rules'] })
    },
  })
  const remove = useMutation({
    mutationFn: deleteAccessAuditRule,
    onSuccess: async () => {
      toast.success('规则已删除')
      await qc.invalidateQueries({ queryKey: ['access-audit-rules'] })
    },
  })

  function openEditor(rule?: AuditRule) {
    setEditor(rule || null)
    setName(rule?.name || '')
    setMatchType(rule?.match_type || 'domain_suffix')
    setMatchValue(rule?.match_value || '')
    setEnabled(rule?.enabled ?? true)
    setEditing(true)
  }
  function submitRule() {
    if (!name.trim() || !matchValue.trim()) return toast.error('请填写规则名称与匹配内容')
    save.mutate({
      ...(editor ? { id: editor.id } : {}),
      name: name.trim(), match_type: matchType,
      match_value: matchValue.trim(), enabled,
    })
  }
  function toggle(rule: AuditRule) {
    save.mutate({ ...rule, enabled: !rule.enabled })
  }
  const ruleColumns: Column<AuditRule>[] = [
    { key: 'name', header: '规则名称', render: r => <strong>{r.name}</strong> },
    { key: 'type', header: '匹配方式', render: r => MATCH_TYPES.find(t => t[0] === r.match_type)?.[1] || r.match_type },
    { key: 'value', header: '匹配内容', render: r => <code style={{whiteSpace: 'pre-wrap'}}>{r.match_value}</code> },
    { key: 'enabled', header: '启用', render: r => <button type="button" role="switch" aria-checked={r.enabled} className={`config-switch ${r.enabled ? 'active' : ''}`} onClick={() => toggle(r)} disabled={save.isPending}><span/></button> },
    { key: 'actions', header: '操作', render: r => <div className="table-actions">
      <button className="icon-button" aria-label="编辑审计规则" onClick={() => openEditor(r)}><Pencil size={15}/></button>
      <button className="icon-button danger" aria-label="删除审计规则" onClick={() => requestConfirm({
        title: '删除访问审计规则', message: `确认删除「${r.name}」？删除不会清除已有审计事件。`,
        danger: true, confirmLabel: '删除', action: () => remove.mutate(r.id),
      })}><Trash2 size={15}/></button>
    </div> },
  ]
  const eventColumns: Column<AuditEvent>[] = [
    { key: 'time', header: '访问时间', render: e => new Date(e.created_at * 1000).toLocaleString() },
    { key: 'server', header: '节点', render: e => `#${e.server_id}` },
    { key: 'user', header: '用户', render: e => `#${e.user_id}` },
    { key: 'target', header: '目标', render: e => <div><strong>{e.target}</strong><small className="table-sub">{e.target_ip || '-'}</small></div> },
    { key: 'source', header: '来源 IP', render: e => e.source_ip || '-' },
    { key: 'matched', header: '规则命中', render: e => <span className="badge">{e.matched ? '命中' : '全量采集'}</span> },
  ]
  const applyFilter = () => { setKeyword(search.trim()); setPage(1) }

  return <>
    <PageHeader title="访问审计 · AccessAudit" description="TXBoard 原生节点审计：管理匹配规则、查看审计记录。与管理员操作审计和流量计费互相独立。" action={tab === 'rules' ? <button className="button primary" onClick={() => openEditor()}><Plus size={16}/>添加规则</button> : undefined}/>
    <div className="page-alert">审计记录可能包含用户访问目标与 IP，管理员应仅在需要时开启。原生节点需启用 sing-box 的 audit.enabled；默认关闭。事件保留 30 天，前提是服务器定期运行 Laravel schedule:run。</div>
    <div className="card-actions">
      <button className={`button ${tab === 'events' ? 'primary' : ''}`} onClick={() => setTab('events')}>访问记录</button>
      <button className={`button ${tab === 'rules' ? 'primary' : ''}`} onClick={() => setTab('rules')}>匹配规则</button>
    </div>
    {tab === 'rules' ? (
      <section className="card content-table-card">
        <DataTable rows={rules.data || []} loading={rules.isFetching} error={rules.isError}
          onRetry={() => void rules.refetch()} rowKey={r => r.id} columns={ruleColumns}
          empty="暂无审计规则。audit.report_all=false 时不会收集未匹配的连接。" />
      </section>
    ) : (
      <>
        <div className="server-page-toolbar">
          <div className="server-search"><Search size={15}/><input value={search} onChange={e => setSearch(e.target.value)} onKeyDown={e => e.key === 'Enter' && applyFilter()} placeholder="搜索域名 / 目标…"/></div>
          <input value={serverId} onChange={e => {setServerId(e.target.value);setPage(1)}} placeholder="节点 ID" aria-label="节点 ID" inputMode="numeric"/>
          <input value={userId} onChange={e => {setUserId(e.target.value);setPage(1)}} placeholder="用户 ID" aria-label="用户 ID" inputMode="numeric"/>
          <label><input type="checkbox" checked={onlyMatched} onChange={e => {setOnlyMatched(e.target.checked);setPage(1)}}/> 仅命中规则</label>
          <button className="button" onClick={applyFilter}>查询</button>
        </div>
        <section className="card content-table-card">
          <DataTable rows={events.data?.rows || []} loading={events.isFetching} error={events.isError}
            onRetry={() => void events.refetch()} rowKey={e => e.id} columns={eventColumns}
            empty="暂无访问审计记录" />
          <div className="pagination-bar">
            <span className="pagination-meta">共 {events.data?.total || 0} 条 · 第 {page}/{events.data?.last_page || 1} 页</span>
            <div className="pagination-actions">
              <button className="icon-button" disabled={page <= 1} onClick={() => setPage(p => Math.max(1, p - 1))}><ChevronLeft size={16}/></button>
              <button className="icon-button" disabled={page >= (events.data?.last_page || 1)} onClick={() => setPage(p => p + 1)}><ChevronRight size={16}/></button>
            </div>
          </div>
        </section>
      </>
    )}
    <Modal open={editing} onClose={() => setEditing(false)} title={editor ? '编辑审计规则' : '新增审计规则'}>
      <div className="form-stack">
        <label className="field"><span>规则名称</span><input value={name} maxLength={120} onChange={e => setName(e.target.value)}/></label>
        <label className="field"><span>匹配类型</span><select value={matchType} onChange={e => setMatchType(e.target.value as AuditRule['match_type'])}>
          {MATCH_TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select></label>
        <label className="field"><span>规则值（多条以换行或逗号分隔）</span><textarea rows={5} value={matchValue} maxLength={4000} onChange={e => setMatchValue(e.target.value)}/></label>
        <label className="config-switch-field"><div><strong>启用规则</strong><small>仅启用规则才会下发给节点。</small></div>
          <button type="button" role="switch" aria-checked={enabled} className={`config-switch ${enabled ? 'active' : ''}`} onClick={() => setEnabled(v => !v)}><span/></button></label>
        <div className="card-actions">
          <button className="button" onClick={() => setEditing(false)}>取消</button>
          <button className="button primary" onClick={submitRule} disabled={save.isPending}>保存</button>
        </div>
      </div>
    </Modal>
  </>
}
