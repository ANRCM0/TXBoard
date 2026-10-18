import { useLocation } from 'react-router-dom'
import { Plug } from 'lucide-react'
import { coreNavigationGroups } from '../navigation/core'
import { EmptyState } from '../components/ui/EmptyState'
import { PageHeader } from '../components/ui/PageHeader'

export function PendingPage() {
  const { pathname } = useLocation()
  const title = coreNavigationGroups.map(group => group.items.find(([path]) => path === pathname)?.[1])
    .find(Boolean) || '功能迁移'
  return <div className="native-management-page">
    <PageHeader title={title} />
    <EmptyState icon={<Plug size={30} />} title="此功能暂未开放"
      description="相关页面正在适配新的管理接口，完成后将开放使用。" />
  </div>
}
