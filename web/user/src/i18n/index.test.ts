import { afterEach, expect, it, vi } from 'vitest'
import { initLocale } from './index'

afterEach(() => { vi.restoreAllMocks(); localStorage.clear(); document.documentElement.lang = '' })

it('uses the saved supported language', () => {
  localStorage.setItem('txboard_user_locale', 'en-US')
  initLocale()
  expect(document.documentElement.lang).toBe('en-US')
})

it.each([['zh-TW', 'zh-CN'], ['fr-FR', 'en-US']])('keeps startup available without storage for %s', (language, expected) => {
  vi.spyOn(window.localStorage, 'getItem').mockImplementation(() => { throw new DOMException('Blocked', 'SecurityError') })
  vi.spyOn(navigator, 'language', 'get').mockReturnValue(language)
  expect(() => initLocale()).not.toThrow()
  expect(document.documentElement.lang).toBe(expected)
})
