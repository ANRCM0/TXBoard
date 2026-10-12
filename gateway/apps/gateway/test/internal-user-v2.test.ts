import { describe, expect, it } from 'vitest'
import { adaptInternalUserV2, internalError, internalSuccessV2, strictInternalJson } from '../src/adapters/internal-user-v2.js'
import { loadConfig } from '../src/config/env.js'
import { adaptNativeData } from '../src/adapters/txapi-native.js'
import { nativeProfile, nativeOrder } from './native-fixtures.js'

export const internalProfile = (patch = {}) => ({
  id: '1', email: 'user@example.test', plan_id: null, balance_minor: '0', commission_balance_minor: '0',
  expired_at: null, telegram_id: null, traffic: { upload_bytes: '0', download_bytes: '0', limit_bytes: '0' }, ...patch,
})
export const internalDetail = (patch = {}) => ({
  id: '1', trade_no: 'T-1', plan_id: '1', period: 'monthly', type: 1, status: 4, amount_minor: '1200',
  plan: { id: '1', name: 'Plan', traffic_limit_bytes: '1073741824' }, payment_id: null,
  balance_amount_minor: '200', discount_amount_minor: '100', paid_at: null, created_at: 1791763200, ...patch,
})
describe('Internal User v2 strict DTO and opt-in', () => {
  it('preserves safe decimal values, signed telegram IDs and discounted status', () => {
    expect(adaptInternalUserV2('userProfile', internalProfile({ telegram_id: '-12345', balance_minor: '9007199254740991' })))
      .toMatchObject({ telegram_id: -12345, balance: Number.MAX_SAFE_INTEGER, id: 1 })
    expect(adaptInternalUserV2('userOrderDetail', internalDetail(), 'T-1'))
      .toMatchObject({ status: 4, total_amount: 1200, balance_amount: 200, discount_amount: 100, plan: { transfer_enable: 1 } })
  })
  it.each(['9007199254740992', '-1', '01', '1e3', '1.0', ' 1', '', '999999999999999999999'])('rejects unsafe/noncanonical minor money %s', value => {
    expect(() => adaptInternalUserV2('userProfile', internalProfile({ balance_minor: value }))).toThrow()
  })
  it('rejects overflow in nested IDs, bytes, signed telegram and any private extension', () => {
    for (const patch of [{ id: '9007199254740992' }, { telegram_id: '-9007199254740992' }, { uuid: 'private' },
      { traffic: { upload_bytes: '9007199254740992', download_bytes: '0', limit_bytes: '0' } }]) {
      expect(() => adaptInternalUserV2('userProfile', internalProfile(patch))).toThrow()
    }
    for (const patch of [{ payment_id: '9007199254740992' }, { plan: { id: '1', name: 'Plan', traffic_limit_bytes: '9007199254740992' } },
      { balance_amount_minor: '-1' }, { callback_no: 'private' }, { status: 5 }, { trade_no: 'wrong-order' }, { plan: null }]) {
      expect(() => adaptInternalUserV2('userOrderDetail', internalDetail(patch), 'T-1')).toThrow()
    }
  })
  it.each(['\n', '\r\n', '\r', '\u2028', '\u2029'])('rejects final line breaks in decimal telegram order and request identifiers %j', ending => {
    for (const patch of [{ balance_minor: '1'+ending }, { id: '1'+ending }, { telegram_id: '-1'+ending },
      { traffic: { upload_bytes: '1'+ending, download_bytes: '0', limit_bytes: '0' } }]) {
      expect(() => adaptInternalUserV2('userProfile', internalProfile(patch))).toThrow()
    }
    expect(() => adaptInternalUserV2('userOrderDetail', internalDetail({ trade_no: 'T-1'+ending }), 'T-1'+ending)).toThrow()
    const request_id = '00000000-0000-4000-8000-000000000000'+ending
    expect(internalSuccessV2.safeParse({ data: {}, request_id, meta: { current_only: true, slice: 'internal-readonly/2' } }).success).toBe(false)
    expect(internalError.safeParse({ error: { code: 'NOT_FOUND', message: 'hidden' }, request_id }).success).toBe(false)
  })
  it('matches every frozen Native display field for equivalent profile and full detail', () => {
    const profile = nativeProfile({ id: 1, email: 'user@example.test', telegram_id: -12345, plan_id: null,
      balance_minor: 0, commission_balance_minor: 0, expired_at: null,
      traffic: { upload_bytes: 0, download_bytes: 0, limit_bytes: 0 } })
    expect(adaptInternalUserV2('userProfile', internalProfile({ telegram_id: '-12345' }))).toEqual(adaptNativeData('userProfile', profile, undefined))
    const order = nativeOrder({ id: 1, trade_no: 'T-1', plan_id: 1, period: 'monthly', type: 1, status: 4, amount_minor: 1200,
      plan: { id: 1, name: 'Plan', traffic_limit_bytes: 1073741824 }, payment_id: null,
      balance_amount_minor: 200, discount_amount_minor: 100, paid_at: null, created_at: '2026-10-12T00:00:00+00:00' })
    expect(adaptInternalUserV2('userOrderDetail', internalDetail(), 'T-1')).toEqual(adaptNativeData('userOrderDetail', order, undefined, { tradeNo: 'T-1' }))
  })
  it('rejects nested/escaped duplicate keys and excessive depth without changing standard JSON semantics', () => {
    for (const text of ['{"id":1,"id":2}', '{"data":{"id":1,"\\u0069d":2}}', '{"x":'+ '['.repeat(32)+'0'+']'.repeat(32)+'}']) {
      expect(() => strictInternalJson(text)).toThrow()
    }
    expect(strictInternalJson('{"a":{"text":"a\\\"b"},"b":[null,true,1]}')).toEqual({ a: { text: 'a"b' }, b: [null,true,1] })
  })
  it('requires v2 exact metadata and local/testing trusted server config', () => {
    expect(internalSuccessV2.safeParse({ data: {}, request_id: '00000000-0000-4000-8000-000000000000',
      meta: { current_only: true, slice: 'internal-readonly/1' } }).success).toBe(false)
    const baseline = { TXBOARD_UPSTREAM_URL: 'https://core.test' }
    expect(loadConfig(baseline).internalUser).toBeUndefined()
    for (const GATEWAY_INTERNAL_USER_ENABLED of ['', 'false']) {
      expect(loadConfig({ ...baseline, GATEWAY_INTERNAL_USER_ENABLED }).internalUser).toBeUndefined()
    }
    for (const APP_ENV of [undefined, 'production', 'testing']) {
      expect(() => loadConfig({ ...baseline, APP_ENV, GATEWAY_INTERNAL_USER_ENABLED: 'true' })).toThrow('Invalid local/testing Internal User configuration')
    }
  })
  it.each(['1', '0', 'TRUE', 'False', ' true', 'false ', 'yes'])('rejects invalid Internal opt-in flag %s at startup', GATEWAY_INTERNAL_USER_ENABLED => {
    expect(() => loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.test', GATEWAY_INTERNAL_USER_ENABLED }))
      .toThrow('Invalid local/testing Internal User configuration')
  })
})
