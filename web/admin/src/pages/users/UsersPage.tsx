import { useQuery } from '@tanstack/react-query'
import { useEffect, useState, useSyncExternalStore, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { RefreshCw, Search } from 'lucide-react'
import { toast } from 'sonner'
import type { CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from '../../native/session'
import { managementError } from '../../native/Feedback'
import { ReadList } from '../../native/ReadList'
import { readPage, unixDate } from '../../native/readPresentation'
import { PageHeader } from '../../components/ui/PageHeader'
import { FilterBar } from '../../components/ui/FilterBar'
import { Input, Select } from '../../components/ui/Field'
import { StatusBadge } from '../../components/ui/StatusBadge'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import type { Column } from '../../components/ui/DataTable'
import './UsersPage.css'

type Account = CoreOutput<'identity.accounts.list'>[number]
const baseColumns: Column<Account>[] = [
  { key: 'email', header: '用户', render: row => <div className="reference-account"><span className="reference-account-avatar" aria-hidden="true">{row.email.slice(0, 1).toUpperCase()}</span><Link className="table-link" to={'/user/' + row.id}>{row.email}</Link></div> },
  { key: 'id', header: '用户 ID', render: row => <code className="reference-user-id">{row.id}</code> },
  { key: 'state', header: '状态', render: row => <StatusBadge tone={row.banned ? 'danger' : 'success'}>{row.banned ? '已封禁' : '正常'}</StatusBadge> },
  { key: 'role', header: '角色', render: row => <span className={'reference-tag ' + (row.is_admin ? 'is-primary' : '')}>{row.is_admin ? '管理员' : '用户'}</span> },
  { key: 'created', header: '注册时间', render: row => unixDate(row.created_at) },
  { key: 'login', header: '最近登录', render: row => unixDate(row.last_login_at) },
]
export function UsersPage() {
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [params, setParams] = useSearchParams()
  const page = readPage(params.get('page'))
  const appliedEmail = params.get('email') ?? ''
  const appliedBanned = params.get('banned') ?? ''
  const [email, setEmail] = useState(appliedEmail)
  const [banned, setBanned] = useState(appliedBanned)
  useEffect(() => { setEmail(appliedEmail); setBanned(appliedBanned) }, [appliedEmail, appliedBanned])
  const valid = appliedEmail.length <= 254 && ['', '0', '1'].includes(appliedBanned)
  const input = { page, per_page: 20, ...(appliedEmail ? { email: appliedEmail } : {}),
    ...(appliedBanned !== '' ? { banned: appliedBanned === '1' } : {}) }
  const query = useQuery({ queryKey: ['native-admin', 'accounts', input], enabled: valid,
    queryFn: ({ signal }) => admin.read('identity.accounts.list', input, signal) })
  const data = query.isError ? undefined : query.data
  const locked = state.busy || !!state.pending || !valid || query.isFetching || query.isError || !query.data
  async function runStatus(row: Account) {
    const nextBanned = !row.banned, generation = workflowActions.generation()
    try {
      await workflowActions.run('identity.accounts.status.update', {
        user_id: row.id, expected_banned: row.banned, banned: nextBanned,
      })
      if (workflowActions.generation() !== generation) return
      toast.success(nextBanned ? '用户已封禁' : '用户已解除封禁')
    } catch (error) {
      if (workflowActions.generation() !== generation) return
      toast.error(managementError(error))
    } finally {
      if (workflowActions.generation() === generation) await query.refetch()
    }
  }
  function confirmStatus(row: Account) {
    if (locked) return
    const nextBanned = !row.banned
    requestConfirm({
      title: nextBanned ? '封禁用户' : '解除封禁',
      message: nextBanned ? `封禁 ${row.email} 后，该账号将无法继续使用站点服务` : `解除 ${row.email} 的封禁状态？`,
      confirmLabel: nextBanned ? '封禁用户' : '解除封禁',
      danger: nextBanned,
      action: () => { void runStatus(row) },
    })
  }
  const columns: Column<Account>[] = [...baseColumns, {
    key: 'actions', header: '操作', render: row => <button type="button"
      className={'button ' + (row.banned ? '' : 'danger')} disabled={locked}
      aria-label={(row.banned ? '解封 ' : '封禁 ') + row.email}
      onClick={() => confirmStatus(row)}>{row.banned ? '解封' : '封禁'}</button>,
  }]
  function apply(event: FormEvent) {
    event.preventDefault()
    setParams({ ...(email.trim() ? { email: email.trim() } : {}), ...(banned !== '' ? { banned } : {}) })
  }
  function paginate(nextPage: number) {
    setParams(current => { const next = new URLSearchParams(current); next.set('page', String(nextPage)); return next })
  }
  return <div className="native-management-page reference-users">
    <PageHeader title="用户管理" description="账户身份、状态与订阅"
      action={<button className="button" disabled={!valid || query.isFetching} onClick={() => void query.refetch()}><RefreshCw size={15} />刷新</button>} />
    {!valid ? <p role="alert">筛选参数有误，请清除筛选后重试。</p> : null}
    <ReadList title="用户列表" rows={data?.data ?? []} columns={columns} meta={data?.meta} page={page}
      ready={!!data} loading={query.isFetching} error={query.isError ? query.error : undefined}
      onRetry={() => void query.refetch()} onPage={paginate}>
      <header className="reference-user-list-heading"><div><h2>用户列表</h2><p>按邮箱精确定位，或筛选账户状态</p></div>{data ? <span className="reference-tag">{data.meta.total.toLocaleString('zh-CN')} 位用户</span> : null}</header>
      <form className="management-filter-form" onSubmit={apply}>
        <FilterBar applied={[
          ...(appliedEmail ? [{ key: 'email', label: '邮箱 ' + appliedEmail }] : []),
          ...(appliedBanned ? [{ key: 'banned', label: appliedBanned === '1' ? '已封禁' : '正常账户' }] : []),
        ]} onClear={() => { setEmail(''); setBanned(''); setParams({}) }}>
          <label>邮箱精确查询<Input aria-label="用户邮箱" value={email} maxLength={254} type="email"
            onChange={event => setEmail(event.target.value)} placeholder="user@example.com" /></label>
          <label>账户状态<Select aria-label="账户状态" value={banned} onChange={event => setBanned(event.target.value)}>
            <option value="">全部状态</option><option value="0">正常</option><option value="1">已封禁</option>
          </Select></label>
          <button className="button primary" type="submit"><Search size={15} aria-hidden="true" />查询</button>
        </FilterBar>
      </form>
    </ReadList>
  </div>
}
