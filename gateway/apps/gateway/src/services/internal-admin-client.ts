import { randomUUID, X509Certificate } from 'node:crypto'
import https from 'node:https'
import type { ClientRequest } from 'node:http'
import { checkServerIdentity } from 'node:tls'
import type { InternalAdminConfig } from '../config/internal-admin.js'
import { adminLoginInput, adminReadInput, adminOrderListInput, adminPlanListInput, adminSessionRevokeInput, adminResponses, type AdminOperation, type AdminResponses } from '../adapters/internal-admin-v1.js'
import { internalError, strictInternalJson } from '../adapters/internal-user-v2.js'
import { GatewayFailure } from './failures.js'

const operations = { login: 'auth.admin.login', identity: 'admin.identity.get', dashboard: 'admin.dashboard.get', orders: 'admin.order.list',
  plans: 'admin.plan.list', sessions: 'admin.session.list', revokeSession: 'admin.session.revoke', logout: 'admin.session.logout' } as const
type Options = { auth?: string; input: unknown; signal?: AbortSignal }

/** Isolated server-side consumer only. No public route, role cache or Native fallback. */
export async function internalAdminRequest<K extends AdminOperation>(config: InternalAdminConfig | undefined,
  operation: K, options: Options): Promise<AdminResponses[K]> {
  if (!config || !['local', 'testing'].includes(config.environment) || !Object.hasOwn(operations, operation)) {
    throw new GatewayFailure('UPSTREAM_ERROR', 404, 'Internal Admin operation unavailable')
  }
  const login = operation === 'login'
  if (login ? options.auth !== undefined : !/^Bearer [A-Za-z0-9|._-]{1,4089}$(?![\s\S])/.test(options.auth ?? '')) {
    throw new GatewayFailure('VALIDATION_ERROR', login ? 400 : 401, 'Invalid Internal Admin credential')
  }
  const input = (login ? adminLoginInput : operation === 'orders' ? adminOrderListInput
    : operation === 'plans' ? adminPlanListInput : operation === 'revokeSession' ? adminSessionRevokeInput : adminReadInput).safeParse(options.input)
  if (!input.success) throw new GatewayFailure('VALIDATION_ERROR', 400, 'Invalid Internal Admin input')
  if (Date.now() < Date.parse(config.certificate.validFrom) || Date.now() >= Date.parse(config.certificate.validTo)) {
    throw new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal Admin service unavailable')
  }
  const body = Buffer.from(JSON.stringify({ contract_version: '1', request_id: randomUUID(),
    deadline_at: Math.floor(Date.now()/1000)+5, input: input.data }))
  if (body.length > 16384) throw new GatewayFailure('VALIDATION_ERROR', 400, 'Internal Admin input too large')
  return new Promise((resolve, reject) => {
    let finished = false
    let req: ClientRequest | undefined
    let timer: ReturnType<typeof setTimeout> | undefined
    const finish = (error?: GatewayFailure, value?: AdminResponses[K]) => {
      if (finished) return
      finished = true; clearTimeout(timer); options.signal?.removeEventListener('abort', abort); req?.destroy()
      if (error) reject(error); else resolve(value!)
    }
    const abort = () => finish(new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal Admin request cancelled'))
    try {
      req = https.request({ hostname: config.origin.hostname, port: config.origin.port || 443,
        servername: config.servername, ca: config.ca, crl: config.crl, cert: config.cert, key: config.key,
        minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3', rejectUnauthorized: true, agent: false,
        checkServerIdentity: (host, cert) => {
          const error = checkServerIdentity(host, cert)
          if (error) return error
          try { if (new X509Certificate(cert.raw).subjectAltName === 'DNS:internal-core.local') return undefined } catch {}
          return new Error('Invalid Internal server identity')
        }, method: 'POST', path: '/txapi/internal/v1/operations/' + operations[operation],
        headers: { ...(login ? {} : { authorization: options.auth! }), 'content-type': 'application/json',
          accept: 'application/json', 'content-length': body.length },
      }, response => {
        if (finished) { response.destroy(); return }
        let bytes = 0; const chunks: Buffer[] = []
        if ((response.statusCode ?? 500) >= 300 && (response.statusCode ?? 500) < 400
          || !/^application\/json(?:;\s*charset=utf-8)?$/i.test(response.headers['content-type'] ?? '')
          || Number(response.headers['content-length'] ?? 0) > 65536) {
          finish(new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal Admin response')); response.destroy(); return
        }
        response.on('data', (chunk: Buffer) => {
          bytes += chunk.length
          if (bytes > 65536) { finish(new GatewayFailure('UPSTREAM_ERROR', 502, 'Internal Admin response too large')); response.destroy() }
          else chunks.push(chunk)
        })
        response.on('end', () => {
          if (finished) return
          try {
            const value = strictInternalJson(new TextDecoder('utf-8', { fatal: true }).decode(Buffer.concat(chunks)))
            const status = response.statusCode ?? 502
            if (status !== 200) {
              const parsed = internalError.safeParse(value)
              if (!parsed.success || ![400,401,403,404,409,422,429,500,502,503,504].includes(status)) {
                throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal Admin response')
              }
              // Fixed vocabulary only; never reflect upstream text, fields, SQL or input.
              throw new GatewayFailure(status === 429 ? 'RATE_LIMITED' : 'UPSTREAM_ERROR', status, 'Internal Admin operation rejected')
            }
            const parsed = adminResponses[operation].safeParse(value)
            if (!parsed.success) throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal Admin response')
            if (operation === 'orders' || operation === 'plans') {
              const expected = adminOrderListInput.parse(input.data)
              const { items, pagination } = (parsed.data as AdminResponses['orders'] | AdminResponses['plans']).data
              if (pagination.page !== expected.page || pagination.per_page !== expected.per_page
                || pagination.last_page !== Math.max(1, Math.ceil(pagination.total / pagination.per_page))
                || items.length > expected.per_page) {
                throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal Admin response')
              }
            }
            if (operation === 'orders') {
              const expected = adminOrderListInput.parse(input.data)
              const { items } = (parsed.data as AdminResponses['orders']).data
              if (items.some(item => expected.status !== undefined && item.status !== expected.status
                || item.plan !== null && item.plan.id !== item.plan_id
                || item.user !== null && item.user.id !== item.user_id)) {
                throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal Admin response')
              }
            }
            if (operation === 'plans' && (parsed.data as AdminResponses['plans']).data.items.some(item =>
              item.group !== null && item.group.id !== item.group_id || item.active_users_count > item.users_count)) {
              throw new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal Admin response')
            }
            finish(undefined, parsed.data as AdminResponses[K])
          } catch (error) {
            finish(error instanceof GatewayFailure ? error : new GatewayFailure('UPSTREAM_ERROR', 502, 'Unexpected Internal Admin response'))
          }
        })
        response.on('error', () => finish(new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal Admin service unavailable')))
        response.on('aborted', () => finish(new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal Admin response interrupted')))
        response.on('close', () => { if (!response.complete) finish(new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal Admin response interrupted')) })
      })
    } catch {
      finish(new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal Admin service unavailable')); return
    }
    req.on('error', () => finish(new GatewayFailure('UPSTREAM_UNAVAILABLE', 502, 'Internal Admin service unavailable')))
    timer = setTimeout(() => finish(new GatewayFailure('UPSTREAM_UNAVAILABLE', 504, 'Internal Admin request timed out')), 5000)
    options.signal?.addEventListener('abort', abort, { once: true })
    if (options.signal?.aborted) abort()
    if (!finished) req.end(body)
  })
}
