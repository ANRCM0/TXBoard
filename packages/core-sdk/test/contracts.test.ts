import { expect, it } from 'vitest'
import { coreOperations } from '../src/index.js'

it('retains Unicode code-point bounds and strict nested filter admission', () => {
  const input = coreOperations['identity.accounts.list'].input
  const filter = { field: 'email', operator: 'contains', value: 'customer' }
  expect(input.safeParse({ email: '\u{1f600}'.repeat(254), filters: Array(10).fill(filter) }).success).toBe(true)
  for (const value of [
    { email: '\u{1f600}'.repeat(255) }, { email: '' }, { filters: Array(11).fill(filter) },
    { filters: [{ ...filter, actor: 'admin' }] }, { filters: [{ field: 'email', operator: 'contains' }] },
    { filters: [{ ...filter, operator: 'execute' }] }, { unknown: true },
  ]) expect(input.safeParse(value).success).toBe(false)
})

it('refuses unsafe transport numbers and preserves inclusive integer bounds', () => {
  const input = coreOperations['identity.accounts.list'].input
  expect(input.safeParse({ page: 100000, per_page: 100 }).success).toBe(true)
  for (const page of [0, -1, 100001, 1.5, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1]) {
    expect(input.safeParse({ page }).success).toBe(false)
  }
  expect(input.safeParse({ per_page: 101 }).success).toBe(false)
})

it('preserves exact SQL identifiers and rejects alternate decimal encodings', () => {
  const input = coreOperations['commerce.orders.create'].input
  for (const id of ['1', '9007199254740993', '9223372036854775807']) {
    expect(input.parse({ offer_id: id })).toEqual({ offer_id: id })
  }
  for (const id of ['0', '01', '1\n', '1\r\n', '+1', ' 1', '1e1', '9223372036854775808', 1]) {
    expect(input.safeParse({ offer_id: id }).success).toBe(false)
  }
})

it('keeps the public schema composition API isolated from the generated operation contract', () => {
  const input = coreOperations['commerce.orders.create'].input
  const selectedOffer = input.refine(value => value.offer_id === '1')
  expect(selectedOffer.safeParse({ offer_id: '2' }).success).toBe(false)
  expect(input.parse({ offer_id: '2' })).toEqual({ offer_id: '2' })
  expect(coreOperations['identity.accounts.get'].input.parse({ user_id: '2' })).toEqual({ user_id: '2' })
})

it('keeps anchored version checks, optional settings and nested unknown-field rejection', () => {
  const input = coreOperations['settings.configuration.update'].input
  const expected_version = 'a'.repeat(64)
  expect(input.parse({ expected_version, values: { app_name: 'Site' } })).toEqual({ expected_version, values: { app_name: 'Site' } })
  for (const value of [
    { expected_version: expected_version + '\n', values: {} },
    { expected_version: 'A'.repeat(64), values: {} },
    { expected_version, values: { app_name: '' } },
    { expected_version, values: { hidden_writer: true } }, { expected_version },
  ]) expect(input.safeParse(value).success).toBe(false)
})

it('preserves required nullable timestamps and closed content enums', () => {
  const input = coreOperations['content.knowledge.create'].input
  const value = { title: 'Help', show: true, sort: 0, audience: 'authenticated', publish_at: null,
    expire_at: 253402300799, category: 'Help', language: 'en', body: 'Content' }
  expect(input.safeParse(value).success).toBe(true)
  for (const patch of [{ audience: 'public' }, { publish_at: undefined }, { publish_at: -1 },
    { expire_at: 253402300800 }, { sort: 2147483648 }, { body: '' }]) {
    expect(input.safeParse({ ...value, ...patch }).success).toBe(false)
  }
})
