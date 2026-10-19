import { expect, it } from 'vitest'
import { minorAmount, readPage, validId, unixDate } from './readPresentation'

it('preserves large canonical identifiers and displays exact minor units', () => {
  expect(validId('9007199254740993')).toBe(true)
  expect(validId('9223372036854775807')).toBe(true)
  for (const value of ['0', '01', '1e3', '-1', '9223372036854775808']) expect(validId(value)).toBe(false)
  expect(minorAmount('9007199254740993', 'USD')).toBe('90071992547409.93 USD')
  expect(minorAmount('1', 'CNY')).toBe('0.01 CNY')
  expect(minorAmount('0', 'USD')).toBe('0.00 USD')
})
it('bounds URL pagination and avoids overflowing displayed timestamps', () => {
  expect(readPage('100000')).toBe(100000)
  for (const value of ['0', '100001', '1e5', '9007199254740993', null]) expect(readPage(value)).toBe(1)
  expect(unixDate('9223372036854775807')).toBe('时间超出显示范围')
})
