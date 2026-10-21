import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { NetworkTrafficSplit } from './NetworkValues'

it('renders exact upload/download bytes in a split traffic bar', () => {
  const html = renderToStaticMarkup(<NetworkTrafficSplit upload="9007199254740993" download="1" />)
  expect(html).toContain('title="9007199254740993 字节"')
  expect(html).toContain('title="1 字节"')
  expect(html).toContain('network-traffic-split-bar')
  expect(html).toContain('is-upload')
  expect(html).toContain('is-download')
})

it('reports an unknown split when neither side was observed', () => {
  const html = renderToStaticMarkup(<NetworkTrafficSplit upload={null} download={null} />)
  expect(html).toContain('未上报')
  expect(html).not.toContain('network-traffic-split-bar')
})
