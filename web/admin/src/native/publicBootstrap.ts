/** Public CAPTCHA bootstrap has no actor headers, cookies or arbitrary target URL. */
export async function readPublicBootstrap(signal?: AbortSignal): Promise<unknown> {
  const abort = new AbortController()
  const timer = setTimeout(() => abort.abort(), 8_000)
  const combined = signal ? AbortSignal.any([abort.signal, signal]) : abort.signal
  let reader: ReadableStreamDefaultReader<Uint8Array> | undefined
  try {
    const response = await fetch('/gateway/v1/bootstrap', { signal: combined, credentials: 'omit',
      redirect: 'error', cache: 'no-store', headers: { Accept: 'application/json' } })
    if (!response.ok || !response.headers.get('content-type')?.includes('application/json') || !response.body) {
      throw new Error('PUBLIC_BOOTSTRAP_UNAVAILABLE')
    }
    reader = response.body.getReader()
    const decoder = new TextDecoder('utf-8', { fatal: true })
    let text = '', bytes = 0
    for (;;) {
      const part = await reader.read()
      if (part.done) break
      bytes += part.value.byteLength
      if (bytes > 262_144) throw new Error('PUBLIC_BOOTSTRAP_TOO_LARGE')
      text += decoder.decode(part.value, { stream: true })
    }
    text += decoder.decode()
    return JSON.parse(text)
  } finally {
    clearTimeout(timer)
    void reader?.cancel().catch(() => {})
  }
}
