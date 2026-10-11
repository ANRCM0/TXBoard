import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { SummaryStrip } from './SummaryStrip'
import { StatusText } from './StatusText'

describe('Admin UI v2 primitives', () => {
  it('renders all compact summary metrics and preserves unknown values', () => {
    const html = renderToStaticMarkup(<SummaryStrip label="模块概览" items={[
      {key:'total', label:'总数', value:6},
      {key:'healthy', label:'健康', value:6, tone:'success'},
      {key:'missing', label:'未获取', value:null},
    ]}/>)
    expect(html).toContain('admin-v2-summary')
    expect(html).toContain('模块概览')
    expect(html).toContain('总数')
    expect(html).toContain('is-success')
    expect(html).toContain('未获取')
    expect(html).toContain('—')
  })
  it('uses a semantic text label in addition to a decorative status dot', () => {
    const html = renderToStaticMarkup(<StatusText tone="danger">运行失败</StatusText>)
    expect(html).toContain('is-danger')
    expect(html).toContain('aria-hidden="true"')
    expect(html).toContain('运行失败')
  })
})
