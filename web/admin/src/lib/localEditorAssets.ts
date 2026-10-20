const maxAssets = 256, maxAssetBytes = 8 * 1024 * 1024, maxTotalBytes = 32 * 1024 * 1024
const assetPath = /^assets\/[A-Za-z0-9_.-]+\.(?:js|css|ttf|woff2?|svg|png)$/

/** Populate the HTTP cache before import() can remember a failed module fetch. */
export async function preflightLocalEditorAssets(basePath: string) {
  const base = new URL(basePath, window.location.origin)
  if (base.origin !== window.location.origin || base.search || base.hash || !base.pathname.endsWith('/')) throw new Error('LOCAL_EDITOR_BASE_INVALID')
  const controller = new AbortController()
  const timer = window.setTimeout(() => controller.abort(), 20000)
  let total = 0
  async function read(url: URL, limit: number, manifest = false) {
    const response = await fetch(url, { signal: controller.signal, cache: manifest ? 'no-cache' : 'force-cache', credentials: 'same-origin', redirect: 'error' })
    const mime = response.headers.get('content-type')?.split(';', 1)[0].trim().toLowerCase()
    if (!response.ok || !response.body || (manifest ? mime !== 'application/json' : mime === 'text/html')
      || Number(response.headers.get('content-length')) > limit) throw new Error('LOCAL_EDITOR_ASSET_UNAVAILABLE')
    const reader = response.body.getReader(), chunks: Uint8Array[] = []
    let count = 0
    try {
      while (true) {
        const { value, done } = await reader.read()
        if (done) break
        count += value.byteLength; total += value.byteLength
        if (count > limit || total > maxTotalBytes) throw new Error('LOCAL_EDITOR_ASSET_LIMIT')
        if (manifest) chunks.push(value)
      }
    } finally { reader.releaseLock() }
    if (!count) throw new Error('LOCAL_EDITOR_ASSET_EMPTY')
    if (!manifest) return ''
    const bytes = new Uint8Array(count)
    let offset = 0
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength }
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes)
  }
  try {
    const manifest: unknown = JSON.parse(await read(new URL('editor-assets.json', base), 32768, true))
    if (!manifest || typeof manifest !== 'object' || Array.isArray(manifest)) throw new Error('LOCAL_EDITOR_MANIFEST_INVALID')
    const spec = manifest as Record<string, unknown>
    const paths = spec.assets
    if (spec.schema !== 'txboard-lazy-editor-assets/v1' || !Array.isArray(paths) || !paths.length || paths.length > maxAssets
      || paths.some(path => typeof path !== 'string' || !assetPath.test(path)) || new Set(paths).size !== paths.length
      || !Array.isArray(spec.chunks) || !spec.chunks.length || spec.chunks.some(path => !paths.includes(path))
      || !Array.isArray(spec.workers) || spec.workers.length !== 4 || spec.workers.some(path => !paths.includes(path))) throw new Error('LOCAL_EDITOR_MANIFEST_INVALID')
    let next = 0
    await Promise.all(Array.from({ length: Math.min(4, paths.length) }, async () => {
      while (next < paths.length) { const path = paths[next++] as string; await read(new URL(path, base), maxAssetBytes) }
    }))
  } catch (error) { controller.abort(); throw error }
  finally { window.clearTimeout(timer) }
}
