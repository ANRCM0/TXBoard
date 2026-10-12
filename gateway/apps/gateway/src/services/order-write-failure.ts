import { GatewayFailure } from './failures.js'

/** Shared Native/Internal classification; Core owns every business decision. */
export function mappedOrderWriteFailure(code: unknown): GatewayFailure | null {
  if (code === 'IDEMPOTENCY_IN_PROGRESS') return new GatewayFailure('IDEMPOTENCY_IN_PROGRESS', 409,
    'An identical order is already being processed')
  if (code === 'IDEMPOTENCY_CONFLICT') return new GatewayFailure('IDEMPOTENCY_CONFLICT', 409,
    'Idempotency key is bound to different order parameters')
  if (['INSUFFICIENT_BALANCE', 'PLAN_NOT_FOUND', 'PLAN_NOT_AVAILABLE', 'PERIOD_NOT_ALLOWED', 'COUPON_NOT_FOUND',
    'COUPON_EXPIRED', 'ORDER_REJECTED', 'INVALID_PERIOD'].includes(code as string)) {
    return new GatewayFailure('ORDER_REJECTED', 422, 'Invalid request data')
  }
  return null
}
