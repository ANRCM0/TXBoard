import { CoreAdminApiError } from './api'
import { AlertCircle, RefreshCw } from 'lucide-react'
import { admin } from './session'

export function managementError(error: unknown): string {
  if (error instanceof CoreAdminApiError) {
    if (error.code === 'CAPTCHA_REQUIRED') return '请完成验证码后再试。'
    if (error.code === 'VALIDATION_ERROR') return '输入内容未通过校验，请检查后重试。'
    if (error.status === 401) return '登录已失效，请重新登录。'
    if (error.status === 403) return '当前账号没有此操作的权限，请联系管理员。'
    if (error.status === 404) return '管理入口或功能已变更，请重新登录后再试。'
    if (error.status === 409) return '记录已变化或操作发生冲突，请重新读取后确认。'
    if (error.status === 429) return '请求过于频繁，请稍后再试。'
    if (error.status === 503) return '管理服务暂不可用，请稍后重试。'
  }
  return '请求失败，请检查网络后重试。'
}
export function ManagementFeedback({ error, loading, onRetry, notFoundMessage }: {
  error?: unknown; loading?: boolean; onRetry: () => void; notFoundMessage?: string
}) {
  if (error) return <div className="query-feedback is-error" role="alert">
    <AlertCircle size={17} /><span>{error instanceof CoreAdminApiError && error.status === 404 && notFoundMessage
      ? notFoundMessage : managementError(error)}</span>
    <button className="button" disabled={loading} onClick={onRetry}>
      <RefreshCw size={14} />{loading ? '重试中…' : '重新加载'}
    </button>
    {error instanceof CoreAdminApiError && error.status === 404 && !notFoundMessage
      ? <button className="button" onClick={() => admin.forgetSession()}>重新登录</button> : null}
  </div>
  return loading ? <div className="query-feedback" role="status">正在加载数据…</div> : null
}
