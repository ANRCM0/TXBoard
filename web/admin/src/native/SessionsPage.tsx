import './observation-reference.css'
import { useMutation, useQuery } from '@tanstack/react-query'
import type { CoreOutput } from '@txboard/core-sdk'
import { RefreshCw, Monitor, ShieldCheck, LogOut } from 'lucide-react'
import { toast } from 'sonner'
import { admin, revokeAdminSession } from './session'
import { managementError, ManagementFeedback } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'
import { DataTable, type Column } from '../components/ui/DataTable'
import { requestConfirm } from '../components/ui/ConfirmDialog'

type Session = CoreOutput<'identity.sessions.list'>[number]
const date = (value: string | null) => value ? new Date(value).toLocaleString('zh-CN') : '未记录'

export function SessionsPage() {
  const query = useQuery({ queryKey: ['native-admin', 'sessions'],
    queryFn: ({ signal }) => admin.read('identity.sessions.list', {}, signal) })
  const revoke = useMutation({
    mutationFn: (id: string) => revokeAdminSession(id),
    onSuccess: () => { toast.success('会话已撤销'); void query.refetch() },
    onError: error => {
      toast.error(managementError(error) + ' 请刷新列表确认；再次操作将沿用原请求标识。')
      void query.refetch()
    },
  })
  function confirmRevoke(id: string) {
    if (revoke.isPending) return
    requestConfirm({ title: '撤销登录会话', message: '撤销此登录会话？若为当前会话，您将需要重新登录。',
      confirmLabel: '撤销', danger: true, action: () => revoke.mutate(id) })
  }
  const columns: Column<Session>[] = [
    { key: 'name', header: '会话', render: row => <div className="observation-identity"><Monitor size={20} /><span><strong>{row.name}</strong><small className="observation-mono">会话 #{row.id}</small></span></div> },
    { key: 'created', header: '创建时间', render: row => date(row.created_at) },
    { key: 'used', header: '最近使用', render: row => date(row.last_used_at) },
    { key: 'expires', header: '到期时间', render: row => <span className="observation-tag">{row.expires_at ? date(row.expires_at) : '长期有效'}</span> },
    { key: 'actions', header: '操作', render: row => <button className="button danger" disabled={revoke.isPending}
      onClick={() => void confirmRevoke(row.id)}><LogOut size={14} />撤销</button> },
  ]
  return <div className="native-management-page observation-page">
    <PageHeader title="登录会话" description="管理此账户的登录设备与访问权限"
      action={<button className="button" disabled={query.isFetching} onClick={() => void query.refetch()}><RefreshCw size={15} />刷新</button>} />
    <ManagementFeedback error={query.isError ? query.error : undefined} loading={query.isFetching} onRetry={() => void query.refetch()} />
    {!query.isError && query.data ? <div className="list-card"><div className="observation-card-heading"><div><h2>登录设备</h2><p>发现不熟悉的登录？撤销会话以终止其访问。</p></div><span className="observation-tag"><ShieldCheck size={14} />{query.data.data.length} 个会话</span></div>
      {query.data.meta.has_more ? <p className="native-list-note">当前显示前 100 项，另有会话未展示。</p> : null}
      <DataTable rows={query.data.data} columns={columns} rowKey={row => row.id} label="登录会话" />
    </div> : null}
  </div>
}
