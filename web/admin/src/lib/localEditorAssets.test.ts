import { afterEach, expect, it, vi } from 'vitest'
import { preflightLocalEditorAssets } from './localEditorAssets'

const assets = ['assets/editor-fixture.js', ...['editor', 'json', 'html', 'css'].map(name => `assets/${name}.worker-fixture.js`)]
const manifest = { schema: 'txboard-lazy-editor-assets/v1', chunks: [assets[0]], workers: assets.slice(1), assets }
const json = (value: unknown) => new Response(JSON.stringify(value), { headers: { 'Content-Type': 'application/json' } })
afterEach(() => vi.unstubAllGlobals())

it('fetches the fixed same-origin dependency closure with bounded concurrency and HTTP caching', async () => {
  const requests: { url: URL; options: RequestInit }[] = []
  let active = 0, peak = 0
  vi.stubGlobal('fetch', vi.fn(async (url: URL, options: RequestInit) => {
    requests.push({ url, options })
    if (url.pathname.endsWith('editor-assets.json')) return json(manifest)
    active++; peak = Math.max(peak, active)
    await Promise.resolve(); active--
    return new Response('/* local asset */', { headers: { 'Content-Type': 'text/javascript' } })
  }))
  await preflightLocalEditorAssets('/admin/')
  expect(requests.map(row => row.url.pathname)).toEqual(['/admin/editor-assets.json', ...assets.map(path => '/admin/' + path)])
  expect(peak).toBeLessThanOrEqual(4)
  expect(requests[0].options.cache).toBe('no-cache')
  expect(requests.slice(1).every(row => row.options.cache === 'force-cache' && row.options.redirect === 'error')).toBe(true)
})
it('can retry after a failed asset preflight without importing an editor module', async () => {
  let blocked = true
  vi.stubGlobal('fetch', vi.fn(async (url: URL) => {
    if (url.pathname.endsWith('editor-assets.json')) return json(manifest)
    if (blocked && url.pathname.endsWith('editor-fixture.js')) throw new TypeError('Offline')
    return new Response('/* restored */')
  }))
  await expect(preflightLocalEditorAssets('/admin/')).rejects.toThrow('Offline')
  blocked = false
  await expect(preflightLocalEditorAssets('/admin/')).resolves.toBeUndefined()
})
it('refuses external bases, path escape and incomplete worker or chunk declarations', async () => {
  const fetch = vi.fn(async () => json(manifest)); vi.stubGlobal('fetch', fetch)
  await expect(preflightLocalEditorAssets('https://external.example/admin/')).rejects.toThrow('LOCAL_EDITOR_BASE_INVALID')
  expect(fetch).not.toHaveBeenCalled()
  for (const invalid of [
    { ...manifest, assets: [...assets, '../outside.js'] },
    { ...manifest, chunks: ['assets/undeclared.js'] },
    { ...manifest, workers: assets.slice(1, 3) },
    { ...manifest, assets: [...assets, assets[0]] },
  ]) {
    fetch.mockImplementationOnce(async () => json(invalid))
    await expect(preflightLocalEditorAssets('/admin/')).rejects.toThrow('LOCAL_EDITOR_MANIFEST_INVALID')
  }
})
it('refuses SPA HTML fallback, declared oversized assets and excessive streamed bodies', async () => {
  for (const response of [
    () => new Response('<html>fallback</html>', { headers: { 'Content-Type': 'text/html' } }),
    () => new Response('oversized', { headers: { 'Content-Length': String(9 * 1024 * 1024) } }),
    () => new Response(new ReadableStream({ start(controller) { controller.enqueue(new Uint8Array(8 * 1024 * 1024 + 1)); controller.close() } })),
  ]) {
    vi.stubGlobal('fetch', vi.fn(async (url: URL) => url.pathname.endsWith('editor-assets.json') ? json(manifest) : response()))
    await expect(preflightLocalEditorAssets('/admin/')).rejects.toThrow(/LOCAL_EDITOR_ASSET_/)
  }
})
