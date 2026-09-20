import { Link } from 'react-router-dom'
import { PageHeader } from '../components/ui/PageHeader'

/**
 * In-app 404 for the admin SPA.
 *
 * This replaces the old `user/*` placeholder entry, which claimed to be a
 * "用户扩展" section but only ever rendered a permanent loading skeleton for
 * any unmatched path. An explicit 404 is honest about an unknown URL and keeps
 * the admin chrome (navigation, sign-out) around it.
 */
export function NotFoundPage() {
  return (
    <>
      <PageHeader title="页面不存在" description="该地址没有对应的管理页面。" />
      <div className="card placeholder-card">
        <p>
          请从左侧菜单选择功能，或返回<Link to="/">仪表盘</Link>。
        </p>
      </div>
    </>
  )
}
