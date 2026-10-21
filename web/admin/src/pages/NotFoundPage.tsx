import { t, useLocale } from '../lib/i18n'
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
export function NotFoundPage({ status = 404 }: { status?: 404 | 500 | 503 }) {
  useLocale()
  const title = status === 404 ? t('页面不存在') : status === 500 ? t('页面暂时出错') : t('服务暂不可用')
  const message = status === 404 ? t('该地址没有对应的管理页面。') : status === 500 ? t('页面未能完成加载，请重试或返回仪表盘。') : t('管理服务当前无法响应，请稍后重试。')
  return (
    <>
      <PageHeader title={t(title)} description={t(message)} />
      <div className="card admin-status-page">
        <strong className="admin-status-code" aria-hidden="true">{status}</strong>
        <p>{t('请从管理菜单选择功能，或返回仪表盘继续。')}</p>
        <div className="ui-route-error-actions"><Link className="button primary" to="/">{t('返回仪表盘')}</Link>
          {status !== 404 ? <button type="button" className="button" onClick={() => window.location.reload()}>{t('重新加载')}</button> : null}
        </div>
      </div>
    </>
  )
}
