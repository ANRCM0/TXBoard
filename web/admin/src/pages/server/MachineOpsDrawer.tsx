import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Activity,
  ArrowUpCircle,
  Boxes,
  Cable,
  Copy,
  ExternalLink,
  Eye,
  KeyRound,
  Pencil,
  RotateCcw,
  Server,
  ShieldAlert,
  ShieldCheck,
  X,
} from 'lucide-react'
import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { createPortal } from 'react-dom'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import {
  getMachineNodes,
  getMachineCredentials,
  resetMachineToken,
  updateMachineRuntime,
  type MachineItem,
} from '../../api/server'
import { MachineHistoryChart } from '../../components/server/MachineHistoryChart'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { useDialog } from '../../lib/useDialog'
import { isMachineOnline, machineRatio } from './machineOpsModel'
import './MachineOpsDrawer.css'

type MachineSection = 'overview' | 'runtime' | 'nodes' | 'security'
const sections = [
  { id: 'overview', label: '概览', icon: Activity },
  { id: 'runtime', label: '运行时', icon: ArrowUpCircle },
  { id: 'nodes', label: '节点', icon: Boxes },
  { id: 'security', label: '安全', icon: ShieldCheck },
] as const

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
  const [credentials, setCredentials] = useState<{ machineId: number; token: string; command: string } | null>(null)
  const [activeSection, setActiveSection] = useState<MachineSection>('overview')
  const credentialRequestId = useRef(0)
  const [credentialsLoading, setCredentialsLoading] = useState(false)
  const [updateTarget, setUpdateTarget] = useState<'latest' | 'dev'>('latest')
  const hideTimer = useRef<number | undefined>()

  const machineId = machine?.id ?? 0
  const nodesQuery = useQuery({
    queryKey: ['machineNodes', machineId],
    queryFn: () => getMachineNodes(machineId),
    enabled: open && machineId > 0 && activeSection === 'nodes',
    staleTime: 15_000,
    refetchInterval: 30_000,
  })

  const resetToken = useMutation({
    mutationFn: () => resetMachineToken(machineId),
    onSuccess: async result => {
      toast.success('Machine Token 已重置，请保存新的凭据')
      setCredentials({ machineId, token: result.token, command: result.install_command })
      window.clearTimeout(hideTimer.current)
      hideTimer.current = window.setTimeout(() => setCredentials(null), 18_000)
      await qc.invalidateQueries({ queryKey: ['machines'] })
    },
  })

  const runtimeUpdate = useMutation({
    mutationFn: () => updateMachineRuntime(machineId, updateTarget),
    onSuccess: async result => {
      toast.success(
        result?.request_id
          ? `TX-Node 更新请求已下发：${result.request_id}`
          : 'TX-Node 更新请求已下发',
      )
      await qc.invalidateQueries({ queryKey: ['machines'] })
    },
    onError: () => {
      toast.error('更新请求未下发，请检查机器在线状态与 Installer 更新桥接能力')
    },
  })

  useEffect(() => {
    setUpdateTarget(machine?.image_channel === 'dev' ? 'dev' : 'latest')
  }, [machineId, machine?.image_channel])

  useEffect(() => {
    credentialRequestId.current += 1
    window.clearTimeout(hideTimer.current)
    setCredentials(null)
    setCredentialsLoading(false)
    setActiveSection('overview')
  }, [open, machineId])

  useEffect(() => () => window.clearTimeout(hideTimer.current), [])

  if (!machine) return null

  const nodes = Array.isArray(nodesQuery.data) ? nodesQuery.data : []
  const online = isMachineOnline(machine)
  const cpu = finite(machine.load_status?.cpu)
  const memory = machineRatio(machine.load_status?.mem)
  const disk = machineRatio(machine.load_status?.disk)
  const runtime = machine.load_status?.runtime
  const lastRuntimeUpdate = runtime?.update
  const visibleCredentials = credentials?.machineId === machineId && activeSection === 'security' ? credentials : null
  const tabId = (section: MachineSection) => 'machine-' + machineId + '-' + section + '-tab'
  const panelId = (section: MachineSection) => 'machine-' + machineId + '-' + section + '-panel'

  function selectSection(next: MachineSection) {
    if (next !== 'security') {
      credentialRequestId.current += 1
      window.clearTimeout(hideTimer.current)
      setCredentials(null)
      setCredentialsLoading(false)
    }
    setActiveSection(next)
  }

  function onTabKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    const index = sections.findIndex(section => section.id === activeSection)
    let nextIndex = index
    if (event.key === 'ArrowRight') nextIndex = (index + 1) % sections.length
    else if (event.key === 'ArrowLeft') nextIndex = (index + sections.length - 1) % sections.length
    else if (event.key === 'Home') nextIndex = 0
    else if (event.key === 'End') nextIndex = sections.length - 1
    else return
    event.preventDefault()
    const next = sections[nextIndex].id
    selectSection(next)
    dialogRef.current?.querySelector<HTMLButtonElement>('[data-machine-section="' + next + '"]')?.focus()
  }

  async function showCredentials() {
    window.clearTimeout(hideTimer.current)
    const requestId = ++credentialRequestId.current
    setCredentialsLoading(true)
    try {
      const result = await getMachineCredentials(machineId)
      if (credentialRequestId.current !== requestId) return
      setCredentials({ machineId, token: result.token, command: result.install_command })
      hideTimer.current = window.setTimeout(() => {
        credentialRequestId.current += 1
        setCredentials(null)
      }, 18_000)
    } catch {
      if (credentialRequestId.current === requestId) toast.error('无法读取机器凭据')
    } finally {
      if (credentialRequestId.current === requestId) setCredentialsLoading(false)
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

        <nav className="machine-ops-tabs" role="tablist" aria-label="机器运维分类">
          {sections.map(section => {
            const Icon = section.icon
            const selected = activeSection === section.id
            return <button
              type="button"
              key={section.id}
              id={tabId(section.id)}
              data-machine-section={section.id}
              role="tab"
              aria-selected={selected}
              aria-controls={panelId(section.id)}
              tabIndex={selected ? 0 : -1}
              className={'machine-ops-tab' + (selected ? ' is-active' : '')}
              onClick={() => selectSection(section.id)}
              onKeyDown={onTabKeyDown}
            ><Icon size={16} aria-hidden="true"/>{section.label}</button>
          })}
        </nav>

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

            {activeSection === 'overview' ? <section
              id={panelId('overview')} role="tabpanel" aria-labelledby={tabId('overview')}
              className="machine-ops-panel"
            >
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
            </section> : null}

            {activeSection === 'runtime' ? <section
              id={panelId('runtime')} role="tabpanel" aria-labelledby={tabId('runtime')}
              className="machine-ops-panel"
            >
            <section className="machine-ops-card machine-runtime-card">
              <div className="machine-ops-card-head">
                <div>
                  <h3>TX-Node Runtime</h3>
                  <p>Machine 级运行时生命周期；实际升级与回滚由 TX-Node Installer 执行。</p>
                </div>
                <div className="field">
                  <span>升级 / 切换到</span>
                  <select value={updateTarget} onChange={event => setUpdateTarget(event.target.value as 'latest' | 'dev')} aria-label="选择运行时镜像渠道">
                    <option value="latest">稳定版（latest）</option>
                    <option value="dev">开发版（dev）</option>
                  </select>
                </div>
                <button
                  type="button"
                  className="button primary"
                  disabled={
                    runtimeUpdate.isPending ||
                    !online ||
                    machine.is_active === false ||
                    runtime?.updater_available !== true ||
                    (updateTarget === 'dev' && !runtime?.update_targets?.includes('dev'))
                  }
                  onClick={() => requestConfirm({
                    title: '更新 TX-Node Runtime',
                    message: `确认将 ${machine.name || `Machine #${machine.id}`} 切换/更新到 ${updateTarget === "dev" ? "开发版 dev" : "稳定版 latest"}？更新会重启 TX-Node，当前承载的 ${Number(machine.servers_count || nodes.length || 0)} 个节点可能短暂断开。失败时 Installer 会尝试恢复之前的镜像。`,
                    danger: true,
                    confirmLabel: updateTarget === 'dev' ? '切换到开发版' : '切换到稳定版',
                    action: () => runtimeUpdate.mutate(),
                  })}
                >
                  <ArrowUpCircle size={14} />
                  {runtimeUpdate.isPending ? '下发中…' : updateTarget === 'dev' ? '切换/更新到 dev' : '切换/更新到 latest'}
                </button>
              </div>

              <div className="machine-runtime-summary">
                <div>
                  <span>当前版本</span>
                  <strong>{runtime?.version || '未上报'}</strong>
                  <small>{runtime?.build_time ? `构建 ${runtime.build_time}` : '等待新版 TX-Node 上报 build 信息'}</small>
                </div>
                <div>
                  <span>部署方式</span>
                  <strong>{runtime?.deployment === 'docker' ? 'Docker' : 'Unknown'}</strong>
                  <small>{runtime?.updater_available ? `Installer bridge 可用：${runtime.update_targets?.join(', ') || 'latest（旧版）'}` : '远程更新不可用'}</small>
                </div>
                <div>
                  <span>最近更新</span>
                  <strong>{lastRuntimeUpdate ? runtimeUpdateLabel(lastRuntimeUpdate.status) : '暂无记录'}</strong>
                  <small>{lastRuntimeUpdate?.updated_at ? formatEpoch(lastRuntimeUpdate.updated_at) : '尚未收到更新状态'}</small>
                </div>
              </div>

              {lastRuntimeUpdate?.message ? (
                <div className={`machine-runtime-result machine-runtime-${lastRuntimeUpdate.status}`}>
                  <strong>{lastRuntimeUpdate.request_id}</strong>
                  <span>{lastRuntimeUpdate.message}</span>
                </div>
              ) : null}

              {runtimeUpdate.isSuccess && runtimeUpdate.data?.request_id !== lastRuntimeUpdate?.request_id ? (
                <div className="machine-runtime-result machine-runtime-running">
                  <strong>{runtimeUpdate.data?.request_id || 'update accepted'}</strong>
                  <span>请求已下发，等待 TX-Node 重启并重新上报最终状态。断开 WebSocket 本身不代表更新失败。</span>
                </div>
              ) : null}

              {runtime?.updater_available !== true ? (
                <div className="machine-ops-sensitive-note">
                  <ShieldAlert size={15} />
                  <span>当前 TX-Node / Installer 尚未提供 Machine Runtime Update v1。可继续在服务器使用公开 Installer 的 <code>txnode upgrade</code>，升级到支持版本后这里会自动显示可用状态。</span>
                </div>
              ) : null}
            </section>
            </section> : null}

            {activeSection === 'security' ? <section
              id={panelId('security')} role="tabpanel" aria-labelledby={tabId('security')}
              className="machine-ops-panel"
            >
            <section className="machine-ops-card">
              <div className="machine-ops-card-head">
                <div>
                  <h3>服务器 Token 与 TX-Node 接入</h3>
                  <p>凭据按需读取并在 18 秒后从界面自动隐藏。</p>
                </div>
                {!visibleCredentials ? (
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

              {!visibleCredentials ? (
                <div className="machine-ops-sensitive-note">
                  <ShieldAlert size={15} />
                  <span>Machine Token 属于高敏感凭据。仅在受信任环境中查看；重置后旧 TX-Node 进程需要重新配置。</span>
                </div>
              ) : (
                <div className="machine-ops-credentials">
                  <div>
                    <strong>Machine Token</strong>
                    <pre className="code-block">{visibleCredentials.token || '-'}</pre>
                  </div>
                  <div>
                    <strong>安装 / 接入命令</strong>
                    <pre className="code-block">{visibleCredentials.command || '-'}</pre>
                  </div>
                  <div className="machine-ops-credential-actions">
                    <button className="button" onClick={() => void copyText(visibleCredentials.token, 'Token')}>
                      <KeyRound size={14} />复制 Token
                    </button>
                    <button className="button primary" onClick={() => void copyText(visibleCredentials.command, '安装命令')}>
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
            </section> : null}

            {activeSection === 'nodes' ? <section
              id={panelId('nodes')} role="tabpanel" aria-labelledby={tabId('nodes')}
              className="machine-ops-panel"
            >
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
                <div className="machine-ops-empty">
                  <Boxes size={25} aria-hidden="true"/>
                  <strong>该机器暂未绑定节点</strong>
                  <p>可以通过节点绑定将已有节点关联到当前服务器。</p>
                  <button type="button" className="button" onClick={() => onBind(machine)}>绑定节点</button>
                </div>
              ) : null}
            </section>
            </section> : null}
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
      <span className="machine-ops-progress" role="progressbar"
        aria-label={label + ' 使用率'} aria-valuemin={0} aria-valuemax={100}
        aria-valuenow={value === null ? undefined : Math.min(100, Math.max(0, value))}
        aria-valuetext={value === null ? '暂无数据' : value.toFixed(1) + '%'}>
        <i style={{ width: width + '%' }} />
      </span>
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

function runtimeUpdateLabel(status: string) {
  switch (status) {
    case 'accepted': return '已接受'
    case 'running': return '更新中'
    case 'succeeded': return '更新成功'
    case 'rolled_back': return '已自动回滚'
    case 'failed': return '更新失败'
    default: return status || '未知'
  }
}

function formatEpoch(value: number) {
  if (!Number.isFinite(value) || value <= 0) return '-'
  return new Date(value * 1000).toLocaleString()
}

function loadFreshness(machine: MachineItem) {
  return machine.load_status?.updated_at
    ? `采样于 ${formatTime(machine.load_status.updated_at)}`
    : '最近一次心跳负载'
}
