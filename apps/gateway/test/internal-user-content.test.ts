import { describe, expect, it } from 'vitest'
import { adaptInternalContent } from '../src/adapters/internal-user-v2.js'
import { adaptNativeData } from '../src/adapters/txapi-native.js'

const summary = (patch = {}) => ({ plan: { id: '2', name: 'Plan', traffic_limit_bytes: '1073741824' },
  reset_day: 1, upload_bytes: '100', download_bytes: '200', traffic_limit_bytes: '1073741824',
  device_limit: '0', speed_limit_mbps: 2.5, expired_at: 1791849600, next_reset_at: null, ...patch })
const notice = (patch = {}) => ({ id: '2', title: 'Fixture', content: '<p>Body</p>', img_url: null,
  tags: ['fixture'], created_at: 1791763200, ...patch })
const notices = (patch = {}) => ({ items: [notice()], pagination: { page: 1, per_page: 5, total: 1, last_page: 1 }, ...patch })

describe('Internal subscription summary and notice projection', () => {
  it('matches Native summary including nullable limits and empty plans', () => {
    for (const value of [summary(), summary({ plan: null, device_limit: null, speed_limit_mbps: null, expired_at: null })]) {
      const native = { ...value, plan: value.plan === null ? null : { ...value.plan, id: 2, traffic_limit_bytes: 1073741824 },
        upload_bytes: 100, download_bytes: 200, traffic_limit_bytes: 1073741824, device_limit: value.device_limit === null ? null : 0,
        expired_at: value.expired_at === null ? null : '2026-10-13T00:00:00+00:00' }
      expect(adaptInternalContent('userSubscription', value, {})).toEqual(adaptNativeData('userSubscription', native, undefined))
    }
  })
  it.each([{ upload_bytes: '9007199254740992' }, { device_limit: '9007199254740992' }, { reset_day: 32 },
    { expired_at: -1 }, { speed_limit_mbps: Infinity }, { subscribe_url: 'private' }, { token: 'private' }])
    ('rejects unsafe or secret summary fields %j', patch => {
      expect(() => adaptInternalContent('userSubscription', summary(patch), {})).toThrow()
    })
  it('preserves Native content/tags and rejects inconsistent or duplicate notice pages', () => {
    const native = [{ ...notice(), id: 2, created_at: '2026-10-12T00:00:00+00:00' }]
    expect(adaptInternalContent('userNotices', notices(), {}))
      .toEqual(adaptNativeData('userNotices', native, { page: 1, per_page: 5, total: 1, last_page: 1 }))
    expect(adaptInternalContent('userNotices', notices({ items: [], pagination: { page: 2, per_page: 5, total: 0, last_page: 1 } }), { current: 2 }))
      .toEqual({ data: [], total: 0 })
    for (const value of [notices({ pagination: { page: 2, per_page: 5, total: 1, last_page: 1 } }),
      notices({ items: [notice(), notice()], pagination: { page: 1, per_page: 5, total: 2, last_page: 1 } }),
      notices({ items: [notice({ id: '9007199254740992' })] }), notices({ items: [notice({ show: true })] }),
      notices({ items: [notice({ created_at: -1 })] }), notices({ pagination: { page: 1, per_page: 5, total: 1, last_page: 2 } })]) {
      expect(() => adaptInternalContent('userNotices', value, {})).toThrow()
    }
  })
})
