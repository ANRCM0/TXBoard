import { t, useLocale, getLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useListPagination } from './useListPagination'
import { RefreshCw, Send, MessageSquare } from 'lucide-react'
import './support-reference.css'
import { useEffect, useLayoutEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react'
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query'
import type { CoreInput, CoreOutput } from '@txboard/core-sdk'
import { CoreAdminApiError } from './api'
import { admin, attachmentTransfers, workflowActions } from './session'
import { AttachmentsPanel } from './AttachmentsPanel'
import { ManagementFeedback, managementError } from './Feedback'
import { ReadFields, ReadList } from './ReadList'
import { isoDate } from './readPresentation'
import { PageHeader } from '../components/ui/PageHeader'
import { FilterBar } from '../components/ui/FilterBar'
import { Field, Input, Select, Textarea } from '../components/ui/Field'
import type { Column } from '../components/ui/DataTable'
import { Modal } from '../components/ui/Modal'
import { requestConfirm } from '../components/ui/ConfirmDialog'

type Ticket = CoreOutput<'support.admin-tickets.list'>[number]
const auditActions = { create: '创建工单', reply: '回复工单', close: '关闭工单', claim: '认领工单', unassign: '释放工单' }
const denied = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)

export function TicketsPage() {
  useLocale()
  const client = useQueryClient()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const transfer = useSyncExternalStore(attachmentTransfers.subscribe, attachmentTransfers.snapshot)
  const { page, setPage, perPage, setPerPage } = useListPagination()
  const [status, setStatus] = useState(''), [userId, setUserId] = useState('')
  const [owner, setOwner] = useState(''), [selected, setSelected] = useState(''), [message, setMessage] = useState('')
  const [conversationSearch, setConversationSearch] = useState(''), [newMessages, setNewMessages] = useState(false)
  const [attachmentsOpen, setAttachmentsOpen] = useState(false)
  const scroll = useRef<HTMLDivElement>(null), followMessages = useRef(true), composing = useRef(false)
  const view = useMemo(() => ({}), [selected]), activeView = useRef<object>()
  useLayoutEffect(() => { activeView.current = view; return () => { activeView.current = undefined } }, [view])
  const [assignment, setAssignment] = useState<'any' | 'mine' | 'unassigned'>('any')
  const input: CoreInput<'support.admin-tickets.list'> = { page, per_page: perPage,
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
  const readyAttachments = transfer.entries.filter(entry => entry.ticketId === selected && entry.metadata?.status === 'ready').length
  useEffect(() => {
    if (transfer.pending || transfer.entries.some(entry => entry.ticketId === selected && (!entry.metadata || ['uploading', 'ready'].includes(entry.metadata.status)))) setAttachmentsOpen(true)
  }, [selected, transfer.entries, transfer.pending])
  const ownPending = state.pending?.operation.startsWith('support.admin-tickets.') ? state.pending : undefined
  const blocked = denied(list.error) || denied(detail.error) || denied(messages.error) || denied(audit.error) || denied(state.error)
  useDirtyDraft(!!selected && (!!message || hasReadyAttachments) && !blocked && !state.pending)
  const messageRows = messages.data?.pages.flatMap(result => result.data) ?? []
  const lastMessage = messageRows.at(-1)?.id
  function latestReadMessage() {
    const element = scroll.current
    if (element) element.scrollTop = element.scrollHeight
    followMessages.current = true; setNewMessages(false)
  }
  useLayoutEffect(() => {
    if (!selected) return
    if (followMessages.current) latestReadMessage()
    else setNewMessages(true)
  }, [selected, lastMessage, detail.data?.data.id])
  useEffect(() => {
    if (!selected || !message) return
    const preventUnload = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', preventUnload)
    return () => window.removeEventListener('beforeunload', preventUnload)
  }, [selected, message])
  useEffect(() => { if (blocked) { setMessage(''); setConversationSearch('') } }, [blocked])
  function selectTicket(next: string) {
    if (next === selected || state.busy || transfer.busy) return
    const generation = workflowActions.generation()
    const change = () => {
      if (activeView.current !== view || generation !== workflowActions.generation() || workflowActions.snapshot().busy) return
      followMessages.current = true; composing.current = false; setNewMessages(false)
      setSelected(next); setMessage(''); setAttachmentsOpen(false)
      if (!next) setConversationSearch('')
    }
    if (message || hasReadyAttachments) requestConfirm({ title: t("放弃未发送的回复？"),
      message: t("未发送的正文将被丢弃。已上传附件仍属于原工单，不会转移到其他工单；原操作的结果确认记录会继续保留。"),
      confirmLabel: next ? t("放弃并切换") : t("放弃并关闭"), danger: true, action: change })
    else change()
  }
  async function refresh() {
    const generation = workflowActions.generation()
    if (activeView.current !== view) return
    try {
      await client.refetchQueries({ queryKey: ['native-admin', 'support'], type: 'active' }, { throwOnError: true })
      if (generation !== workflowActions.generation() || activeView.current !== view) return
      workflowActions.clearError()
      workflowActions.confirmReadAuthorization('support.admin-tickets.')
    } catch { /* Query feedback retains the failed permission check. */ }
  }
  async function mutate(close: boolean) {
    const ticket = detail.data?.data
    if (!ticket || ticket.status !== 0 || detail.isError || detail.isFetching || blocked || state.pending || state.busy) return
    if (!close && (!message.trim() || new TextEncoder().encode(message).length > 10000 || composing.current)) return
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
      if (generation !== workflowActions.generation() || activeView.current !== view) return
      followMessages.current = true
      setMessage(''); await refresh()
    } catch (error) {
      if (error instanceof CoreAdminApiError && error.status === 409 && !error.needsStateConfirmation) await refresh()
    }
  }
  function confirmCloseTicket() {
    const ticket = detail.data?.data, generation = workflowActions.generation()
    if (!ticket || blocked || state.busy || state.pending || detail.isFetching || attachmentBlocked || hasReadyAttachments) return
    requestConfirm({ title: t("关闭这条工单？"), message: t("确认问题已处理完毕。未发送的回复不会提交，关闭后不能继续回复。"),
      confirmLabel: t("确认关闭工单"), action: () => {
        if (activeView.current === view && generation === workflowActions.generation()) void mutate(true)
      } })
  }
  async function assign(operation: 'support.admin-tickets.claim' | 'support.admin-tickets.unassign') {
    const ticket = detail.data?.data
    if (!ticket || detail.isError || detail.isFetching || blocked || state.pending || state.busy) return
    const generation = workflowActions.generation()
    try {
      await workflowActions.run(operation, { ticket_id: ticket.id, expected_version: ticket.row_version })
      if (generation !== workflowActions.generation() || activeView.current !== view) return
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
      if (generation !== workflowActions.generation() || activeView.current !== view) return
      if (action.operation === 'support.admin-tickets.reply') attachmentTransfers.markAttached(action.input.attachment_ids ?? [])
      if ('ticket_id' in action.input) { followMessages.current = true; setSelected(action.input.ticket_id) }
      if (generation !== workflowActions.generation()) return
      setMessage(''); await refresh()
    } catch { /* Preserve unknown outcomes across navigation. */ }
  }
  const columns: Column<Ticket>[] = [
    { key: 'subject', header: t("主题"), render: row => <div className="native-row-identity"><strong>{row.subject}</strong><small>#{row.id}{t("· 用户")}{row.user_id}</small></div> },
    { key: 'status', header: t("状态"), render: row => <span className="support-tag" data-tone={row.status === 0 ? 'warning' : undefined}>{row.status === 0 ? t("处理中") : t("已关闭")}</span> },
    { key: 'reply', header: t("回复"), render: row => <span className="support-tag" data-tone={row.reply_status === 1 ? 'success' : 'warning'}>{row.reply_status === 1 ? t("已回复") : t("待回复")}</span> },
    { key: 'assignment', header: t("负责人"), render: row => row.assigned_admin_id ? t("客服 #") + row.assigned_admin_id : t("未分配") },
    { key: 'updated', header: t("更新时间"), render: row => <time dateTime={row.updated_at}>{isoDate(row.updated_at)}</time> },
    { key: 'actions', header: t("操作"), render: row => <button className="button" onClick={() => selectTicket(row.id)}><MessageSquare size={14} />{t("查看")}</button> },
  ]
  const ticket = !blocked && !detail.isError ? detail.data?.data : undefined
  const canReply = message.trim().length > 0 && new TextEncoder().encode(message).length <= 10000
  return <div className="native-management-page support-page tickets-page">
    <PageHeader title={t("工单管理")} description={t("查看用户工单、回复并关闭已处理的工单。")}
      action={<button className="button" disabled={list.isFetching} onClick={() => void refresh()}><RefreshCw size={15} />{t("刷新")}</button>} />
    {ownPending ? <section className="list-card support-pending" role="status"><h2>{t("操作等待确认")}</h2><p>{t("上次操作结果尚未确定，请先确认结果再继续处理。")}</p>
      <details className="support-help"><summary>{t("查看操作标识")}</summary><p>{ownPending.operation} · {ownPending.key}</p></details>
      <button className="button" disabled={state.busy || blocked} onClick={() => void recover()}>{t("使用原操作确认结果")}</button></section> : null}
    {state.error ? <p role="alert">{managementError(state.error)}</p> : null}
    {blocked ? <p role="alert">{t("当前无法确认工单访问权限，已隐藏工单内容。确认权限后刷新重试。")}</p> : null}
    <ReadList title={"工单列表"} rows={blocked ? [] : list.data?.data ?? []} columns={columns}
      meta={list.data?.meta} perPage={perPage} onPageSize={setPerPage} page={page} ready={!!list.data && !blocked} loading={list.isFetching}
      error={list.isError ? list.error : undefined} onRetry={() => void list.refetch()} onPage={setPage}
      columnSettingsKey="tickets" stickyHeader stickyFirstColumn mobilePrimaryKey="subject">
      <div className="support-heading"><div><h2>{t("工单列表")}</h2><p>{t("筛选待处理问题与负责的工单")}</p></div></div><form className="native-filters" onSubmit={event => { event.preventDefault(); setPage(1); setOwner(userId) }}>
        <FilterBar applied={[
          ...(status ? [{ key: 'status', label: status === '0' ? t("处理中") : t("已关闭") }] : []),
          ...(owner ? [{ key: 'owner', label: t("用户 #") + owner }] : []),
          ...(assignment !== 'any' ? [{ key: 'assignment', label: assignment === 'mine' ? t("我负责的") : t("未分配") }] : []),
        ]} onClear={() => { setStatus(''); setUserId(''); setOwner(''); setAssignment('any'); setPage(1) }}>
          <label>{t("状态")}<Select value={status} onChange={event => { setPage(1); setStatus(event.target.value) }}><option value="">{t("全部")}</option><option value="0">{t("处理中")}</option><option value="1">{t("已关闭")}</option></Select></label>
          <label>{t("用户 ID")}<Input value={userId} inputMode="numeric" pattern="[1-9][0-9]{0,18}" onChange={event => setUserId(event.target.value)} /></label>
          <label>{t("分派")}<Select value={assignment} onChange={event => { setPage(1); setAssignment(event.target.value as typeof assignment) }}><option value="any">{t("全部工单")}</option><option value="mine">{t("我负责的")}</option><option value="unassigned">{t("未分配")}</option></Select></label>
          <button className="button">{t("筛选")}</button>
        </FilterBar>
      </form>
    </ReadList>
    <Modal open={!!selected && !blocked} title={t("工单会话")} subtitle={ticket ? ticket.subject + ' · #' + ticket.id : t("正在读取工单详情")} wide
      className="support-page support-ticket-dialog" bodyClassName="support-conversation-body" onClose={() => selectTicket('')}
      footer={ticket ? ticket.status === 0 ? <form className="native-content-form support-reply-form" onSubmit={event => { event.preventDefault(); void mutate(false) }}>
        <Field label={t("回复用户")}><Textarea id="native-ticket-reply" placeholder={t("输入回复内容…")} rows={3} maxLength={10000} value={message}
          disabled={state.busy || !!state.pending || detail.isFetching} onChange={event => setMessage(event.target.value)}
          onCompositionStart={() => { composing.current = true }} onCompositionEnd={() => { composing.current = false }}
          onKeyDown={event => {
            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey) && !event.nativeEvent.isComposing && event.nativeEvent.keyCode !== 229 && !composing.current) {
              event.preventDefault(); void mutate(false)
            }
          }} /></Field>
        <div className="support-reply-hints"><small>{t("Enter 换行 · Ctrl / ⌘ + Enter 发送")}</small><small className="support-byte-count" aria-live="polite">{new TextEncoder().encode(message).length.toLocaleString(getLocale())}{t("/ 10,000 字节")}</small></div>
        <details className="support-reply-attachments" open={attachmentsOpen} onToggle={event => setAttachmentsOpen(event.currentTarget.open)}>
          <summary>{t("附件与上传")}{readyAttachments ? ' · ' + readyAttachments + t(" 个待发送") : t("（可选）")}</summary>
          <AttachmentsPanel ticketId={ticket.id} disabled={state.busy || !!state.pending} />
        </details>
        <div className="support-form-footer"><span>{t("回复将连同已上传附件一起发送")}</span><div className="support-actions">
          <button className="button primary" disabled={state.busy || !!state.pending || detail.isFetching || attachmentBlocked || !canReply}><Send size={14} />{t("发送回复")}</button>
          <button className="button" type="button" disabled={state.busy || !!state.pending || detail.isFetching || attachmentBlocked || hasReadyAttachments} onClick={confirmCloseTicket}>{t("关闭工单")}</button>
          <button className="button" type="button" disabled={state.busy || transfer.busy} onClick={() => selectTicket('')}>{t("关闭详情")}</button>
        </div></div>
      </form> : <p className="support-closed-note">{t("该工单已关闭，无法继续回复。")}</p> : undefined}>
      <aside className="support-ticket-sidebar" aria-label={t("当前页工单")}>
        <label>{t("当前页工单")}<Input value={conversationSearch} placeholder={t("筛选当前页主题")} onChange={event => setConversationSearch(event.target.value)} /></label>
        <small>{t("沿用列表的服务端筛选与分页")}</small>
        <div>{(list.isError ? [] : list.data?.data ?? []).filter(row => row.subject.toLocaleLowerCase().includes(conversationSearch.toLocaleLowerCase())).map(row =>
          <button type="button" key={row.id} aria-current={selected === row.id ? 'true' : undefined} disabled={state.busy || transfer.busy}
            onClick={() => selectTicket(row.id)}><strong>{row.subject}</strong><span>#{row.id}{t("· 用户 #")}{row.user_id}</span><small>{row.status === 0 ? row.reply_status === 1 ? t("已回复") : t("待回复") : t("已关闭")}</small></button>)}</div>
      </aside>
      <section className="support-ticket-detail">
      <ManagementFeedback error={detail.isError ? detail.error : messages.isError ? messages.error : undefined}
        loading={detail.isFetching || messages.isFetching} onRetry={() => void refresh()} notFoundMessage={t("工单不存在或已不可访问。")} />
      {ticket ? <><div className="support-conversation-summary"><ReadFields fields={[[t("工单号"), ticket.id], [t("用户 ID"), ticket.user_id], [t("状态"), ticket.status === 0 ? t("处理中") : t("已关闭")], [t("负责人"), ticket.assigned_admin_id ? t("客服 #") + ticket.assigned_admin_id : t("未分配")], [t("版本"), ticket.row_version]]} />
        {ticket.status === 0 && detail.data ? <div className="support-assignment" aria-label={t("工单分派")}>
          {!ticket.assigned_admin_id ? <button className="button" disabled={state.busy || !!state.pending || detail.isFetching} onClick={() => void assign('support.admin-tickets.claim')}>{t("认领给我")}</button> : null}
          {ticket.assigned_admin_id === detail.data.meta.current_admin_id ? <button className="button" disabled={state.busy || !!state.pending || detail.isFetching} onClick={() => void assign('support.admin-tickets.unassign')}>{t("释放我的工单")}</button> : null}
        </div> : null}</div>
        <div ref={scroll} className="support-conversation-scroll" role="log" aria-label={t("工单消息")} aria-live="polite" tabIndex={0}
          onScroll={event => { const element = event.currentTarget; followMessages.current = element.scrollHeight - element.scrollTop - element.clientHeight < 80; if (followMessages.current) setNewMessages(false) }}>
        {!messages.isError ? <ol className="native-ticket-messages">{messageRows.map(row =>
          <li key={row.id} data-author={row.author_kind}><p><strong>{row.author_kind === 'admin' ? t("客服") : row.author_kind === 'user' ? t("用户") : t("历史消息")}</strong><time dateTime={row.created_at}>{isoDate(row.created_at)}</time></p><p className="support-message-body">{row.message}</p>{row.attachments.length ? <AttachmentsPanel ticketId={ticket.id} attachments={row.attachments} upload={false} disabled={state.busy || !!state.pending} /> : null}</li>)}</ol> : null}
        {messages.hasNextPage ? <div className="support-message-more"><p>{t("还有更新消息未读取；继续读取后才能查看完整会话。")}</p><button className="button" disabled={messages.isFetching} onClick={() => void messages.fetchNextPage()}>{t("继续读取消息")}</button></div> : null}
        <details className="support-help" aria-label={t("工单操作审计")}><summary>{t("处理记录")}</summary>
          <ManagementFeedback error={audit.isError ? audit.error : undefined} loading={audit.isFetching} onRetry={() => void audit.refetch()} />
          {!audit.isError ? <ol className="native-ticket-messages">{audit.data?.pages.flatMap(result => result.data).map(row =>
            <li key={row.id}><p><strong>{t(auditActions[row.action])}</strong><time dateTime={row.occurred_at}>{isoDate(row.occurred_at)}</time></p>
              <ReadFields fields={[[t("操作者"), (row.actor_kind === 'admin' ? t("客服 #") : t("用户 #")) + row.actor_id], [t("操作后负责人"), row.assigned_admin_id ? t("客服 #") + row.assigned_admin_id : t("未分配")], [t("版本"), row.row_version]]} /></li>)}</ol> : null}
          {!audit.isError && audit.data && audit.data.pages.every(result => result.data.length === 0) ? <p>{t("暂无审计记录。")}</p> : null}
          {audit.hasNextPage ? <button className="button" disabled={audit.isFetching} onClick={() => void audit.fetchNextPage()}>{t("继续读取审计")}</button> : null}
        </details></div>
        {newMessages ? <button type="button" className="button support-new-messages" onClick={latestReadMessage}>{t("查看最新已读取消息")}</button> : null}
      </> : null}
    </section></Modal>
  </div>
}
