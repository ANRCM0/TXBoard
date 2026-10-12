import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createTXBoardClient, GatewayApiError } from '../src/index.js'

function okResponse(data: unknown, status = 200) {
  return Response.json({ ok: true, data, meta: { version: '1', requestId: 'req-1' } }, { status })
}

function errResponse(status: number, code: string, message = 'error', extra: Record<string, unknown> = {}) {
  return Response.json({ ok: false, error: { code, message }, meta: { version: '1', requestId: 'req-1' }, ...extra }, { status })
}

/** Fresh Response per call — a Response body can only be consumed once. */
function freshErr(status: number, code: string, message = 'error') {
  return () => errResponse(status, code, message)
}

describe('GW-308: retry & error-state SDK API', () => {
  beforeEach(() => { vi.useFakeTimers() })
  afterEach(() => { vi.useRealTimers() })

  it('GET retries with exponential backoff and succeeds on second attempt', async () => {
    const fetchImpl = vi.fn()
      .mockResolvedValueOnce(errResponse(503, 'UPSTREAM_UNAVAILABLE'))
      .mockResolvedValueOnce(okResponse({ plans: [] }))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, baseRetryDelay: 50 })

    const promise = sdk.plans.list()
    await vi.advanceTimersByTimeAsync(50)
    const result = await promise

    expect(fetchImpl).toHaveBeenCalledTimes(2)
    expect(result).toEqual({ plans: [] })
  })

  it('GET retries with exponential backoff and succeeds on third attempt', async () => {
    const fetchImpl = vi.fn()
      .mockResolvedValueOnce(errResponse(503, 'UPSTREAM_UNAVAILABLE'))
      .mockResolvedValueOnce(errResponse(502, 'UPSTREAM_UNAVAILABLE'))
      .mockResolvedValueOnce(okResponse({ email: 'u@e.com' }))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, baseRetryDelay: 30, getToken: () => 'test-token' })

    const promise = sdk.user.profile()
    await vi.advanceTimersByTimeAsync(30)   // first backoff
    await vi.advanceTimersByTimeAsync(60)   // second backoff
    const result = await promise

    expect(fetchImpl).toHaveBeenCalledTimes(3)
    expect(result).toEqual({ email: 'u@e.com' })
  })

  it('GET stops after maxRetries and throws a retryable+confirm-required error', async () => {
    const fetchImpl = vi.fn().mockImplementation(freshErr(503, 'UPSTREAM_UNAVAILABLE'))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, maxRetries: 3, baseRetryDelay: 10 })

    // Attach the rejection handler immediately so the failure is observed before
    // the test body yields to the timer scheduler.
    const promise = sdk.plans.list().catch(e => e as GatewayApiError)
    // advance through all backoffs: 10 + 20 + 40
    await vi.advanceTimersByTimeAsync(70)

    const error = await promise
    expect(error).toBeInstanceOf(GatewayApiError)
    expect((error as GatewayApiError).retryable).toBe(false)
    expect((error as GatewayApiError).needsStateConfirmation).toBe(true)
    expect((error as GatewayApiError).status).toBe(503)
    expect(fetchImpl).toHaveBeenCalledTimes(4) // 1 initial + 3 retries
  })

  it('POST write requests never auto-retry even on retryable statuses', async () => {
    const fetchImpl = vi.fn().mockImplementation(freshErr(503, 'UPSTREAM_UNAVAILABLE'))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, baseRetryDelay: 10 })

    await expect(sdk.auth.login({ email: 'u@e.com', password: 'pass1234' }))
      .rejects.toMatchObject({ code: 'UPSTREAM_UNAVAILABLE', status: 503, retryable: false, needsStateConfirmation: true })
    expect(fetchImpl).toHaveBeenCalledTimes(1)
  })

  it('POST on network error never auto-retry', async () => {
    const fetchImpl = vi.fn().mockRejectedValue(new Error('DNS fail'))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, baseRetryDelay: 10 })

    await expect(sdk.auth.login({ email: 'u@e.com', password: 'pass1234' }))
      .rejects.toMatchObject({ code: 'NETWORK_ERROR', retryable: false, needsStateConfirmation: true })
    expect(fetchImpl).toHaveBeenCalledTimes(1) // write attempted exactly once, never retried
  })

  it('GET on non-retryable status fails immediately without retry', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(errResponse(403, 'ORIGIN_DENIED'))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, baseRetryDelay: 10 })

    await expect(sdk.plans.list()).rejects.toMatchObject({ code: 'ORIGIN_DENIED', status: 403, retryable: false, needsStateConfirmation: false })
    expect(fetchImpl).toHaveBeenCalledTimes(1)
  })

  it('GET on network error retries with backoff', async () => {
    const fetchImpl = vi.fn()
      .mockRejectedValueOnce(new Error('timeout'))
      .mockResolvedValueOnce(okResponse({ name: 'Nova' }))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, baseRetryDelay: 20 })

    const promise = sdk.theme.config()
    await vi.advanceTimersByTimeAsync(20)
    const result = await promise
    expect(fetchImpl).toHaveBeenCalledTimes(2)
    expect(result).toEqual({ name: 'Nova' })
  })

  it('unknown error code triggers needsStateConfirmation', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(errResponse(500, 'MYSTERY_FAILURE', 'Something odd happened'))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, maxRetries: 0 })

    await expect(sdk.plans.list()).rejects.toMatchObject({ code: 'MYSTERY_FAILURE', retryable: false, needsStateConfirmation: true })
    expect(fetchImpl).toHaveBeenCalledTimes(1)
  })

  it('known error code on non-retryable status does not need state confirmation', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(errResponse(401, 'UNAUTHORIZED'))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, maxRetries: 0 })

    await expect(sdk.plans.list()).rejects.toMatchObject({ code: 'UNAUTHORIZED', retryable: false, needsStateConfirmation: false })
  })

  it('custom retryableStatuses are respected', async () => {
    const fetchImpl = vi.fn()
      .mockResolvedValueOnce(errResponse(507, 'INSUFFICIENT_STORAGE'))
      .mockResolvedValueOnce(okResponse([]))
    const sdk = createTXBoardClient({
      fetchImpl: fetchImpl as typeof fetch,
      baseRetryDelay: 10,
      retryableStatuses: [507],
    })

    const promise = sdk.plans.list()
    await vi.advanceTimersByTimeAsync(10)
    const result = await promise
    expect(fetchImpl).toHaveBeenCalledTimes(2)
    expect(result).toEqual([])
  })

  it('custom maxRetries=0 disables all retries', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(errResponse(503, 'UPSTREAM_UNAVAILABLE'))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, maxRetries: 0, baseRetryDelay: 10 })

    await expect(sdk.plans.list()).rejects.toMatchObject({ code: 'UPSTREAM_UNAVAILABLE', status: 503, retryable: false })
    expect(fetchImpl).toHaveBeenCalledTimes(1)
  })

  it('respects Retry-After header hint when present', async () => {
    // Server says "wait 2 seconds" even though baseRetryDelay is 500ms.
    const fetchImpl = vi.fn()
      .mockResolvedValueOnce(errResponse(429, 'RATE_LIMITED', 'slow down', { headers: { 'Retry-After': '2' } }))
      .mockResolvedValueOnce(okResponse({ ok: true }))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, baseRetryDelay: 500 })

    const promise = sdk.plans.list()
    // The 500ms exponential backoff alone must NOT trigger the retry.
    await vi.advanceTimersByTimeAsync(499)
    expect(fetchImpl).toHaveBeenCalledTimes(1)
    // The server's 2s hint does.
    await vi.advanceTimersByTimeAsync(2000)
    await promise
    expect(fetchImpl).toHaveBeenCalledTimes(2)
  })

  it('caps a hostile Retry-After hint so it cannot park the caller', async () => {
    const fetchImpl = vi.fn()
      .mockResolvedValueOnce(errResponse(503, 'UPSTREAM_UNAVAILABLE', 'later', { headers: { 'Retry-After': '999999' } }))
      .mockResolvedValueOnce(okResponse([]))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, baseRetryDelay: 10 })

    const promise = sdk.plans.list()
    await vi.advanceTimersByTimeAsync(60000)
    await promise
    expect(fetchImpl).toHaveBeenCalledTimes(2)
  })

  it('ignores a malformed Retry-After and falls back to exponential backoff', async () => {
    const fetchImpl = vi.fn()
      .mockResolvedValueOnce(errResponse(503, 'UPSTREAM_UNAVAILABLE', 'later', { headers: { 'Retry-After': 'soon-ish' } }))
      .mockResolvedValueOnce(okResponse([]))
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch, baseRetryDelay: 25 })

    const promise = sdk.plans.list()
    await vi.advanceTimersByTimeAsync(25)
    await promise
    expect(fetchImpl).toHaveBeenCalledTimes(2)
  })
})
