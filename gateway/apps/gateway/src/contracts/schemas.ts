import { z } from 'zod'

/**
 * contracts/schemas.ts — Zod schemas for every request and upstream response
 * shape the Gateway accepts.
 *
 * Two distinct kinds live here:
 *  1. *Inbound request* schemas (strictObject) — unknown fields are rejected,
 *     so a client cannot smuggle extra parameters upstream.
 *  2. *Upstream response* schemas (looseObject) — unknown TXBoard extension
 *     fields stay compatible, but missing core fields fail closed rather than
 *     reaching a theme in a broken state.
 *
 * These are minimum runtime contracts, not a full mirror of the Laravel API.
 */

/** POST /gateway/v1/auth/login body. */
export const loginSchema = z.strictObject({
  email: z.email().max(254),
  password: z.string().min(8).max(1024),
  turnstile_token: z.string().max(4096).optional(),
  recaptcha_v3_token: z.string().max(4096).optional(),
  recaptcha_data: z.string().max(4096).optional(),
  email_code: z.string().max(128).optional(),
})

export type LoginRequest = z.infer<typeof loginSchema>

const captchaFields = {
  turnstile_token: z.string().max(4096).optional(),
  recaptcha_v3_token: z.string().max(4096).optional(),
  recaptcha_data: z.string().max(4096).optional(),
}

/** Sealed (HPKE) envelope wrapper, validated before decryption. */
export const sealedRequestSchema = z.strictObject({
  kid: z.string().min(1).max(100),
  ts: z.number().int(),
  nonce: z.string().regex(/^[A-Za-z0-9_-]{22}$/),
  enc: z.string().min(40).max(500),
  ct: z.string().min(24).max(20000),
})

export type SealedRequestInput = z.infer<typeof sealedRequestSchema>

/** POST /gateway/v1/secure/auth/register body (after decryption). */
export const registerSchema = z.strictObject({
  email: z.email().max(254),
  password: z.string().min(8).max(1024),
  invite_code: z.string().max(128).optional(),
  email_code: z.string().regex(/^\d{6}$/).optional(),
  ...captchaFields,
})

export type RegisterRequest = z.infer<typeof registerSchema>

/** POST /gateway/v1/secure/auth/email-code body (after decryption). */
export const emailCodeSchema = z.strictObject({ email: z.email().max(254), ...captchaFields })

export type EmailCodeRequest = z.infer<typeof emailCodeSchema>

/** All current Core states, including 4 (discounted historical orders). */
export const orderStatusQueryValues: readonly string[] = ['0', '1', '2', '3', '4']

// Core/PHP signed-64-bit decimals remain strings, including above JS safe integer.
const coreDecimal = z.string().regex(/^(0|[1-9][0-9]{0,18})$/)
  .refine(value => value.length < 19 || value <= '9223372036854775807')
const corePositiveDecimal = coreDecimal.refine(value => value !== '0')

/** Purchase identity only; Core owns admission and immutable Offer terms. */
export const orderCreateSchema = z.strictObject({ offer_id: corePositiveDecimal })

export type OrderCreateRequest = z.infer<typeof orderCreateSchema>

/**
 * GW-304: an order creation response. Only the identifiers a theme needs to
 * follow the order up (via the existing read routes) survive; Laravel decides
 * what else may be shown and the Gateway does not second-guess it.
 */
export const orderCreateResultSchema = z.object({ trade_no: z.string().min(1).max(128) })

export type OrderCreateResult = z.infer<typeof orderCreateResultSchema>

/* ------------------------------------------------------------------ *
 * Upstream response shapes
 * ------------------------------------------------------------------ */

export const nativeOfferSchema = z.object({
  offer_id: corePositiveDecimal, plan_id: corePositiveDecimal, plan_revision_id: corePositiveDecimal,
  name: z.string(), content: z.string().nullable(), tags: z.array(z.string()),
  offer_code: z.enum(['monthly', 'quarterly', 'half_yearly', 'yearly', 'two_yearly',
    'three_yearly', 'onetime', 'reset_traffic']),
  purchase_kind: z.enum(['subscription', 'perpetual', 'traffic_reset']),
  currency: z.string().regex(/^[A-Z]{3}$/), currency_minor_exponent: z.literal(2),
  price_minor: coreDecimal, quota_bytes: coreDecimal,
  group_id: coreDecimal.nullable(), speed_limit_mbps: coreDecimal.nullable(),
  device_limit: coreDecimal.nullable(), sell: z.boolean(), renew: z.boolean(),
  availability_version: corePositiveDecimal, publication_hash: z.string().regex(/^[0-9a-f]{64}$/),
})
export const planListSchema = z.array(nativeOfferSchema).max(256)
  .refine(rows => new Set(rows.map(row => row.offer_id)).size === rows.length)
export type NativeOffer = z.infer<typeof nativeOfferSchema>

export const userProfileSchema = z.object({
  id: z.number().int().positive().safe(), email: z.email(),
  telegram_id: z.number().int().safe().nullable(),
})
export type UserProfile = z.infer<typeof userProfileSchema>

// Core decimal strings retain exact byte counts and identifiers at the SDK boundary.
const decimal = coreDecimal
const positiveDecimal = decimal.refine(value => value !== '0')
const entitlementBase = { schema: z.literal('core-entitlement/v1'), reason: z.string().regex(/^ENTITLEMENT_[A-Z_]+$/) }
export const subscriptionSummarySchema = z.discriminatedUnion('authorized', [
  z.object({ ...entitlementBase, authorized: z.literal(false) }),
  z.object({
    ...entitlementBase, authorized: z.literal(true), reason: z.literal('ENTITLEMENT_GRANTED'),
    subscription_id: positiveDecimal, plan_id: positiveDecimal,
    expires_at: decimal.nullable(), next_reset_at: decimal.nullable(),
    quota_bytes: decimal, consumed_bytes: decimal, remaining_bytes: decimal,
    upload_bytes: decimal, download_bytes: decimal,
    speed_limit_mbps: decimal.nullable(), device_limit: decimal.nullable(),
  }),
])
export type SubscriptionSummary = z.infer<typeof subscriptionSummarySchema>

export const orderListSchema = z.array(z.looseObject({ trade_no: z.string(), status: z.number().int().min(0).max(4) }))
export type OrderSummary = z.infer<typeof orderListSchema>[number]

export const orderPageSchema = z.object({
  items: orderListSchema, current: z.number().int().positive(),
  pageSize: z.number().int().min(1).max(100), total: z.number().int().nonnegative(),
  lastPage: z.number().int().positive(),
})

export const orderDetailSchema = z.looseObject({ trade_no: z.string(), status: z.number().int().min(0).max(4) })
export type OrderDetail = z.infer<typeof orderDetailSchema>

export const paymentMethodsSchema = z.array(z.looseObject({ id: z.number().int(), name: z.string() }))
export type PaymentMethodRaw = z.infer<typeof paymentMethodsSchema>[number]

export const noticePageSchema = z.object({
  data: z.array(z.looseObject({ id: z.number().int() })),
  total: z.number().int().nonnegative(),
})
export type NoticePage = z.infer<typeof noticePageSchema>

export const statsSchema = z.tuple([
  z.number().int().nonnegative(),
  z.number().int().nonnegative(),
  z.number().int().nonnegative(),
])
export type StatsTuple = z.infer<typeof statsSchema>

export const orderStatusSchema = z.number().int().min(0).max(4)

export const loginResultSchema = z.object({
  auth_data: z.string().regex(/^Bearer \S{8,}$/),
  is_admin: z.union([z.boolean(), z.number().int()]).optional(),
})
export type LoginResult = z.infer<typeof loginResultSchema>

/** Notice pagination bounds: 1..9999 pages, 1..100 rows. */
export const noticeCurrentPattern = /^[1-9][0-9]{0,3}$/
export const noticePageSizePattern = /^(?:[1-9]|[1-9][0-9]|100)$/
