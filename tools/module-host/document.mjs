import { randomBytes } from 'node:crypto'
import { readFile } from 'node:fs/promises'

export async function hostDocument() {
  const source = await readFile(new URL('dist/index.html', import.meta.url), 'utf8')
  return source.replaceAll('__MODULE_HOST_NONCE__', randomBytes(24).toString('base64'))
}
