import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BarChart3, Cable, Copy, KeyRound, MoreHorizontal, Plus, RefreshCw, RotateCcw, Search, Trash2, Pencil } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import {
  deleteMachine,
  getInstallCommand,
  getMachineToken,
  getMachines,
  resetMachineToken,
  saveMachine,
  type MachineItem,
} from '../../api/server'
import { MachineHistoryChart } from '../../components/server/MachineHistoryChart'
import { MachineNodeBinding } from '../../components/server/MachineNodeBinding'
import { DataTable, type Column } from '../../components/ui/DataTable'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

export function MachinesPage() {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<MachineItem | null>(null)
  const [name, setName] = useState('')
  const [notes, setNotes] = useState('')
  const [active, setActive] = useState(true)
  const [search, setSearch] = useState('')
  const [tokenInfo, setTokenInfo] = useState<{ token?: string; install_command?: string } | null>(null)
  const [tokenMachineId, setTokenMachineId] = useState<number | null>(null)
  const [historyMachine, setHistoryMachine] = useState<MachineItem | null>(null)
  const [bindingMachine, setBindingMachine] = useState<MachineItem | null>(null)
  const hideTimer = useRef<number | undefined>()

  useEffect(() => () => window.clearTimeout(hideTimer.current), [])

  const query = useQuery({
    queryKey: ['machines'],
    queryFn: getMachines,
    staleTime: 30_000,
    refetchInterval: 60_000,
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
      toast.success(editing ? '机器已更新' : '机器已创建')
      setOpen(false)
      if (!editing && data && typeof data === 'object') {
        const result = data as Record<string, unknown>
        const id = Number(result.id)
        if (Number.isFinite(id)) setTokenMachineId(id)
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
      qc.invalidateQueries({ queryKey: ['machines'] })
    },
  })

  const remove = useMutation({
    mutationFn: deleteMachine,
    onSuccess: () => {
      toast.success('机器已删除')
      qc.invalidateQueries({ queryKey: ['machines'] })
      qc.invalidateQueries({ queryKey: ['nodes'] })
    },
  })

  const resetToken = useMutation({
    mutationFn: resetMachineToken,
    onSuccess: async (_, id) => {
      toast.success('Token 已重置')
      await showToken(id)
      qc.invalidateQueries({ queryKey: ['machines'] })
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

  async function showToken(id: number) {
    window.clearTimeout(hideTimer.current)
    setTokenMachineId(id)
    try {
      const [token, command] = await Promise.all([getMachineToken(id), getInstallCommand(id)])
      setTokenInfo({ token, install_command: command })
      hideTimer.current = window.setTimeout(closeToken, 18_000)
    } catch {
      setTokenMachineId(null)
    }
  }

  function closeToken() {
    window.clearTimeout(hideTimer.current)
    setTokenInfo(null)
    setTokenMachineId(null)
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
    { key: 'id', header: 'ID', width: '72px', render: row => row.id },
    {
      key: 'name',
      header: '机器',
      render: row => (
        <div className="machine-name">
          <Link className="table-link" to={`/server/machine/${row.id}`}><strong>{row.name || `Machine ${row.id}`}</strong></Link>
          {row.notes ? <small>{row.notes}</small> : null}
        </div>
      ),
    },
    { key: 'status', header: '状态', render: row => machineStatus(row) },
    { key: 'nodes', header: '节点', render: row => <span>{Number(row.servers_count || 0)}</span> },
    { key: 'cpu', header: 'CPU', render: row => metric(row.load_status?.cpu) },
    { key: 'mem', header: '内存', render: row => metric(ratio(row.load_status?.mem)) },
    { key: 'disk', header: '磁盘', render: row => metric(ratio(row.load_status?.disk)) },
    { key: 'last', header: '最后在线', render: row => formatTime(row.last_seen_at) },
    {
      key: 'actions',
      header: '操作',
      width: '72px',
      render: row => (
        <details className="row-menu">
          <summary title="更多操作"><MoreHorizontal size={18}/></summary>
          <div className="row-menu-popover">
            <button onClick={() => openEdit(row)}><Pencil size={15}/>编辑</button>
            <button onClick={() => showToken(row.id)}><KeyRound size={15}/>Token / 安装命令</button>
            <button onClick={() => setBindingMachine(row)}><Cable size={15}/>节点绑定</button>
            <button onClick={() => setHistoryMachine(row)}><BarChart3 size={15}/>负载历史</button>
            <button
              onClick={() => requestConfirm({ title: '重置 Token', message: '重置后旧 Token 将失效，确认继续？', danger: true, confirmLabel: '重置', action: () => resetToken.mutate(row.id) })}
            >
              <RotateCcw size={15}/>重置 Token
            </button>
            <button
              className="danger"
              onClick={() => requestConfirm({ title: '删除机器', message: '确认删除机器？关联节点将自动解绑。', danger: true, confirmLabel: '删除', action: () => remove.mutate(row.id) })}
            >
              <Trash2 size={15}/>删除
            </button>
          </div>
        </details>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="机器管理"
        description="管理节点机器、凭据、节点绑定和负载状态。"
        action={<button className="button primary" onClick={openCreate}><Plus size={16}/>添加机器</button>}
      />

      <div className="server-page-toolbar">
        <div className="server-search">
          <Search size={15}/>
          <input value={search} onChange={event => setSearch(event.target.value)} placeholder="搜索机器…" />
        </div>
        <button className="button" onClick={() => query.refetch()}><RefreshCw size={16}/>刷新</button>
      </div>

      <div className="server-table-card">
        <DataTable rows={filteredRows} columns={columns} rowKey={row=>row.id} loading={query.isFetching} error={query.isError} onRetry={()=>query.refetch()}/>
      </div>

      <Modal open={open} title={editing ? '编辑机器' : '添加机器'} onClose={() => setOpen(false)}>
        <div className="form-stack">
          <label className="field"><span>机器名称</span><input value={name} onChange={event => setName(event.target.value)} placeholder="Tokyo-01"/></label>
          <label className="field"><span>备注</span><textarea value={notes} onChange={event => setNotes(event.target.value)} placeholder="入口机 / 日本区域…"/></label>
          <label className="config-switch-field">
            <div><strong>启用机器</strong><small>停用后机器仍保留配置，但不参与正常调度。</small></div>
            <button type="button" role="switch" aria-checked={active} className={`config-switch ${active ? 'active' : ''}`} onClick={() => setActive(value => !value)}><span/></button>
          </label>
          <div className="modal-actions">
            <button className="button" onClick={() => setOpen(false)}>取消</button>
            <button className="button primary" onClick={() => save.mutate()} disabled={!name || save.isPending}>{save.isPending ? '保存中…' : '保存'}</button>
          </div>
        </div>
      </Modal>

      <Modal open={!!tokenInfo} title="机器凭据（18 秒后自动隐藏）" onClose={closeToken}>
        <div className="form-stack">
          <label className="field">
            <span>Machine Token</span>
            <pre className="code-block">{tokenInfo?.token || '-'}</pre>
          </label>
          <label className="field">
            <span>一键安装命令</span>
            <pre className="code-block">{tokenInfo?.install_command || '-'}</pre>
          </label>
        </div>
        <div className="card-actions">
          {tokenInfo?.install_command ? (
            <button className="button primary" onClick={copyInstallCommand}>
              <Copy size={16}/>复制安装命令
            </button>
          ) : null}
          {tokenMachineId !== null ? (
            <button
              className="button"
              disabled={resetToken.isPending}
              onClick={() => requestConfirm({ title: '重置 Token', message: '重置后旧 Token 将失效，确认继续？', danger: true, confirmLabel: '重置', action: () => resetToken.mutate(tokenMachineId) })}
            >
              <RotateCcw size={16}/>{resetToken.isPending ? '重置中…' : '重置 Token'}
            </button>
          ) : null}
          <button className="button" onClick={closeToken}>关闭</button>
        </div>
      </Modal>

      <Modal open={!!bindingMachine} title={bindingMachine ? `节点绑定：${bindingMachine.name || bindingMachine.id}` : '节点绑定'} onClose={() => setBindingMachine(null)}>
        {bindingMachine ? <MachineNodeBinding machineId={bindingMachine.id}/> : null}
      </Modal>

      <Modal open={!!historyMachine} title={historyMachine ? `负载历史：${historyMachine.name || historyMachine.id}` : '负载历史'} onClose={() => setHistoryMachine(null)}>
        {historyMachine ? <MachineHistoryChart machineId={historyMachine.id}/> : null}
      </Modal>
    </>
  )
}

function ratio(value?: { total?: number; used?: number }) {
  const total = Number(value?.total)
  const used = Number(value?.used)
  if (!Number.isFinite(total) || !Number.isFinite(used) || total <= 0) return null
  return (used / total) * 100
}

function metric(value: unknown) {
  const n = Number(value)
  if (!Number.isFinite(n)) return '-'
  return <span className="machine-metric"><i style={{ width: `${Math.min(100, Math.max(0, n))}%` }}/><b>{n.toFixed(1)}%</b></span>
}

function machineStatus(row: MachineItem) {
  if (row.is_active === false) return <span className="machine-status"><i className="off"/>已停用</span>
  const last = timestamp(row.last_seen_at)
  const online = last !== null && Date.now() - last < 180_000
  return <span className="machine-status"><i className={online ? 'online' : 'off'}/>{online ? '在线' : '离线'}</span>
}

function timestamp(value: unknown) {
  if (typeof value === 'number' && Number.isFinite(value)) return value < 10_000_000_000 ? value * 1000 : value
  if (typeof value === 'string' && value) {
    const parsed = Date.parse(value)
    if (Number.isFinite(parsed)) return parsed
  }
  return null
}

function formatTime(value: unknown) {
  const time = timestamp(value)
  return time === null ? '-' : new Date(time).toLocaleString()
}
