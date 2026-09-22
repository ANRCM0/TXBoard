import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Copy, KeyRound, ShieldCheck, Trash2, X } from 'lucide-react'
import { useEffect, useMemo, useState, type Dispatch, type SetStateAction } from 'react'
import { toast } from 'sonner'
import {
  approveAgentAction,
  createAgentToken,
  getAgentAbilities,
  getAgentActions,
  getAgentTokens,
  rejectAgentAction,
  revokeAgentToken,
  type AgentActionItem,
} from '../../api/agent'
import { getMachines, getNodes } from '../../api/server'
import { PageHeader } from '../../components/ui/PageHeader'

export function AgentOpsPage() {
  const qc = useQueryClient()
  const abilities = useQuery({ queryKey: ['agentAbilities'], queryFn: getAgentAbilities })
  const tokens = useQuery({ queryKey: ['agentTokens'], queryFn: getAgentTokens })
  const nodes = useQuery({ queryKey: ['agentScopeNodes'], queryFn: getNodes })
  const machines = useQuery({ queryKey: ['agentScopeMachines'], queryFn: getMachines })
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

  useEffect(() => {
    if (!selected.length && abilities.data?.default_read?.length) {
      setSelected(abilities.data.default_read)
    }
  }, [abilities.data, selected.length])

  const createToken = useMutation({
    mutationFn: createAgentToken,
    onSuccess: data => {
      setPlainToken(data?.plain_text_token || '')
      setClientName('')
      setTargetMode('all')
      setTargetNodeIds([])
      setTargetMachineIds([])
      void qc.invalidateQueries({ queryKey: ['agentTokens'] })
      toast.success('Agent Token 已创建，仅本次显示明文')
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

  return (
    <>
      <PageHeader
        title="Agent 运维"
        description="管理 MCP / Agent 凭据与审批队列。Agent 写操作必须经过这里的人工批准后才会下发到 TX-Node。"
      />

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
            <button type="button" className="button" onClick={() => void copyToken()}>
              <Copy size={15} />复制
            </button>
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
                      onClick={() => revokeToken.mutate(token.id)}
                    >
                      <Trash2 size={15} />
                    </button>
                  </td>
                </tr>
              ))}
              {!tokens.data?.length ? (
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
              {(actions.data || []).map(action => (
                <ActionRow
                  key={action.request_id}
                  action={action}
                  approving={approve.isPending}
                  rejecting={reject.isPending}
                  onApprove={() => approve.mutate(action.request_id)}
                  onReject={() => {
                    const reason = window.prompt('拒绝原因（可选）') || undefined
                    reject.mutate({ requestId: action.request_id, reason })
                  }}
                />
              ))}
              {!actions.data?.length ? (
                <tr><td colSpan={7} className="empty-cell">{actions.isLoading ? '加载中…' : '暂无 Agent 动作'}</td></tr>
              ) : null}
            </tbody>
          </table>
        </div>
      </div>
    </>
  )
}

function ActionRow({
  action,
  approving,
  rejecting,
  onApprove,
  onReject,
}: {
  action: AgentActionItem
  approving: boolean
  rejecting: boolean
  onApprove: () => void
  onReject: () => void
}) {
  return (
    <tr>
      <td><code>{action.request_id}</code></td>
      <td>#{action.node_id}</td>
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
