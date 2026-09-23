import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const styles = readFileSync(resolve(process.cwd(), 'src/form-system.css'), 'utf8')
const main = readFileSync(resolve(process.cwd(), 'src/main.tsx'), 'utf8')

describe('admin form design system', () => {
  it('loads after the legacy admin stylesheet so form normalization can override it', () => {
    const legacy = main.indexOf("import './styles.css'")
    const forms = main.indexOf("import './form-system.css'")

    expect(legacy).toBeGreaterThanOrEqual(0)
    expect(forms).toBeGreaterThan(legacy)
  })

  it('defines responsive right-side drawers for long editing workflows', () => {
    expect(styles).toContain('.modal-placement-right')
    expect(styles).toContain('height:100dvh')
    expect(styles).toContain('.user-editor-drawer')
  })

  it('normalizes shared fields and settings forms without replacing specialized editors', () => {
    expect(styles).toContain('.field input')
    expect(styles).toContain('.config-form-fields')
    expect(styles).toContain('.admin-form-section')
    expect(styles).toContain('.input-with-unit')
  })

  it('keeps actions reachable in existing long centered editors', () => {
    expect(styles).toContain('.plan-editor > .card-actions:last-child')
    expect(styles).toContain('.payment-editor > .card-actions:last-child')
    expect(styles).toContain('position:sticky')
  })
})
