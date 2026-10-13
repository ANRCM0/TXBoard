import { PREFIX } from '../middleware/request-context.js'

/**
 * config/policies.ts — the compile-time route policy table (GW-212 / PR-B).
 *
 * This table is the only source of truth for the security posture of a route:
 * what it authenticates with, which request envelope it accepts, which limiter
 * runs in front of it and whether its response may ever be cached. It is a
 * frozen `as const` value, so no HTTP header, theme manifest, client capability
 * or database theme config can mutate it at runtime, and `validatePolicies()`
 * refuses to let a boot start on an illegal combination.
 *
 * docs/middleware-architecture.md §3 — "策略表属于编译期只读定义，而不是运行时
 * 任意 JSON". Non-overridable items: the global baseline, the static upstream
 * allowlist, Laravel's authority, response/log redaction and the payment write
 * ban. No URL, JSON field or status code changes here.
 */

export type PolicyName =
  | 'publicRead'
  | 'userRead'
  | 'login'
  | 'secureLogin'
  | 'secureRegister'
  | 'secureEmailCode'
  | 'securePasswordReset'
  | 'isolatedSessionRead'
  | 'secureSessionMutation'
  | 'captchaChallenge'
  | 'disabledWrite'
  | 'orderWrite'

export type RoutePolicy = Readonly<{
  /** Identity of the policy; the stable key used by snapshots and tests. */
  name: PolicyName
  /** 'none' performs no token shape check here. It never means "anonymous may
   *  call any Laravel operation": the upstream allowlist stays the boundary. */
  auth: 'none' | 'bearer'
  /** 'hpke' routes only accept a sealed HPKE envelope. */
  body: 'none' | 'json' | 'hpke'
  /** Which limiter must guard the route before its handler runs. */
  limiter: 'none' | 'public' | 'login' | 'register' | 'email-code'
  /** Response cacheability. Per-user data is never 'public-short'. */
  cache: 'off' | 'public-short'
}>

/**
 * The canonical policy definitions. Kept beside the type so a PolicyName can
 * never exist without a definition, and frozen so a route can only ever
 * reference the canonical object rather than a locally tweaked copy.
 */
export const POLICY_DEFINITIONS: Readonly<Record<PolicyName, RoutePolicy>> = {
  publicRead: { name: 'publicRead', auth: 'none', body: 'none', limiter: 'public', cache: 'public-short' },
  userRead: { name: 'userRead', auth: 'bearer', body: 'none', limiter: 'public', cache: 'off' },
  login: { name: 'login', auth: 'none', body: 'json', limiter: 'login', cache: 'off' },
  secureLogin: { name: 'secureLogin', auth: 'none', body: 'hpke', limiter: 'login', cache: 'off' },
  secureRegister: { name: 'secureRegister', auth: 'none', body: 'hpke', limiter: 'register', cache: 'off' },
  secureEmailCode: { name: 'secureEmailCode', auth: 'none', body: 'hpke', limiter: 'email-code', cache: 'off' },
  securePasswordReset: { name: 'securePasswordReset', auth: 'none', body: 'hpke', limiter: 'public', cache: 'off' },
  isolatedSessionRead: { name: 'isolatedSessionRead', auth: 'bearer', body: 'none', limiter: 'public', cache: 'off' },
  secureSessionMutation: { name: 'secureSessionMutation', auth: 'bearer', body: 'hpke', limiter: 'public', cache: 'off' },
  // GW-209: challenge issue. Anonymous, limiter-guarded, never cached: the
  // returned challengeId is a bearer capability that must never be shared.
  captchaChallenge: { name: 'captchaChallenge', auth: 'none', body: 'none', limiter: 'public', cache: 'off' },
  // The write ban. No auth, no envelope, no limiter, no cache and no upstream
  // call at all — the policy itself is the enforcement. This is the DEFAULT
  // posture of POST /gateway/v1/orders and stays that way unless a deployment
  // explicitly promotes the route to `orderWrite` (see activeRouteDefinitions).
  disabledWrite: { name: 'disabledWrite', auth: 'none', body: 'none', limiter: 'none', cache: 'off' },
  // GW-304: the order-write adaptation layer, promoted onto POST
  // /gateway/v1/orders ONLY when GATEWAY_ORDER_WRITE_ENABLED is set. Bearer
  // required (the user's own token — never an admin token), a JSON envelope
  // shape-checked before any handler runs, and never cached. The policy
  // guarantees no upstream call without a credential and a well-formed body;
  // idempotency and every business rule stay in Laravel (GW-302/303).
  orderWrite: { name: 'orderWrite', auth: 'bearer', body: 'json', limiter: 'public', cache: 'off' },
} as const

/**
 * The full static route table.
 *
 * Every entry carries an explicit HTTP method: there is no method-agnostic
 * entry anywhere in the table, so an unlisted method on a listed path can
 * never inherit a permissive policy.
 */
export const routeDefinitions = [
  { method: 'GET', path: `${PREFIX}/bootstrap`, policy: POLICY_DEFINITIONS.publicRead },
  { method: 'GET', path: `${PREFIX}/theme/config`, policy: POLICY_DEFINITIONS.publicRead },
  { method: 'GET', path: `${PREFIX}/plans`, policy: POLICY_DEFINITIONS.publicRead },
  { method: 'GET', path: `${PREFIX}/crypto/key`, policy: POLICY_DEFINITIONS.publicRead },
  { method: 'GET', path: `${PREFIX}/catalog/payment-methods`, policy: POLICY_DEFINITIONS.publicRead },

  { method: 'POST', path: `${PREFIX}/auth/login`, policy: POLICY_DEFINITIONS.login },
  { method: 'POST', path: `${PREFIX}/secure/auth/login`, policy: POLICY_DEFINITIONS.secureLogin },
  { method: 'POST', path: `${PREFIX}/secure/auth/register`, policy: POLICY_DEFINITIONS.secureRegister },
  { method: 'POST', path: `${PREFIX}/secure/auth/password-reset`, policy: POLICY_DEFINITIONS.securePasswordReset },
  { method: 'POST', path: `${PREFIX}/secure/auth/email-code`, policy: POLICY_DEFINITIONS.secureEmailCode },

  // GW-209: one-time CAPTCHA challenge issue. Anonymous but limiter-guarded,
  // so challenge farming is throttled by the same public policy as bootstrap.
  { method: 'POST', path: `${PREFIX}/auth/captcha/challenge`, policy: POLICY_DEFINITIONS.captchaChallenge },

  { method: 'GET', path: `${PREFIX}/user/sessions`, policy: POLICY_DEFINITIONS.isolatedSessionRead },
  { method: 'POST', path: `${PREFIX}/secure/auth/session-revoke`, policy: POLICY_DEFINITIONS.secureSessionMutation },
  { method: 'POST', path: `${PREFIX}/secure/auth/session-logout`, policy: POLICY_DEFINITIONS.secureSessionMutation },
  { method: 'GET', path: `${PREFIX}/user/profile`, policy: POLICY_DEFINITIONS.userRead },
  { method: 'GET', path: `${PREFIX}/user/subscription/summary`, policy: POLICY_DEFINITIONS.userRead },
  { method: 'GET', path: `${PREFIX}/notices`, policy: POLICY_DEFINITIONS.userRead },
  { method: 'GET', path: `${PREFIX}/dashboard/stats`, policy: POLICY_DEFINITIONS.userRead },
  { method: 'GET', path: `${PREFIX}/orders`, policy: POLICY_DEFINITIONS.userRead },
  { method: 'GET', path: `${PREFIX}/orders/page`, policy: POLICY_DEFINITIONS.userRead },
  { method: 'GET', path: `${PREFIX}/orders/:tradeNo`, policy: POLICY_DEFINITIONS.userRead },
  { method: 'GET', path: `${PREFIX}/orders/:tradeNo/status`, policy: POLICY_DEFINITIONS.userRead },
  { method: 'GET', path: `${PREFIX}/payments`, policy: POLICY_DEFINITIONS.userRead },

  // Payment write ban: fixed 405, structurally zero upstream calls.
  { method: 'POST', path: `${PREFIX}/orders`, policy: POLICY_DEFINITIONS.disabledWrite },
] as const

export type RouteDefinition = (typeof routeDefinitions)[number]

/** Stable identity of a route definition, shared by lookups and tests. */
export function routeKey(method: string, path: string): string {
  return `${method.toUpperCase()} ${path}`
}

/**
 * Upstream operations each route definition may talk to. Reads only, and a
 * subset of the allowlist in services/upstream.ts — that allowlist remains the
 * hard boundary, this map only makes the per-route coupling auditable in one
 * place.
 *
 * GW-304: POST /gateway/v1/orders is posture-dependent. With the write ban in
 * force the route's policy is `disabledWrite` and its operation set is EMPTY
 * (the ban is structural: zero upstream calls are possible); only the promoted
 * `orderWrite` posture maps to the single `userOrderCreate` operation.
 * `operationsFor()` resolves that per definition, so the frozen table can
 * never state one posture while mapping the operations of the other.
 */
export const ROUTE_OPERATIONS: Readonly<Record<string, readonly string[]>> = {
  [routeKey('GET', `${PREFIX}/bootstrap`)]: ['guestConfig'],
  [routeKey('GET', `${PREFIX}/theme/config`)]: ['guestConfig'],
  [routeKey('GET', `${PREFIX}/plans`)]: ['guestPlans'],
  [routeKey('GET', `${PREFIX}/crypto/key`)]: [],
  // Native Core has no anonymous counterpart; local 404, zero upstream calls.
  [routeKey('GET', `${PREFIX}/catalog/payment-methods`)]: [],
  [routeKey('POST', `${PREFIX}/auth/login`)]: ['login'],
  [routeKey('POST', `${PREFIX}/secure/auth/login`)]: ['login'],
  [routeKey('POST', `${PREFIX}/secure/auth/register`)]: ['register'],
  [routeKey('POST', `${PREFIX}/secure/auth/email-code`)]: ['sendEmailCode'],
  [routeKey('POST', `${PREFIX}/secure/auth/password-reset`)]: ['resetPassword'],
  [routeKey('POST', `${PREFIX}/auth/captcha/challenge`)]: [],
  [routeKey('GET', `${PREFIX}/user/sessions`)]: ['userSessionList'],
  [routeKey('POST', `${PREFIX}/secure/auth/session-revoke`)]: ['userSessionRevoke'],
  [routeKey('POST', `${PREFIX}/secure/auth/session-logout`)]: ['userSessionLogout'],
  [routeKey('GET', `${PREFIX}/user/profile`)]: ['userProfile'],
  [routeKey('GET', `${PREFIX}/user/subscription/summary`)]: ['userSubscription'],
  [routeKey('GET', `${PREFIX}/notices`)]: ['userNotices'],
  [routeKey('GET', `${PREFIX}/dashboard/stats`)]: ['userStats'],
  [routeKey('GET', `${PREFIX}/orders`)]: ['userOrders'],
  [routeKey('GET', `${PREFIX}/orders/page`)]: ['userOrders'],
  [routeKey('GET', `${PREFIX}/orders/:tradeNo`)]: ['userOrderDetail'],
  [routeKey('GET', `${PREFIX}/orders/:tradeNo/status`)]: ['userOrderStatus'],
  [routeKey('GET', `${PREFIX}/payments`)]: ['userPaymentMethods'],
  // The write ban: zero upstream operations, in every posture where the
  // route is NOT promoted. See operationsFor().
  [routeKey('POST', `${PREFIX}/orders`)]: [],
} as const

/**
 * GW-304: the operations a route definition may talk to, resolved for the
 * posture that definition actually carries. The single mutating operation of
 * the order-write layer appears ONLY when the definition's policy is
 * `orderWrite` — a `disabledWrite` definition maps to the empty set, which is
 * what keeps the payment write ban structural in the default deployment.
 */
export function operationsFor(definition: RouteDefinition): readonly string[] {
  if (definition.policy.name === 'orderWrite') {
    return ['userOrderCreate']
  }
  return ROUTE_OPERATIONS[routeKey(definition.method, definition.path)] ?? []
}

/** Upstream operations that mutate state. A `disabledWrite` route must never
 *  map to one of these, and no route definition in the table does. GW-304:
 *  `orderWrite` is ALLOWED to map to a write operation — that is exactly what
 *  the promoted policy is for — but only together with the upstream write
 *  operation, never with the read operations. */
const UPSTREAM_WRITE_OPERATIONS: ReadonlySet<string> = new Set<string>([
  'login',
  'register',
  'sendEmailCode',
  'resetPassword',
  'userOrderCreate',
  'userSessionRevoke',
  'userSessionLogout',
])

/**
 * Wired dependencies the policy table is validated against.
 *
 * `replayStore` means "HPKE is wired together with its mandatory nonce replay
 * store": CryptoService.create() refuses to exist without one, so the presence
 * of an HPKE service already implies replay protection.
 */
export interface PolicyEnvironment {
  replayStore?: boolean
  hpke?: boolean
  redis?: boolean
  /** GATEWAY_ACCOUNT_WORKFLOWS_ENABLED. */
  accountWorkflows?: boolean
  /** Extra local Internal flag enables only secureEmailCode, never registration. */
  isolatedEmailCode?: boolean
  isolatedRegister?: boolean
  isolatedPasswordReset?: boolean
  isolatedSessions?: boolean
  /** GW-209: a CAPTCHA challenge store is wired, so the challenge route exists. */
  captchaChallenge?: boolean
  /**
   * GW-304: GATEWAY_ORDER_WRITE_ENABLED. The single opt-in that promotes
   * POST /gateway/v1/orders from the permanent `disabledWrite` ban to the
   * `orderWrite` adaptation layer. Default false: the write ban is the
   * shipped posture and nothing else — not a header, manifest or capability
   * — can weaken it.
   */
  orderWrite?: boolean
}

/** Policies that may only be unlocked by HPKE + Redis + the feature flag
 *  together — never by a single boolean, and never by degrading to plaintext. */
const ACCOUNT_WORKFLOW_POLICIES: ReadonlySet<PolicyName> = new Set<PolicyName>([
  'secureRegister',
  'secureEmailCode',
  'securePasswordReset',
])

/**
 * The route table actually in force for a given deployment.
 *
 * Routes whose policy needs a capability this deployment does not have are
 * simply ABSENT, so an unenabled route is a plain 404 rather than a route that
 * could be reached with a weaker posture:
 *  - `secureRegister` / `secureEmailCode` need HPKE and Redis and
 *    GATEWAY_ACCOUNT_WORKFLOWS_ENABLED together;
 *  - every `secure*` route needs HPKE (with its mandatory replay store);
 *  - `/gateway/v1/crypto/key` is the HPKE public-key discovery endpoint, so it
 *    exists only when HPKE is wired;
 *  - GW-304: POST `/gateway/v1/orders` stays `disabledWrite` (the write ban)
 *    unless GATEWAY_ORDER_WRITE_ENABLED is set. The policy name only ever
 *    changes from `disabledWrite` to `orderWrite` — the route never appears
 *    twice, never without a credential check and never without an upstream
 *    write operation mapped to it.
 */
export function activeRouteDefinitions(
  environment: PolicyEnvironment = {},
): readonly RouteDefinition[] {
  const accountWorkflows = Boolean(
    environment.hpke && environment.redis && environment.accountWorkflows,
  )
  const hpkeAvailable = Boolean(environment.replayStore)
  // GW-304: the write ban is lifted only by the explicit deployment flag.
  const orderWriteEnabled = Boolean(environment.orderWrite)
  return routeDefinitions.map(definition => {
    // The ONLY policy promotion in the whole table: the write ban becomes the
    // order-write adaptation layer. Nothing else about the entry changes —
    // same method, same path, same position.
    if (definition.policy.name === 'disabledWrite' && orderWriteEnabled) {
      return { ...definition, policy: POLICY_DEFINITIONS.orderWrite }
    }
    return definition
  }).filter(definition => {
    const { policy, path } = definition
    if (policy.name === 'isolatedSessionRead' || policy.name === 'secureSessionMutation') {
      return Boolean(environment.hpke && environment.redis && environment.replayStore
        && environment.isolatedSessions && !environment.accountWorkflows)
    }
    if (policy.name === 'securePasswordReset') {
      return Boolean(environment.hpke && environment.redis && environment.replayStore
        && environment.isolatedPasswordReset && !environment.accountWorkflows)
    }
    if (policy.name === 'secureRegister') {
      return accountWorkflows || Boolean(environment.hpke && environment.redis
        && environment.replayStore && environment.isolatedRegister)
    }
    if (policy.name === 'secureEmailCode') {
      return accountWorkflows || Boolean(environment.hpke && environment.redis
        && environment.replayStore && environment.isolatedEmailCode)
    }
    // crypto/key is the HPKE public-key discovery endpoint: it exists only
    // when HPKE is wired, and every sealed route needs the same capability.
    if (policy.body === 'hpke' || policy.name === 'secureLogin' || path === `${PREFIX}/crypto/key`) {
      return hpkeAvailable
    }
    // The challenge route only exists when a challenge store is wired; without
    // one it is a 404 rather than a route that cannot honour a challenge.
    if (policy.name === 'captchaChallenge') {
      return Boolean(environment.captchaChallenge)
    }
    return true
  })
}

/**
 * Boot-time validation of the policy table. Called by createGatewayApp before
 * the Hono instance exists, so an illegal table fails the boot closed instead
 * of silently weakening one route.
 *
 * Two passes, so the check never depends on which deployment happens to be
 * running:
 *
 *  1. Static rules over the FULL table — structural properties that must hold
 *     for every definition regardless of feature flags: well-formed, on-prefix,
 *     unique, canonical policy objects, `userRead` never cacheable, and
 *     `disabledWrite` never mapped to an upstream write operation.
 *  2. Environment rules over the ACTIVE table — what the running deployment
 *     actually exposes: `body: 'hpke'` requires a replay store, and a
 *     `secureRegister` / `secureEmailCode` route must be fully unlocked.
 *
 * Routes absent from the active table (`secureRegister` / `secureEmailCode`
 * when the account workflows are off) are unreachable 404s, so they are not
 * held to the environment rules.
 *
 * Throws on: a `body: 'hpke'` route with no replay store (a replayable
 * envelope); `userRead` with `cache !== 'off'` (one user's data in a shared
 * cache); `disabledWrite` paired with an upstream write operation (a reachable
 * payment write); a `secureRegister` / `secureEmailCode` route that is live
 * without HPKE and Redis and the feature flag together; and a duplicate,
 * off-prefix, malformed or non-canonical entry.
 */
export function validatePolicies(
  definitions: readonly RouteDefinition[] = routeDefinitions,
  environment: PolicyEnvironment = {},
  operations: Readonly<Record<string, readonly string[]>> = ROUTE_OPERATIONS,
): void {
  // ---- pass 1: static rules over the full table ----
  const seen = new Set<string>()
  for (const definition of definitions) {
    const { method, path, policy } = definition
    const key = routeKey(method, path)

    if (!method || !path || !policy) {
      throw new Error(`Route policy entry is missing a method, path or policy: ${JSON.stringify(definition)}`)
    }
    if (!path.startsWith(`${PREFIX}/`)) {
      throw new Error(`Route policy path is outside ${PREFIX}: ${key}`)
    }
    if (seen.has(key)) {
      throw new Error(`Duplicate route policy entry: ${key}`)
    }
    seen.add(key)
    if (!Object.hasOwn(POLICY_DEFINITIONS, policy.name)) {
      throw new Error(`Route ${key} references unknown policy ${policy.name}`)
    }
    if (POLICY_DEFINITIONS[policy.name] !== policy) {
      throw new Error(`Route ${key} uses a non-canonical definition for policy ${policy.name}`)
    }
    // The payment write ban is structural, not a promise inside a handler:
    // a `disabledWrite` route may NEVER map to an upstream write operation.
    // `orderWrite` is the opposite by construction — the whole point of the
    // promotion — so it is held to the complementary rule below instead.
    // `operationsFor()` is the DEFAULT resolution (posture-aware); an explicit
    // override map replaces it wholesale, which is what lets the unit tests
    // inject an unsound combination and watch the boot refuse it.
    const routeOperations = operations === ROUTE_OPERATIONS
      ? operationsFor(definition)
      : (operations[key] ?? [])
    for (const operation of routeOperations) {
      if (policy.name === 'disabledWrite' && UPSTREAM_WRITE_OPERATIONS.has(operation)) {
        throw new Error(`disabledWrite route ${key} must not call upstream write operation ${operation}`)
      }
    }
    // GW-304: the promotion is only sound if the order-write route maps to
    // EXACTLY its one write operation — no reads (a write route that also
    // reads would widen its blast radius) and no other write.
    if (policy.name === 'orderWrite') {
      if (routeOperations.length !== 1 || routeOperations[0] !== 'userOrderCreate') {
        throw new Error(`orderWrite route ${key} must map to exactly ['userOrderCreate']`)
      }
    }
    // Per-user data can never be served from a shared public cache.
    if (policy.name === 'userRead' && policy.cache !== 'off') {
      throw new Error(`Policy userRead must never be cacheable (route ${definition.method} ${definition.path})`)
    }
  }

  // ---- pass 2: environment rules over the LIVE table ----
  // A route absent from the live table is an unreachable 404, so it is not
  // held to these rules. The caller decides which table is live by what it
  // passes in: the whole table plus a complete environment (the boot path),
  // or a hand-built table plus a matching environment (the unit tests).
  for (const definition of definitions) {
    const { policy } = definition

    if (policy.body === 'hpke' && !environment.replayStore) {
      throw new Error(`Policy ${policy.name} accepts an HPKE envelope without a replay store`)
    }
    if ((policy.name === 'isolatedSessionRead' || policy.name === 'secureSessionMutation')
      && !(environment.hpke && environment.redis && environment.replayStore
        && environment.isolatedSessions && !environment.accountWorkflows)) {
      throw new Error('Isolated session policies require HPKE, Redis and the independent local flag')
    }
    if (ACCOUNT_WORKFLOW_POLICIES.has(policy.name)) {
      // A single boolean must never unlock an account workflow: HPKE and Redis
      // and GATEWAY_ACCOUNT_WORKFLOWS_ENABLED have to be present together.
      const emailOnly = policy.name === 'secureEmailCode' && environment.isolatedEmailCode
        && !environment.accountWorkflows
      const registerOnly = policy.name === 'secureRegister' && environment.isolatedRegister
        && !environment.accountWorkflows
      const resetOnly = policy.name === 'securePasswordReset' && environment.isolatedPasswordReset
        && !environment.accountWorkflows
      const unlocked = Boolean(environment.hpke && environment.redis
        && (policy.name === 'securePasswordReset' ? resetOnly : environment.accountWorkflows || emailOnly || registerOnly))
      const halfUnlocked = Boolean(environment.hpke || environment.redis
        || environment.accountWorkflows) && !unlocked
      if (halfUnlocked) {
        throw new Error(
          `Policy ${policy.name} must not be unlocked by a partial configuration: ` +
          `HPKE, Redis and GATEWAY_ACCOUNT_WORKFLOWS_ENABLED are required together`,
        )
      }
      if (!unlocked) {
        throw new Error(
          `Policy ${policy.name} requires HPKE, Redis and GATEWAY_ACCOUNT_WORKFLOWS_ENABLED together`,
        )
      }
    }
  }
}
