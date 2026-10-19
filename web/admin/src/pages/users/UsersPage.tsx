import { useQuery } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { RefreshCw, Search } from 'lucide-react'
import type { CoreOutput } from '@txboard/core-sdk'
import { admin } from '../../native/session'
import { ReadList } from '../../native/ReadList'
import { readPage, unixDate } from '../../native/readPresentation'
import { PageHeader } from '../../components/ui/PageHeader'
import type { Column } from '../../components/ui/DataTable'
import './UsersPage.css'

type Account = CoreOutput<'identity.accounts.list'>[number]
const columns: Column<Account>[] = [
  { key: 'email', header: '用户', render: row => <div className="reference-account"><span className="reference-account-avatar" aria-hidden="true">{row.email.slice(0, 1).toUpperCase()}</span><Link className="table-link" to={'/user/' + row.id}>{row.email}</Link></div> },
  { key: 'id', header: '用户 ID', render: row => <code className="reference-user-id">{row.id}</code> },
  { key: 'state', header: '状态', render: row => <span className={'reference-status ' + (row.banned ? 'is-danger' : 'is-success')}>{row.banned ? '已封禁' : '正常'}</span> },
  { key: 'role', header: '角色', render: row => <span className={'reference-tag ' + (row.is_admin ? 'is-primary' : '')}>{row.is_admin ? '管理员' : '用户'}</span> },
  { key: 'created', header: '注册时间', render: row => unixDate(row.created_at) },
  { key: 'login', header: '最近登录', render: row => unixDate(row.last_login_at) },
]
export function UsersPage() {
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
        <label>邮箱精确查询<input aria-label="用户邮箱" value={email} maxLength={254} type="email"
          onChange={event => setEmail(event.target.value)} placeholder="user@example.com" /></label>
        <label>账户状态<select aria-label="账户状态" value={banned} onChange={event => setBanned(event.target.value)}>
          <option value="">全部状态</option><option value="0">正常</option><option value="1">已封禁</option>
        </select></label>
        <button className="button primary" type="submit"><Search size={15} />查询</button>
        <button className="button" type="button" onClick={() => setParams({})}>清除筛选</button>
      </form>
      {appliedEmail || appliedBanned ? <p className="reference-applied-filters" role="status">当前筛选：{[appliedEmail ? '邮箱 ' + appliedEmail : '', appliedBanned ? (appliedBanned === '1' ? '已封禁' : '正常账户') : ''].filter(Boolean).join(' · ')}</p> : null}
    </ReadList>
  </div>
}
