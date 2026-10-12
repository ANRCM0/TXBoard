// Synthetic, complete Native Core fixtures for tests focused on Gateway
// middleware. Adapter contract tests use these and explicitly malformed DTOs.
export const requestId = '12345678-1234-4234-8234-123456789abc'
export const nativeEnvelope = (data: unknown, meta?: unknown) => ({ data, request_id: requestId, ...(meta ? { meta } : {}) })
export const nativeError = (code = 'REJECTED', message = 'refused') => ({ error: { code, message }, request_id: requestId })
export const nativePlan = (overrides: Record<string, unknown> = {}) => ({
  id: 1, name: 'Plan', content: '', tags: [], traffic_limit_bytes: 1073741824,
  speed_limit_mbps: null, device_limit: null, capacity_limit: null,
  reset_traffic_method: null, prices: [], renewable: true, ...overrides,
})
export const nativeProfile = (overrides: Record<string, unknown> = {}) => ({
  id: 1, email: 'alice@example.test', plan_id: null, uuid: 'private-uuid',
  balance_minor: 0, commission_balance_minor: 0, expired_at: null, telegram_id: null,
  traffic: { upload_bytes: 0, download_bytes: 0, limit_bytes: 0 }, ...overrides,
})
export const nativeOrder = (overrides: Record<string, unknown> = {}) => ({
  id: 1, trade_no: 'T-1', plan_id: 1, period: 'monthly', type: 1, status: 0,
  amount_minor: 1200, plan: { id: 1, name: 'Plan', traffic_limit_bytes: 1073741824 },
  paid_at: null, created_at: '2026-10-12T00:00:00+00:00',
  payment_id: null, balance_amount_minor: 0, discount_amount_minor: 0, ...overrides,
})
export const nativeSubscription = (overrides: Record<string, unknown> = {}) => ({
  plan: null, subscribe_url: 'https://private.test/subscription-secret', reset_day: null,
  upload_bytes: 0, download_bytes: 0, traffic_limit_bytes: 0, device_limit: null,
  speed_limit_mbps: null, expired_at: null, next_reset_at: null, ...overrides,
})
export const nativeNotice = (overrides: Record<string, unknown> = {}) => ({
  id: 1, title: 'Update', content: '', img_url: null, tags: [], created_at: '2026-10-12T00:00:00+00:00', ...overrides,
})
