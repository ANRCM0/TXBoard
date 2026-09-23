import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Cable,
  Copy,
  ExternalLink,
  Eye,
  KeyRound,
  Pencil,
  RotateCcw,
  Server,
  ShieldAlert,
  X,
} from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import {
  getInstallCommand,
  getMachineNodes,
  getMachineToken,
  resetMachineToken,
  type MachineItem,
} from '../../api/server'
import { MachineHistoryChart } from '../../components/server/MachineHistoryChart'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { useDialog } from '../../lib/useDialog'
import { isMachineOnline, machineRatio } from './machineOpsModel'
import './MachineOpsDrawer.css'

export function MachineOpsDrawer({
  machine,
  onClose,
  onEdit,
  onBind,
}: {
  machine: MachineItem | null
  onClose: () => void
  onEdit: (machine: MachineItem) => void
  onBind: (machine: MachineItem) => void
}) {
  const open = Boolean(machine)
  const dialogRef = useDialog(open, onClose)
  const qc = useQueryClient()
  const [credentials, setCredentials] = useState<{ token: string; command: string } | null>(null)
  const [credentialsLoading, setCredentialsLoading] = useState(false)
  const hideTimer = useRef<number | undefined>()

  const machineId = machine?.id ?? 0
  const nodesQuery = useQuery({
    queryKey: ['machineNodes', machineId],
    queryFn: () => getMachineNodes(machineId),
    enabled: open && machineId > 0,
    staleTime: 15_000,
    refetchInterval: 30_000,
  })

  const resetToken = useMutation({
    mutationFn: () => resetMachineToken(machineId),
    onSuccess: async () => {
      toast.success('Machine Token 已重置，请保存新的凭据')
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['machines'] }),
        showCredentials(),
      ])
    },
  })

  useEffect(() => {
    if (!open) {
      window.clearTimeout(hideTimer.current)
      setCredentials(null)
      setCredentialsLoading(false)
    }
  }, [open, machineId])

  useEffect(() => () => window.clearTimeout(hideTimer.current), [])

  if (!machine) return null

  const nodes = Array.isArray(nodesQuery.data) ? nodesQuery.data : []
  const online = isMachineOnline(machine)
  const cpu = finite(machine.load_status?.cpu)
  const memory = machineRatio(machine.load_status?.mem)
  const disk = machineRatio(machine.load_status?.disk)

  async function showCredentials() {
    window.clearTimeout(hideTimer.current)
    setCredentialsLoading(true)
    try {
      const [token, command] = await Promise.all([
        getMachineToken(machineId),
        getInstallCommand(machineId),
      ])
      setCredentials({ token, command })
      hideTimer.current = window.setTimeout(() => setCredentials(null), 18_000)
    } catch {
      toast.error('无法读取机器凭据')
    } finally {
      setCredentialsLoading(false)
    }
  }

  async function copyText(value: string, label: string) {
    if (!value) return
    try {
      await navigator.clipboard.writeText(value)
      toast.success(`${label}已复制`)
    } catch {
      toast.error('复制失败')
    }
  }

  return createPortal(
    <div
      ref={dialogRef}
      tabIndex={-1}
      className="machine-ops-root"
      role="dialog"
      aria-modal="true"
      aria-label={`${machine.name || `Machine #${machine.id}`} 运维详情`}
    >
      <button
        type="button"
        tabIndex={-1}
        className="machine-ops-backdrop"
        aria-label="关闭机器详情"
        onClick={onClose}
      />

      <aside className="machine-ops-drawer">
        <header className="machine-ops-head">
          <div className="machine-ops-title">
            <span><Server size={18} /></span>
            <div>
              <strong>{machine.name || `Machine #${machine.id}`}</strong>
              <small>Machine #{machine.id} · 快速运维详情</small>
            </div>
          </div>
          <div className="machine-ops-head-actions">
            <Link className="button" to={`/server/machine/${machine.id}`} onClick={onClose}>
              完整详情 <ExternalLink size={14} />
            </Link>
            <button type="button" className="icon-button" onClick={onClose} aria-label="关闭">
              <X size={18} />
            </button>
          </div>
        </header>

        <div className="machine-ops-body">
          <div className="machine-ops-stack">
            <section className="machine-ops-context">
              <div className="machine-ops-context-main">
                <span className={`machine-ops-status ${online ? '' : 'off'}`}>
                  <i />{machine.is_active === false ? '已停用' : online ? '在线' : '离线'}
                </span>
                <span className="badge">SID:{machine.id}</span>
                <small>最后心跳：{formatTime(machine.last_seen_at)}</small>
                <small>承载节点：{Number(machine.servers_count || nodes.length || 0)}</small>
              </div>
              <div className="machine-ops-context-actions">
                <button type="button" className="button" onClick={() => onBind(machine)}>
                  <Cable size={14} />节点绑定
                </button>
                <button type="button" className="button" onClick={() => onEdit(machine)}>
                  <Pencil size={14} />编辑机器
                </button>
              </div>
            </section>

            <div className="machine-ops-grid">
              <section className="machine-ops-card">
                <MachineHistoryChart machineId={machine.id} />
              </section>

              <section className="machine-ops-card">
                <div className="machine-ops-card-head">
                  <div>
                    <h3>当前负载</h3>
                    <p>{loadFreshness(machine)}</p>
                  </div>
                </div>
                <div className="machine-ops-load-list">
                  <LoadMetric label="CPU" value={cpu} text={cpu === null ? '—' : `${cpu.toFixed(1)}%`} />
                  <LoadMetric
                    label="内存"
                    value={memory}
                    text={memory === null ? '—' : `${bytes(machine.load_status?.mem?.used)} / ${bytes(machine.load_status?.mem?.total)}`}
                  />
                  <LoadMetric
                    label="磁盘"
                    value={disk}
                    text={disk === null ? '—' : `${bytes(machine.load_status?.disk?.used)} / ${bytes(machine.load_status?.disk?.total)}`}
                  />
                </div>
                <div className="machine-ops-network">
                  <div>
                    <span>入站速率</span>
                    <strong>↓ {formatRate(machine.load_status?.net?.in_speed)}</strong>
                  </div>
                  <div>
                    <span>出站速率</span>
                    <strong>↑ {formatRate(machine.load_status?.net?.out_speed)}</strong>
                  </div>
                </div>
              </section>
            </div>

            <section className="machine-ops-card">
              <div className="machine-ops-card-head">
                <div>
                  <h3>服务器 Token 与 TX-Node 接入</h3>
                  <p>凭据按需读取并在 18 秒后从界面自动隐藏。</p>
                </div>
                {!credentials ? (
                  <button
                    type="button"
                    className="button"
                    disabled={credentialsLoading}
                    onClick={() => void showCredentials()}
                  >
                    <Eye size={14} />{credentialsLoading ? '读取中…' : '查看凭据'}
                  </button>
                ) : null}
              </div>

              {!credentials ? (
                <div className="machine-ops-sensitive-note">
                  <ShieldAlert size={15} />
                  <span>Machine Token 属于高敏感凭据。仅在受信任环境中查看；重置后旧 TX-Node 进程需要重新配置。</span>
                </div>
              ) : (
                <div className="machine-ops-credentials">
                  <div>
                    <strong>Machine Token</strong>
                    <pre className="code-block">{credentials.token || '-'}</pre>
                  </div>
                  <div>
                    <strong>安装 / 接入命令</strong>
                    <pre className="code-block">{credentials.command || '-'}</pre>
                  </div>
                  <div className="machine-ops-credential-actions">
                    <button className="button" onClick={() => void copyText(credentials.token, 'Token')}>
                      <KeyRound size={14} />复制 Token
                    </button>
                    <button className="button primary" onClick={() => void copyText(credentials.command, '安装命令')}>
                      <Copy size={14} />复制安装命令
                    </button>
                    <button
                      className="button danger"
                      disabled={resetToken.isPending}
                      onClick={() => requestConfirm({
                        title: '重置 Machine Token',
                        message: `确认重置 ${machine.name || `Machine #${machine.id}`} 的 Token？旧 Token 会立即失效，TX-Node 需要重新配置。`,
                        danger: true,
                        confirmLabel: '重置 Token',
                        action: () => resetToken.mutate(),
                      })}
                    >
                      <RotateCcw size={14} />{resetToken.isPending ? '重置中…' : '重置 Token'}
                    </button>
                  </div>
                </div>
              )}
            </section>

            <section className="machine-ops-card machine-ops-nodes">
              <div className="machine-ops-card-head">
                <div>
                  <h3>已承载节点</h3>
                  <p>机器是宿主环境；节点继续由 TXBoard Node Runtime 管理。</p>
                </div>
                <Link className="button" to="/server/manage" onClick={onClose}>
                  前往节点管理 <ExternalLink size={14} />
                </Link>
              </div>

              <QueryFeedback
                loading={nodesQuery.isFetching && !nodesQuery.data}
                error={nodesQuery.isError}
                onRetry={() => nodesQuery.refetch()}
              />

              {nodes.length ? (
                <div className="table-wrap">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>节点</th>
                        <th>协议</th>
                        <th>地址</th>
                        <th>状态</th>
                      </tr>
                    </thead>
                    <tbody>
                      {nodes.map(node => (
                        <tr key={node.id}>
                          <td>
                            <Link className="table-link" to={`/server/node/${node.id}`} onClick={onClose}>
                              <strong>{node.name || `Node #${node.id}`}</strong>
                            </Link>
                          </td>
                          <td><span className="badge">{node.type === 'hysteria' ? 'hysteria2' : node.type || '-'}</span></td>
                          <td>{node.host ? `${node.host}${node.port ? ':' + node.port : ''}` : '-'}</td>
                          <td>
                            <span className="machine-status">
                              <i className={node.online ? 'online' : 'off'} />
                              {node.online ? '在线' : '离线'}
                            </span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : !nodesQuery.isLoading && !nodesQuery.isError ? (
                <div className="machine-ops-empty">该机器暂未绑定节点。</div>
              ) : null}
            </section>
          </div>
        </div>
      </aside>
    </div>,
    document.body,
  )
}

function LoadMetric({
  label,
  value,
  text,
}: {
  label: string
  value: number | null
  text: string
}) {
  const width = value === null ? 0 : Math.min(100, Math.max(0, value))
  return (
    <div className="machine-ops-load-row">
      <div><span>{label}</span><strong>{text}</strong></div>
      <span className="machine-ops-progress"><i style={{ width: `${width}%` }} /></span>
    </div>
  )
}

function finite(value: unknown) {
  const number = Number(value)
  return Number.isFinite(number) ? number : null
}

function bytes(value: unknown) {
  const number = Number(value)
  if (!Number.isFinite(number) || number < 0) return '—'
  if (number >= 1024 ** 3) return `${(number / 1024 ** 3).toFixed(number >= 10 * 1024 ** 3 ? 1 : 2)} GB`
  if (number >= 1024 ** 2) return `${(number / 1024 ** 2).toFixed(1)} MB`
  if (number >= 1024) return `${(number / 1024).toFixed(1)} KB`
  return `${number.toFixed(0)} B`
}

function formatRate(value: unknown) {
  const number = Number(value)
  if (!Number.isFinite(number) || number < 0) return '—'
  if (number >= 1024 ** 2) return `${(number / 1024 ** 2).toFixed(2)} MB/s`
  if (number >= 1024) return `${(number / 1024).toFixed(1)} KB/s`
  return `${number.toFixed(0)} B/s`
}

function formatTime(value: unknown) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    const date = new Date(value < 10_000_000_000 ? value * 1000 : value)
    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString()
  }
  if (typeof value === 'string' && value) {
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
  }
  return '-'
}

function loadFreshness(machine: MachineItem) {
  return machine.load_status?.updated_at
    ? `采样于 ${formatTime(machine.load_status.updated_at)}`
    : '最近一次心跳负载'
}
