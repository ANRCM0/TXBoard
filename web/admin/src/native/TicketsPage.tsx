import { useEffect, useState, useSyncExternalStore } from 'react'
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query'
import type { CoreInput, CoreOutput } from '@txboard/core-sdk'
import { CoreAdminApiError } from './api'
import { admin, attachmentTransfers, workflowActions } from './session'
import { AttachmentsPanel } from './AttachmentsPanel'
import { ManagementFeedback, managementError } from './Feedback'
import { ReadFields, ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import type { Column } from '../components/ui/DataTable'

type Ticket = CoreOutput<'support.admin-tickets.list'>[number]
const auditActions = { create: '创建工单', reply: '回复工单', close: '关闭工单', claim: '认领工单', unassign: '释放工单' }
const denied = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)

export function TicketsPage() {
  const client = useQueryClient()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const transfer = useSyncExternalStore(attachmentTransfers.subscribe, attachmentTransfers.snapshot)
  const [page, setPage] = useState(1), [status, setStatus] = useState(''), [userId, setUserId] = useState('')
  const [owner, setOwner] = useState(''), [selected, setSelected] = useState(''), [message, setMessage] = useState('')
  const [assignment, setAssignment] = useState<'any' | 'mine' | 'unassigned'>('any')
  const input: CoreInput<'support.admin-tickets.list'> = { page, per_page: 20,
    assignment, ...(status ? { status: Number(status) } : {}), ...(owner ? { user_id: owner } : {}) }
  const list = useQuery({ queryKey: ['native-admin', 'support', 'list', input],
    queryFn: ({ signal }) => admin.read('support.admin-tickets.list', input, signal) })
  const detail = useQuery({ queryKey: ['native-admin', 'support', 'detail', selected], enabled: !!selected,
    queryFn: ({ signal }) => admin.read('support.admin-tickets.get', { ticket_id: selected }, signal) })
  const messages = useInfiniteQuery({ queryKey: ['native-admin', 'support', 'messages', selected], enabled: !!selected,
    initialPageParam: '0',
    queryFn: ({ signal, pageParam }) => admin.read('support.admin-tickets.messages.list',
      { ticket_id: selected, after_id: pageParam, limit: 20 }, signal),
    getNextPageParam: result => result.meta.has_more ? result.meta.next_after_id : undefined })
  const audit = useInfiniteQuery({ queryKey: ['native-admin', 'support', 'audit', selected], enabled: !!selected,
    initialPageParam: '0',
    queryFn: ({ signal, pageParam }) => admin.read('support.admin-tickets.audit.list',
      { ticket_id: selected, after_id: pageParam, limit: 20 }, signal),
    getNextPageParam: result => result.meta.has_more ? result.meta.next_after_id : undefined })
  useEffect(() => {
    const ids = messages.data?.pages.flatMap(result => result.data.flatMap(row => row.attachments.map(file => file.id))) ?? []
    if (ids.length) attachmentTransfers.markAttached(ids)
  }, [messages.data])
  const attachmentBlocked = transfer.busy || transfer.pending || transfer.denied
    || transfer.entries.some(entry => entry.ticketId === selected && (!entry.metadata || entry.metadata.status === 'uploading'))
  const hasReadyAttachments = transfer.entries.some(entry => entry.ticketId === selected && entry.metadata?.status === 'ready')
  const ownPending = state.pending?.operation.startsWith('support.admin-tickets.') ? state.pending : undefined
  const blocked = denied(list.error) || denied(detail.error) || denied(messages.error) || denied(audit.error) || denied(state.error)
  async function refresh() {
    const generation = workflowActions.generation()
    try {
      await client.refetchQueries({ queryKey: ['native-admin', 'support'], type: 'active' }, { throwOnError: true })
      if (generation !== workflowActions.generation()) return
      workflowActions.clearError()
      workflowActions.confirmReadAuthorization('support.admin-tickets.')
    } catch { /* Query feedback retains the failed permission check. */ }
  }
  async function mutate(close: boolean) {
    const ticket = detail.data?.data
    if (!ticket || detail.isError || detail.isFetching || blocked || state.pending || state.busy) return
    if (attachmentBlocked || (close && hasReadyAttachments)) return
    const generation = workflowActions.generation()
    const target = { ticket_id: ticket.id, expected_version: ticket.row_version }
    try {
      if (close) await workflowActions.run('support.admin-tickets.close', target)
      else {
        const attachment_ids = attachmentTransfers.readyIds(ticket.id)
        await workflowActions.run('support.admin-tickets.reply', { ...target, message, attachment_ids })
        if (generation !== workflowActions.generation()) return
        attachmentTransfers.markAttached(attachment_ids)
      }
      if (generation !== workflowActions.generation()) return
      setMessage(''); await refresh()
    } catch (error) {
      if (error instanceof CoreAdminApiError && error.status === 409 && !error.needsStateConfirmation) await refresh()
    }
  }
  async function assign(operation: 'support.admin-tickets.claim' | 'support.admin-tickets.unassign') {
    const ticket = detail.data?.data
    if (!ticket || detail.isError || detail.isFetching || blocked || state.pending || state.busy) return
    const generation = workflowActions.generation()
    try {
      await workflowActions.run(operation, { ticket_id: ticket.id, expected_version: ticket.row_version })
      if (generation !== workflowActions.generation()) return
      await refresh()
    } catch (error) {
      if (error instanceof CoreAdminApiError && error.status === 409 && !error.needsStateConfirmation) await refresh()
    }
  }
  async function recover() {
    const action = ownPending
    if (!action || state.busy || blocked) return
    const generation = workflowActions.generation()
    try {
      await workflowActions.run(action.operation, action.input)
      if (generation !== workflowActions.generation()) return
      if (action.operation === 'support.admin-tickets.reply') attachmentTransfers.markAttached(action.input.attachment_ids ?? [])
      if ('ticket_id' in action.input) setSelected(action.input.ticket_id)
      if (generation !== workflowActions.generation()) return
      setMessage(''); await refresh()
    } catch { /* Preserve unknown outcomes across navigation. */ }
  }
  const columns: Column<Ticket>[] = [
    { key: 'subject', header: '主题', render: row => <div className="native-row-identity"><strong>{row.subject}</strong><small>#{row.id} · 用户 {row.user_id}</small></div> },
    { key: 'status', header: '状态', render: row => row.status === 0 ? '处理中' : '已关闭' },
    { key: 'reply', header: '回复', render: row => row.reply_status === 1 ? '客服已回复' : '等待客服回复' },
    { key: 'assignment', header: '负责人', render: row => row.assigned_admin_id ? '客服 #' + row.assigned_admin_id : '未分配' },
    { key: 'updated', header: '更新时间', render: row => row.updated_at },
    { key: 'open', header: '操作', render: row => <button className="button" onClick={() => { setSelected(row.id); setMessage('') }}>查看</button> },
  ]
  const ticket = !blocked && !detail.isError ? detail.data?.data : undefined
  const canReply = message.trim().length > 0 && new TextEncoder().encode(message).length <= 10000
  return <div className="native-management-page">
    <PageHeader title="工单管理" description="查看用户工单、回复并关闭已处理的工单。"
      action={<button className="button" disabled={list.isFetching} onClick={() => void refresh()}>刷新</button>} />
    {ownPending ? <section className="list-card" role="status"><h2>操作等待确认</h2>
      <p>{ownPending.operation} · 原操作 {ownPending.key}</p>
      <button className="button" disabled={state.busy || blocked} onClick={() => void recover()}>使用原操作确认结果</button></section> : null}
    {state.error ? <p role="alert">{managementError(state.error)}</p> : null}
    {blocked ? <p role="alert">当前无法确认工单访问权限，已隐藏工单内容。确认权限后刷新重试。</p> : null}
    <ReadList title="工单列表" rows={blocked ? [] : list.data?.data ?? []} columns={columns}
      meta={list.data?.meta} page={page} ready={!!list.data && !blocked} loading={list.isFetching}
      error={list.isError ? list.error : undefined} onRetry={() => void list.refetch()} onPage={setPage}>
      <form className="native-filters" onSubmit={event => { event.preventDefault(); setPage(1); setOwner(userId) }}>
        <label>状态<select value={status} onChange={event => { setPage(1); setStatus(event.target.value) }}><option value="">全部</option><option value="0">处理中</option><option value="1">已关闭</option></select></label>
        <label>用户 ID<input value={userId} inputMode="numeric" pattern="[1-9][0-9]{0,18}" onChange={event => setUserId(event.target.value)} /></label>
        <label>分派<select value={assignment} onChange={event => { setPage(1); setAssignment(event.target.value as typeof assignment) }}><option value="any">全部工单</option><option value="mine">我负责的</option><option value="unassigned">未分配</option></select></label>
        <button className="button">筛选</button>
      </form>
    </ReadList>
    {selected && !blocked ? <section className="list-card">
      <ManagementFeedback error={detail.isError ? detail.error : messages.isError ? messages.error : undefined}
        loading={detail.isFetching || messages.isFetching} onRetry={() => void refresh()} notFoundMessage="工单不存在或已不可访问。" />
      {ticket ? <><h2>{ticket.subject}</h2><ReadFields fields={[["工单号", ticket.id], ["用户 ID", ticket.user_id], ["状态", ticket.status === 0 ? '处理中' : '已关闭'], ["负责人", ticket.assigned_admin_id ? '客服 #' + ticket.assigned_admin_id : '未分配'], ["版本", ticket.row_version]]} />
        {ticket.status === 0 && detail.data ? <div className="native-filters" aria-label="工单分派">
          {!ticket.assigned_admin_id ? <button className="button" disabled={state.busy || !!state.pending || detail.isFetching} onClick={() => void assign('support.admin-tickets.claim')}>认领给我</button> : null}
          {ticket.assigned_admin_id === detail.data.meta.current_admin_id ? <button className="button" disabled={state.busy || !!state.pending || detail.isFetching} onClick={() => void assign('support.admin-tickets.unassign')}>释放我的工单</button> : null}
        </div> : null}
        {!messages.isError ? <ol className="native-ticket-messages">{messages.data?.pages.flatMap(result => result.data).map(row =>
          <li key={row.id}><p><strong>{row.author_kind === 'admin' ? '客服' : row.author_kind === 'user' ? '用户' : '历史消息'}</strong> · {row.created_at}</p><p style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{row.message}</p>{row.attachments.length ? <AttachmentsPanel ticketId={ticket.id} attachments={row.attachments} upload={false} disabled={state.busy || !!state.pending} /> : null}</li>)}</ol> : null}
        {messages.hasNextPage ? <button className="button" disabled={messages.isFetching} onClick={() => void messages.fetchNextPage()}>继续读取消息</button> : null}
        {ticket.status === 0 ? <form className="native-content-form" onSubmit={event => { event.preventDefault(); void mutate(false) }}>
          <label htmlFor="native-ticket-reply">回复（最多 10 KB）</label>
          <textarea id="native-ticket-reply" rows={5} maxLength={10000} value={message} disabled={state.busy || !!state.pending} onChange={event => setMessage(event.target.value)} />
          <AttachmentsPanel ticketId={ticket.id} disabled={state.busy || !!state.pending} />
          <button className="button" disabled={state.busy || !!state.pending || detail.isFetching || attachmentBlocked || !canReply}>发送回复</button>
          <button className="button" type="button" disabled={state.busy || !!state.pending || detail.isFetching || attachmentBlocked || hasReadyAttachments} onClick={() => void mutate(true)}>关闭工单</button>
        </form> : null}
        <section aria-label="工单操作审计"><h3>操作审计</h3><p>仅显示操作、操作者与负责人变更摘要。</p>
          <ManagementFeedback error={audit.isError ? audit.error : undefined} loading={audit.isFetching} onRetry={() => void audit.refetch()} />
          {!audit.isError ? <ol className="native-ticket-messages">{audit.data?.pages.flatMap(result => result.data).map(row =>
            <li key={row.id}><p><strong>{auditActions[row.action]}</strong> · {row.occurred_at}</p>
              <ReadFields fields={[["操作者", (row.actor_kind === 'admin' ? '客服 #' : '用户 #') + row.actor_id], ["操作后负责人", row.assigned_admin_id ? '客服 #' + row.assigned_admin_id : '未分配'], ["版本", row.row_version]]} /></li>)}</ol> : null}
          {!audit.isError && audit.data && audit.data.pages.every(result => result.data.length === 0) ? <p>暂无审计记录。</p> : null}
          {audit.hasNextPage ? <button className="button" disabled={audit.isFetching} onClick={() => void audit.fetchNextPage()}>继续读取审计</button> : null}
        </section>
      </> : null}
    </section> : null}
  </div>
}
