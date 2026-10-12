import { describe, expect, it } from 'vitest'
import { toLumaThemeConfig, discoverLumaTheme, loadLumaTheme, lumaSupports } from '../src/adapters/luma-theme.js'
import { toPublicTheme } from '../src/adapters/txboard-v1.js'

describe('Luma theme adapter (GW-217)', () => {
  it('extends toPublicTheme with Luma metadata', () => {
    const data = {
      frontend_theme: 'Luma',
      theme_config: { primary_color: '#007bff', dark_mode: true },
    }
    const luma = toLumaThemeConfig(data)
    expect(luma.name).toBe('Luma')
    expect(luma.config).toEqual({ primary_color: '#007bff', dark_mode: true })
    expect(luma.luma.version).toBe('1.0.0')
    expect(luma.luma.enabled).toBe(true)
    expect(luma.luma.features).toContain('theme.config')
    expect(luma.luma.features).toContain('bootstrap')
  })

  it('preserves existing toPublicTheme behavior for non-Luma themes', () => {
    const data = {
      frontend_theme: 'Nova',
      theme_config: { accent: 'blue' },
    }
    const base = toPublicTheme(data)
    const luma = toLumaThemeConfig(data)
    expect(luma.name).toBe(base.name)
    expect(luma.config).toEqual(base.config)
    expect(luma.luma.enabled).toBe(true)
  })

  it('handles missing theme_config gracefully', () => {
    const data = { frontend_theme: 'Luma' }
    const luma = toLumaThemeConfig(data)
    expect(luma.name).toBe('Luma')
    expect(luma.config).toEqual({})
    expect(luma.luma.enabled).toBe(true)
  })

  it('discovers Luma theme from upstream data', () => {
    const data = {
      frontend_theme: 'Luma',
      theme_config: { font_size: '14px' },
    }
    const discovered = discoverLumaTheme(data)
    expect(discovered.name).toBe('Luma')
    expect(discovered.config).toEqual({ font_size: '14px' })
    expect(discovered.luma.features).toContain('plans.list')
  })

  it('loads Luma theme with optional theme name', () => {
    const data = {
      frontend_theme: 'Luma',
      theme_config: { sidebar: 'collapsed' },
    }
    const loaded = loadLumaTheme(data, 'Luma')
    expect(loaded.name).toBe('Luma')
    expect(loaded.config).toEqual({ sidebar: 'collapsed' })
  })

  it('checks feature support correctly', () => {
    const data = {
      frontend_theme: 'Luma',
      theme_config: {},
    }
    const config = toLumaThemeConfig(data)
    expect(lumaSupports(config, 'theme.config')).toBe(true)
    expect(lumaSupports(config, 'bootstrap')).toBe(true)
    expect(lumaSupports(config, 'nonexistent')).toBe(false)
  })

  it('includes all expected Luma features', () => {
    const data = {
      frontend_theme: 'Luma',
      theme_config: {},
    }
    const config = toLumaThemeConfig(data)
    const expectedFeatures = [
      'theme.config',
      'bootstrap',
      'plans.list',
      'user.profile',
      'orders.list',
      'orders.detail',
      'payments.methods',
      'notices.list',
      'dashboard.stats',
    ]
    for (const feature of expectedFeatures) {
      expect(config.luma.features).toContain(feature)
    }
  })
})
