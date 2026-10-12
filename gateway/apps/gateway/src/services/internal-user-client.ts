import { randomUUID, X509Certificate } from 'node:crypto'
import https from 'node:https'
import { checkServerIdentity } from 'node:tls'
import type { GatewayConfig } from '../config/env.js'
import { emailCodeSchema, loginSchema, registerSchema, passwordResetSchema } from '../contracts/schemas.js'
import { adaptInternalContent, adaptInternalDisplay, adaptInternalOrderList, adaptInternalOrderStatus, adaptInternalUserV2, adaptInternalPublic, internalPublicSuccess, internalLoginSuccess, internalEmailCodeSuccess, internalRegisterSuccess, internalPasswordResetSuccess, internalError, internalSuccessV2, internalOrderCreateInput, internalOrderWriteSuccess, strictInternalJson } from '../adapters/internal-user-v2.js'
import { GatewayFailure } from './failures.js'
import { mappedOrderWriteFailure } from './order-write-failure.js'

const operations = { login: 'auth.login', register: 'auth.register', resetPassword: 'auth.password.reset', sendEmailCode: 'auth.email-code.send', guestConfig: 'public.site-config.get', guestPlans: 'public.plan.list',
  userProfile: 'user.profile.get', userOrderDetail: 'user.order.get', userOrders: 'user.order.list',
  userStats: 'user.dashboard.get', userPaymentMethods: 'user.payment-methods.list',
  userSubscription: 'user.subscription-summary.get', userNotices: 'user.notice.list', userOrderStatus: 'user.order-status.get',
  userOrderCreate: 'user.order.create' } as const
export async function internalUserRequest(config: GatewayConfig, operation: keyof typeof operations,
  options: { auth?: string; tradeNo?: string; signal?: AbortSignal; current?: number; pageSize?: number;
    status?: number; paginated?: boolean; body?: unknown; headers?: Record<string, string> }): Promise<unknown> {
  const tls = config.internalUser
  if (!tls || !Object.hasOwn(operations, operation)) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Internal operation unavailable')
  const login = operation === 'login'
  const emailCode = operation === 'sendEmailCode'
  const registration = operation === 'register'
  const passwordReset = operation === 'resetPassword'
  const publicRead = operation === 'guestConfig' || operation === 'guestPlans'
  const anonymous = publicRead || login || emailCode || registration || passwordReset
  const anonymousEnabled = publicRead ? config.internalPublicReadEnabled
    : emailCode ? config.internalEmailCodeEnabled : registration ? config.internalRegisterEnabled : passwordReset ? config.internalPasswordResetEnabled : config.internalLoginEnabled
  if (anonymous && (anonymousEnabled !== true || options.auth !== undefined)) {
    throw new GatewayFailure('UPSTREAM_ERROR', 400, 'Internal public operation unavailable')
  }
  if (!anonymous && (!options.auth || !/^Bearer [A-Za-z0-9|._-]{1,4089}$(?![\s\S])/.test(options.auth))) {
    throw new GatewayFailure('UPSTREAM_ERROR', 401, 'Authentication required')
  }
  const write = operation === 'userOrderCreate'
  const credentials = login ? loginSchema.safeParse(options.body) : undefined
  if (credentials && !credentials.success) throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid login data')
  const emailInput = emailCode ? emailCodeSchema.safeParse(options.body) : undefined
  if (emailInput && !emailInput.success) throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid verification data')
  const registerInput = registration ? registerSchema.safeParse(options.body) : undefined
  if (registerInput && !registerInput.success) throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid registration data')
  const resetInput = passwordReset ? passwordResetSchema.safeParse(options.body) : undefined
  if (resetInput && !resetInput.success) throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid recovery data')
  if (write && config.orderWriteEnabled !== true) throw new GatewayFailure('UPSTREAM_ERROR', 405, 'Order creation unavailable')
  if ((operation === 'userOrderDetail' || operation === 'userOrderStatus') && (!options.tradeNo || !/^[A-Za-z0-9_-]{1,128}$(?![\s\S])/.test(options.tradeNo))) {
    throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid order identifier')
  }
  const page = options.current ?? 1, perPage = options.pageSize ?? (operation === 'userNotices' ? 5 : 100)
  if ((operation === 'userOrders' || operation === 'userNotices') && (!Number.isInteger(page) || page < 1 || page > 9999
    || !Number.isInteger(perPage) || perPage < 1 || perPage > 100
    || (options.status !== undefined && (operation === 'userNotices' || !Number.isInteger(options.status) || options.status < 0 || options.status > 4)))) {
    throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid order query')
  }
  const input = login ? credentials!.data : emailCode ? emailInput!.data : registration ? registerInput!.data : passwordReset ? resetInput!.data : publicRead ? {} : write ? internalOrderCreateInput(options.body, options.headers?.['Idempotency-Key'])
    : operation === 'userProfile' || operation === 'userStats' || operation === 'userPaymentMethods' || operation === 'userSubscription' ? {} : operation === 'userOrderDetail' || operation === 'userOrderStatus'
    ? { trade_no: options.tradeNo } : { page, per_page: perPage,
      ...(options.status === undefined ? {} : { status: options.status }) }
  if (Date.now() < Date.parse(tls.certificate.validFrom) || Date.now() >= Date.parse(tls.certificate.validTo)) {
    throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal service unavailable')
  }
  const body = Buffer.from(JSON.stringify({ contract_version: write || anonymous ? '1' : '2', request_id: randomUUID(),
    deadline_at: Math.floor(Date.now() / 1000) + 5, input }))
  if (body.length > 16384) throw new GatewayFailure('VALIDATION_ERROR', 400, 'Internal request too large')
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
      headers: { ...(anonymous ? {} : { authorization: options.auth! }), 'content-type': 'application/json', accept: 'application/json', 'content-length': body.length },
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
          if (status !== (write || registration ? 201 : 200)) {
            const parsedError = internalError.safeParse(value)
            if (!parsedError.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response')
            if ((login || emailCode || registration || passwordReset) && ((status === 401 && (!login || parsedError.data.error.code !== 'INVALID_CREDENTIALS'))
              || (status === 429 && parsedError.data.error.code !== 'RATE_LIMITED'))) {
              throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 503,
                login ? 'Internal login unavailable' : registration ? 'Internal registration unavailable' : passwordReset ? 'Internal password recovery unavailable' : 'Internal email verification unavailable')
            }
            const mapped = write && [409, 422].includes(status) ? mappedOrderWriteFailure(parsedError.data.error.code) : null
            if (mapped) throw mapped
            throw new GatewayFailure('UPSTREAM_ERROR', status >= 400 && status <= 599 ? status : 502,
              status === 401 ? 'Authentication failed' : status === 403 ? 'Access denied' : status === 404 ? 'Resource not found' : 'Internal request rejected')
          }
          if (write) {
            const receipt = internalOrderWriteSuccess.safeParse(value)
            if (!receipt.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response')
            finish(undefined, receipt.data.data); return
          }
          if (login) {
            const envelope = internalLoginSuccess.safeParse(value)
            if (!envelope.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response')
            finish(undefined, envelope.data.data); return
          }
          if (registration) {
            const envelope = internalRegisterSuccess.safeParse(value)
            if (!envelope.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response')
            finish(undefined, envelope.data.data); return
          }
          if (passwordReset) {
            const envelope = internalPasswordResetSuccess.safeParse(value)
            if (!envelope.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response')
            finish(undefined, true); return
          }
          if (emailCode) {
            const envelope = internalEmailCodeSuccess.safeParse(value)
            if (!envelope.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response')
            finish(undefined, true); return
          }
          if (publicRead) {
            const envelope = internalPublicSuccess.safeParse(value)
            if (!envelope.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response')
            finish(undefined, adaptInternalPublic(operation, envelope.data.data)); return
          }
          const envelope = internalSuccessV2.safeParse(value)
          if (!envelope.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal response')
          finish(undefined, operation === 'userOrderStatus' ? adaptInternalOrderStatus(envelope.data.data, options.tradeNo)
            : operation === 'userSubscription' || operation === 'userNotices' ? adaptInternalContent(operation, envelope.data.data, options)
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
