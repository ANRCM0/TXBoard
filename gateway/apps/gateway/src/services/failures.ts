/** Stable public failure vocabulary shared by transport and DTO decoding. */
export type GatewayFailureCode =
  | 'UNAUTHORIZED' | 'FORBIDDEN' | 'NOT_FOUND'
  | 'UPSTREAM_UNAVAILABLE' | 'UPSTREAM_ERROR' | 'PAYLOAD_TOO_LARGE'
  | 'VALIDATION_ERROR' | 'RATE_LIMITED' | 'CAPTCHA_REQUIRED'
  | 'IDEMPOTENCY_IN_PROGRESS' | 'IDEMPOTENCY_CONFLICT' | 'ORDER_REJECTED'

export class GatewayFailure extends Error {
  constructor(
    public readonly code: GatewayFailureCode,
    public readonly status: number,
    message: string,
  ) {
    super(message)
  }
}
