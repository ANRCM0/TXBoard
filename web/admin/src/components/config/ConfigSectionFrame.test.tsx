import { describe, expect, it } from 'vitest'
import { renderToStaticMarkup } from 'react-dom/server'
import { MemoryRouter } from 'react-router-dom'
import { ConfigSectionFrame } from './ConfigSectionFrame'

describe('system settings navigation', () => {
  it('removes frontend/theme duplication and unused APP settings', () => {
    const html = renderToStaticMarkup(
      <MemoryRouter initialEntries={['/config/system']}>
        <ConfigSectionFrame title="站点设置">设置</ConfigSectionFrame>
      </MemoryRouter>,
    )
    expect(html).toContain('邮件设置')
    expect(html).not.toContain('>APP 设置<')
    expect(html).not.toContain('>前端设置<')
    expect(html).toContain('主题管理')
  })
})
