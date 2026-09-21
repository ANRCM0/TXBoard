import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, MessageSquare, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import {
  closeTicket,
  getTicketDetail,
  getTickets,
  replyTicket,
  type TicketDetail,
  type TicketItem,
} from '../../api/ticket'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'

export function TicketsPage() {
  const qc = useQueryClient()
  const [statusTab, setStatusTab] = useState<'open' | 'closed'>('open')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)
  const [email, setEmail] = useState('')
  const [appliedEmail, setAppliedEmail] = useState('')
  const [replyStatus, setReplyStatus] = useState('')
  const [detailId, setDetailId] = useState<number | null>(null)

  const query = useQuery({
    queryKey: ['tickets', statusTab, page, pageSize, appliedEmail, replyStatus],
    queryFn: () => getTickets({
      current: page,
      pageSize,
      status: statusTab === 'closed' ? 1 : 0,
      ...(appliedEmail.trim() ? { email: appliedEmail.trim() } : {}),
      ...(replyStatus !== '' ? { reply_status: [Number(replyStatus)] } : {}),
    }),
    placeholderData: previous => previous,
  })

  const rows = Array.isArray(query.data?.data) ? query.data.data : []

  function switchTab(next: 'open' | 'closed') {
    setStatusTab(next)
    setPage(1)
  }

  return <>
    <PageHeader
      title="工单管理"
      description="工单筛选、消息详情、回复和关闭。当前 Xboard core 已不提供工单类型管理接口。"
    />

    <div className="ticket-tabs">
      <button className={statusTab === 'open' ? 'tab active' : 'tab'} onClick={() => switchTab('open')}>处理中</button>
      <button className={statusTab === 'closed' ? 'tab active' : 'tab'} onClick={() => switchTab('closed')}>已关闭</button>
    </div>

    <div className="ticket-toolbar">
      <div className="order-search">
        <input
          value={email}
          onChange={event => setEmail(event.target.value)}
          onKeyDown={event => {
            if (event.key === 'Enter') {
              setAppliedEmail(email)
              setPage(1)
            }
          }}
          placeholder="按用户邮箱搜索…"
        />
        <button className="button" onClick={() => { setAppliedEmail(email); setPage(1) }}>
          <Search size={15}/>搜索
        </button>
      </div>

      <select value={replyStatus} onChange={event => { setReplyStatus(event.target.value); setPage(1) }}>
        <option value="">全部回复状态</option>
        <option value="0">待回复</option>
        <option value="1">已回复</option>
      </select>

      {(appliedEmail || replyStatus) && <button className="button" onClick={() => {
        setEmail('')
        setAppliedEmail('')
        setReplyStatus('')
        setPage(1)
      }}>清除筛选</button>}
    </div>

    <div className="card ticket-table-card">
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>主题</th>
              <th>用户</th>
              <th>优先级</th>
              <th>回复状态</th>
              <th>更新时间</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(ticket => <tr key={ticket.id}>
              <td>{ticket.id}</td>
              <td>
                <button className="link-button ticket-subject" onClick={() => setDetailId(ticket.id)}>
                  {cleanSubject(ticket.subject)}
                </button>
                {isWithdrawTicket(ticket) && <span className="badge withdraw-badge">提现</span>}
              </td>
              <td>{ticket.user?.email || '-'}</td>
              <td><span className={levelClass(ticket.level)}>{levelLabel(ticket)}</span></td>
              <td><span className={ticket.reply_status === 0 ? 'status off' : 'status ok'}>{ticket.reply_status === 0 ? '待回复' : '已回复'}</span></td>
              <td>{formatTime(ticket.updated_at)}</td>
              <td>
                <button className="button compact-button" onClick={() => setDetailId(ticket.id)}>
                  <MessageSquare size={14}/>{ticket.status === 1 ? '查看' : '回复'}
                </button>
              </td>
            </tr>)}
            {!rows.length && <tr>
              <td colSpan={7} className="empty-cell">{query.isLoading ? '加载中…' : '暂无工单'}</td>
            </tr>}
          </tbody>
        </table>
      </div>

      <div className="pagination-bar">
        <span className="pagination-meta">
          共 {query.data?.total || 0} 条 · 第 {query.data?.current_page || page} / {query.data?.last_page || 1} 页
        </span>
        <div className="pagination-actions">
          <select value={pageSize} onChange={event => { setPageSize(Number(event.target.value)); setPage(1) }}>
            <option value={10}>10 / 页</option>
            <option value={20}>20 / 页</option>
            <option value={50}>50 / 页</option>
          </select>
          <button className="icon-button" disabled={page <= 1} onClick={() => setPage(value => Math.max(1, value - 1))}>
            <ChevronLeft size={16}/>
          </button>
          <button
            className="icon-button"
            disabled={page >= Number(query.data?.last_page || 1)}
            onClick={() => setPage(value => value + 1)}
          ><ChevronRight size={16}/></button>
        </div>
      </div>
    </div>

    <TicketDetailModal
      ticketId={detailId}
      onClose={() => setDetailId(null)}
      onChanged={() => qc.invalidateQueries({ queryKey: ['tickets'] })}
    />
  </>
}

function TicketDetailModal({
  ticketId,
  onClose,
  onChanged,
}: {
  ticketId: number | null
  onClose: () => void
  onChanged: () => void
}) {
  const [detail, setDetail] = useState<TicketDetail | null>(null)
  const [reply, setReply] = useState('')

  const query = useQuery({
    queryKey: ['ticketDetail', ticketId],
    queryFn: () => getTicketDetail(ticketId!),
    enabled: ticketId !== null,
    refetchInterval: detail?.status === 0 ? 3000 : false,
  })

  useEffect(() => {
    if (query.data) setDetail(query.data)
  }, [query.data])

  useEffect(() => {
    setDetail(null)
    setReply('')
  }, [ticketId])

  const replyMutation = useMutation({
    mutationFn: () => replyTicket(ticketId!, reply.trim()),
    onSuccess: async () => {
      toast.success('回复已发送')
      setReply('')
      await query.refetch()
      onChanged()
    },
  })

  const closeMutation = useMutation({
    mutationFn: () => closeTicket(ticketId!),
    onSuccess: async () => {
      toast.success('工单已关闭')
      await query.refetch()
      onChanged()
    },
  })

  const withdraw = detail ? isWithdrawTicket(detail) : false

  return <Modal
    open={ticketId !== null}
    title={detail ? cleanSubject(detail.subject) : '工单详情'}
    onClose={onClose}
  >
    {query.isLoading && !detail ? <div className="empty-state">加载工单…</div> : detail ? <div className="ticket-detail">
      <div className="ticket-detail-meta">
        <span>用户：<strong>{detail.user?.email || '-'}</strong></span>
        <span>状态：<strong>{detail.status === 1 ? '已关闭' : '处理中'}</strong></span>
      </div>

      {withdraw ? (
        <div className="page-alert">
          这是佣金提现工单。当前 Xboard core 的 /ticket/close 只负责关闭工单，不会自动打款或返还佣金；请完成实际资金处理后再关闭。
        </div>
      ) : null}

      <div className="ticket-messages">
        {(detail.messages || []).map(message => {
          const fromAdmin = Boolean(message.is_from_admin)
          return <div className={fromAdmin ? 'ticket-message admin' : 'ticket-message user'} key={message.id}>
            <div className="ticket-message-role">{fromAdmin ? '管理员' : detail.user?.email || '用户'}</div>
            <div className="ticket-message-body">{message.message}</div>
            <div className="ticket-message-time">{formatTime(message.created_at)}</div>
          </div>
        })}
        {!detail.messages?.length && <div className="empty-state">暂无消息。</div>}
      </div>

      {detail.status === 0 && <>
        <label className="field">
          <span>回复</span>
          <textarea
            className="ticket-reply-editor"
            value={reply}
            maxLength={5000}
            onChange={event => setReply(event.target.value)}
            placeholder="输入回复内容…"
          />
          <small className="field-help">{reply.length} / 5000</small>
        </label>

        <div className="ticket-detail-actions">
          <button
            className="button danger"
            disabled={closeMutation.isPending}
            onClick={() => confirm('确认关闭该工单？') && closeMutation.mutate()}
          >关闭工单</button>

          <button
            className="button primary"
            disabled={replyMutation.isPending || !reply.trim()}
            onClick={() => replyMutation.mutate()}
          >{replyMutation.isPending ? '发送中…' : '发送回复'}</button>
        </div>
      </>}
    </div> : <div className="empty-state">未找到工单。</div>}
  </Modal>
}

function isWithdrawTicket(ticket: Pick<TicketItem, 'level' | 'subject'>) {
  if (Number(ticket.level) !== 2) return false
  const subject = ticket.subject || ''
  return subject.includes('[withdraw_ticket]')
    || subject.includes('Commission Withdrawal')
    || subject.includes('Commission withdrawal')
    || subject.includes('佣金提现')
}

function cleanSubject(subject?: string) {
  return (subject || '-').replace('[withdraw_ticket]', '').trim()
}

function levelLabel(ticket: TicketItem) {
  if (isWithdrawTicket(ticket)) return '提现'
  if (ticket.level === 0) return '低'
  if (ticket.level === 1) return '中'
  if (ticket.level === 2) return '高'
  return '-'
}

function levelClass(level?: number) {
  if (level === 2) return 'badge priority-high'
  if (level === 1) return 'badge priority-medium'
  return 'badge'
}

function formatTime(value?: number) {
  if (!value) return '-'
  return new Date(value * 1000).toLocaleString()
}
