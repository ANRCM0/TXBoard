import { t, useLocale } from '../../lib/i18n'
import { AlertCircle, LoaderCircle, RefreshCw } from 'lucide-react'

export function QueryFeedback({ loading, error, onRetry, hasData = false, id }: {
  loading?: boolean
  error?: boolean
  onRetry?: () => void
  hasData?: boolean
  id?: string
}) {
  useLocale()
  if (error) return <div id={id} className="query-feedback is-error" role="alert" aria-atomic="true">
    <AlertCircle size={17} aria-hidden="true" />
    <span>{hasData ? t("更新失败，当前显示上次加载的数据。请重试后再操作。") : t("加载失败，请检查网络后重试。")}</span>
    {onRetry && <button type="button" className="button" disabled={loading} onClick={onRetry}>
      <RefreshCw size={14} aria-hidden="true" />{loading ? t("重试中…") : t("重新加载")}
    </button>}
  </div>
  if (loading) return <div id={id} className="query-feedback" role="status" aria-atomic="true">
    <LoaderCircle size={17} className="loading-spinner" aria-hidden="true" />
    <span>{hasData ? t("正在更新，当前显示上次加载的数据。") : t("正在加载数据…")}</span>
  </div>
  return null
}
