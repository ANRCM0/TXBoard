import { formatDate, t, useLocale } from '../lib/i18n'
import './observation-reference.css'
import { useMutation, useQuery } from '@tanstack/react-query'
import type { CoreOutput } from '@txboard/core-sdk'
import { RefreshCw, Monitor, ShieldCheck, LogOut } from 'lucide-react'
import { toast } from 'sonner'
import { admin, revokeAdminSession } from './session'
import { managementError, ManagementFeedback } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'
import { DataTable, type Column } from '../components/ui/DataTable'
import { EmptyState } from '../components/ui/EmptyState'
import { requestConfirm } from '../components/ui/ConfirmDialog'

type Session = CoreOutput<'identity.sessions.list'>[number]
const date = (value: string | null) => value ? formatDate(value, { dateStyle: 'medium', timeStyle: 'medium' }) : t('未记录')

export function SessionsPage() {
  useLocale()
  const query = useQuery({ queryKey: ['native-admin', 'sessions'],
    queryFn: ({ signal }) => admin.read('identity.sessions.list', {}, signal) })
  const revoke = useMutation({
    mutationFn: (id: string) => revokeAdminSession(id),
    onSuccess: () => { toast.success(t('会话已撤销')); void query.refetch() },
    onError: error => {
      toast.error(managementError(error) + t(' 请刷新列表确认；再次操作将沿用原请求标识。'))
      void query.refetch()
    },
  })
  function confirmRevoke(id: string) {
    if (revoke.isPending) return
    requestConfirm({ title: t('撤销登录会话'), message: t('撤销此登录会话？若为当前会话，您将需要重新登录。'),
      confirmLabel: t('撤销'), danger: true, action: () => revoke.mutate(id) })
  }
  const columns: Column<Session>[] = [
    { key: 'name', header: t('会话'), render: row => <div className="observation-identity"><Monitor size={20} /><span><strong>{row.name}</strong><small className="observation-mono">{t('会话 #{id}', { id: row.id })}</small></span></div> },
    { key: 'created', header: t('创建时间'), render: row => <span className="observation-date">{date(row.created_at)}</span> },
    { key: 'used', header: t('最近使用'), render: row => <span className="observation-date">{date(row.last_used_at)}</span> },
    { key: 'expires', header: t('到期时间'), render: row => <span className="observation-tag">{row.expires_at ? date(row.expires_at) : t('长期有效')}</span> },
    { key: 'actions', header: t('操作'), pin: 'right', hideable: false, render: row => <button className="button danger" disabled={revoke.isPending || query.isFetching || query.isError}
      onClick={() => void confirmRevoke(row.id)}><LogOut size={14} />{revoke.isPending && revoke.variables === row.id ? t('撤销中…') : t('撤销')}</button> },
  ]
  return <div className="native-management-page observation-page observation-sessions-page">
    <PageHeader title={t("登录会话")} description={t("管理此账户的登录设备与访问权限")}
      action={<button className="button" disabled={query.isFetching} onClick={() => void query.refetch()}><RefreshCw size={15} />{t("刷新")}</button>} />
    <ManagementFeedback error={query.isError ? query.error : undefined} loading={query.isFetching} onRetry={() => void query.refetch()} />
    {!query.isError && query.data ? <div className="list-card"><div className="observation-card-heading"><div><h2>{t("登录设备")}</h2><p>{t("发现不熟悉的登录？撤销会话以终止其访问。")}</p></div><span className="observation-tag"><ShieldCheck size={14} />{t('{count} 个会话', { count: query.data.data.length })}</span></div>
      {query.data.meta.has_more ? <p className="native-list-note">{t("当前显示前 100 项，另有会话未展示。")}</p> : null}
      {query.data.data.length
        ? <><p className="native-scroll-hint">{t("左右滑动表格查看完整会话信息")}</p><DataTable rows={query.data.data} columns={columns} rowKey={row => row.id} label={t("登录会话")} mobilePrimaryKey="name" /></>
        : <EmptyState title={t("暂无登录会话")} description={t("当前账户没有可管理的活跃登录设备")} />}
    </div> : null}
  </div>
}
