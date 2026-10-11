import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, Eye, Pencil, Plus, RefreshCw, Search, Server, Trash2, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import {
  deleteMachine,
  getMachines,
  saveMachine,
  type MachineItem,
} from '../../api/server'
import { MachineNodeBinding } from '../../components/server/MachineNodeBinding'
import { DataTable, type Column } from '../../components/ui/DataTable'
import { EmptyState } from '../../components/ui/EmptyState'
import { listSurfaceState } from '../../components/ui/listSurfaceState'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { MachineOpsDrawer } from './MachineOpsDrawer'
import {
  machineFleetSummary, machineHeartbeat, machinePercentage,
  machinePresence, machineUtilization,
} from './machineListPresentation'
import './MachinesPage.css'

export function MachinesPage() {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<MachineItem | null>(null)
  const [name, setName] = useState('')
  const [notes, setNotes] = useState('')
  const [active, setActive] = useState(true)
  const [imageChannel, setImageChannel] = useState<'stable' | 'dev'>('stable')
  const [search, setSearch] = useState('')
  const [tokenInfo, setTokenInfo] = useState<{ token?: string; install_command?: string } | null>(null)
  const [opsMachineId, setOpsMachineId] = useState<number | null>(null)
  const [bindingMachine, setBindingMachine] = useState<MachineItem | null>(null)
  const hideTimer = useRef<number | undefined>()

  useEffect(() => () => window.clearTimeout(hideTimer.current), [])

  const query = useQuery({
    queryKey: ['machines'],
    queryFn: getMachines,
    staleTime: 30_000,
    refetchInterval: opsMachineId !== null ? 10_000 : 60_000,
    refetchOnWindowFocus: true,
  })

  const save = useMutation({
    mutationFn: () => saveMachine({
      ...(editing ? { id: editing.id } : {}),
      name,
      notes,
      is_active: active,
      image_channel: imageChannel,
    }),
    onSuccess: data => {
      toast.success(editing ? '服务器已更新' : '服务器已创建')
      setOpen(false)
      if (!editing && data && typeof data === 'object') {
        const result = data as Record<string, unknown>
        setTokenInfo({
          token: typeof result.token === 'string' ? result.token : '',
          install_command: typeof result.install_command === 'string' ? result.install_command : '',
        })
        window.clearTimeout(hideTimer.current)
        hideTimer.current = window.setTimeout(closeToken, 18_000)
      }
      setEditing(null)
      setName('')
      setNotes('')
      setActive(true)
      setImageChannel('stable')
      void qc.invalidateQueries({ queryKey: ['machines'] })
    },
  })

  const remove = useMutation({
    mutationFn: deleteMachine,
    onSuccess: (_, id) => {
      toast.success('服务器已删除')
      if (opsMachineId === id) setOpsMachineId(null)
      void qc.invalidateQueries({ queryKey: ['machines'] })
      void qc.invalidateQueries({ queryKey: ['nodes'] })
    },
  })

  function openCreate() {
    setEditing(null)
    setName('')
    setNotes('')
    setActive(true)
    setImageChannel('stable')
    setOpen(true)
  }

  function openEdit(row: MachineItem) {
    setEditing(row)
    setName(row.name || '')
    setNotes(String(row.notes || ''))
    setActive(row.is_active !== false)
    setImageChannel(row.image_channel === 'dev' ? 'dev' : 'stable')
    setOpen(true)
  }

  function closeToken() {
    window.clearTimeout(hideTimer.current)
    setTokenInfo(null)
  }

  async function copyInstallCommand() {
    const command = tokenInfo?.install_command
    if (!command) return
    try {
      await navigator.clipboard.writeText(command)
      toast.success('安装命令已复制')
    } catch {
      toast.error('复制失败，请手动复制安装命令')
    }
  }

  const rows = Array.isArray(query.data) ? query.data : []
  const summary = useMemo(() => machineFleetSummary(rows), [rows])
  const opsMachine = opsMachineId === null
    ? null
    : rows.find(row => row.id === opsMachineId) || null

  const hasFilters = Boolean(search.trim())
  const filteredRows = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return rows
    return rows.filter(row =>
      String(row.name || '').toLowerCase().includes(q) ||
      String(row.notes || '').toLowerCase().includes(q) ||
      String(row.id).includes(q),
    )
  }, [rows, search])

  const columns: Column<MachineItem>[] = [
    {
      key: 'name', header: '服务器', width: '24%',
      render: row => <div className="machine-name">
        <Link className="table-link" to={`/server/machine/${row.id}`}>
          <strong>{row.name || `Machine ${row.id}`}</strong>
        </Link>
        <small title={row.notes || undefined}>SID:{row.id}{row.notes ? ` · ${row.notes}` : ''}</small>
      </div>,
    },
    {
      key: 'status', header: '连接状态', width: '11%',
      render: row => {
        const presence = machinePresence(row)
        return <span className={'machines-v3-status is-' + presence.tone}>
          <i aria-hidden="true"/>{presence.label}
        </span>
      },
    },
    {
      key: 'channel', header: '镜像渠道', width: '12%',
      render: row => <span className="machines-v3-subtle">
        {row.image_channel === 'dev' ? '开发版 · dev' : '稳定版 · latest'}
      </span>,
    },
    {
      key: 'nodes', header: '节点数', width: '8%',
      render: row => <span className="machines-v3-count">
        {row.servers_count == null ? '—' : row.servers_count}
      </span>,
    },
    {
      key: 'load', header: '资源负载', width: '25%',
      render: row => <div className="machines-v3-load">
        <LoadMeter label="CPU" value={machinePercentage(row.load_status?.cpu)} threshold={85}/>
        <LoadMeter label="内存" value={machineUtilization(row.load_status?.mem)} threshold={85}/>
        <LoadMeter label="磁盘" value={machineUtilization(row.load_status?.disk)} threshold={90}/>
      </div>,
    },
    {
      key: 'last', header: '最后心跳', width: '12%',
      render: row => <span className="machines-v3-last">{machineHeartbeat(row.last_seen_at)}</span>,
    },
    {
      key: 'actions', header: '操作', width: '8%',
      render: row => <div className="machine-ops-row-actions">
        <button type="button" className="icon-button" title="机器运维详情"
          aria-label={`查看 ${row.name || row.id} 运维详情`}
          onClick={() => setOpsMachineId(row.id)}><Eye size={16} aria-hidden="true"/></button>
        <button type="button" className="icon-button" title="编辑"
          aria-label={`编辑 ${row.name || row.id}`} onClick={() => openEdit(row)}>
          <Pencil size={15} aria-hidden="true"/>
        </button>
        <button type="button" className="icon-button danger" title="删除"
          aria-label={`删除 ${row.name || row.id}`} disabled={remove.isPending}
          onClick={() => requestConfirm({
            title:'删除服务器',
            message:`确认删除「${row.name || `Machine #${row.id}`}」？关联节点将自动解绑。`,
            danger:true, confirmLabel:'删除',
            action:() => remove.mutate(row.id),
          })}><Trash2 size={15} aria-hidden="true"/></button>
      </div>,
    },
  ]
  const surface = listSurfaceState({
    count: filteredRows.length, loading: query.isLoading, error: query.isError,
  })
  return (
    <div className="machines-v3-page">
      <PageHeader
        title="服务器管理"
        description="集中查看 TX-Node 宿主服务器的在线状态、负载、节点关系与接入凭据。"
        action={<button type="button" className="button primary" onClick={openCreate}>
          <Plus size={16} aria-hidden="true"/>添加服务器
        </button>}
      />

      {query.data && rows.length > 0 ? <div className="machines-v3-summary" aria-label="服务器运行概览">
        {[
          {label:'服务器总数',value:summary.total},
          {label:'在线',value:summary.online},
          {label:'需关注',value:summary.attention,tone:summary.attention ? 'attention' : ''},
          {label:'高负载',value:summary.highLoad,tone:summary.highLoad ? 'alert' : ''},
          {label:'承载节点',value:summary.nodes},
        ].map(item => <div className={'machines-v3-summary-item' + (item.tone ? ' is-' + item.tone : '')}
          key={item.label}>
          <span className="machines-v3-summary-label">{item.label}</span>
          <strong>{item.value}</strong>
        </div>)}
      </div> : null}

      <div className="machines-v3-toolbar" role="search" aria-label="筛选服务器">
        <div className="machines-v3-search">
          <Search size={17} aria-hidden="true"/>
          <input type="search" aria-label="搜索服务器名称、备注或 SID" value={search}
            onChange={event => setSearch(event.target.value)} placeholder="搜索服务器名称、备注或 SID…"/>
          {search ? <button type="button" className="machines-v3-clear-search" aria-label="清空服务器搜索词"
            onClick={() => setSearch('')}><X size={15} aria-hidden="true"/></button> : null}
        </div>
        {hasFilters ? <button type="button" className="button machines-v3-clear-filters"
          onClick={() => setSearch('')}>清除筛选</button> : null}
        <button type="button" className="button machines-v3-toolbar-refresh"
          onClick={() => void query.refetch()} disabled={query.isFetching}>
          <RefreshCw size={16} aria-hidden="true"
            className={query.isFetching ? 'loading-spinner' : undefined}/>
          {query.isFetching ? '刷新中…' : '刷新'}
        </button>
        <span className="machines-v3-toolbar-help">高负载：CPU / 内存 ≥ 85%，磁盘 ≥ 90%</span>
      </div>

      {!query.isLoading && !query.isError ? <div className="machines-v3-list-meta" aria-live="polite">
        <span>{hasFilters ? `显示 ${filteredRows.length} / ${rows.length} 台服务器` : `共 ${rows.length} 台服务器`}</span>
        {query.isFetching ? <span>正在同步最新状态…</span> : null}
      </div> : null}

      <div className={'server-table-card admin-v2-list-surface machines-v3-table is-' + surface}>
        <DataTable
          label="服务器列表" rows={filteredRows} columns={columns} rowKey={row => row.id}
          loading={query.isLoading} error={query.isError} onRetry={() => void query.refetch()}
          emptyState={hasFilters ? (
            <EmptyState className="admin-v2-list-empty" icon={<Search size={23}/>}
              title="没有符合条件的服务器"
              description="当前名称、备注或 SID 搜索条件下没有匹配结果。"
              action={<button type="button" className="button" onClick={() => setSearch('')}>清除筛选</button>}/>
          ) : (
            <EmptyState className="admin-v2-list-empty" icon={<Server size={23}/>}
              title="还没有服务器"
              description="添加第一台 TX-Node 宿主服务器，开始管理节点和运行状态。"
              action={<button type="button" className="button primary" onClick={openCreate}>
                <Plus size={15} aria-hidden="true"/>添加服务器
              </button>}/>
          )}
        />
      </div>
      {surface === 'rows' ? <p className="machines-v3-scroll-hint">左右滑动表格可查看全部负载、心跳及操作</p> : null}

      <MachineOpsDrawer
        machine={opsMachine}
        onClose={() => setOpsMachineId(null)}
        onEdit={row => {
          setOpsMachineId(null)
          openEdit(row)
        }}
        onBind={row => {
          setOpsMachineId(null)
          setBindingMachine(row)
        }}
      />

      <Modal open={open} title={editing ? '编辑服务器' : '添加服务器'} onClose={() => setOpen(false)}>
        <div className="form-stack">
          <label className="field">
            <span>服务器名称</span>
            <input value={name} onChange={event => setName(event.target.value)} placeholder="Tokyo-01"/>
          </label>
          <label className="field">
            <span>备注</span>
            <textarea value={notes} onChange={event => setNotes(event.target.value)} placeholder="入口机 / 日本区域…"/>
          </label>
          <label className="field"><span>TX-Node 镜像渠道</span><select value={imageChannel} onChange={event => setImageChannel(event.target.value as 'stable' | 'dev')}><option value="stable">稳定版（latest）</option><option value="dev">开发版（dev）</option></select><small>新安装命令会使用此渠道；修改已有服务器的渠道不会自动升级运行容器，请在运行时管理中执行切换。</small></label>
          <label className="config-switch-field">
            <div>
              <strong>启用服务器</strong>
              <small>停用后服务器仍保留配置，但不参与正常调度。</small>
            </div>
            <button
              type="button"
              role="switch"
              aria-label="启用服务器"
              aria-checked={active}
              className={`config-switch ${active ? 'active' : ''}`}
              onClick={() => setActive(value => !value)}
            >
              <span/>
            </button>
          </label>
          <div className="modal-actions">
            <button className="button" onClick={() => setOpen(false)}>取消</button>
            <button
              className="button primary"
              onClick={() => save.mutate()}
              disabled={!name.trim() || save.isPending}
            >
              {save.isPending ? '保存中…' : '保存'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal open={Boolean(tokenInfo)} title="新服务器凭据（18 秒后自动隐藏）" onClose={closeToken}>
        <div className="form-stack">
          <div className="page-alert">请立即保存 Machine Token 或安装命令。关闭后可从服务器详情中再次按需查看。</div>
          <label className="field">
            <span>Machine Token</span>
            <pre className="code-block">{tokenInfo?.token || '-'}</pre>
          </label>
          <label className="field">
            <span>TX-Node 安装 / 接入命令</span>
            <pre className="code-block">{tokenInfo?.install_command || '-'}</pre>
          </label>
        </div>
        <div className="card-actions">
          {tokenInfo?.install_command ? (
            <button className="button primary" onClick={copyInstallCommand}>
              <Copy size={16}/>复制安装命令
            </button>
          ) : null}
          <button className="button" onClick={closeToken}>关闭</button>
        </div>
      </Modal>

      <Modal
        open={Boolean(bindingMachine)}
        title={bindingMachine ? `节点绑定：${bindingMachine.name || bindingMachine.id}` : '节点绑定'}
        onClose={() => setBindingMachine(null)}
      >
        {bindingMachine ? <MachineNodeBinding machineId={bindingMachine.id}/> : null}
      </Modal>
    </div>
  )
}

function LoadMeter({label,value,threshold}: {
  label:string;value:number|null;threshold:number
}) {
  const known = value !== null
  const alert = known && value >= threshold
  return <div className={'machines-v3-load-metric' + (alert ? ' is-alert' : '')}>
    <div className="machines-v3-load-label">
      <span>{label}</span>
      <strong>{known ? value.toFixed(0) + '%' : '—'}</strong>
    </div>
    <div className="machines-v3-load-track" aria-hidden="true">
      <span style={{width: known ? Math.max(0,Math.min(100,value)) + '%' : '0%'}}/>
    </div>
  </div>
}
