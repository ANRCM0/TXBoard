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

/** Order status query accepts only the four TXBoard states. */
export const orderStatusQueryValues: readonly string[] = ['0', '1', '2', '3']

/**
 * GW-304: POST /gateway/v1/orders request body.
 *
 * Deliberately minimal and strict: the Gateway validates SHAPE only. Amount
 * (the plan price), period pricing, coupon, balance and every other business
 * rule stay in Laravel — the Gateway must never recompute a price or decide
 * whether the order may proceed. Unknown fields are rejected, so no parameter
 * can be smuggled upstream.
 */
export const orderCreateSchema = z.strictObject({
  plan_id: z.number().int().positive(),
  period: z.enum(['month_price', 'quarter_price', 'half_year_price', 'year_price',
    'two_year_price', 'three_year_price', 'onetime_price', 'reset_price']),
  coupon_code: z.string().min(1).max(64).optional(),
})

export type OrderCreateRequest = z.infer<typeof orderCreateSchema>

/**
 * GW-304: an order creation response. Only the identifiers a theme needs to
 * follow the order up (via the existing read routes) survive; Laravel decides
 * what else may be shown and the Gateway does not second-guess it.
 */
export const orderCreateResultSchema = z.looseObject({ trade_no: z.string().min(1).max(128) })

export type OrderCreateResult = z.infer<typeof orderCreateResultSchema>

/* ------------------------------------------------------------------ *
 * Upstream response shapes
 * ------------------------------------------------------------------ */

export const planListSchema = z.array(z.looseObject({ id: z.number().int(), name: z.string() }))
export type Plan = z.infer<typeof planListSchema>[number]

export const userProfileSchema = z.looseObject({ email: z.email() })
export type UserProfile = z.infer<typeof userProfileSchema>

export const orderListSchema = z.array(z.looseObject({ trade_no: z.string(), status: z.number().int() }))
export type OrderSummary = z.infer<typeof orderListSchema>[number]

export const orderDetailSchema = z.looseObject({ trade_no: z.string(), status: z.number().int() })
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

export const orderStatusSchema = z.number().int().min(0).max(3)

export const loginResultSchema = z.object({
  auth_data: z.string().regex(/^Bearer \S{8,}$/),
  is_admin: z.union([z.boolean(), z.number().int()]).optional(),
})
export type LoginResult = z.infer<typeof loginResultSchema>

/** Notice pagination bounds: 1..9999 pages, 1..100 rows. */
export const noticeCurrentPattern = /^[1-9][0-9]{0,3}$/
export const noticePageSizePattern = /^(?:[1-9]|[1-9][0-9]|100)$/
