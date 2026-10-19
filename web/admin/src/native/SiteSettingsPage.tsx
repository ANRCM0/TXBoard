import { useQuery } from '@tanstack/react-query'
import { RefreshCw } from 'lucide-react'
import { admin } from './session'
import { ManagementFeedback } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'

export function SiteSettingsPage() {
  const query = useQuery({ queryKey: ['native-admin', 'site'],
    queryFn: ({ signal }) => admin.read('settings.site.get', {}, signal) })
  const site = query.isError ? undefined : query.data?.data.site
  return <div className="native-management-page">
    <PageHeader title="系统配置" description="查看当前站点配置。"
      action={<button className="button" disabled={query.isFetching} onClick={() => void query.refetch()}><RefreshCw size={15} />刷新</button>} />
    <ManagementFeedback error={query.isError ? query.error : undefined} loading={query.isFetching} onRetry={() => void query.refetch()} />
    {site ? <section className="card native-site-settings" aria-label="站点配置">
      <p>当前为只读视图，配置修改将在后续开放。</p>
      <dl>{[
        ['站点名称', site.app_name], ['站点描述', site.app_description],
        ['站点地址', site.app_url], ['标志地址', site.logo], ['服务条款', site.tos_url],
        ['币种', site.currency], ['新用户注册', site.stop_register === 1 ? '已关闭' : '已开放'],
      ].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value || '未设置'}</dd></div>)}</dl>
    </section> : null}
  </div>
}
