import { randomUUID, X509Certificate } from 'node:crypto'
import https from 'node:https'
import { checkServerIdentity } from 'node:tls'
import type { GatewayConfig } from '../config/env.js'
import { adaptInternalContent, adaptInternalDisplay, adaptInternalOrderList, adaptInternalUserV2, internalError, internalSuccessV2, strictInternalJson } from '../adapters/internal-user-v2.js'
import { GatewayFailure } from './failures.js'

const operations = { userProfile: 'user.profile.get', userOrderDetail: 'user.order.get', userOrders: 'user.order.list',
  userStats: 'user.dashboard.get', userPaymentMethods: 'user.payment-methods.list',
  userSubscription: 'user.subscription-summary.get', userNotices: 'user.notice.list' } as const
export async function internalUserRequest(config: GatewayConfig, operation: keyof typeof operations,
  options: { auth?: string; tradeNo?: string; signal?: AbortSignal; current?: number; pageSize?: number;
    status?: number; paginated?: boolean }): Promise<unknown> {
  const tls = config.internalUser
  if (!tls || !Object.hasOwn(operations, operation)) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Internal operation unavailable')
  if (!options.auth || !/^Bearer [A-Za-z0-9|._-]{1,4089}$(?![\s\S])/.test(options.auth)) {
    throw new GatewayFailure('UPSTREAM_ERROR', 401, 'Authentication required')
  }
  if (operation === 'userOrderDetail' && (!options.tradeNo || !/^[A-Za-z0-9_-]{1,128}$(?![\s\S])/.test(options.tradeNo))) {
    throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid order identifier')
  }
  const page = options.current ?? 1, perPage = options.pageSize ?? (operation === 'userNotices' ? 5 : 100)
  if ((operation === 'userOrders' || operation === 'userNotices') && (!Number.isInteger(page) || page < 1 || page > 9999
    || !Number.isInteger(perPage) || perPage < 1 || perPage > 100
    || (options.status !== undefined && (operation === 'userNotices' || !Number.isInteger(options.status) || options.status < 0 || options.status > 4)))) {
    throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid order query')
  }
  const input = operation === 'userProfile' || operation === 'userStats' || operation === 'userPaymentMethods' || operation === 'userSubscription' ? {} : operation === 'userOrderDetail'
    ? { trade_no: options.tradeNo } : { page, per_page: perPage,
      ...(options.status === undefined ? {} : { status: options.status }) }
  if (Date.now() < Date.parse(tls.certificate.validFrom) || Date.now() >= Date.parse(tls.certificate.validTo)) {
    throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal service unavailable')
  }
  const body = Buffer.from(JSON.stringify({ contract_version: '2', request_id: randomUUID(),
    deadline_at: Math.floor(Date.now() / 1000) + 5, input }))
  return new Promise((resolve, reject) => {
    let finished = false
    let timer: ReturnType<typeof setTimeout> | undefined
    const finish = (error?: GatewayFailure, value?: unknown) => {
      if (finished) return
      finished = true; clearTimeout(timer); options.signal?.removeEventListener('abort', abort); req.destroy()
      if (error) reject(error); else resolve(value)
    }
    const abort = () => finish(new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal request cancelled'))
    const req = https.request({ hostname: tls.origin.hostname, port: tls.origin.port || 443,
      servername: tls.servername, ca: tls.ca, crl: tls.crl, cert: tls.cert, key: tls.key,
      minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3', rejectUnauthorized: true, agent: false,
      checkServerIdentity: (host, cert) => {
        const error = checkServerIdentity(host, cert)
        if (error) return error
        try { if (new X509Certificate(cert.raw).subjectAltName === 'DNS:internal-core.local') return undefined } catch {}
        return new Error('Invalid Internal server identity')
      },
      method: 'POST', path: '/txapi/internal/v1/operations/' + operations[operation],
      headers: { authorization: options.auth!, 'content-type': 'application/json', accept: 'application/json', 'content-length': body.length },
    }, response => {
      if (finished) { response.destroy(); return }
      const chunks: Buffer[] = []; let bytes = 0
      if ((response.statusCode ?? 500) >= 300 && (response.statusCode ?? 500) < 400
        || !/^application\/json(?:;\s*charset=utf-8)?$/i.test(response.headers['content-type'] ?? '')
        || Number(response.headers['content-length'] ?? 0) > 65536) {
        finish(new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response')); response.destroy(); return
      }
      response.on('data', (chunk: Buffer) => {
        bytes += chunk.length
        if (bytes > 65536) { finish(new GatewayFailure('UPSTREAM_ERROR', 502, 'Internal response too large')); response.destroy() }
        else chunks.push(chunk)
      })
      response.on('end', () => {
        if (finished) return
        try {
          const value = strictInternalJson(new TextDecoder('utf-8', { fatal: true }).decode(Buffer.concat(chunks)))
          const status = response.statusCode ?? 502
          if (status !== 200) {
            if (!internalError.safeParse(value).success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response')
            throw new GatewayFailure('UPSTREAM_ERROR', status >= 400 && status <= 599 ? status : 502,
              status === 401 ? 'Authentication failed' : status === 403 ? 'Access denied' : status === 404 ? 'Resource not found' : 'Internal request rejected')
          }
          const envelope = internalSuccessV2.safeParse(value)
          if (!envelope.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response')
          finish(undefined, operation === 'userSubscription' || operation === 'userNotices' ? adaptInternalContent(operation, envelope.data.data, options)
            : operation === 'userStats' || operation === 'userPaymentMethods' ? adaptInternalDisplay(operation, envelope.data.data)
            : operation === 'userOrders' ? adaptInternalOrderList(envelope.data.data, options)
            : adaptInternalUserV2(operation, envelope.data.data, options.tradeNo))
        } catch (error) {
          finish(error instanceof GatewayFailure ? error : new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response'))
        }
      })
      response.on('error', () => finish(new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal connection failed')))
      response.on('close', () => { if (!response.complete) finish(new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal connection failed')) })
    })
    // Total walltime, independent of socket idle events or a slow continuous response stream.
    timer = setTimeout(() => finish(new GatewayFailure('UPSTREAM_UNAVAILABLE', 504, 'Internal request timed out')), Math.min(config.timeoutMs, 5000))
    req.on('error', () => finish(new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal connection failed')))
    if (options.signal?.aborted) { abort(); return }
    options.signal?.addEventListener('abort', abort, { once: true })
    req.end(body)
  })
}
