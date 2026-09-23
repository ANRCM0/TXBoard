import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  CircleCheckBig,
  CircleX,
  Copy,
  Eye,
  Gauge,
  Network,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  Server,
  Trash2,
} from 'lucide-react'
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
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { MachineOpsDrawer } from './MachineOpsDrawer'
import {
  isMachineOnline,
  machineRatio,
  summarizeMachines,
} from './machineOpsModel'

export function MachinesPage() {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<MachineItem | null>(null)
  const [name, setName] = useState('')
  const [notes, setNotes] = useState('')
  const [active, setActive] = useState(true)
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
    setOpen(true)
  }

  function openEdit(row: MachineItem) {
    setEditing(row)
    setName(row.name || '')
    setNotes(String(row.notes || ''))
    setActive(row.is_active !== false)
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
  const summary = useMemo(() => summarizeMachines(rows), [rows])
  const opsMachine = opsMachineId === null
    ? null
    : rows.find(row => row.id === opsMachineId) || null

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
      key: 'name',
      header: '服务器',
      render: row => (
        <div className="machine-name">
          <Link className="table-link" to={`/server/machine/${row.id}`}>
            <strong>{row.name || `Machine ${row.id}`}</strong>
          </Link>
          <small>SID:{row.id}{row.notes ? ` · ${row.notes}` : ''}</small>
        </div>
      ),
    },
    { key: 'status', header: '状态', render: row => machineStatus(row) },
    { key: 'nodes', header: '节点数', render: row => <strong>{Number(row.servers_count || 0)}</strong> },
    { key: 'cpu', header: 'CPU', render: row => metric(row.load_status?.cpu) },
    { key: 'mem', header: '内存', render: row => metric(machineRatio(row.load_status?.mem)) },
    { key: 'disk', header: '磁盘', render: row => metric(machineRatio(row.load_status?.disk)) },
    { key: 'last', header: '最后心跳', render: row => formatTime(row.last_seen_at) },
    {
      key: 'actions',
      header: '操作',
      width: '138px',
      render: row => (
        <div className="machine-ops-row-actions">
          <button
            type="button"
            className="icon-button"
            title="服务器详情"
            aria-label={`查看 ${row.name || row.id} 运维详情`}
            onClick={() => setOpsMachineId(row.id)}
          >
            <Eye size={16} />
          </button>
          <button
            type="button"
            className="icon-button"
            title="编辑"
            aria-label={`编辑 ${row.name || row.id}`}
            onClick={() => openEdit(row)}
          >
            <Pencil size={15} />
          </button>
          <button
            type="button"
            className="icon-button danger"
            title="删除"
            aria-label={`删除 ${row.name || row.id}`}
            disabled={remove.isPending}
            onClick={() => requestConfirm({
              title: '删除服务器',
              message: `确认删除「${row.name || `Machine #${row.id}`}」？关联节点将自动解绑。`,
              danger: true,
              confirmLabel: '删除',
              action: () => remove.mutate(row.id),
            })}
          >
            <Trash2 size={15} />
          </button>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="服务器管理"
        description="集中查看 TX-Node 宿主服务器的在线状态、负载、节点关系与接入凭据。"
        action={<button className="button primary" onClick={openCreate}><Plus size={16}/>添加服务器</button>}
      />

      <div className="machine-ops-overview">
        <Overview icon={<Server size={17} />} label="服务器总数" value={summary.total} />
        <Overview icon={<CircleCheckBig size={17} />} label="在线服务器" value={summary.online} tone="ok" />
        <Overview icon={<CircleX size={17} />} label="离线 / 停用" value={summary.offline} />
        <Overview icon={<Gauge size={17} />} label="高负载" value={summary.highLoad} tone={summary.highLoad ? 'warn' : 'ok'} />
        <Overview icon={<Network size={17} />} label="节点数" value={summary.nodes} />
      </div>

      <div className="server-page-toolbar">
        <div className="server-search">
          <Search size={15}/>
          <input value={search} onChange={event => setSearch(event.target.value)} placeholder="搜索服务器名称、备注或 SID…" />
        </div>
        <span className="toolbar-hint">高负载：CPU / 内存 ≥ 85% 或磁盘 ≥ 90%</span>
        <button className="button" onClick={() => query.refetch()} disabled={query.isFetching}>
          <RefreshCw size={16}/>{query.isFetching ? '刷新中…' : '刷新'}
        </button>
      </div>

      <div className="server-table-card">
        <DataTable
          rows={filteredRows}
          columns={columns}
          rowKey={row => row.id}
          loading={query.isFetching}
          error={query.isError}
          onRetry={() => query.refetch()}
        />
      </div>

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
          <label className="config-switch-field">
            <div>
              <strong>启用服务器</strong>
              <small>停用后服务器仍保留配置，但不参与正常调度。</small>
            </div>
            <button
              type="button"
              role="switch"
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
    </>
  )
}

function Overview({
  icon,
  label,
  value,
  tone,
}: {
  icon: React.ReactNode
  label: string
  value: number
  tone?: 'ok' | 'warn'
}) {
  return (
    <div className="machine-ops-overview-card">
      <span className={`machine-ops-overview-icon ${tone || ''}`}>{icon}</span>
      <span className="machine-ops-overview-copy">
        <span>{label}</span>
        <strong>{value}</strong>
      </span>
    </div>
  )
}

function metric(value: unknown) {
  const number = Number(value)
  if (!Number.isFinite(number)) return '-'
  const width = Math.min(100, Math.max(0, number))
  return <span className="machine-metric"><i style={{ width: `${width}%` }}/><b>{number.toFixed(1)}%</b></span>
}

function machineStatus(row: MachineItem) {
  if (row.is_active === false) {
    return <span className="machine-status"><i className="off"/>已停用</span>
  }
  const online = isMachineOnline(row)
  return <span className="machine-status"><i className={online ? 'online' : 'off'}/>{online ? '在线' : '离线'}</span>
}

function formatTime(value: unknown) {
  let time: number | null = null
  if (typeof value === 'number' && Number.isFinite(value)) {
    time = value < 10_000_000_000 ? value * 1000 : value
  } else if (typeof value === 'string' && value) {
    const parsed = Date.parse(value)
    if (Number.isFinite(parsed)) time = parsed
  }
  return time === null ? '-' : new Date(time).toLocaleString()
}
