import type { ProxyOptions } from 'vite'

// Adapt the local browser origin only after validating the original request.
export function coreBrowserProxy(target: string): ProxyOptions {
  const targetOrigin = new URL(target).origin
  return {
    target,
    changeOrigin: true,
    bypass(req, res) {
      const origin = req.headers.origin
      if (origin === undefined) return
      const protocol = 'encrypted' in req.socket && req.socket.encrypted ? 'https' : 'http'
      let sameOrigin = false
      try {
        sameOrigin = !!req.headers.host && origin === new URL(`${protocol}://${req.headers.host}`).origin
      } catch { /* Invalid authority is rejected below. */ }
      if (!sameOrigin) {
        res.statusCode = 403
        res.end('Forbidden browser origin')
        // Vite stops a string bypass once the response has ended.
        return req.url || '/gateway'
      }
      req.headers.origin = targetOrigin
    },
  }
}
