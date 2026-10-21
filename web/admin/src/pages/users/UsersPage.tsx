import { t, useLocale, formatNumber } from '../../lib/i18n'
import { useDirtyDraft } from '../../lib/useDirtyDraft'
import { readPageSize } from '../../native/useListPagination'
import { useQuery } from '@tanstack/react-query'
import { useEffect, useState, useSyncExternalStore, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { ExternalLink, Pencil, Plus, RefreshCw, Search, ShieldCheck, UserCheck, UserX } from 'lucide-react'
import { toast } from 'sonner'
import type { CoreInput, CoreOutput } from '@txboard/core-sdk'
import { ListRuleFilter, accountRules, parseListRules } from '../../native/ListRuleFilter'
import { BatchAccountDialog } from '../../native/IdentityEditors'
import { AccountProfileDialog } from '../../native/AccountProfileDialog'
import { admin, workflowActions } from '../../native/session'
import { managementError } from '../../native/Feedback'
import { NetworkRecovery, useNetworkManagement } from '../../native/NetworkManagement'
import { ReadList } from '../../native/ReadList'
import { readPage, unixDate } from '../../native/readPresentation'
import { PageHeader } from '../../components/ui/PageHeader'
import { Modal } from '../../components/ui/Modal'
import { FilterBar } from '../../components/ui/FilterBar'
import { FacetedFilter } from '../../components/ui/FacetedFilter'
import { Field, Input } from '../../components/ui/Field'
import { StatusBadge } from '../../components/ui/StatusBadge'
import { CopyButton } from '../../components/ui/CopyButton'
import { RowActionsMenu } from '../../components/ui/RowActionsMenu'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import type { Column } from '../../components/ui/DataTable'
import './UsersPage.css'

type Account = CoreOutput<'identity.accounts.list'>[number]
const baseColumns = (): Column<Account>[] => [
  { key: 'email', header: t("用户"), hideable: false, pin: 'left', render: row => <div className="reference-account"><span className="reference-account-avatar" aria-hidden="true">{row.email.slice(0, 1).toUpperCase()}</span><div className="user-account-identity"><div className="user-copy-line"><Link className="table-link" to={'/user/' + row.id}>{row.email}</Link><CopyButton value={row.email} label={t("复制用户邮箱")} /></div><small>{row.is_admin ? t("管理账户") : t("站点账户")}</small></div></div> },
  { key: 'id', header: t("用户 ID"), render: row => <div className="user-copy-line"><code className="reference-user-id">#{row.id}</code><CopyButton value={row.id} label={t("复制用户 ID")} /></div> },
  { key: 'state', header: t("状态"), description: t("封禁后账号无法继续使用站点服务；解封后恢复访问。"), render: row => <StatusBadge tone={row.banned ? 'danger' : 'success'}>{row.banned ? t("已封禁") : t("正常")}</StatusBadge> },
  { key: 'role', header: t("角色"), render: row => <span className={'reference-tag user-role-tag ' + (row.is_admin ? 'is-primary' : '')}>{row.is_admin ? <ShieldCheck size={13} aria-hidden="true" /> : null}{row.is_admin ? t("管理员") : t("用户")}</span> },
  { key: 'created', header: t("注册时间"), render: row => unixDate(row.created_at) },
  { key: 'login', header: t("最近登录"), render: row => row.last_login_at === null ? <span className="user-never-login">{t("尚未登录")}</span> : unixDate(row.last_login_at) },
]
export function UsersPage() {
  useLocale()
  const navigate = useNavigate()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const generation = workflowActions.generation()
  const [params, setParams] = useSearchParams()
  const page = readPage(params.get('page'))
  const perPage = readPageSize(params.get('per_page'))
  function resize(size: number) {
    setParams(current => { const next = new URLSearchParams(current); next.delete('page'); next.set('per_page', String(size)); return next })
  }
  const appliedEmail = params.get('email') ?? ''
  const appliedBanned = params.get('banned') ?? ''
  const [email, setEmail] = useState(appliedEmail)
  const [banned, setBanned] = useState(appliedBanned)
  const [creating, setCreating] = useState(false), [newEmail, setNewEmail] = useState('')
  const [newPassword, setNewPassword] = useState(''), [createError, setCreateError] = useState('')
  const [batchOpen, setBatchOpen] = useState(false)
  const [editingUser, setEditingUser] = useState<string>()
  const createDirty = creating && (newEmail !== '' || newPassword !== '')
  useEffect(() => {
    if (!createDirty) return
    const prevent = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', prevent)
    return () => window.removeEventListener('beforeunload', prevent)
  }, [createDirty])
  useEffect(() => { setCreating(false); setNewEmail(''); setNewPassword(''); setCreateError('') }, [generation])
  useEffect(() => { setEmail(appliedEmail); setBanned(appliedBanned) }, [appliedEmail, appliedBanned])
  const advanced = parseListRules(params, accountRules)
  const valid = advanced.valid && appliedEmail.length <= 254 && ['', '0', '1'].includes(appliedBanned)
  const input = { page, per_page: perPage, ...(appliedEmail ? { email: appliedEmail } : {}),
    ...(appliedBanned !== '' ? { banned: appliedBanned === '1' } : {}),
    ...(params.has('filters') ? { filters: advanced.filters } : {}),
    ...(params.has('sort_by') ? { sort_by: advanced.sort_by } : {}), ...(params.has('sort_direction') ? { sort_direction: advanced.sort_direction } : {}) } as CoreInput<'identity.accounts.list'>
  const query = useQuery({ queryKey: ['native-admin', 'accounts', input], enabled: valid,
    queryFn: ({ signal }) => admin.read('identity.accounts.list', input, signal) })
  const recovery = useNetworkManagement('identity.accounts.', [query.error], () => query.refetch({ throwOnError: true }))
  useEffect(() => { if (recovery.denied) { setCreating(false); setNewPassword(''); setNewEmail(''); setBatchOpen(false); setEditingUser(undefined) } }, [recovery.denied])
  useEffect(() => { setEditingUser(undefined) }, [page, perPage, appliedEmail, appliedBanned])
  const subscriptionRecovery = useNetworkManagement('subscription.lifecycle.', [query.error], () => query.refetch({ throwOnError: true }))
  const data = !valid || recovery.denied || query.isError ? undefined : query.data
  const locked = recovery.denied || state.busy || !!state.pending || !valid || query.isFetching || query.isError || !query.data
  useDirtyDraft(createDirty && !recovery.denied && !state.pending)
  async function runStatus(row: Account) {
    const nextBanned = !row.banned, generation = workflowActions.generation()
    try {
      await workflowActions.run('identity.accounts.status.update', {
        user_id: row.id, expected_banned: row.banned, banned: nextBanned,
      })
      if (workflowActions.generation() !== generation) return
      toast.success(nextBanned ? t("用户已封禁") : t("用户已解除封禁"))
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
      title: nextBanned ? t("封禁用户") : t("解除封禁"),
      message: nextBanned ? t("封禁 {v0} 后，该账号将无法继续使用站点服务", { v0: row.email }) : t("解除 {v0} 的封禁状态？", { v0: row.email }),
      confirmLabel: nextBanned ? t("封禁用户") : t("解除封禁"),
      danger: nextBanned,
      action: () => { void runStatus(row) },
    })
  }
  const columns: Column<Account>[] = [...baseColumns(), {
    key: 'actions', header: t("操作"), hideable: false, pin: 'right', render: row => <RowActionsMenu label={t('更多操作')} actions={[
      { key: 'view', label: t('详情'), ariaLabel: t('查看 {email} 详情', { email: row.email }), icon: <ExternalLink size={14} aria-hidden="true" />, href: '/user/' + row.id },
      { key: 'edit', label: t('编辑'), ariaLabel: t('编辑 {email} 登录资料', { email: row.email }), icon: <Pencil size={14} aria-hidden="true" />, disabled: locked || row.is_admin, onSelect: () => setEditingUser(row.id) },
      {
        key: 'status', label: row.banned ? t('解封') : t('封禁'), ariaLabel: t(row.banned ? '解封 {email}' : '封禁 {email}', { email: row.email }), separator: true, danger: !row.banned,
        icon: row.banned ? <UserCheck size={14} aria-hidden="true" /> : <UserX size={14} aria-hidden="true" />,
        disabled: locked || row.is_admin, onSelect: () => confirmStatus(row),
      },
    ]} />,
  }]
  function apply(event: FormEvent) {
    event.preventDefault()
    setParams(current => { const next = new URLSearchParams(current); next.delete('page'); if (email.trim()) next.set('email', email.trim()); else next.delete('email'); if (banned) next.set('banned', banned); else next.delete('banned'); return next })
  }
  function paginate(nextPage: number) {
    setParams(current => { const next = new URLSearchParams(current); next.set('page', String(nextPage)); return next })
  }
  function removeFilter(key: string) {
    setParams(current => { const next = new URLSearchParams(current); next.delete(key); next.delete('page'); return next })
  }
  function openCreate() {
    setNewEmail(''); setNewPassword(''); setCreateError(''); setCreating(true)
  }
  function closeCreate() {
    if (state.busy || (createDirty && !window.confirm(t('新用户尚未创建，放弃当前输入？')))) return
    setCreating(false); setNewEmail(''); setNewPassword(''); setCreateError('')
  }
  async function createAccount(event: FormEvent) {
    event.preventDefault()
    if (locked) return
    const email = newEmail.trim(), password = newPassword
    if (!email || password.length < 8) {
      setCreateError('请输入有效邮箱和至少 8 位密码')
      return
    }
    setCreateError('')
    const generation = workflowActions.generation()
    try {
      const result = await workflowActions.runResult('identity.accounts.create', { email, password }) as { data?: { id?: string } }
      if (workflowActions.generation() !== generation) return
      setCreating(false); setNewEmail(''); setNewPassword('')
      toast.success(t("用户已创建"))
      await query.refetch()
      if (result?.data?.id) navigate('/user/' + result.data.id)
    } catch (error) {
      if (workflowActions.generation() !== generation) return
      setCreateError(managementError(error, false))
      if (workflowActions.snapshot().pending) {
        setCreating(false); setNewPassword('')
      }
    }
  }
  return <div className="native-management-page reference-users">
    <PageHeader title={t("用户管理")} description={t("账户身份、状态与订阅")}
      action={<><button className="button" disabled={!valid || query.isFetching} onClick={() => void recovery.refresh()}><RefreshCw size={15} />{t("刷新")}</button><button className="button" disabled={locked} onClick={() => setBatchOpen(true)}>{t("批量创建")}</button><button className="button primary" disabled={locked} onClick={openCreate}><Plus size={15} />{t("新建用户")}</button></>} />
    {recovery.pending || recovery.state.error
      ? <NetworkRecovery management={recovery} blocked={query.isFetching || query.isError || !query.data} />
      : null}
    {subscriptionRecovery.pending && subscriptionRecovery.pending.operation !== 'subscription.lifecycle.reset'
      ? <NetworkRecovery management={subscriptionRecovery} blocked={query.isFetching || query.isError || !query.data} />
      : null}
    {!valid ? <p role="alert">{t("筛选参数有误，请清除筛选后重试。")}</p> : null}
    <ReadList title="用户列表" rows={data?.data ?? []} columns={columns} meta={data?.meta} perPage={perPage} onPageSize={resize} page={page}
      ready={!!data} loading={query.isFetching} error={query.isError ? query.error : undefined}
      onRetry={() => void recovery.refresh()} onPage={paginate}
      columnSettingsKey="users" stickyHeader stickyFirstColumn mobilePrimaryKey="email">
      <header className="reference-user-list-heading"><div><h2>{t("用户列表")}</h2><p>{t("按邮箱精确定位，或筛选账户状态")}</p></div>{data ? <span className="reference-tag">{t('{count} 位用户', { count: formatNumber(data.meta.total) })}</span> : null}</header>
      <form className="management-filter-form" onSubmit={apply}>
        <FilterBar applied={[
          ...(appliedEmail ? [{ key: 'email', label: t('邮箱 {email}', { email: appliedEmail }) }] : []),
          ...(appliedBanned ? [{ key: 'banned', label: appliedBanned === '1' ? t("已封禁") : t("正常账户") }] : []),
        ]} onRemove={removeFilter} hint={t("按邮箱精确匹配账户，筛选结果在所有分页中生效")} onClear={() => { setEmail(''); setBanned(''); setParams(perPage !== 20 ? { per_page: String(perPage) } : {}) }}>
          <label>{t("邮箱精确查询")}<Input aria-label={t("用户邮箱")} value={email} maxLength={254} type="email"
            onChange={event => setEmail(event.target.value)} placeholder="user@example.com" /></label>
          <FacetedFilter label={t("账户状态")} multiple={false} value={banned ? [banned] : []} onChange={next => setBanned(next[0] ?? '')}
            options={[{ value: '0', label: t("正常") }, { value: '1', label: t("已封禁") }]} />
          <button className="button primary" type="submit"><Search size={15} aria-hidden="true" />{t("查询")}</button>
        </FilterBar>
      </form>
      <ListRuleFilter definition={accountRules} />
    </ReadList>
    <Modal open={creating && !recovery.denied} title={t("新建用户")} subtitle={t("创建后进入用户详情；初始密码仅本次提交使用")}
      onClose={closeCreate}>
      <form className="native-content-form" onSubmit={createAccount}>
        <Field label={t("邮箱")} required><Input type="email" autoFocus value={newEmail} maxLength={255} disabled={state.busy}
          onChange={event => setNewEmail(event.target.value)} placeholder="user@example.com" /></Field>
        <Field label={t("初始密码")} required hint={t("至少 8 个字符")}><Input type="password" value={newPassword} minLength={8} maxLength={255}
          disabled={state.busy} autoComplete="new-password" onChange={event => setNewPassword(event.target.value)} placeholder={t("设置初始密码")} /></Field>
        {createError ? <p role="alert">{t(createError)}</p> : null}
        <div className="network-form-actions">
          <button className="button primary" disabled={locked || !newEmail.trim() || newPassword.length < 8}>{t("创建用户")}</button>
          <button type="button" className="button" disabled={state.busy} onClick={closeCreate}>{t("取消")}</button>
        </div>
      </form>
    </Modal>
    {batchOpen && !recovery.denied ? <BatchAccountDialog locked={locked} onClose={() => setBatchOpen(false)} onCreated={() => void query.refetch()} /> : null}
    {editingUser && !recovery.denied && !recovery.pending ? <AccountProfileDialog userId={editingUser} locked={locked} onClose={() => setEditingUser(undefined)} onChanged={() => void query.refetch()} /> : null}
  </div>
}
