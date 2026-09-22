import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Ban,
  ChevronLeft,
  ChevronRight,
  Clipboard,
  KeyRound,
  Mail,
  Pencil,
  Plus,
  Search,
  Trash2,
} from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import { getPlans } from '../../api/finance'
import {
  banUsers,
  destroyUser,
  getUserDetail,
  getUsers,
  resetUserSecret,
  type AdminUser,
  type UserFilter,
  type UserSort,
} from '../../api/user-admin'
import { UserEditorModal } from '../../components/users/UserEditorModal'
import { UserMailModal } from '../../components/users/UserMailModal'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { PageHeader } from '../../components/ui/PageHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

const GB = 1024 * 1024 * 1024

export function UsersPage() {
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [planId, setPlanId] = useState('')
  const [banState, setBanState] = useState('')
  const [sortField, setSortField] = useState('id')
  const [sortDesc, setSortDesc] = useState(true)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [editorOpen, setEditorOpen] = useState(false)
  const [editing, setEditing] = useState<AdminUser | null>(null)
  const [mailOpen, setMailOpen] = useState(false)
  const selectPageRef = useRef<HTMLInputElement>(null)

  const filters = useMemo<UserFilter[]>(() => {
    const result: UserFilter[] = []
    if (appliedSearch.trim()) result.push({ id: 'email', value: appliedSearch.trim() })
    if (planId) result.push({ id: 'plan_id', value: 'eq:' + planId })
    if (banState) result.push({ id: 'banned', value: 'eq:' + banState })
    return result
  }, [appliedSearch, planId, banState])

  const sorts = useMemo<UserSort[]>(() => [
    { id: sortField, desc: sortDesc },
  ], [sortField, sortDesc])

  const query = useQuery({
    queryKey: ['adminUsers', page, pageSize, filters, sorts],
    queryFn: () => getUsers({
      current: page,
      pageSize,
      ...(filters.length ? { filter: filters } : {}),
      sort: sorts,
    }),
    placeholderData: previous => previous,
  })

  const plansQuery = useQuery({
    queryKey: ['plans'],
    queryFn: getPlans,
  })

  const users = Array.isArray(query.data?.data) ? query.data.data : []
  const plans = Array.isArray(plansQuery.data) ? plansQuery.data : []

  const refresh = () => qc.invalidateQueries({ queryKey: ['adminUsers'] })

  const resetSecret = useMutation({
    mutationFn: resetUserSecret,
    onSuccess: () => {
      toast.success('订阅密钥已重置')
      refresh()
    },
  })

  const remove = useMutation({
    mutationFn: destroyUser,
    onSuccess: () => {
      toast.success('用户已删除')
      setSelected(new Set())
      refresh()
    },
  })

  const banSelected = useMutation({
    mutationFn: (ids: number[]) => banUsers({ scope: 'selected', user_ids: ids }),
    onSuccess: () => {
      toast.success('所选用户已封禁')
      setSelected(new Set())
      refresh()
    },
  })

  const pageIds = users.map(user => user.id)
  const allPageSelected = pageIds.length > 0 && pageIds.every(id => selected.has(id))

  useEffect(() => {
    if (selectPageRef.current) selectPageRef.current.indeterminate = !allPageSelected && pageIds.some(id => selected.has(id))
  }, [allPageSelected, pageIds, selected])

  // Selection is scoped to the current view so hidden rows cannot be acted on accidentally.
  useEffect(() => { setSelected(new Set()) }, [page, pageSize, appliedSearch, planId, banState, sortField, sortDesc])

  function togglePage() {
    setSelected(current => {
      const next = new Set(current)
      if (allPageSelected) pageIds.forEach(id => next.delete(id))
      else pageIds.forEach(id => next.add(id))
      return next
    })
  }

  function toggleUser(id: number) {
    setSelected(current => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  function openCreate() {
    setEditing(null)
    setEditorOpen(true)
  }

  async function openEdit(user: AdminUser) {
    try {
      const detail = await getUserDetail(user.id)
      setEditing(detail)
      setEditorOpen(true)
    } catch {
      toast.error('无法加载用户详情，请重试')
    }
  }

  async function copySubscribe(user: AdminUser) {
    const url = user.subscribe_url
    if (!url) {
      toast.error('该用户没有订阅链接')
      return
    }
    try {
      await navigator.clipboard.writeText(url)
      toast.success('订阅链接已复制')
    } catch {
      toast.error('复制失败')
    }
  }

  function clearFilters() {
    setSearch('')
    setAppliedSearch('')
    setPlanId('')
    setBanState('')
    setPage(1)
  }

  return <>
    <PageHeader
      title="用户管理"
      description="管理用户套餐、流量与账户状态，支持筛选和批量操作。"
      action={<div className="actions">
        <button className="button" onClick={() => setMailOpen(true)}><Mail size={15}/>发送邮件</button>
        <button className="button primary" onClick={openCreate}><Plus size={15}/>创建用户</button>
      </div>}
    />

    <div className="user-toolbar">
      <div className="order-search">
        <input
          aria-label="搜索用户邮箱"
          value={search}
          onChange={event => setSearch(event.target.value)}
          onKeyDown={event => {
            if (event.key === 'Enter') {
              setAppliedSearch(search)
              setPage(1)
            }
          }}
          placeholder="搜索邮箱…"
        />
        <button className="button" onClick={() => { setAppliedSearch(search); setPage(1) }}>
          <Search size={15}/>搜索
        </button>
      </div>

      <select aria-label="筛选套餐" value={planId} onChange={event => { setPlanId(event.target.value); setPage(1) }}>
        <option value="">全部套餐</option>
        {plans.map(plan => <option key={plan.id} value={plan.id}>{plan.name}</option>)}
      </select>

      <select aria-label="筛选用户状态" value={banState} onChange={event => { setBanState(event.target.value); setPage(1) }}>
        <option value="">全部状态</option>
        <option value="0">正常</option>
        <option value="1">已封禁</option>
      </select>

      <select aria-label="排序字段" value={sortField} onChange={event => { setSortField(event.target.value); setPage(1) }}>
        <option value="id">按 ID</option>
        <option value="email">按邮箱</option>
        <option value="balance">按余额</option>
        <option value="total_used">按已用流量</option>
        <option value="expired_at">按到期时间</option>
        <option value="created_at">按注册时间</option>
      </select>

      <button className="button" onClick={() => setSortDesc(value => !value)}>
        {sortDesc ? '降序' : '升序'}
      </button>

      {(appliedSearch || planId || banState) && <button className="button" onClick={clearFilters}>清除筛选</button>}
    </div>

    {selected.size > 0 && <div className="selection-bar">
      <span>已选择 {selected.size} 个用户</span>
      <div className="actions">
        <button className="button" onClick={() => setMailOpen(true)}><Mail size={15}/>发送邮件</button>
        <button
          className="button danger"
          disabled={banSelected.isPending || query.isPlaceholderData || query.isFetching}
          onClick={() => requestConfirm({ title: '批量封禁', message: '确认封禁所选用户？被封禁用户将无法登录。', danger: true, confirmLabel: '封禁', action: () => banSelected.mutate(Array.from(selected)) })}
        ><Ban size={15}/>{banSelected.isPending ? '处理中…' : '批量封禁'}</button>
        <button className="button" onClick={() => setSelected(new Set())}>取消选择</button>
      </div>
    </div>}

    <div className="card user-table-card">
      <QueryFeedback loading={query.isFetching} error={query.isError} onRetry={() => query.refetch()} />
      <div className="table-wrap" tabIndex={0} role="region" aria-label="用户列表" aria-busy={query.isFetching}>
        <table className="data-table user-table">
          <thead>
            <tr>
              <th className="select-col"><input ref={selectPageRef} aria-label="选择本页全部用户" disabled={query.isPlaceholderData || query.isFetching} type="checkbox" checked={allPageSelected} onChange={togglePage}/></th>
              <th>ID</th>
              <th>用户</th>
              <th>套餐</th>
              <th>流量</th>
              <th>到期</th>
              <th>余额</th>
              <th>在线</th>
              <th>状态</th>
              <th>注册时间</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            {users.map(user => <tr key={user.id}>
              <td className="select-col"><input aria-label={'选择用户 '+user.email} disabled={query.isPlaceholderData || query.isFetching} type="checkbox" checked={selected.has(user.id)} onChange={() => toggleUser(user.id)}/></td>
              <td>{user.id}</td>
              <td>
                <div className="user-identity">
                  <strong>{user.email}</strong>
                  <div className="user-badges">
                    {Boolean(user.is_admin) && <span className="badge">Admin</span>}
                    {Boolean(user.is_staff) && <span className="badge">Staff</span>}
                    {user.group?.name && <span className="badge">{user.group.name}</span>}
                  </div>
                </div>
              </td>
              <td>{user.plan?.name || (user.plan_id ? 'Plan #' + user.plan_id : <span className="muted">无套餐</span>)}</td>
              <td><TrafficCell user={user}/></td>
              <td>{formatExpire(user.expired_at)}</td>
              <td>
                <strong>¥ {(Number(user.balance || 0) / 100).toFixed(2)}</strong>
                {Number(user.commission_balance || 0) > 0 && <small className="table-sub">佣金 ¥ {(Number(user.commission_balance) / 100).toFixed(2)}</small>}
              </td>
              <td>{Number(user.online_count || 0)}</td>
              <td><span className={Boolean(user.banned) ? 'status off' : 'status ok'}>{user.banned ? '已封禁' : '正常'}</span></td>
              <td>{formatTime(user.created_at)}</td>
              <td>
                <div className="actions">
                  <button className="icon-button" title="编辑" onClick={() => openEdit(user)}><Pencil size={15}/></button>
                  <button className="icon-button" title="复制订阅链接" onClick={() => copySubscribe(user)}><Clipboard size={15}/></button>
                  <button
                    className="icon-button"
                    title="重置订阅密钥"
                    disabled={resetSecret.isPending}
                    onClick={() => requestConfirm({ title: '重置订阅密钥', message: '重置后原订阅链接将失效，确认继续？', danger: true, confirmLabel: '重置', action: () => resetSecret.mutate(user.id) })}
                  ><KeyRound size={15}/></button>
                  <button
                    className="icon-button danger"
                    title="删除"
                    disabled={remove.isPending}
                    onClick={() => requestConfirm({ title: '删除用户', message: '删除用户会清理关联数据，且后端会拒绝删除仍有余额/处理中订单的用户。确认继续？', danger: true, confirmLabel: '删除', action: () => remove.mutate(user.id) })}
                  ><Trash2 size={15}/></button>
                </div>
              </td>
            </tr>)}

            {!users.length && !query.isFetching && !query.isError && <tr>
              <td colSpan={11} className="empty-cell">{query.isLoading ? '加载中…' : '没有符合条件的用户'}</td>
            </tr>}
          </tbody>
        </table>
      </div>

      <div className="pagination-bar">
        <span className="pagination-meta">
          共 {query.data?.total || 0} 人 · 第 {query.data?.current_page || page} / {query.data?.last_page || 1} 页
        </span>
        <div className="pagination-actions">
          <select aria-label="每页条数" value={pageSize} onChange={event => { setPageSize(Number(event.target.value)); setPage(1) }}>
            <option value={10}>10 / 页</option>
            <option value={20}>20 / 页</option>
            <option value={50}>50 / 页</option>
            <option value={100}>100 / 页</option>
          </select>
          <button className="icon-button" aria-label="上一页" disabled={query.isFetching || page <= 1} onClick={() => setPage(value => Math.max(1, value - 1))}>
            <ChevronLeft size={16}/>
          </button>
          <button
            className="icon-button"
            aria-label="下一页" disabled={query.isFetching || page >= Number(query.data?.last_page || 1)}
            onClick={() => setPage(value => value + 1)}
          ><ChevronRight size={16}/></button>
        </div>
      </div>
    </div>

    <UserEditorModal
      open={editorOpen}
      user={editing}
      plans={plans}
      onClose={() => setEditorOpen(false)}
      onSaved={() => refresh()}
    />

    <UserMailModal
      open={mailOpen}
      selectedIds={Array.from(selected)}
      filters={filters}
      onClose={() => setMailOpen(false)}
    />
  </>
}

function TrafficCell({ user }: { user: AdminUser }) {
  const used = Number(user.total_used ?? ((user.u || 0) + (user.d || 0)))
  const total = Number(user.transfer_enable || 0)
  const percent = total > 0 ? Math.min(100, (used / total) * 100) : 0

  return <div className="traffic-cell">
    <div className="traffic-bar"><span style={{ width: percent + '%' }}/></div>
    <small>{formatBytes(used)} / {total > 0 ? formatBytes(total) : '∞'}</small>
  </div>
}

function formatBytes(value: number) {
  if (!Number.isFinite(value) || value <= 0) return '0 GB'
  const gb = value / GB
  if (gb >= 1024) return (gb / 1024).toFixed(2) + ' TB'
  return gb.toFixed(gb >= 10 ? 1 : 2) + ' GB'
}

function formatExpire(value?: number | null) {
  if (!value) return '长期有效'
  return new Date(value * 1000).toLocaleDateString()
}

function formatTime(value?: number | null) {
  if (!value) return '-'
  return new Date(value * 1000).toLocaleString()
}
