import { t, useLocale } from '../../lib/i18n'
import { AlertCircle, LoaderCircle, RefreshCw } from 'lucide-react'

export function QueryFeedback({ loading, error, onRetry }: {
  loading?: boolean
  error?: boolean
  onRetry?: () => void
}) {
  useLocale()
  if (error) return <div className="query-feedback is-error" role="alert">
    <AlertCircle size={17} aria-hidden="true" />
    <span>{t("加载失败，请检查网络后重试。")}</span>
    {onRetry && <button type="button" className="button" disabled={loading} onClick={onRetry}>
      <RefreshCw size={14} aria-hidden="true" />{loading ? t("重试中…") : t("重新加载")}
    </button>}
  </div>
  if (loading) return <div className="query-feedback" role="status">
    <LoaderCircle size={17} className="loading-spinner" aria-hidden="true" />
    <span>{t("正在加载数据…")}</span>
  </div>
  return null
}
