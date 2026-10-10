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

  it('never squeezes the dashboard row or expandable groups when the sidebar overflows', () => {
    expect(styles).toContain(['.admin-nav-root,', '.admin-nav-group{', '  flex:none;'].join('\n'))
  })

  it('keeps the default compact rail and dashboard picker controls independently sized', () => {
    expect(styles).toContain('--admin-sidebar-compact-width:64px')
    expect(styles).toContain('.admin-shell.is-sidebar-collapsed .admin-main{margin-left:var(--admin-sidebar-compact-width)}')
    expect(styles).toContain('.dashboard-period-popover{')
    expect(styles).toContain('.dashboard-chart-card,')
  })

  it('keeps the main workspace centered and bounded on wide screens', () => {
    const adminPage = declarationBlock('.admin-page')

    expect(adminPage).toContain('max-width:1520px')
    expect(adminPage).toContain('margin:0 auto')
  })

  it('uses the shared sidebar width token for shell alignment', () => {
    const sidebar = declarationBlock('.admin-sidebar')
    const main = declarationBlock('.admin-main')

    expect(sidebar).toContain('width:var(--admin-sidebar-width)')
    expect(main).toContain('margin-left:var(--admin-sidebar-width)')
  })
})
