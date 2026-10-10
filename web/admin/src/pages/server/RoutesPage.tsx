import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Pencil, Play, Plus, Search, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { toast } from 'sonner'
import {
  deleteRoute,
  getNodes,
  getRoutes,
  saveRoute,
  simulateRoute,
  sortRoutes,
  type RouteItem,
} from '../../api/server'
import { DataTable, type Column } from '../../components/ui/DataTable'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

export function RoutesPage() {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<RouteItem | null>(null)
  const [remarks, setRemarks] = useState('')
  const [match, setMatch] = useState('')
  const [action, setAction] = useState<'block' | 'direct' | 'dns' | 'proxy'>('block')
  const [value, setValue] = useState('')
  const [enabled, setEnabled] = useState(true)
  const [search, setSearch] = useState('')
  const [simNodeId, setSimNodeId] = useState('')
  const [simTarget, setSimTarget] = useState('')

  const query = useQuery({ queryKey: ['routes'], queryFn: getRoutes })
  const nodesQuery = useQuery({ queryKey: ['nodes'], queryFn: getNodes, staleTime: 30_000 })

  const save = useMutation({
    mutationFn: (payload: Partial<RouteItem>) => saveRoute(payload),
    onSuccess: async () => {
      toast.success(open ? (editing ? '路由规则已更新' : '路由规则已创建') : '路由规则状态已更新')
      if (open) closeEditor()
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['routes'] }),
        qc.invalidateQueries({ queryKey: ['nodes'] }),
      ])
    },
  })

  const remove = useMutation({
    mutationFn: deleteRoute,
    onSuccess: async () => {
      toast.success('路由规则已删除')
      await qc.invalidateQueries({ queryKey: ['routes'] })
    },
  })

  const reorder = useMutation({
    mutationFn: sortRoutes,
    onSuccess: async () => {
      toast.success('路由优先级已更新')
      await qc.invalidateQueries({ queryKey: ['routes'] })
    },
  })

  const simulator = useMutation({
    mutationFn: () => simulateRoute(Number(simNodeId), simTarget.trim()),
  })

  const rows = Array.isArray(query.data) ? query.data : []
  const nodes = Array.isArray(nodesQuery.data) ? nodesQuery.data : []
  const filtered = useMemo(() => {
    const keyword = search.trim().toLowerCase()
    if (!keyword) return rows
    return rows.filter(row =>
      String(row.remarks || '').toLowerCase().includes(keyword) ||
      String(row.action || '').toLowerCase().includes(keyword) ||
      String(row.action_value || '').toLowerCase().includes(keyword) ||
      (row.match || []).some(item => item.toLowerCase().includes(keyword)),
    )
  }, [rows, search])

  function openCreate() {
    setEditing(null)
    setRemarks('')
    setMatch('')
    setAction('block')
    setValue('')
    setEnabled(true)
    setOpen(true)
  }

  function openEdit(route: RouteItem) {
    setEditing(route)
    setRemarks(route.remarks || '')
    setMatch((route.match || []).join('\n'))
    setAction(isRouteAction(route.action) ? route.action : 'block')
    setValue(route.action_value || '')
    setEnabled(route.enabled !== false)
    setOpen(true)
  }

  function closeEditor() {
    setOpen(false)
    setEditing(null)
  }

  function editorPayload(): Partial<RouteItem> {
    return {
      ...(editing ? { id: editing.id, sort: editing.sort } : {}),
      remarks: remarks.trim(),
      match: match.split('\n').map(item => item.trim()).filter(Boolean),
      action,
      action_value: ['dns', 'proxy'].includes(action) ? value.trim() : null,
      enabled,
    }
  }

  function saveEditor() {
    const payload = editorPayload()
    if (!payload.remarks) return toast.error('请输入规则备注')
    if (!payload.match?.length) return toast.error('请输入至少一条匹配条件')
    if (['dns', 'proxy'].includes(action) && !value.trim()) {
      return toast.error('DNS / 代理动作必须填写目标出站标签')
    }
    save.mutate(payload)
  }

  function toggleRoute(route: RouteItem) {
    save.mutate({
      id: route.id,
      remarks: route.remarks,
      match: route.match || [],
      action: route.action,
      action_value: route.action_value,
      enabled: route.enabled === false,
      sort: route.sort,
    })
  }

  function moveRoute(route: RouteItem, direction: -1 | 1) {
    const currentIndex = rows.findIndex(item => item.id === route.id)
    const targetIndex = currentIndex + direction
    if (currentIndex < 0 || targetIndex < 0 || targetIndex >= rows.length) return

    const next = [...rows]
    const [moved] = next.splice(currentIndex, 1)
    next.splice(targetIndex, 0, moved)
    reorder.mutate(next.map((item, index) => ({ id: item.id, sort: (index + 1) * 10 })))
  }

  const columns: Column<RouteItem>[] = [
    {
      key: 'priority',
      header: '优先级',
      width: '104px',
      render: row => {
        const index = rows.findIndex(item => item.id === row.id)
        return <div className="route-priority">
          <strong>{index + 1}</strong>
          <button className="icon-button" aria-label="提高优先级" disabled={index <= 0 || reorder.isPending} onClick={() => moveRoute(row, -1)}><ArrowUp size={14}/></button>
          <button className="icon-button" aria-label="降低优先级" disabled={index < 0 || index >= rows.length - 1 || reorder.isPending} onClick={() => moveRoute(row, 1)}><ArrowDown size={14}/></button>
        </div>
      },
    },
    { key: 'remarks', header: '规则', render: row => <div><strong>{row.remarks || `Route #${row.id}`}</strong><small className="table-sub">#{row.id}</small></div> },
    {
      key: 'match',
      header: '匹配条件',
      render: row => <div className="route-match-preview">
        {(row.match || []).slice(0, 3).map(item => <code key={item}>{item}</code>)}
        {(row.match || []).length > 3 ? <small>+{(row.match || []).length - 3} 条</small> : null}
      </div>,
    },
    { key: 'action', header: '动作', render: row => <span className="badge">{actionLabel(row.action)}</span> },
    { key: 'target', header: '目标', render: row => row.action_value ? <code>{row.action_value}</code> : '-' },
    { key: 'usage', header: '使用节点', render: row => <span>{Number(row.server_count || 0)}</span> },
    {
      key: 'enabled',
      header: '启用',
      render: row => <button
        type="button"
        role="switch"
        aria-checked={row.enabled !== false}
        className={`config-switch ${row.enabled !== false ? 'active' : ''}`}
        disabled={save.isPending}
        onClick={() => toggleRoute(row)}
      ><span/></button>,
    },
    {
      key: 'actions',
      header: '操作',
      width: '110px',
      render: row => <div className="table-actions">
        <button className="icon-button" aria-label="编辑路由" onClick={() => openEdit(row)}><Pencil size={15}/></button>
        <button
          className="icon-button danger"
          aria-label="删除路由"
          disabled={remove.isPending || Number(row.server_count || 0) > 0}
          title={Number(row.server_count || 0) > 0 ? '仍有节点绑定该路由，请先解除关联' : '删除路由'}
          onClick={() => requestConfirm({
            title: '删除路由规则',
            message: `确认删除「${row.remarks || `Route #${row.id}`}」？`,
            danger: true,
            confirmLabel: '删除',
            action: () => remove.mutate(row.id),
          })}
        ><Trash2 size={15}/></button>
      </div>,
    },
  ]

  const simulation = simulator.data

  return <>
    <PageHeader
      title="路由管理"
      description="按优先级管理节点的 panel 路由。节点自定义原生路由优先级更高；内核私网保护也先于 panel 路由。"
      action={<button className="button primary" onClick={openCreate}><Plus size={16}/>添加规则</button>}
    />

    <section className="card route-simulator-card">
      <div className="route-workbench-head">
        <div>
          <h2>规则命中模拟</h2>
          <p>选择真实节点并输入域名、URL 或 IP，检查 panel 路由层最终会命中哪一条规则。</p>
        </div>
        <span className="badge">只模拟当前节点绑定的启用规则</span>
      </div>
      <div className="route-simulator-form">
        <select value={simNodeId} onChange={event => { setSimNodeId(event.target.value); simulator.reset() }}>
          <option value="">选择节点…</option>
          {nodes.map(node => <option key={node.id} value={node.id}>{node.name || `Node #${node.id}`} · #{node.id}</option>)}
        </select>
        <input
          value={simTarget}
          onChange={event => { setSimTarget(event.target.value); simulator.reset() }}
          onKeyDown={event => event.key === 'Enter' && simNodeId && simTarget.trim() && simulator.mutate()}
          placeholder="例如 api.example.com / https://example.com/path / 1.1.1.1"
        />
        <button className="button primary" disabled={!simNodeId || !simTarget.trim() || simulator.isPending} onClick={() => simulator.mutate()}>
          <Play size={15}/>{simulator.isPending ? '模拟中…' : '开始模拟'}
        </button>
      </div>
      {simulator.isError ? <QueryFeedback error onRetry={() => simulator.mutate()} /> : null}
      {simulation ? <div className="route-simulation-result">
        {simulation.warning ? <div className="page-alert">{simulation.warning}</div> : null}
        <div className={simulation.match ? 'route-result-hit' : 'route-result-miss'}>
          <strong>{simulation.match ? '命中' : '未命中 panel 路由'}</strong>
          {simulation.match ? <span>
            {simulation.match.remarks} · {actionLabel(simulation.match.action)}
            {simulation.match.action_value ? ` → ${simulation.match.action_value}` : ''}
            {simulation.match.pattern ? ` · ${simulation.match.pattern}` : ''}
          </span> : <span>将继续使用内核后续规则 / 默认行为。</span>}
        </div>
        {simulation.unresolved_patterns.length ? <small className="muted">
          以下 Geo 规则需要内核规则集才能判断，模拟器不会猜测：{simulation.unresolved_patterns.join('、')}
        </small> : null}
        {simulation.evaluated_routes.length ? <details className="route-evaluation-details">
          <summary>查看评估过程（{simulation.evaluated_routes.length} 条）</summary>
          <div className="route-evaluation-list">
            {simulation.evaluated_routes.map(item => <div key={item.id}>
              <span className={item.matched ? 'status ok' : 'muted'}>{item.matched ? '命中' : '跳过'}</span>
              <strong>{item.remarks || `Route #${item.id}`}</strong>
              <code>{item.matched_pattern || '-'}</code>
            </div>)}
          </div>
        </details> : null}
      </div> : null}
    </section>

    <div className="server-page-toolbar">
      <div className="server-search"><Search size={15}/><input value={search} onChange={event => setSearch(event.target.value)} placeholder="搜索规则 / 匹配条件 / 动作…" /></div>
      {search ? <button className="button" onClick={() => setSearch('')}>清除筛选</button> : null}
    </div>

    <div className="card content-table-card">
      <DataTable
        rowKey={row => row.id}
        loading={query.isFetching}
        error={query.isError}
        onRetry={() => query.refetch()}
        rows={filtered}
        columns={columns}
        empty={search ? '没有匹配的路由规则。' : '还没有路由规则。'}
      />
    </div>

    <Modal open={open} title={editing ? '编辑路由规则' : '添加路由规则'} onClose={closeEditor} wide>
      <div className="form-stack">
        {editing ? <div className="page-alert">
          当前被 {Number(editing.server_count || 0)} 个节点引用。保存后，相关在线节点会收到配置更新通知。
        </div> : null}
        <label className="field"><span>备注</span><input value={remarks} onChange={event => setRemarks(event.target.value)} placeholder="例如：Google 直连"/></label>
        <label className="field">
          <span>匹配条件（每行一条）</span>
          <textarea rows={8} value={match} onChange={event => setMatch(event.target.value)} placeholder={'example.com\n*.example.org\n1.1.1.0/24\ngeosite:cn\ngeoip:cn'}/>
          <small>普通域名按后缀匹配；CIDR 用于 IP；Geo 规则由 TX-Node 内核规则集解析。</small>
        </label>
        <div className="form-grid">
          <label className="field">
            <span>动作</span>
            <select value={action} onChange={event => setAction(event.target.value as typeof action)}>
              <option value="block">Block · 拦截</option>
              <option value="direct">Direct · 直连</option>
              <option value="proxy">Proxy · 指定出站</option>
              <option value="dns">DNS · 指定 DNS 出站</option>
            </select>
          </label>
          {['dns', 'proxy'].includes(action) ? <label className="field">
            <span>目标出站标签</span>
            <input value={value} onChange={event => setValue(event.target.value)} placeholder={action === 'dns' ? '例如 dns-egress' : '例如 warp-out'}/>
          </label> : <div/>}
        </div>
        <label className="config-switch-field">
          <div><strong>启用规则</strong><small>关闭后规则保留，但不会下发到 TX-Node。</small></div>
          <button type="button" role="switch" aria-checked={enabled} className={`config-switch ${enabled ? 'active' : ''}`} onClick={() => setEnabled(current => !current)}><span/></button>
        </label>
        <div className="card-actions">
          <button className="button" onClick={closeEditor}>取消</button>
          <button className="button primary" disabled={save.isPending} onClick={saveEditor}>{save.isPending ? '保存中…' : '保存规则'}</button>
        </div>
      </div>
    </Modal>
  </>
}

function isRouteAction(value: unknown): value is 'block' | 'direct' | 'dns' | 'proxy' {
  return value === 'block' || value === 'direct' || value === 'dns' || value === 'proxy'
}

function actionLabel(value: unknown) {
  return ({
    block: 'Block',
    direct: 'Direct',
    proxy: 'Proxy',
    dns: 'DNS',
  } as Record<string, string>)[String(value)] || String(value || '-')
}
