import { expect, it } from 'vitest'
import { monthlyPriceDraft } from './catalogPrices'

it('multiplies exact minor amounts and retains free, one-time and reset terms', () => {
  const original = { monthly: '9007199254740993', quarterly: '', half_yearly: '0', yearly: '40', two_yearly: '', three_yearly: '', onetime: '7', reset_traffic: '8' }
  expect(monthlyPriceDraft(original, false)).toEqual({ ...original, quarterly: '27021597764222979', two_yearly: '216172782113783832', three_yearly: '324259173170675748' })
  expect(monthlyPriceDraft(original, true)).toMatchObject({ yearly: '108086391056891916', half_yearly: '0', onetime: '7', reset_traffic: '8' })
  expect(original.quarterly).toBe('')
})

it('rejects invalid monthly values and overflowing output without partial drafts', () => {
  for (const monthly of ['', '0', '-1', '1.5', '01']) expect(() => monthlyPriceDraft({ monthly, quarterly: '' }, false)).toThrow()
  expect(() => monthlyPriceDraft({ monthly: '9223372036854775807', quarterly: '' }, false)).toThrow('超出允许范围')
})
