import { loadInternalUserConfig, type InternalUserConfig } from './internal-user.js'

export type GatewayConfig = {
  host: string
  port: number
  upstream: URL
  allowedOrigins: ReadonlySet<string>
  /** GW-204: ingress IPs/CIDRs whose forwarded headers may be trusted. Empty = trust nobody. */
  trustedIngress: string[]
  /** GW-204: allowed Host header values (exact or *.suffix). Empty = syntax check only. */
  allowedHosts: string[]
  /** GW-204: expected upstream hostnames; the resolved IP of the fetch is verified against these. */
  upstreamHosts: string[]
  timeoutMs: number
  maxRequestBytes: number
  maxResponseBytes: number
  /** GW-218: TTL for public content cache in milliseconds. Default: 60000 (60s). */
  publicCacheTtlMs: number
  /** Historical GW-311 setting, default false. Native Core has no anonymous
   *  counterpart: this flag cannot enable the catalog in the current adapter. */
  paymentCatalogEnabled: boolean
  /** GW-304: enable the order-write adaptation layer. Default: false — the
   *  payment write ban stays in force and POST /gateway/v1/orders answers 405
   *  with zero upstream calls. */
  orderWriteEnabled: boolean
  /** Local/testing-only mTLS read slice; absence preserves Native behavior. */
  internalUser?: InternalUserConfig
  /** Additional local public-read opt-in; cannot enable authentication operations. */
  internalPublicReadEnabled?: boolean
  /** Separate isolated local account login; does not enable mail or registration. */
  internalLoginEnabled?: boolean
  /** Separate encrypted email-only isolated mode, with HPKE and Redis required at boot. */
  internalEmailCodeEnabled?: boolean
  /** Independent synthetic registration over the local Internal boundary. */
  internalRegisterEnabled?: boolean
  internalPasswordResetEnabled?: boolean
  /** Independent local/testing current User session consumer. */
  internalSessionsEnabled?: boolean
}

function intSetting(value: string | undefined, fallback: number, min: number, max: number): number {
  if (value === undefined || value.trim() === '') return fallback
  const parsed = Number(value)
  if (!Number.isSafeInteger(parsed) || parsed < min || parsed > max) {
    throw new Error(`Invalid gateway numeric configuration (expected ${min}-${max})`)
  }
  return parsed
}

function privateHost(host: string): boolean {
  const name = host.toLowerCase().replace(/^\[|\]$/g, '')
  if (name === 'localhost' || name === '::1' || name.endsWith('.localhost') || name.endsWith('.internal')) return true
  // Docker Compose service names are unqualified DNS labels.
  if (/^[a-z][a-z0-9-]{0,62}$/.test(name) && !name.includes('.')) return true
  const ip = name.split('.').map(Number)
  if (ip.length !== 4 || ip.some(x => !Number.isInteger(x) || x < 0 || x > 255)) return false
  return ip[0] === 10 || ip[0] === 127 || (ip[0] === 172 && ip[1]! >= 16 && ip[1]! <= 31)
    || (ip[0] === 192 && ip[1] === 168)
}

export function loadConfig(env: Record<string, string | undefined> = process.env): GatewayConfig {
  if (!env.TXBOARD_UPSTREAM_URL) throw new Error('TXBOARD_UPSTREAM_URL is required')
  let upstream: URL
  try {
    upstream = new URL(env.TXBOARD_UPSTREAM_URL)
  } catch {
    throw new Error('TXBOARD_UPSTREAM_URL must be an absolute http(s) URL')
  }
  if (!['http:', 'https:'].includes(upstream.protocol)
      || upstream.username || upstream.password || upstream.search || upstream.hash
      || upstream.pathname !== '/') {
    throw new Error('TXBOARD_UPSTREAM_URL must be a clean origin without credentials/path/query')
  }
  if (upstream.protocol === 'http:' && !(env.TXBOARD_ALLOW_PRIVATE_HTTP === 'true' && privateHost(upstream.hostname))) {
    throw new Error('HTTP upstream is allowed only for explicitly opted-in private hosts')
  }
  const allowedOrigins = new Set<string>()
  for (const raw of (env.GATEWAY_ALLOWED_ORIGINS || '').split(',')) {
    const trimmed = raw.trim()
    if (!trimmed) continue
    let url: URL
    try { url = new URL(trimmed) } catch { throw new Error('Invalid allowed origin') }
    if (url.origin !== trimmed || url.username || url.password || !['https:', 'http:'].includes(url.protocol)) {
      throw new Error('Allowed origins must be exact http(s) origins without wildcard or path')
    }
    allowedOrigins.add(url.origin)
  }

  const trustedIngress = (env.GATEWAY_TRUSTED_INGRESS || '')
    .split(',').map(s => s.trim()).filter(Boolean)
  const allowedHosts = (env.GATEWAY_ALLOWED_HOSTS || '')
    .split(',').map(s => s.trim().toLowerCase()).filter(Boolean)
  const upstreamHosts = (env.GATEWAY_UPSTREAM_HOSTS || upstream.hostname)
    .split(',').map(s => s.trim().toLowerCase()).filter(Boolean)

  const internalUser = loadInternalUserConfig(env)
  const publicRead = env.GATEWAY_INTERNAL_PUBLIC_READ_ENABLED
  if (publicRead !== undefined && publicRead !== '' && publicRead !== 'false'
    && (publicRead !== 'true' || !internalUser || !['local', 'testing'].includes(env.APP_ENV ?? ''))) {
    throw new Error('Invalid local Internal public-read configuration')
  }
  const login = env.GATEWAY_INTERNAL_LOGIN_ENABLED
  if (login !== undefined && login !== '' && login !== 'false'
    && (login !== 'true' || !internalUser || !['local', 'testing'].includes(env.APP_ENV ?? ''))) {
    throw new Error('Invalid local Internal login configuration')
  }
  const emailCode = env.GATEWAY_INTERNAL_EMAIL_CODE_ENABLED
  if (emailCode !== undefined && emailCode !== '' && emailCode !== 'false'
    && (emailCode !== 'true' || !internalUser || !['local', 'testing'].includes(env.APP_ENV ?? ''))) {
    throw new Error('Invalid local Internal email-code configuration')
  }
  const registration = env.GATEWAY_INTERNAL_REGISTER_ENABLED
  if (registration !== undefined && registration !== '' && registration !== 'false'
    && (registration !== 'true' || !internalUser || !['local', 'testing'].includes(env.APP_ENV ?? ''))) {
    throw new Error('Invalid local Internal registration configuration')
  }
  const passwordReset = env.GATEWAY_INTERNAL_PASSWORD_RESET_ENABLED
  if (passwordReset !== undefined && passwordReset !== '' && passwordReset !== 'false'
    && (passwordReset !== 'true' || !internalUser || !['local', 'testing'].includes(env.APP_ENV ?? ''))) {
    throw new Error('Invalid local Internal password-reset configuration')
  }
  const sessions = env.GATEWAY_INTERNAL_SESSIONS_ENABLED
  if (sessions !== undefined && sessions !== '' && sessions !== 'false'
    && (sessions !== 'true' || !internalUser || !['local', 'testing'].includes(env.APP_ENV ?? ''))) {
    throw new Error('Invalid local Internal sessions configuration')
  }
  return {
    host: env.GATEWAY_HOST || '127.0.0.1',
    port: intSetting(env.GATEWAY_PORT, 8787, 1, 65535),
    upstream,
    allowedOrigins,
    trustedIngress,
    allowedHosts,
    upstreamHosts,
    timeoutMs: intSetting(env.GATEWAY_UPSTREAM_TIMEOUT_MS, 8000, 500, 60000),
    maxRequestBytes: intSetting(env.GATEWAY_MAX_REQUEST_BYTES, 16384, 1024, 1048576),
    maxResponseBytes: intSetting(env.GATEWAY_MAX_RESPONSE_BYTES, 1048576, 16384, 8388608),
    publicCacheTtlMs: intSetting(env.GATEWAY_PUBLIC_CACHE_TTL_MS, 60000, 1000, 3600000),
    paymentCatalogEnabled: env.GATEWAY_PAYMENT_CATALOG_ENABLED === 'true',
    // GW-304: the single opt-in. Anything other than the exact string 'true'
    // leaves the write ban in force — a typo, a '1' or an empty value can
    // never open a transaction write path.
    orderWriteEnabled: env.GATEWAY_ORDER_WRITE_ENABLED === 'true',
    internalUser,
    internalPublicReadEnabled: publicRead === 'true',
    internalLoginEnabled: login === 'true',
    internalEmailCodeEnabled: emailCode === 'true',
    internalRegisterEnabled: registration === 'true',
    internalPasswordResetEnabled: passwordReset === 'true',
    internalSessionsEnabled: sessions === 'true',
  }
}
