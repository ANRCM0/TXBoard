import { ExtensionClient, acceptsModuleSchema, parseExtensionDescriptor, type ExtensionDescriptor } from '@txboard/core-sdk'
import { ModuleBridge } from './bridge.js'
import { moduleDocument } from './resources.js'

function element<T extends HTMLElement>(selector: string): T {
  const value = document.querySelector<T>(selector)
  if (!value) throw new Error('HOST_ELEMENT_MISSING')
  return value
}
const status = element('#status')
const surface = element('#surface')
const configForm = element<HTMLFormElement>('#configuration')
let generation = 0
let disconnect: (() => void) | null = null

element<HTMLFormElement>('#connect').addEventListener('submit', async event => {
  event.preventDefault()
  const current = ++generation
  disconnect?.()
  const connection = new AbortController()
  disconnect = () => connection.abort()
  surface.replaceChildren()
  element<HTMLButtonElement>('#navigation').hidden = true
  element('#details').textContent = ''
  configForm.replaceChildren()
  configForm.hidden = true
  try {
    const origin = new URL(element<HTMLInputElement>('#origin').value)
    if (origin.protocol !== 'https:' && !(origin.protocol === 'http:' && ['127.0.0.1', 'localhost'].includes(origin.hostname))) throw new Error('CORE_ORIGIN_REJECTED')
    if (origin.username || origin.password || origin.search || origin.hash || origin.pathname !== '/') throw new Error('CORE_ORIGIN_REJECTED')
    const kind = element<HTMLSelectElement>('#actor').value
    const adminPath = element<HTMLInputElement>('#admin-path').value
    const module = element<HTMLInputElement>('#module').value
    if (!/^[a-z][a-z0-9_]{0,63}$/.test(module) || kind === 'admin' && !/^[a-zA-Z0-9_-]{1,128}$/.test(adminPath)) throw new Error('HOST_INPUT_INVALID')
    const token = element<HTMLInputElement>('#token').value
    let expectedFingerprint: string | null = null
    const base = `${origin.origin}/txapi/${kind === 'admin' ? `admin/${encodeURIComponent(adminPath)}/` : ''}extensions/${module}`
    const request = async (path: string, init: RequestInit = {}): Promise<Response> => {
      const response = await fetch(`${base}/${path}`, { ...init, signal: init.signal ?? connection.signal, credentials: 'omit', cache: 'no-store', headers: {
        Authorization: `Bearer ${token}`, ...(expectedFingerprint ? { 'X-Core-Module-Fingerprint': expectedFingerprint } : {}), ...init.headers } })
      if (!response.ok) {
        const data = await response.json().catch(() => null)
        const code: unknown = data?.error?.code
        throw new Error(typeof code === 'string' && /^[A-Z][A-Z0-9_]{1,79}$/.test(code) ? code : 'MODULE_REQUEST_FAILED')
      }
      return response
    }
    status.textContent = 'Checking module permissions...'
    const descriptor = parseExtensionDescriptor((await (await request('presentation')).json()).data)
    if (descriptor.module !== module || descriptor.actor !== kind) throw new Error('MODULE_DESCRIPTOR_INVALID')
    expectedFingerprint = descriptor.fingerprint
    const nonce = element<HTMLMetaElement>('meta[name="module-script-nonce"]').content
    const html = await moduleDocument(descriptor, path => request(`resources/${path}`), nonce)
    if (current !== generation) return
    const navigation = element<HTMLButtonElement>('#navigation')
    navigation.textContent = descriptor.title
    navigation.hidden = false
    element('#details').textContent = `${descriptor.module}\n${descriptor.version}\n${descriptor.fingerprint.slice(0, 16)}`
    const frame = document.createElement('iframe')
    frame.title = descriptor.title
    frame.setAttribute('sandbox', 'allow-scripts')
    frame.referrerPolicy = 'no-referrer'
    frame.srcdoc = html
    const client = new ExtensionClient(descriptor, async (operation, input, options) => {
      const response = await request(`operations/${encodeURIComponent(operation)}`, { method: 'POST', signal: options.signal,
        headers: { 'Content-Type': 'application/json', ...(options.idempotencyKey ? { 'Idempotency-Key': options.idempotencyKey } : {}) }, body: JSON.stringify({ input }) })
      return (await response.json()).data
    })
    surface.append(frame)
    if (!frame.contentWindow) throw new Error('MODULE_FRAME_UNAVAILABLE')
    const bridge = new ModuleBridge(frame.contentWindow, client)
    const receive = async (message: MessageEvent): Promise<void> => {
      if (current !== generation) return
      const reply = await bridge.receive(message.source, message.origin, message.data)
      if (reply && current === generation) {
        frame.contentWindow?.postMessage(reply, '*')
        if (reply.error) {
          status.textContent = String(reply.error)
          if (['MODULE_NOT_AVAILABLE', 'MODULE_PERMISSION_DENIED', 'MODULE_ACTOR_REJECTED', 'MODULE_VERSION_CHANGED'].includes(String(reply.error))) {
            disconnect?.()
            navigation.hidden = true
            configForm.hidden = true
          }
        }
      }
    }
    window.addEventListener('message', receive)
    disconnect = () => { connection.abort(); bridge.close(); window.removeEventListener('message', receive); frame.remove() }
    navigation.onclick = () => frame.focus()
    let pendingConfiguration: { key: string; body: string } | null = null
    renderConfiguration(descriptor, async configuration => {
      const body = JSON.stringify({ configuration })
      if (pendingConfiguration && pendingConfiguration.body !== body) throw new Error('MODULE_CONFIGURATION_RECOVERY_REQUIRED')
      pendingConfiguration ??= { key: crypto.randomUUID(), body }
      status.textContent = `Saving configuration: ${pendingConfiguration.key}`
      let receipt: unknown
      try {
        receipt = await (await request('configuration', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': pendingConfiguration.key }, body })).json()
      } catch (error) {
        if (error instanceof Error && /^MODULE_[A-Z_]+$/.test(error.message)) throw error
        throw new Error('MODULE_CONFIGURATION_UNCONFIRMED')
      }
      const result = (receipt as { data?: Record<string, unknown> } | null)?.data
      if (result?.deployment_id !== pendingConfiguration.key || result.module !== module || result.action !== 'configure'
        || result.phase !== 'completed' || result.package_version !== descriptor.version) throw new Error('MODULE_CONFIGURATION_UNCONFIRMED')
      pendingConfiguration = null
      status.textContent = 'Configuration saved. Open the module again to load its current version.'
    })
    status.textContent = 'Module admitted. Page is isolated; operations remain permission checked by Core.'
  } catch (error) { if (current === generation) status.textContent = error instanceof Error ? error.message : 'MODULE_HOST_UNAVAILABLE' }
})

function renderConfiguration(descriptor: ExtensionDescriptor, save: (configuration: Record<string, unknown>) => Promise<void>): void {
  const schema = descriptor.configuration_schema
  if (!schema || !descriptor.configuration || !schema.properties || typeof schema.properties !== 'object') return
  const inputs = new Map<string, [HTMLInputElement, string]>()
  for (const [name, raw] of Object.entries(schema.properties)) {
    if (!raw || typeof raw !== 'object') continue
    const type: unknown = (raw as Record<string, unknown>).type
    if (!['string', 'integer', 'boolean'].includes(String(type))) continue
    const label = document.createElement('label')
    label.textContent = name
    const input = document.createElement('input')
    input.type = type === 'integer' ? 'number' : type === 'boolean' ? 'checkbox' : 'text'
    if (type === 'boolean') input.checked = descriptor.configuration[name] === true
    else input.value = String(descriptor.configuration[name] ?? '')
    label.append(input)
    configForm.append(label)
    inputs.set(name, [input, String(type)])
  }
  const button = document.createElement('button')
  button.textContent = 'Save configuration'
  configForm.append(button)
  configForm.hidden = false
  configForm.onsubmit = async event => {
    event.preventDefault()
    const value = { ...descriptor.configuration }
    for (const [name, [input, type]] of inputs) value[name] = type === 'boolean' ? input.checked : type === 'integer' ? Number(input.value) : input.value
    if (!acceptsModuleSchema(value, schema)) { status.textContent = 'MODULE_CONFIGURATION_INVALID'; return }
    button.disabled = true
    try { await save(value) } catch (error) { status.textContent = error instanceof Error ? error.message : 'MODULE_CONFIGURATION_FAILED' }
    finally { button.disabled = false }
  }
}
