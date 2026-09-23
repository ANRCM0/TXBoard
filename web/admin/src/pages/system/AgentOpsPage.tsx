import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Activity,
  Bot,
  Check,
  CircleCheckBig,
  Copy,
  ExternalLink,
  KeyRound,
  Server,
  ShieldCheck,
  Trash2,
  TriangleAlert,
  X,
} from 'lucide-react'
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
import './AgentOpsPage.css'

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

  const [createModalOpen, setCreateModalOpen] = useState(false)
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
      setCreateModalOpen(false)
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

  const fleetStatus = fleet.data?.status || 'unknown'
  const unhealthyNodes =
    (fleet.data?.summary.critical_nodes ?? 0) +
    (fleet.data?.summary.degraded_nodes ?? 0)
  const canCreate =
    Boolean(clientName.trim()) &&
    selected.length > 0 &&
    !createToken.isPending &&
    (targetMode === 'all' || targetNodeIds.length > 0 || targetMachineIds.length > 0)

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
    <div className="agent-ops-page">
      <PageHeader
        title="Agent 运维"
        description="统一管理 Agent 凭据、Fleet 健康状态与人工审批；MCP 仍通过 TXBoard Agent Ops 安全边界执行。"
        action={
          <div className="agent-page-actions">
            {plainToken ? (
              <button type="button" className="button" onClick={() => setConnectModalOpen(true)}>
                <Bot size={15} />接入信息
              </button>
            ) : null}
            <button type="button" className="button primary" onClick={() => setCreateModalOpen(true)}>
              <KeyRound size={15} />创建 Agent Token
            </button>
          </div>
        }
      />

      <div className="agent-overview-grid">
        <OverviewCard
          icon={<Activity size={18} />}
          label="Fleet 状态"
          value={fleetStatus}
          tone={fleetStatus === 'healthy' ? 'ok' : fleetStatus === 'critical' ? 'danger' : 'warn'}
        />
        <OverviewCard
          icon={<Server size={18} />}
          label="节点"
          value={String(fleet.data?.summary.total_nodes ?? 0)}
        />
        <OverviewCard
          icon={<CircleCheckBig size={18} />}
          label="Healthy"
          value={String(fleet.data?.summary.healthy_nodes ?? 0)}
          tone="ok"
        />
        <OverviewCard
          icon={<TriangleAlert size={18} />}
          label="异常节点"
          value={String(unhealthyNodes)}
          tone={unhealthyNodes > 0 ? 'warn' : 'ok'}
        />
        <OverviewCard
          icon={<KeyRound size={18} />}
          label="Agent Token / 待审批"
          value={`${tokens.data?.length ?? 0} / ${pending.length}`}
          tone={pending.length ? 'warn' : undefined}
        />
      </div>

      <section className="card agent-panel">
        <div className="agent-panel-head">
          <div>
            <h2>AI-native Fleet Health</h2>
            <p>定时巡检生成规范化健康快照；这里不会保存原始日志或凭据。</p>
          </div>
          <button
            type="button"
            className="button"
            disabled={runInspection.isPending}
            onClick={() => runInspection.mutate()}
          >
            <Activity size={14} />
            {runInspection.isPending ? '巡检中…' : '立即巡检'}
          </button>
        </div>

        <div className="agent-panel-body">
          <QueryFeedback loading={fleet.isFetching && !fleet.data} error={fleet.isError} onRetry={() => fleet.refetch()} />

          <div className="agent-health-summary">
            <StatusPill label={`状态 ${fleetStatus}`} status={fleetStatus} />
            <StatusPill label={`Critical ${fleet.data?.summary.critical_nodes ?? 0}`} status="critical" />
            <StatusPill label={`Degraded ${fleet.data?.summary.degraded_nodes ?? 0}`} status="degraded" />
            <StatusPill label={`Healthy ${fleet.data?.summary.healthy_nodes ?? 0}`} status="healthy" />
          </div>

          <div className="table-wrap">
            <table className="data-table agent-table">
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
                    <td>
                      <Link className="table-link" to={`/server/node/${node.node_id}`}>
                        <strong>{node.name}</strong>
                      </Link>{' '}
                      <code>#{node.node_id}</code>
                    </td>
                    <td><StatusPill label={node.status} status={node.status} /></td>
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

          <div className="agent-section-divider" />

          <div className="agent-subsection-title">
            <strong>最近巡检</strong>
            <span>最近 10 条</span>
          </div>
          <QueryFeedback loading={inspections.isFetching && !inspections.data} error={inspections.isError} onRetry={() => inspections.refetch()} />
          <div className="table-wrap">
            <table className="data-table agent-table">
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
                    <td><StatusPill label={item.status} status={item.status} /></td>
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
      </section>

      <section className="card agent-panel">
        <div className="agent-panel-head">
          <div>
            <h2>Agent Token</h2>
            <p>Token 使用最小权限能力集；撤销后对应 MCP / Agent 会立即失去访问权限。</p>
          </div>
          <button type="button" className="button" onClick={() => setCreateModalOpen(true)}>
            <KeyRound size={14} />创建 Token
          </button>
        </div>
        <QueryFeedback loading={tokens.isFetching && !tokens.data} error={tokens.isError} onRetry={() => tokens.refetch()} />
        <div className="agent-panel-body flush table-wrap">
          <table className="data-table agent-table">
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
                  <td>
                    <div className="agent-token-client">
                      <strong>{token.client_name}</strong>
                      <small>Token #{token.id}</small>
                    </div>
                  </td>
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
      </section>

      <section className="card agent-panel">
        <div className="agent-panel-head">
          <div>
            <h2>待审批动作</h2>
            <p>Agent 无法自行批准动作；批准后 TXBoard 才会通过既有控制通道下发。</p>
          </div>
          <span className="agent-status-pill"><span className={`agent-status-dot ${pending.length ? 'degraded' : 'healthy'}`} />{pending.length} pending</span>
        </div>
        <QueryFeedback loading={actions.isFetching && !actions.data} error={actions.isError} onRetry={() => actions.refetch()} />
        <div className="agent-panel-body flush table-wrap">
          <table className="data-table agent-table">
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
      </section>

      <Modal
        open={createModalOpen}
        title="创建 Agent Token"
        subtitle="为 Hermes、OpenClaw 或其他 Agent 创建最小权限凭据。"
        className="agent-token-modal"
        bodyClassName="agent-token-modal-body"
        onClose={() => {
          if (!createToken.isPending) setCreateModalOpen(false)
        }}
        footer={
          <div className="agent-modal-actions">
            <button type="button" className="button" disabled={createToken.isPending} onClick={() => setCreateModalOpen(false)}>
              取消
            </button>
            <button
              type="button"
              className="button primary"
              disabled={!canCreate}
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
              <KeyRound size={15} />
              {createToken.isPending ? '创建中…' : '创建 Token'}
            </button>
          </div>
        }
      >
        <div className="agent-token-form">
          <section className="agent-form-section">
            <div className="agent-form-section-head">
              <div>
                <strong>基本信息</strong>
                <small>名称用于审计识别；Token 明文只会在创建成功后显示一次。</small>
              </div>
            </div>
            <div className="agent-form-grid">
              <label className="agent-field">
                <span>客户端名称</span>
                <input
                  autoFocus
                  value={clientName}
                  onChange={event => setClientName(event.target.value)}
                  placeholder="例如 Hermes-prod"
                  maxLength={100}
                />
                <small>建议使用可识别环境的名称。</small>
              </label>
              <label className="agent-field">
                <span>有效期（天）</span>
                <input
                  type="number"
                  min={1}
                  max={90}
                  value={expires}
                  onChange={event => setExpires(Math.min(90, Math.max(1, Number(event.target.value) || 30)))}
                />
                <small>1–90 天</small>
              </label>
            </div>
          </section>

          <section className="agent-form-section">
            <div className="agent-form-section-head">
              <div>
                <strong>Abilities</strong>
                <small>默认选中后端声明的只读能力。写入、操作和危险能力需要主动勾选。</small>
              </div>
              <span className="agent-status-pill">{selected.length} selected</span>
            </div>
            <div className="agent-ability-grid">
              {(abilities.data?.all || []).map(ability => {
                const checked = selected.includes(ability)
                const sensitive = isSensitiveAbility(ability)
                return (
                  <label
                    className={`agent-ability-option ${checked ? 'selected' : ''} ${sensitive ? 'sensitive' : ''}`}
                    key={ability}
                  >
                    <input
                      type="checkbox"
                      checked={checked}
                      onChange={() => toggleAbility(ability)}
                    />
                    <span className="agent-ability-copy">
                      <code>{ability}</code>
                      <small>{abilityCaption(ability)}</small>
                    </span>
                  </label>
                )
              })}
              {!abilities.data?.all?.length ? <span className="agent-empty-hint">Abilities 加载中…</span> : null}
            </div>
          </section>

          <section className="agent-form-section">
            <div className="agent-form-section-head">
              <div>
                <strong>资源范围</strong>
                <small>限制 Agent 能够观察或操作的节点与机器范围。</small>
              </div>
            </div>
            <div className="agent-scope-picker">
              <label className={`agent-scope-option ${targetMode === 'all' ? 'active' : ''}`}>
                <input
                  type="radio"
                  name="agent-target-mode"
                  checked={targetMode === 'all'}
                  onChange={() => setTargetMode('all')}
                />
                <span>
                  <strong>全部节点 / 机器</strong>
                  <small>适合全局只读巡检或受控运维 Agent。</small>
                </span>
              </label>
              <label className={`agent-scope-option ${targetMode === 'restricted' ? 'active' : ''}`}>
                <input
                  type="radio"
                  name="agent-target-mode"
                  checked={targetMode === 'restricted'}
                  onChange={() => setTargetMode('restricted')}
                />
                <span>
                  <strong>限定资源</strong>
                  <small>只授权明确勾选的机器与节点。</small>
                </span>
              </label>
            </div>

            {targetMode === 'restricted' ? (
              <>
                <div className="agent-resource-group">
                  <strong>机器</strong>
                  <div className="agent-resource-grid">
                    {(machines.data || []).map(machine => (
                      <label className="agent-resource-option" key={machine.id}>
                        <input
                          type="checkbox"
                          checked={targetMachineIds.includes(machine.id)}
                          onChange={() => toggleNumber(machine.id, setTargetMachineIds)}
                        />
                        <span>{machine.name || `Machine #${machine.id}`}</span>
                      </label>
                    ))}
                    {!machines.data?.length ? <span className="agent-empty-hint">暂无机器</span> : null}
                  </div>
                </div>
                <div className="agent-resource-group">
                  <strong>单独节点</strong>
                  <div className="agent-resource-grid">
                    {(nodes.data || []).map(node => (
                      <label className="agent-resource-option" key={node.id}>
                        <input
                          type="checkbox"
                          checked={targetNodeIds.includes(node.id)}
                          onChange={() => toggleNumber(node.id, setTargetNodeIds)}
                        />
                        <span>{node.name || `Node #${node.id}`}</span>
                      </label>
                    ))}
                    {!nodes.data?.length ? <span className="agent-empty-hint">暂无节点</span> : null}
                  </div>
                </div>
              </>
            ) : null}
          </section>
        </div>
      </Modal>

      <Modal
        open={connectModalOpen && Boolean(plainToken)}
        title="让 AI Agent 接入 TXBoard"
        subtitle="把提示词交给 Hermes、OpenClaw 或其他 MCP Agent，由它读取当前 TXBoard 版本的接入文档并自行配置。"
        wide
        onClose={() => setConnectModalOpen(false)}
      >
        <div className="agent-connect-steps">
          <section className="agent-connect-step">
            <h4>1. 复制一句话给 Agent</h4>
            <p>
              {pairing
                ? `提示词包含一个仅可使用一次的临时配对码，有效至 ${formatDate(pairing.expires_at)}；长期 Agent Token 不会进入提示词。`
                : '临时配对服务当前不可用，提示词会退回 v1 手动 secret / env 接入方式。'}
            </p>
            <pre className="code-block">{selfConnectPrompt}</pre>
            <div className="card-actions">
              <button type="button" className="button primary" onClick={() => void copySelfConnectPrompt()}>
                <Copy size={15} />复制接入提示词
              </button>
              <a className="button" href={selfConnectGuideUrl} target="_blank" rel="noreferrer">
                <ExternalLink size={15} />查看 Agent Guide
              </a>
            </div>
          </section>

          <section className="agent-connect-step">
            <h4>2. 长期 Agent Token（备用手动方式）</h4>
            <p>{pairing ? '正常情况下 Agent 会用一次性配对码自动兑换并本地保存 Token；这里仍保留原有一次性明文作为兼容与故障回退。' : '请通过 Agent 的本地 secret / env 机制提供 Token，不要把长期 Token 发到聊天里或提交到仓库。'}</p>
            <pre className="code-block">{plainToken}</pre>
            <div className="card-actions">
              <button type="button" className="button" onClick={() => void copyToken()}>
                <Copy size={15} />复制 Token
              </button>
            </div>
          </section>

          <section className="agent-connect-step">
            <h4>Agent 会做什么？</h4>
            <p>它会检测自己的 MCP 配置方式；有配对码时先向 TXBoard 一次性兑换长期 Agent Token，再连接同域 <code>/mcp</code>，只用只读工具验证连接，并汇报非敏感结果。</p>
          </section>
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
    </div>
  )
}

function OverviewCard({
  icon,
  label,
  value,
  tone,
}: {
  icon: React.ReactNode
  label: string
  value: string
  tone?: 'ok' | 'warn' | 'danger'
}) {
  return (
    <div className="agent-overview-card">
      <span className={`agent-overview-icon ${tone || ''}`}>{icon}</span>
      <span className="agent-overview-copy">
        <span>{label}</span>
        <strong>{value}</strong>
      </span>
    </div>
  )
}

function StatusPill({ label, status }: { label: string; status: string }) {
  const normalized = String(status).toLowerCase()
  const tone = normalized === 'healthy'
    ? 'healthy'
    : normalized === 'critical'
      ? 'critical'
      : normalized === 'degraded'
        ? 'degraded'
        : ''
  return (
    <span className="agent-status-pill">
      <span className={`agent-status-dot ${tone}`} />
      {label}
    </span>
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

function isSensitiveAbility(ability: string) {
  return /(write|operate|sync|dangerous)/i.test(ability)
}

function abilityCaption(ability: string) {
  if (/dangerous/i.test(ability)) return '高风险能力'
  if (/(write|operate|sync)/i.test(ability)) return '变更 / 操作能力'
  return '只读 / 分析能力'
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
