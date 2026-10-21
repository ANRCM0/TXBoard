import { t, useLocale } from '../lib/i18n'
import { CoreAdminApiError } from './api'
import { AlertCircle, LoaderCircle, RefreshCw } from 'lucide-react'
import { admin } from './session'

export function managementError(error: unknown, localize = true): string {
  const text = localize ? t : (message: string) => message
  if (error instanceof CoreAdminApiError) {
    if (error.code === 'CAPTCHA_REQUIRED') return text("请完成验证码后再试。")
    if (error.code === 'VALIDATION_ERROR') return text("输入内容未通过校验，请检查后重试。")
    if (error.status === 401) return text("登录已失效，请重新登录。")
    if (error.status === 403) return text("当前账号没有此操作的权限，请联系管理员。")
    if (error.status === 404) return text("管理入口或功能已变更，请重新登录后再试。")
    if (error.status === 409) return text("记录已变化或操作发生冲突，请重新读取后确认。")
    if (error.status === 429) return text("请求过于频繁，请稍后再试。")
    if (error.status === 503) return text("管理服务暂不可用，请稍后重试。")
  }
  return text("请求失败，请检查网络后重试。")
}
export function ManagementFeedback({ error, loading, onRetry, notFoundMessage }: {
  error?: unknown; loading?: boolean; onRetry: () => void; notFoundMessage?: string
}) {
  useLocale()
  if (error) return <div className="query-feedback is-error" role="alert">
    <AlertCircle size={17} aria-hidden="true" /><span>{error instanceof CoreAdminApiError && error.status === 404 && notFoundMessage
      ? notFoundMessage : managementError(error)}</span>
    <button type="button" className="button" disabled={loading} onClick={onRetry}>
      <RefreshCw size={14} className={loading ? 'loading-spinner' : undefined} aria-hidden="true" />{loading ? t("重试中…") : t("重新加载")}
    </button>
    {error instanceof CoreAdminApiError && error.status === 404 && !notFoundMessage
      ? <button type="button" className="button" onClick={() => admin.forgetSession()}>{t("重新登录")}</button> : null}
  </div>
  return loading ? <div className="query-feedback" role="status"><LoaderCircle size={17} className="loading-spinner" aria-hidden="true" /><span>{t("正在加载数据…")}</span></div> : null
}
