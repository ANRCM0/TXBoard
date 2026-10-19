import { afterEach, expect, it, vi } from 'vitest'
import { createActions, createUserSession, money, paymentURL, StaleUserSession } from './session'

const key = '78a166a1-d6cc-4f11-a214-cb2d1f9b1d1c'
const envelope = (data: unknown) => ({ ok: true, data,
  meta: { version: '1', requestId: 'gateway-trace', coreRequestId: key, coreMeta: {} } })
afterEach(() => { localStorage.clear(); sessionStorage.clear() })

it('preserves large minor-unit amounts and rejects unsafe payment destinations', () => {
  expect(money('9007199254740993', 'USD')).toBe('90071992547409.93 USD')
  expect(money('1', 'CNY')).toBe('0.01 CNY')
  expect(paymentURL('https://pay.example.test/checkout?id=1')).toBe('https://pay.example.test/checkout?id=1')
  for (const target of ['http://pay.example.test', 'javascript:alert(1)', 'https://user:secret@pay.example.test', '/relative']) {
    expect(paymentURL(target)).toBeUndefined()
  }
})

it('keeps authentication in session memory and clears it after a failed logout', async () => {
  const send = vi.fn<typeof fetch>().mockResolvedValueOnce(Response.json(envelope({ auth_data: 'Bearer user-session-long' })))
    .mockRejectedValueOnce(new Error('lost logout response'))
  const session = createUserSession(send)
  await session.login({ email: 'user@example.test', password: 'user-password' }, key)
  expect(session.authenticated.value).toBe(true)
  expect(localStorage.length).toBe(0); expect(sessionStorage.length).toBe(0)
  await expect(session.logout()).rejects.toBeDefined()
  expect(session.authenticated.value).toBe(false)
})

it('ignores a late login response after the session has been replaced', async () => {
  let resolve!: (response: Response) => void
  const send = vi.fn<typeof fetch>().mockImplementation(() => new Promise(done => { resolve = done }))
  const session = createUserSession(send)
  const login = session.login({ email: 'user@example.test', password: 'user-password' }, key)
  const rejected = expect(login).rejects.toBeInstanceOf(StaleUserSession)
  session.clear()
  resolve(Response.json(envelope({ auth_data: 'Bearer obsolete-session-long' })))
  await rejected
  expect(session.authenticated.value).toBe(false)
})

it('retains the original order input and action key after a lost write response', async () => {
  const order = { id: '9007199254740993', trade_no: 'TX-1', status: 0, amount_minor: '1200',
    currency: 'CNY', purchase_terms_version: 'tx-core.purchase-terms/v2' }
  const send = vi.fn<typeof fetch>().mockResolvedValueOnce(Response.json(envelope({ auth_data: 'Bearer user-session-long' })))
    .mockRejectedValueOnce(new Error('lost order response')).mockResolvedValueOnce(Response.json(envelope(order)))
  const session = createUserSession(send)
  await session.login({ email: 'user@example.test', password: 'user-password' }, key)
  const actions = createActions(session)
  await expect(actions.run('commerce.orders.create', { offer_id: '9007199254740995' })).rejects.toMatchObject({ needsStateConfirmation: true })
  const original = { key: actions.pending.value!.key, input: JSON.parse(JSON.stringify(actions.pending.value!.input)) }
  await expect(actions.run('commerce.orders.create', { offer_id: '2' })).rejects.toThrow('ACTION_RECOVERY_REQUIRED')
  expect(send).toHaveBeenCalledTimes(2)
  await actions.run('commerce.orders.create', { offer_id: '9007199254740995' })
  expect(actions.pending.value).toBeUndefined()
  for (const [, request] of send.mock.calls.slice(1)) {
    expect(new Headers(request?.headers).get('Idempotency-Key')).toBe(original.key)
    expect(JSON.parse(request?.body as string)).toEqual({ input: original.input })
  }
})
