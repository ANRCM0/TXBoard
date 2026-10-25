import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, coreOperations, type CoreOutput } from '@txboard/core-sdk'
import { Network, RefreshCw } from 'lucide-react'
import { t, useLocale } from '../lib/i18n'
import { DetailSection } from '../components/ui/DetailSection'
import { DataTable } from '../components/ui/DataTable'
import { CopyButton } from '../components/ui/CopyButton'
import { admin, workflowActions } from './session'
import { managementError } from './Feedback'
import { useAdminConfirmation } from './useAdminConfirmation'

type Receipt = CoreOutput<'network.nodes.connections.result.get'>
type ListInput = { node_id: string; after?: string; limit?: number }
type CloseInput = { node_id: string; connection_id: string }

export function NodeConnections({ nodeId, blocked }: { nodeId: string; blocked: boolean }) {
  useLocale()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [request, setRequest] = useState<Receipt>()
  const [snapshot, setSnapshot] = useState<Receipt>()
  const [error, setError] = useState('')
  const [denied, setDenied] = useState(false)
  const mounted = useRef(true)
  const generation = useRef(workflowActions.generation())
  const sessionChanged = workflowActions.generation() !== generation.current
  const active = () => mounted.current && workflowActions.generation() === generation.current
  const confirm = useAdminConfirmation(() => !blocked && !denied, nodeId)
  const result = useQuery({ queryKey: ['native-admin', 'node-connections', nodeId, request?.request_id],
    queryFn: async ({ signal }) => {
      const expected = request!
      const response = await admin.read('network.nodes.connections.result.get', { node_id: nodeId, request_id: expected.request_id }, signal)
      const actual = response.data
      if (actual.node_id !== nodeId || actual.request_id !== expected.request_id || actual.action !== expected.action
        || actual.deadline !== expected.deadline || actual.connection_id !== expected.connection_id) throw new Error('NODE_CONNECTION_RESULT_MISMATCH')
      return response
    },
    enabled: !!request && !blocked && !denied && !sessionChanged, retry: false,
    refetchInterval: query => query.state.data?.data.status === 'pending' ? 1000 : false })
  const receipt = result.data?.data ?? request
  const waiting = !!request && (!result.data || receipt?.status === 'pending') && !result.error
  const pending = state.pending?.operation === 'network.nodes.connections.list.request' || state.pending?.operation === 'network.nodes.connections.close'
    ? state.pending : undefined
  const resultDenied = result.error instanceof CoreAdminApiError && [401, 403].includes(result.error.status)
  const locked = blocked || denied || resultDenied || sessionChanged || state.busy || !!state.pending || waiting

  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => {
    if (result.data?.data.status === 'complete') setSnapshot(result.data.data)
  }, [result.data])
  useEffect(() => {
    if (result.error instanceof CoreAdminApiError && [401, 403].includes(result.error.status)) {
      setDenied(true); setSnapshot(undefined); setRequest(undefined)
    }
  }, [result.error])

  async function send(operation: 'network.nodes.connections.list.request' | 'network.nodes.connections.close', input: ListInput | CloseInput) {
    setError('')
    try {
      const response = operation === 'network.nodes.connections.list.request'
        ? await workflowActions.runResult(operation, input as ListInput)
        : await workflowActions.runResult(operation, input as CloseInput)
      if (!active()) return
      const body = response as { data?: unknown }
      const next = coreOperations['network.nodes.connections.result.get'].output.parse(body.data)
      setRequest(next)
      if (operation === 'network.nodes.connections.list.request') setSnapshot(undefined)
    } catch (failure) {
      if (!active()) return
      setError(managementError(failure, false))
      if (failure instanceof CoreAdminApiError && [401, 403].includes(failure.status)) {
        setDenied(true); setSnapshot(undefined); setRequest(undefined)
      }
    }
  }

  async function close(id: string) {
    if (locked || !(await confirm(t('确认关闭此连接？只影响当前节点上所选连接，关闭后客户端可能重新连接。')))) return
    if (!active()) return
    await send('network.nodes.connections.close', { node_id: nodeId, connection_id: id })
  }

  const messages: Record<string, string> = {
    pending: '正在等待节点返回结果…', complete: '已取得节点连接快照。', closed: '节点已确认关闭连接，请刷新列表查看当前状态。',
    not_found: '连接或分页游标已失效，请刷新列表。', invalid: '连接标识已失效，请刷新列表。',
    expired: '请求已过期，请刷新列表核对当前状态。', unavailable: '节点连接管理暂不可用，请确认节点在线且已升级。',
    unknown: '未取得节点确认，操作结果未知。请刷新列表核对，不会自动再次关闭连接。',
  }
  const stale = receipt?.action === 'close' || receipt?.status !== 'complete' || !!result.error
  return <DetailSection label={t('实时连接')} title={t('连接管理')} icon={<Network size={17} />} className="network-settings-card"
    description={t('按节点查看连接并关闭所选连接。列表为即时快照，客户端可能重新连接。')}
    actions={<button type="button" className="button" disabled={locked} onClick={() => void send('network.nodes.connections.list.request', { node_id: nodeId, limit: 50 })}>
      <RefreshCw size={15} aria-hidden="true" />{t('读取连接列表')}</button>}>
    {denied || resultDenied || sessionChanged ? <p role="alert">{t('当前账号没有此操作的权限，已隐藏资源数据。')}</p> : <>
      {error || result.error ? <p role="alert">{error ? t(error) : managementError(result.error)}</p> : null}
      {pending ? <div role="status"><p>{t('原连接请求等待确认。确认时复用原请求，不会自动重试关闭。')}</p>
        <button type="button" className="button" disabled={blocked || state.busy || pending.input.node_id !== nodeId}
          onClick={async () => {
            if (!(await confirm(t('确认继续查询或完成这项原操作？')))) return
            if (pending.operation === 'network.nodes.connections.list.request') void send(pending.operation, pending.input)
            else if (pending.operation === 'network.nodes.connections.close') void send(pending.operation, pending.input)
          }}>{t('确认原连接请求')}</button></div> : null}
      {receipt ? <p role="status">{t(messages[receipt.status])}</p> : <p className="network-help">{t('读取列表后可查看连接。每页最多显示 50 条，不展示地址或凭据。')}</p>}
      {snapshot ? <>
        <p className="network-help">{t('快照共 {total} 条连接', { total: snapshot.total })}</p>
        <DataTable rows={snapshot.connections} rowKey={row => row.id} label={t('节点实时连接')} empty={t('暂无活动连接')}
          mobilePrimaryKey="id" columns={[
            { key: 'id', header: t('连接 ID'), render: row => <span className="network-copy-value"><code>{row.id}</code><CopyButton value={row.id} label={t('复制连接 ID')} /></span> },
            { key: 'user', header: t('用户 ID'), render: row => row.user_id },
            { key: 'transport', header: t('传输'), render: row => row.transport.toUpperCase() },
            { key: 'actions', header: t('操作'), pin: 'right', render: row => <button type="button" className="button danger" disabled={locked || stale} onClick={() => void close(row.id)}>{t('关闭连接')}</button> },
          ]} />
        {snapshot.next ? <button type="button" className="button" disabled={locked || stale}
          onClick={() => void send('network.nodes.connections.list.request', { node_id: nodeId, after: snapshot.next, limit: 50 })}>{t('下一页连接')}</button> : null}
      </> : null}
    </>}
  </DetailSection>
}
