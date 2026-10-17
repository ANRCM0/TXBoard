import type { ExtensionDescriptor } from '@txboard/core-sdk'

const framePolicy = "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; font-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'"

/** Fetch declared assets with host authorization, then embed them in the opaque frame without credentials or resource URLs. */
export async function moduleDocument(descriptor: ExtensionDescriptor, load: (path: string) => Promise<Response>): Promise<string> {
  const assets = new Map<string, Uint8Array>()
  let total = 0
  for (const path of descriptor.assets) {
    const response = await load(path)
    const reader = response.body?.getReader()
    if (!reader) throw new Error('MODULE_RESOURCE_UNAVAILABLE')
    const chunks: Uint8Array[] = []
    let size = 0
    try {
      while (true) {
        const next = await reader.read()
        if (next.done) break
        size += next.value.length
        total += next.value.length
        if (size > 2097152 || total > 8388608) throw new Error('MODULE_RESOURCE_LIMIT')
        chunks.push(next.value)
      }
    } finally { await reader.cancel().catch(() => {}) }
    const bytes = new Uint8Array(size)
    let offset = 0
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.length }
    assets.set(path, bytes)
  }
  const text = (path: string): string => {
    const bytes = assets.get(path)
    if (!bytes) throw new Error('MODULE_RESOURCE_NOT_DECLARED')
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes)
  }
  const relative = (reference: string, from: string): string => {
    const url = new URL(reference, `https://module.invalid/${from}`)
    const path = url.pathname.slice(1)
    if (url.origin !== 'https://module.invalid' || url.search || url.hash || !assets.has(path)) throw new Error('MODULE_RESOURCE_NOT_DECLARED')
    return path
  }
  const dataUrl = (path: string): string => {
    const type = path.endsWith('.png') ? 'image/png' : path.endsWith('.svg') ? 'image/svg+xml' : path.endsWith('.woff2') ? 'font/woff2' : null
    const bytes = assets.get(path)
    if (!type || !bytes) throw new Error('MODULE_RESOURCE_TYPE_UNSUPPORTED')
    let binary = ''
    for (let offset = 0; offset < bytes.length; offset += 8192) binary += String.fromCharCode(...bytes.subarray(offset, offset + 8192))
    return `data:${type};base64,${btoa(binary)}`
  }
  const css = (source: string, from: string): string => {
    if (/@import\b/i.test(source)) throw new Error('MODULE_RESOURCE_IMPORT_UNSUPPORTED')
    return source.replace(/url\(\s*(['"]?)([^)'"\s]+)\1\s*\)/gi, (_match, _quote, reference: string) => `url("${dataUrl(relative(reference, from))}")`)
      .replace(/<\/style/gi, '<\\/style')
  }
  const page = new DOMParser().parseFromString(`<meta http-equiv="Content-Security-Policy" content="${framePolicy}">` + text(descriptor.entry), 'text/html')
  page.querySelectorAll('base, meta[http-equiv], iframe, object, embed, audio, video, source, track').forEach(element => element.remove())
  const embeddedStyles = new Set<HTMLStyleElement>()
  page.querySelectorAll<HTMLScriptElement>('script[src]').forEach(script => {
    const path = relative(script.getAttribute('src')!, descriptor.entry)
    if (!path.endsWith('.js')) throw new Error('MODULE_RESOURCE_TYPE_UNSUPPORTED')
    script.removeAttribute('src')
    script.removeAttribute('integrity')
    script.textContent = text(path).replace(/<\/script/gi, '<\\/script')
  })
  page.querySelectorAll<HTMLLinkElement>('link').forEach(link => {
    if (link.rel !== 'stylesheet') { link.remove(); return }
    const path = relative(link.getAttribute('href') ?? '', descriptor.entry)
    if (!path.endsWith('.css')) throw new Error('MODULE_RESOURCE_TYPE_UNSUPPORTED')
    const style = page.createElement('style')
    style.textContent = css(text(path), path)
    embeddedStyles.add(style)
    link.replaceWith(style)
  })
  page.querySelectorAll<HTMLStyleElement>('style').forEach(style => {
    if (!embeddedStyles.has(style)) style.textContent = css(style.textContent ?? '', descriptor.entry)
  })
  page.querySelectorAll<HTMLElement>('[style]').forEach(element => { element.setAttribute('style', css(element.getAttribute('style')!, descriptor.entry)) })
  page.querySelectorAll<HTMLImageElement>('img[src]').forEach(image => { image.src = dataUrl(relative(image.getAttribute('src')!, descriptor.entry)) })
  page.querySelectorAll('[srcset]').forEach(element => element.removeAttribute('srcset'))
  page.querySelectorAll<HTMLAnchorElement>('a[href]').forEach(link => {
    if (!link.getAttribute('href')?.startsWith('#')) link.removeAttribute('href')
  })
  const policy = page.createElement('meta')
  policy.httpEquiv = 'Content-Security-Policy'
  policy.content = framePolicy
  page.head.prepend(policy)
  return '<!doctype html>' + page.documentElement.outerHTML
}
