import { describe, expect, it, vi } from 'vitest'
import {
  createTXBoardClient,
  extractLumaTheme,
  discoverLumaTheme,
  loadLumaTheme,
  createLumaFeatureFlag,
  supportsLumaFeature,
  type LumaThemeConfig,
} from '../src/index.js'

function response(data: unknown, status = 200) {
  return Response.json({ ok: true, data, meta: { version: '1', requestId: 'test-id' } }, { status })
}

describe('Luma theme SDK (GW-217)', () => {
  it('extracts Luma theme from bootstrap data', () => {
    const bootstrap = {
      site: { name: 'TXBoard', description: '', url: '', logo: '' },
      theme: { name: 'Luma', config: { primary_color: '#007bff' } },
      security: { captcha: { enabled: false, type: null, siteKey: null } },
      capabilities: ['theme.config', 'bootstrap'],
      luma: { version: '1.0.0', enabled: true, features: ['theme.config', 'bootstrap'] },
    }
    const luma = extractLumaTheme(bootstrap as any)
    expect(luma).not.toBeNull()
    expect(luma!.name).toBe('Luma')
    expect(luma!.config).toEqual({ primary_color: '#007bff' })
    expect(luma!.luma.version).toBe('1.0.0')
    expect(luma!.luma.enabled).toBe(true)
  })

  it('returns null for non-Luma themes', () => {
    const bootstrap = {
      site: { name: 'TXBoard', description: '', url: '', logo: '' },
      theme: { name: 'Nova', config: { accent: 'blue' } },
      security: { captcha: { enabled: false, type: null, siteKey: null } },
      capabilities: ['theme.config'],
    }
    const luma = extractLumaTheme(bootstrap as any)
    expect(luma).toBeNull()
  })

  it('discovers Luma theme via fetch', async () => {
    const fetchImpl = vi.fn(async () => response({
      site: { name: 'TXBoard', description: '', url: '', logo: '' },
      theme: { name: 'Luma', config: { dark_mode: true } },
      security: { captcha: { enabled: false, type: null, siteKey: null } },
      capabilities: ['theme.config', 'bootstrap'],
      luma: { version: '1.0.0', enabled: true, features: ['theme.config', 'bootstrap'] },
    }))
    const config = await discoverLumaTheme(fetchImpl as typeof fetch, '/gateway/v1')
    expect(config.name).toBe('Luma')
    expect(config.config).toEqual({ dark_mode: true })
    expect(config.luma.enabled).toBe(true)
    expect(fetchImpl).toHaveBeenCalledWith(
      '/gateway/v1/bootstrap',
      expect.objectContaining({ method: 'GET' }),
    )
  })

  it('loads Luma theme with optional theme name', async () => {
    const fetchImpl = vi.fn(async () => response({
      site: { name: 'TXBoard', description: '', url: '', logo: '' },
      theme: { name: 'Luma', config: { sidebar: 'collapsed' } },
      security: { captcha: { enabled: false, type: null, siteKey: null } },
      capabilities: ['theme.config'],
      luma: { version: '1.0.0', enabled: true, features: ['theme.config'] },
    }))
    const config = await loadLumaTheme(fetchImpl as typeof fetch, '/gateway/v1', 'Luma')
    expect(config.name).toBe('Luma')
    expect(config.config).toEqual({ sidebar: 'collapsed' })
  })

  it('creates feature flag from Luma config', () => {
    const config: LumaThemeConfig = {
      name: 'Luma',
      config: {},
      luma: { version: '1.0.0', enabled: true, features: ['theme.config', 'bootstrap'] },
    }
    const flag = createLumaFeatureFlag(config)
    expect(flag.enabled).toBe(true)
    expect(flag.features).toContain('theme.config')
    expect(flag.features).toContain('bootstrap')
  })

  it('checks feature support', () => {
    const config: LumaThemeConfig = {
      name: 'Luma',
      config: {},
      luma: { version: '1.0.0', enabled: true, features: ['theme.config', 'bootstrap'] },
    }
    expect(supportsLumaFeature(config, 'theme.config')).toBe(true)
    expect(supportsLumaFeature(config, 'bootstrap')).toBe(true)
    expect(supportsLumaFeature(config, 'nonexistent')).toBe(false)
  })

  it('integrates with existing TXBoardClient', async () => {
    const fetchImpl = vi.fn(async (url: unknown) => {
      if (String(url).endsWith('bootstrap')) {
        return response({
          site: { name: 'TXBoard', description: '', url: '', logo: '' },
          theme: { name: 'Luma', config: { primary_color: '#007bff' } },
          security: { captcha: { enabled: false, type: null, siteKey: null } },
          capabilities: ['theme.config', 'bootstrap'],
          luma: { version: '1.0.0', enabled: true, features: ['theme.config', 'bootstrap'] },
        })
      }
      return response([])
    })
    const sdk = createTXBoardClient({ fetchImpl: fetchImpl as typeof fetch })
    const bootstrap = await sdk.bootstrap()
    expect(bootstrap.theme.name).toBe('Luma')
    const luma = extractLumaTheme(bootstrap)
    expect(luma).not.toBeNull()
    expect(luma!.name).toBe('Luma')
  })
})
