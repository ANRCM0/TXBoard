import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bot, Check, Copy, ExternalLink, KeyRound, ShieldCheck, Trash2, X } from 'lucide-react'
import { useEffect, useMemo, useState, type Dispatch, type SetStateAction } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import {
  approveAgentAction,
  getAgentFleetHealth,
  getAgentInspections,
  createAgentToken,
  getAgentAbilities,
  getAgentActions,
  getAgentTokens,
  rejectAgentAction,
  runAgentInspection,
  revokeAgentToken,
  type AgentActionItem,
  type AgentPairing,
} from '../../api/agent'
import { getMachines, getNodes } from '../../api/server'
import { PageHeader } from '../../components/ui/PageHeader'
import { Modal } from '../../components/ui/Modal'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { agentSelfConnectGuideUrl, buildAgentSelfConnectPrompt } from '../../lib/agentSelfConnect'

export function AgentOpsPage() {
  const qc = useQueryClient()
  const abilities = useQuery({ queryKey: ['agentAbilities'], queryFn: getAgentAbilities })
  const tokens = useQuery({ queryKey: ['agentTokens'], queryFn: getAgentTokens })
  const nodes = useQuery({ queryKey: ['agentScopeNodes'], queryFn: getNodes })
  const machines = useQuery({ queryKey: ['agentScopeMachines'], queryFn: getMachines })
  const fleet = useQuery({
    queryKey: ['agentFleetHealth'],
    queryFn: getAgentFleetHealth,
    refetchInterval: 30_000,
  })
  const inspections = useQuery({
    queryKey: ['agentInspections'],
    queryFn: () => getAgentInspections(10),
    refetchInterval: 30_000,
  })
  const actions = useQuery({
    queryKey: ['agentActions'],
    queryFn: () => getAgentActions(),
    refetchInterval: 5_000,
  })

  const [clientName, setClientName] = useState('')
  const [expires, setExpires] = useState(30)
  const [selected, setSelected] = useState<string[]>([])
  const [targetMode, setTargetMode] = useState<'all' | 'restricted'>('all')
  const [targetNodeIds, setTargetNodeIds] = useState<number[]>([])
  const [targetMachineIds, setTargetMachineIds] = useState<number[]>([])
  const [plainToken, setPlainToken] = useState('')
  const [pairing, setPairing] = useState<AgentPairing | null>(null)
  const [connectModalOpen, setConnectModalOpen] = useState(false)
  const [rejectingAction, setRejectingAction] = useState<AgentActionItem | null>(null)
  const [rejectReason, setRejectReason] = useState('')
  const selfConnectGuideUrl = useMemo(() => agentSelfConnectGuideUrl(window.location.origin), [])
  const selfConnectPrompt = useMemo(
    () => buildAgentSelfConnectPrompt(selfConnectGuideUrl, pairing?.code),
    [selfConnectGuideUrl, pairing?.code],
  )

  useEffect(() => {
    if (!selected.length && abilities.data?.default_read?.length) {
      setSelected(abilities.data.default_read)
    }
  }, [abilities.data, selected.length])

  const createToken = useMutation({
    mutationFn: createAgentToken,
    onSuccess: data => {
      setPlainToken(data?.plain_text_token || '')
      setPairing(data?.pairing || null)
      setConnectModalOpen(Boolean(data?.plain_text_token))
      setClientName('')
      setTargetMode('all')
      setTargetNodeIds([])
      setTargetMachineIds([])
      void qc.invalidateQueries({ queryKey: ['agentTokens'] })
      toast.success('Agent Token 已创建，仅本次显示明文')
    },
  })

  const runInspection = useMutation({
    mutationFn: runAgentInspection,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['agentFleetHealth'] })
      void qc.invalidateQueries({ queryKey: ['agentInspections'] })
      toast.success('Fleet 巡检已完成')
    },
  })

  const revokeToken = useMutation({
    mutationFn: revokeAgentToken,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['agentTokens'] })
      toast.success('Token 已撤销')
    },
  })

  const approve = useMutation({
    mutationFn: approveAgentAction,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['agentActions'] })
      toast.success('运维动作已批准并下发')
    },
  })

  const reject = useMutation({
    mutationFn: ({ requestId, reason }: { requestId: string; reason?: string }) =>
      rejectAgentAction(requestId, reason),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['agentActions'] })
      setRejectingAction(null)
      setRejectReason('')
      toast.success('运维动作已拒绝')
    },
  })

  const pending = useMemo(
    () => (actions.data || []).filter(item => item.status === 'pending'),
    [actions.data],
  )

  function toggleAbility(ability: string) {
    setSelected(current =>
      current.includes(ability)
        ? current.filter(item => item !== ability)
        : [...current, ability],
    )
  }

  function toggleNumber(value: number, setter: Dispatch<SetStateAction<number[]>>) {
    setter(current =>
      current.includes(value)
        ? current.filter(item => item !== value)
        : [...current, value],
    )
  }

  async function copyToken() {
    if (!plainToken) return
    await navigator.clipboard.writeText(plainToken)
    toast.success('已复制 Token')
  }

  async function copySelfConnectPrompt() {
    await navigator.clipboard.writeText(selfConnectPrompt)
    toast.success('已复制 Agent 自助接入提示词')
  }

  return (
    <>
      <PageHeader
        title="Agent 运维"
        description="管理 MCP / Agent 凭据与审批队列。Agent 写操作必须经过这里的人工批准后才会下发到 TX-Node。"
      />

      <div className="card form-stack">
        <div className="card-header">
          <div>
            <h2>AI-native Fleet Health</h2>
            <p className="text-muted">定时巡检每 5 分钟生成一次规范化健康快照；这里不会保存原始日志或凭据。</p>
          </div>
          <button
            type="button"
            className="button"
            disabled={runInspection.isPending}
            onClick={() => runInspection.mutate()}
          >
            {runInspection.isPending ? '巡检中…' : '立即巡检'}
          </button>
        </div>

        <QueryFeedback loading={fleet.isFetching && !fleet.data} error={fleet.isError} onRetry={() => fleet.refetch()} />

        <div className="chip-list">
          <span className="badge">状态 {fleet.data?.status || 'unknown'}</span>
          <span className="badge">节点 {fleet.data?.summary.total_nodes ?? 0}</span>
          <span className="badge">Critical {fleet.data?.summary.critical_nodes ?? 0}</span>
          <span className="badge">Degraded {fleet.data?.summary.degraded_nodes ?? 0}</span>
          <span className="badge">Healthy {fleet.data?.summary.healthy_nodes ?? 0}</span>
        </div>

        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>节点</th>
                <th>状态</th>
                <th>WebSocket</th>
                <th>Kernel</th>
                <th>Warnings</th>
              </tr>
            </thead>
            <tbody>
              {(fleet.data?.nodes || []).map(node => (
                <tr key={node.node_id}>
                  <td><Link className="table-link" to={`/server/node/${node.node_id}`}><strong>{node.name}</strong></Link> <code>#{node.node_id}</code></td>
                  <td><span className="badge">{node.status}</span></td>
                  <td>{node.websocket ? 'online' : 'offline'}</td>
                  <td>{node.kernel_running == null ? 'unknown' : node.kernel_running ? 'running' : 'stopped'}</td>
                  <td>{node.warnings.map(item => item.code).join(', ') || '-'}</td>
                </tr>
              ))}
              {!fleet.data?.nodes?.length && !fleet.isError ? (
                <tr><td colSpan={5} className="empty-cell">{fleet.isLoading ? '加载中…' : '暂无节点'}</td></tr>
              ) : null}
            </tbody>
          </table>
        </div>

        <div>
          <strong>最近巡检</strong>
          <QueryFeedback loading={inspections.isFetching && !inspections.data} error={inspections.isError} onRetry={() => inspections.refetch()} />
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>来源</th>
                  <th>状态</th>
                  <th>Critical / Degraded</th>
                  <th>时间</th>
                </tr>
              </thead>
              <tbody>
                {(inspections.data || []).map(item => (
                  <tr key={item.inspection_id}>
                    <td><code>{item.inspection_id}</code></td>
                    <td>{item.source}</td>
                    <td><span className="badge">{item.status}</span></td>
                    <td>{item.summary.critical_nodes} / {item.summary.degraded_nodes}</td>
                    <td>{formatEpoch(item.finished_at)}</td>
                  </tr>
                ))}
                {!inspections.data?.length && !inspections.isError ? (
                  <tr><td colSpan={5} className="empty-cell">{inspections.isLoading ? '加载中…' : '暂无巡检记录'}</td></tr>
                ) : null}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div className="card form-stack">
        <div>
          <h2>创建 Agent Token</h2>
          <p className="text-muted">Token 使用最小权限能力集；明文只在创建成功后显示一次。</p>
        </div>

        <div className="form-grid">
          <label>
            <span>客户端名称</span>
            <input
              value={clientName}
              onChange={event => setClientName(event.target.value)}
              placeholder="例如 chatgpt-prod"
            />
          </label>
          <label>
            <span>有效期（天）</span>
            <input
              type="number"
              min={1}
              max={90}
              value={expires}
              onChange={event => setExpires(Number(event.target.value) || 30)}
            />
          </label>
        </div>

        <div>
          <span>Abilities</span>
          <div className="chip-list">
            {(abilities.data?.all || []).map(ability => (
              <label className="badge" key={ability}>
                <input
                  type="checkbox"
                  checked={selected.includes(ability)}
                  onChange={() => toggleAbility(ability)}
                />
                {ability}
              </label>
            ))}
          </div>
        </div>

        <div className="form-stack">
          <span>资源范围</span>
          <div className="chip-list">
            <label className="badge">
              <input
                type="radio"
                name="agent-target-mode"
                checked={targetMode === 'all'}
                onChange={() => setTargetMode('all')}
              />
              全部节点 / 机器
            </label>
            <label className="badge">
              <input
                type="radio"
                name="agent-target-mode"
                checked={targetMode === 'restricted'}
                onChange={() => setTargetMode('restricted')}
              />
              限定资源
            </label>
          </div>

          {targetMode === 'restricted' ? (
            <>
              <div>
                <strong>机器</strong>
                <div className="chip-list">
                  {(machines.data || []).map(machine => (
                    <label className="badge" key={machine.id}>
                      <input
                        type="checkbox"
                        checked={targetMachineIds.includes(machine.id)}
                        onChange={() => toggleNumber(machine.id, setTargetMachineIds)}
                      />
                      {machine.name || `Machine #${machine.id}`}
                    </label>
                  ))}
                  {!machines.data?.length ? <span className="text-muted">暂无机器</span> : null}
                </div>
              </div>
              <div>
                <strong>单独节点</strong>
                <div className="chip-list">
                  {(nodes.data || []).map(node => (
                    <label className="badge" key={node.id}>
                      <input
                        type="checkbox"
                        checked={targetNodeIds.includes(node.id)}
                        onChange={() => toggleNumber(node.id, setTargetNodeIds)}
                      />
                      {node.name || `Node #${node.id}`}
                    </label>
                  ))}
                  {!nodes.data?.length ? <span className="text-muted">暂无节点</span> : null}
                </div>
              </div>
            </>
          ) : null}
        </div>

        <div>
          <button
            type="button"
            className="button button-primary"
            disabled={
              !clientName.trim() ||
              !selected.length ||
              createToken.isPending ||
              (targetMode === 'restricted' && !targetNodeIds.length && !targetMachineIds.length)
            }
            onClick={() =>
              createToken.mutate({
                client_name: clientName.trim(),
                abilities: selected,
                expires_in_days: expires,
                target_mode: targetMode,
                target_node_ids: targetNodeIds,
                target_machine_ids: targetMachineIds,
              })
            }
          >
            <KeyRound size={16} />
            创建 Token
          </button>
        </div>

        {plainToken ? (
          <div className="callout warning">
            <strong>请立即保存此 Token，关闭页面后不会再次显示。</strong>
            <pre className="code-block">{plainToken}</pre>
            <div className="card-actions">
              <button type="button" className="button" onClick={() => void copyToken()}>
                <Copy size={15} />复制 Token
              </button>
              <button type="button" className="button button-primary" onClick={() => setConnectModalOpen(true)}>
                <Bot size={15} />Agent 自助接入
              </button>
            </div>
          </div>
        ) : null}
      </div>

      <div className="card content-table-card">
        <div className="card-header">
          <div>
            <h2>Agent Token</h2>
            <p className="text-muted">撤销后对应 MCP / Agent 会立即失去访问权限。</p>
          </div>
        </div>
        <QueryFeedback loading={tokens.isFetching && !tokens.data} error={tokens.isError} onRetry={() => tokens.refetch()} />
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>客户端</th>
                <th>Abilities</th>
                <th>资源范围</th>
                <th>最后使用</th>
                <th>过期时间</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              {(tokens.data || []).map(token => (
                <tr key={token.id}>
                  <td><strong>{token.client_name}</strong></td>
                  <td>{token.abilities?.length || 0} 项</td>
                  <td>{formatScope(token.target_scope)}</td>
                  <td>{formatDate(token.last_used_at)}</td>
                  <td>{formatDate(token.expires_at)}</td>
                  <td>
                    <button
                      type="button"
                      className="icon-button danger"
                      aria-label="撤销 Token"
                      disabled={revokeToken.isPending}
                      onClick={() => requestConfirm({
                        title: '撤销 Agent Token',
                        message: `确认撤销「${token.client_name}」？撤销后该客户端会立即失去 Agent Ops 访问权限。`,
                        danger: true,
                        confirmLabel: '撤销 Token',
                        action: () => revokeToken.mutate(token.id),
                      })}
                    >
                      <Trash2 size={15} />
                    </button>
                  </td>
                </tr>
              ))}
              {!tokens.data?.length && !tokens.isError ? (
                <tr><td colSpan={6} className="empty-cell">{tokens.isLoading ? '加载中…' : '暂无 Agent Token'}</td></tr>
              ) : null}
            </tbody>
          </table>
        </div>
      </div>

      <div className="card content-table-card">
        <div className="card-header">
          <div>
            <h2>待审批动作</h2>
            <p className="text-muted">Agent 无法自行批准动作。批准后 TXBoard 才会通过现有 Redis / WebSocket 控制通道下发。</p>
          </div>
          <span className="badge">{pending.length} pending</span>
        </div>
        <QueryFeedback loading={actions.isFetching && !actions.data} error={actions.isError} onRetry={() => actions.refetch()} />
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Request ID</th>
                <th>Node</th>
                <th>Action</th>
                <th>风险</th>
                <th>状态</th>
                <th>结果</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              {(actions.data || []).map(action => {
                const nodeName = (nodes.data || []).find(node => node.id === action.node_id)?.name
                return (
                  <ActionRow
                    key={action.request_id}
                    action={action}
                    nodeName={nodeName}
                    approving={approve.isPending}
                    rejecting={reject.isPending}
                    onApprove={() => requestConfirm({
                      title: '批准运维动作',
                      message: `确认批准 ${action.action}？目标：${nodeName || 'Node'} #${action.node_id}；风险：${action.risk_level}。批准后动作会立即通过控制通道下发。`,
                      danger: ['high', 'critical'].includes(String(action.risk_level).toLowerCase()),
                      confirmLabel: '批准并下发',
                      action: () => approve.mutate(action.request_id),
                    })}
                    onReject={() => {
                      setRejectingAction(action)
                      setRejectReason('')
                    }}
                  />
                )
              })}
              {!actions.data?.length && !actions.isError ? (
                <tr><td colSpan={7} className="empty-cell">{actions.isLoading ? '加载中…' : '暂无 Agent 动作'}</td></tr>
              ) : null}
            </tbody>
          </table>
        </div>
      </div>

      <Modal
        open={connectModalOpen && Boolean(plainToken)}
        title="让 AI Agent 接入 TXBoard"
        subtitle="把提示词交给 Hermes、OpenClaw 或其他 MCP Agent，由它读取当前 TXBoard 版本的接入文档并自行配置。"
        wide
        onClose={() => setConnectModalOpen(false)}
      >
        <div className="form-stack">
          <div>
            <strong>1. 复制一句话给 Agent</strong>
            <p className="text-muted">
              {pairing
                ? `提示词包含一个仅可使用一次的临时配对码，有效至 ${formatDate(pairing.expires_at)}；长期 Agent Token 不会进入提示词。`
                : '临时配对服务当前不可用，提示词会退回 v1 手动 secret / env 接入方式。'}
            </p>
          </div>
          <pre className="code-block">{selfConnectPrompt}</pre>
          <div className="card-actions">
            <button type="button" className="button button-primary" onClick={() => void copySelfConnectPrompt()}>
              <Copy size={15} />复制接入提示词
            </button>
            <a className="button" href={selfConnectGuideUrl} target="_blank" rel="noreferrer">
              <ExternalLink size={15} />查看 Agent Guide
            </a>
          </div>

          <div className="callout warning">
            <strong>2. 长期 Agent Token（备用手动方式）</strong>
            <p>{pairing ? '正常情况下 Agent 会用一次性配对码自动兑换并本地保存 Token；这里仍保留原有一次性明文作为兼容与故障回退。' : '请通过 Agent 的本地 secret / env 机制提供 Token，不要把长期 Token 发到聊天里或提交到仓库。'}</p>
            <pre className="code-block">{plainToken}</pre>
            <button type="button" className="button" onClick={() => void copyToken()}>
              <Copy size={15} />复制 Token
            </button>
          </div>

          <div className="callout">
            <strong>Agent 会做什么？</strong>
            <p>它会检测自己的 MCP 配置方式；有配对码时先向 TXBoard 一次性兑换长期 Agent Token，再连接同域 <code>/mcp</code>，只用只读工具验证连接，并汇报非敏感结果。</p>
          </div>
        </div>
      </Modal>

      <Modal
        open={Boolean(rejectingAction)}
        title="拒绝运维动作"
        onClose={() => {
          if (reject.isPending) return
          setRejectingAction(null)
          setRejectReason('')
        }}
      >
        {rejectingAction ? (
          <div className="form-stack">
            <dl className="meta-list">
              <div><dt>目标节点</dt><dd>Node #{rejectingAction.node_id}</dd></div>
              <div><dt>动作</dt><dd><code>{rejectingAction.action}</code></dd></div>
              <div><dt>风险等级</dt><dd>{rejectingAction.risk_level}</dd></div>
              <div><dt>Request ID</dt><dd><code>{rejectingAction.request_id}</code></dd></div>
            </dl>
            <label className="field">
              <span>拒绝原因（可选）</span>
              <textarea
                value={rejectReason}
                onChange={event => setRejectReason(event.target.value)}
                placeholder="例如：节点正在承载高峰流量，暂不执行重启"
                maxLength={500}
              />
            </label>
            <div className="card-actions">
              <button className="button" disabled={reject.isPending} onClick={() => {
                setRejectingAction(null)
                setRejectReason('')
              }}>取消</button>
              <button
                className="button danger"
                disabled={reject.isPending}
                onClick={() => reject.mutate({
                  requestId: rejectingAction.request_id,
                  reason: rejectReason.trim() || undefined,
                })}
              >{reject.isPending ? '拒绝中…' : '确认拒绝'}</button>
            </div>
          </div>
        ) : null}
      </Modal>
    </>
  )
}

function ActionRow({
  action,
  nodeName,
  approving,
  rejecting,
  onApprove,
  onReject,
}: {
  action: AgentActionItem
  nodeName?: string
  approving: boolean
  rejecting: boolean
  onApprove: () => void
  onReject: () => void
}) {
  return (
    <tr>
      <td><code>{action.request_id}</code></td>
      <td><Link className="table-link" to={`/server/node/${action.node_id}`}><strong>{nodeName || 'Node'}</strong></Link><small className="table-sub">#{action.node_id}</small></td>
      <td><code>{action.action}</code></td>
      <td><span className="badge"><ShieldCheck size={13} /> {action.risk_level}</span></td>
      <td><span className="badge">{action.status}</span></td>
      <td>
        {action.error_code ? <code>{action.error_code}</code> : action.result ? <code>{compact(action.result)}</code> : '-'}
      </td>
      <td>
        {action.status === 'pending' ? (
          <div className="table-actions">
            <button type="button" className="icon-button" aria-label="批准" disabled={approving} onClick={onApprove}>
              <Check size={16} />
            </button>
            <button type="button" className="icon-button danger" aria-label="拒绝" disabled={rejecting} onClick={onReject}>
              <X size={16} />
            </button>
          </div>
        ) : '-'}
      </td>
    </tr>
  )
}

function compact(value: unknown) {
  const text = JSON.stringify(value)
  return text.length > 90 ? text.slice(0, 87) + '…' : text
}

function formatDate(value?: string | null) {
  if (!value) return '-'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString()
}


function formatScope(scope?: { mode: 'all' | 'restricted'; node_ids: number[]; machine_ids: number[] }) {
  if (!scope || scope.mode === 'all') return '全部资源'
  const parts: string[] = []
  if (scope.machine_ids.length) parts.push(`机器 ${scope.machine_ids.join(', ')}`)
  if (scope.node_ids.length) parts.push(`节点 ${scope.node_ids.join(', ')}`)
  return parts.join(' · ') || '无资源'
}


function formatEpoch(value?: number | null) {
  if (!value) return '-'
  return new Date(value * 1000).toLocaleString()
}
