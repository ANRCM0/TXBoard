// ErrorState: maps GatewayApiError codes to recoverable UI (GW-506).
// Identical mapping to the Vue/React templates.
import type { GatewayApiError } from '@txboard/theme-sdk'

export function errorTitle(error: Error | GatewayApiError | null): string {
  const code = (error as GatewayApiError)?.code
  switch (code) {
    case 'UNAUTHORIZED': return '登录已过期，请重新登录。'
    case 'RATE_LIMITED': return '请求过于频繁，请稍后再试。'
    case 'UPSTREAM_UNAVAILABLE':
    case 'GATEWAY_ERROR': return '服务暂时不可用，请稍后重试。'
    case 'NETWORK_ERROR': return '网络连接失败，请检查网络。'
    case 'VERSION_MISMATCH': return '客户端与网关版本不兼容，请联系管理员。'
    case 'ORDER_REJECTED': return '订单被拒绝。'
    default: return '发生错误，请重试。'
  }
}

export function ErrorState({ error }: { error: Error | GatewayApiError | null }) {
  if (!error) return null
  const gerr = error as GatewayApiError
  return (
    <div className="error-state" role="alert">
      <strong>{errorTitle(error)}</strong>
      {gerr.needsStateConfirmation && <p>{error.message}</p>}
      {gerr.requestId && <p className="request-id">请求 ID：{gerr.requestId}</p>}
    </div>
  )
}
