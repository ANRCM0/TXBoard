import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { PageHeader } from './PageHeader'

describe('admin heading semantics', () => {
  it('keeps a single page h1 with a dedicated action slot', () => {
    const html = renderToStaticMarkup(<PageHeader title="节点管理" description="管理所有节点" action={<button>添加节点</button>}/>)
    expect(html).toContain('<h1>节点管理</h1>')
    expect(html).toContain('page-header-copy')
    expect(html).toContain('page-header-actions')
    expect(html).toContain('添加节点')
  })
})
