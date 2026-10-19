import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const src = (path: string) => readFileSync(resolve(process.cwd(), 'src', path), 'utf8')
const tokens = src('design-tokens.css')
const styles = src('styles.css')
const forms = src('form-system.css')
const main = src('main.tsx')

const essentialTokens = [
  'bg', 'panel', 'fg', 'muted', 'border', 'primary', 'primary-fg',
  'hover', 'danger', 'success', 'sidebar', 'sidebar-fg', 'shadow',
  'admin-header-height', 'admin-sidebar-width', 'radius-control',
  'radius-card', 'focus-ring',
]

describe('admin UI foundations', () => {
  it('loads design tokens before legacy layouts and form refinements', () => {
    const tokensIndex = main.indexOf("import './design-tokens.css'")
    const layoutIndex = main.indexOf("import './styles.css'")
    const formIndex = main.indexOf("import './form-system.css'")
    expect(tokensIndex).toBeGreaterThan(-1)
    expect(layoutIndex).toBeGreaterThan(tokensIndex)
    expect(formIndex).toBeGreaterThan(layoutIndex)
  })

  it('keeps core colors in the token sheet instead of duplicate legacy palettes', () => {
    for (const token of essentialTokens) expect(tokens).toContain(`--${token}:`)
    expect(tokens).toContain(':root[data-theme="dark"]')
    expect(styles).not.toContain('--bg:#f6f7fb')
    expect(styles).not.toContain('--bg:#f5f7fa')
  })

  it('uses shared card and keyboard focus tokens', () => {
    expect(styles).toContain('border-radius:var(--radius-card)')
    expect(styles).toContain('outline:2px solid var(--focus-ring)')
  })

  it('keeps helper copy readable in admin forms', () => {
    expect(forms).toContain('font-size:12px;\n  font-weight:400;\n  line-height:1.5;')
  })
})
