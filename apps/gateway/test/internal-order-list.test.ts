import { describe, expect, it } from 'vitest'
import { adaptInternalOrderList } from '../src/adapters/internal-user-v2.js'
import { adaptNativeData } from '../src/adapters/txapi-native.js'

const row = (patch = {}) => ({ id: '1', trade_no: 'T-1', plan_id: '1', period: 'monthly', type: 1,
  status: 4, amount_minor: '1200', plan: { id: '1', name: 'Plan' }, paid_at: null, created_at: 1791763200, ...patch })
const list = (patch = {}) => ({ items: [row()], pagination: { page: 1, per_page: 2, total: 1, last_page: 1 }, ...patch })

describe('Internal order list projection', () => {
  it('matches Native array and paginated display DTO including a missing plan', () => {
    const items = [row(), row({ id: '2', trade_no: 'T-2', plan: null })]
    const pagination = { page: 1, per_page: 2, total: 2, last_page: 1 }
    const nativeItems = items.map(item => ({ ...item, id: Number(item.id), plan_id: Number(item.plan_id),
      amount_minor: Number(item.amount_minor), plan: item.plan === null ? null : { id: 1, name: 'Plan' },
      created_at: '2026-10-12T00:00:00+00:00' }))
    for (const paginated of [false, true]) {
      const options = { current: 1, pageSize: 2, paginated }
      expect(adaptInternalOrderList({ items, pagination }, options))
        .toEqual(adaptNativeData('userOrders', nativeItems, pagination, options))
    }
  })
  it('accepts an empty page past the end and zero-total first page', () => {
    expect(adaptInternalOrderList({ items: [], pagination: { page: 3, per_page: 2, total: 2, last_page: 1 } },
      { current: 3, pageSize: 2 })).toEqual([])
    expect(adaptInternalOrderList({ items: [], pagination: { page: 1, per_page: 100, total: 0, last_page: 1 } }, {})).toEqual([])
  })
  it.each([
    { page: 2, per_page: 2, total: 1, last_page: 1 },
    { page: 1, per_page: 3, total: 1, last_page: 1 },
    { page: 1, per_page: 2, total: 3, last_page: 1 },
    { page: 1, per_page: 2, total: 0, last_page: 1 },
    { page: 1, per_page: 2, total: 9007199254740992, last_page: 4503599627370496 },
  ])('refuses inconsistent or unsafe pagination %j', pagination => {
    expect(() => adaptInternalOrderList(list({ pagination }), { current: 1, pageSize: 2 })).toThrow()
  })
  it.each([
    { id: '9007199254740992' }, { amount_minor: '9007199254740992' }, { amount_minor: '1\n' },
    { plan: { id: '9007199254740992', name: 'Plan' } }, { callback_no: 'secret' },
    { payment_id: '1' }, { trade_no: 'T-1\r\n' }, { status: 5 }, { created_at: -1 },
  ])('refuses overflow, secret extensions and invalid row %j', patch => {
    expect(() => adaptInternalOrderList(list({ items: [row(patch)] }), { pageSize: 2 })).toThrow()
  })
  it('rejects status mismatch and duplicate IDs or trade numbers', () => {
    expect(() => adaptInternalOrderList(list(), { pageSize: 2, status: 0 })).toThrow()
    for (const second of [row({ trade_no: 'T-2' }), row({ id: '2' })]) {
      expect(() => adaptInternalOrderList({ items: [row(), second], pagination: { page: 1, per_page: 2, total: 2, last_page: 1 } },
        { pageSize: 2 })).toThrow()
    }
    expect(() => adaptInternalOrderList(list({ secret: 'private' }), { pageSize: 2 })).toThrow()
  })
})
