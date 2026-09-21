import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const styles = readFileSync(resolve(process.cwd(), 'src/styles.css'), 'utf8')

function declarationBlock(selector: string) {
  const start = styles.indexOf(`${selector}{`)
  if (start === -1) return ''

  const bodyStart = start + selector.length + 1
  const end = styles.indexOf('}', bodyStart)
  return end === -1 ? '' : styles.slice(bodyStart, end)
}

describe('admin sidebar navigation layout', () => {
  it('keeps collapsed top-level groups in a compact vertical flow', () => {
    const sidebarNavigation = declarationBlock('.admin-sidebar-nav')

    expect(sidebarNavigation).toContain('display:flex')
    expect(sidebarNavigation).toContain('flex-direction:column')
    expect(sidebarNavigation).not.toContain('display:grid')
  })
})
