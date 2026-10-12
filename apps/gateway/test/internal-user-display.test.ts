import { describe, expect, it } from 'vitest'
import { adaptInternalDisplay } from '../src/adapters/internal-user-v2.js'
import { adaptNativeData } from '../src/adapters/txapi-native.js'

const method = (patch = {}) => ({ id: '2', name: 'Fixture method', provider: 'Fixture', icon: null,
  fee_fixed_minor: '17', fee_percent: 2.5, ...patch })

describe('Internal User display reads', () => {
  it('preserves the Native dashboard tuple and enabled method display including zero fees', () => {
    const stats = { unpaid_orders: 2, open_tickets: 1, invited_users: 3 }
    expect(adaptInternalDisplay('userStats', stats)).toEqual(adaptNativeData('userStats', stats))
    const rows = [method(), method({ id: '3', icon: '/fixture.svg', fee_fixed_minor: '0', fee_percent: 0 })]
    const native = rows.map(row => ({ ...row, id: Number(row.id), fee_fixed_minor: Number(row.fee_fixed_minor) }))
    expect(adaptInternalDisplay('userPaymentMethods', rows)).toEqual(adaptNativeData('userPaymentMethods', native))
    expect(adaptInternalDisplay('userPaymentMethods', [])).toEqual([])
  })
  it.each([
    { unpaid_orders: -1, open_tickets: 0, invited_users: 0 },
    { unpaid_orders: '1', open_tickets: 0, invited_users: 0 },
    { unpaid_orders: 9007199254740992, open_tickets: 0, invited_users: 0 },
    { unpaid_orders: 1, open_tickets: 0, invited_users: 0, user_id: 2 },
    { unpaid_orders: 1, open_tickets: null, invited_users: 0 },
  ])('rejects unsafe or extended dashboard data %j', value => {
    expect(() => adaptInternalDisplay('userStats', value)).toThrow()
  })
  it.each([
    { id: '9007199254740992' }, { id: '0' }, { fee_fixed_minor: '9007199254740992' },
    { fee_fixed_minor: '17\n' }, { fee_percent: -1 }, { fee_percent: Infinity },
    { fee_percent: '2.5' }, { config: { secret: 'private' } }, { enable: true },
  ])('rejects unsafe or private payment fields %j', patch => {
    expect(() => adaptInternalDisplay('userPaymentMethods', [method(patch)])).toThrow()
  })
  it('rejects duplicate method IDs and requires all public display fields', () => {
    expect(() => adaptInternalDisplay('userPaymentMethods', [method(), method()])).toThrow()
    const { icon: _icon, ...withoutIcon } = method()
    expect(() => adaptInternalDisplay('userPaymentMethods', [withoutIcon])).toThrow()
  })
})
