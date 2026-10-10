import { afterEach, describe, expect, it } from 'vitest'
import { applyUserThemeConfig } from './theme-config'

afterEach(() => {
  delete document.documentElement.dataset.userAccent
  document.documentElement.style.removeProperty('--u-theme-background-image')
})

describe('active theme public configuration', () => {
  it('applies the active theme accent and login background', () => {
    applyUserThemeConfig({ frontend_theme: 'TXBoard', theme_config: {
      theme_color: 'blue',
      background_url: 'https://example.com/login.jpg',
    } })
    expect(document.documentElement.dataset.userAccent).toBe('blue')
    expect(document.documentElement.style.getPropertyValue('--u-theme-background-image')).toContain('https://example.com/login.jpg')
  })

  it('ignores injected CSS schemes and unsupported accents', () => {
    applyUserThemeConfig({ frontend_theme: 'TXBoard', theme_config: {
      theme_color: 'nonsense',
      background_url: 'javascript:alert(1)',
    } })
    expect(document.documentElement.dataset.userAccent).toBe('default')
    expect(document.documentElement.style.getPropertyValue('--u-theme-background-image')).toBe('')
  })

  it('clears a previous theme background when the selected theme removes it', () => {
    applyUserThemeConfig({ frontend_theme: 'TXBoard', theme_config: { background_url: 'https://example.com/login.jpg' } })
    applyUserThemeConfig({ frontend_theme: 'TXBoard', theme_config: { background_url: '' } })
    expect(document.documentElement.style.getPropertyValue('--u-theme-background-image')).toBe('')
  })
})
