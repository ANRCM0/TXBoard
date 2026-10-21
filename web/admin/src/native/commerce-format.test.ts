import { describe, expect, it } from 'vitest'
import { commerceDuration, commerceMoney } from './commerce-format'

describe('commerce display values', () => {
  it('preserves exact large amounts and the currency scale', () => {
    expect(commerceMoney('9223372036854775807', 'CNY')).toBe('92,233,720,368,547,758.07 CNY')
    expect(commerceMoney('-101', 'USD')).toBe('-1.01 USD')
    expect(commerceMoney('0', 'CNY')).toBe('0.00 CNY')
    expect(commerceMoney('123', 'JPY')).toBe('123 最小单位 JPY')
  })

  it('displays exact waiting periods without rounding away seconds', () => {
    expect(commerceDuration(0)).toBe('无需等待')
    expect(commerceDuration(172800)).toBe('2 天')
    expect(commerceDuration(3600)).toBe('1 小时')
    expect(commerceDuration(61)).toBe('61 秒')
  })
})
