import { act, useState } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ProtocolSchemaForm } from './ProtocolSchemaForm'
import { generateSecret } from '../../api/server'
import { toast } from 'sonner'
import type { ProtocolFormField } from '../../api/server'

vi.mock('../../api/server', () => ({ generateSecret: vi.fn() }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

const fields: ProtocolFormField[] = [
  { key: 'reality_settings.public_key', label: 'Reality Public Key', type: 'text', full: true },
  {
    key: 'reality_settings.private_key',
    label: 'Reality Private Key',
    type: 'text',
    full: true,
    generator: {
      kind: 'x25519',
      label: '生成 Reality 密钥对',
      map: {
        private_key: 'reality_settings.private_key',
        public_key: 'reality_settings.public_key',
      },
    },
  },
  {
    key: 'reality_settings.short_id',
    label: 'Reality Short ID',
    type: 'text',
    generator: { kind: 'hex', label: '随机 Short ID', params: { bytes: 8 }, map: { value: 'reality_settings.short_id' } },
  },
  {
    key: 'tls_settings.ech.key',
    label: 'ECH Key',
    type: 'textarea',
    full: true,
    generator: {
      kind: 'ech',
      label: '生成 ECH 密钥',
      params: { public_name: '$host' },
      map: { key: 'tls_settings.ech.key', config: 'tls_settings.ech.config' },
    },
  },
]

let host: HTMLDivElement
let root: Root

beforeEach(() => {
  ;(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true
  vi.clearAllMocks()
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
})
afterEach(() => { act(() => root.unmount()); host.remove() })

function setAt(source: Record<string, unknown>, path: string, value: unknown) {
  const next: Record<string, unknown> = { ...source }
  let cursor = next
  const keys = path.split('.')
  keys.forEach((key, index) => {
    if (index === keys.length - 1) {
      cursor[key] = value
      return
    }
    const child = cursor[key]
    cursor[key] = child && typeof child === 'object' && !Array.isArray(child)
      ? { ...(child as Record<string, unknown>) }
      : {}
    cursor = cursor[key] as Record<string, unknown>
  })
  return next
}

async function mount(options: { host?: string; settings?: Record<string, unknown> } = {}) {
  const Fixture = () => {
    const [settings, setSettings] = useState<Record<string, unknown>>(options.settings ?? {})
    return <ProtocolSchemaForm
      fields={fields}
      value={settings}
      onChange={(path, value) => setSettings(current => setAt(current, path, value))}
      generatorContext={options.host === undefined ? undefined : { host: options.host }}
    />
  }
  await act(async () => {
    root.render(<Fixture/>)
    await new Promise(resolve => setTimeout(resolve, 10))
  })
}

function controlFor(label: string) {
  const wrapper = Array.from(host.querySelectorAll<HTMLElement>('.field'))
    .find(element => element.querySelector('.field-generator-header span, label span, span')?.textContent === label)
  return wrapper?.querySelector<HTMLInputElement | HTMLTextAreaElement>('input, textarea') ?? null
}

function generateButton(text?: string) {
  const buttons = Array.from(host.querySelectorAll<HTMLButtonElement>('.field-generator-button'))
  const button = text === undefined ? buttons[0] : buttons.find(item => item.textContent?.includes(text))
  if (!button) throw new Error(`generator button ${text ?? ''} not found`)
  return button
}

async function flush() {
  await act(async () => { await new Promise(resolve => setTimeout(resolve, 10)) })
}

describe('ProtocolSchemaForm secret generators', () => {
  it('fills the private and public key from one generation', async () => {
    vi.mocked(generateSecret).mockResolvedValue({ private_key: 'PRIVATE', public_key: 'PUBLIC' })
    await mount()

    expect(generateButton('生成 Reality 密钥对')).toBeTruthy()
    act(() => generateButton().click())
    await flush()

    expect(generateSecret).toHaveBeenCalledWith('x25519', {})
    expect(controlFor('Reality Private Key')?.value).toBe('PRIVATE')
    expect(controlFor('Reality Public Key')?.value).toBe('PUBLIC')
    expect(toast.success).toHaveBeenCalled()
  })

  it('resolves generator params from the editor context', async () => {
    vi.mocked(generateSecret).mockResolvedValue({ key: 'ECHKEY', config: 'ECHCONFIG' })
    await mount({ host: 'node.example.com' })

    act(() => generateButton('生成 ECH 密钥').click())
    await flush()

    expect(generateSecret).toHaveBeenCalledWith('ech', { public_name: 'node.example.com' })
    expect(controlFor('ECH Key')?.value).toBe('ECHKEY')
  })

  it('switches to reset and clear, and clear empties every mapped field', async () => {
    vi.mocked(generateSecret).mockResolvedValue({ private_key: 'PRIVATE', public_key: 'PUBLIC' })
    await mount()

    act(() => generateButton().click())
    await flush()
    expect(generateButton('重新生成')).toBeTruthy()
    expect(host.querySelector('button[aria-label="清空"]')).toBeTruthy()

    act(() => host.querySelector<HTMLButtonElement>('button[aria-label="清空"]')!.click())
    await flush()
    expect(controlFor('Reality Private Key')?.value).toBe('')
    expect(controlFor('Reality Public Key')?.value).toBe('')
    expect(host.querySelector('button[aria-label="清空"]')).toBeNull()
  })

  it('keeps the current value when generation fails', async () => {
    vi.mocked(generateSecret).mockRejectedValue(new Error('offline'))
    await mount({
      settings: {
        reality_settings: { private_key: 'KEEP', public_key: 'KEEP-PUBLIC' },
        tls_settings: { ech: { key: 'KEEP-ECH' } },
      },
    })

    act(() => generateButton().click())
    await flush()

    expect(controlFor('Reality Private Key')?.value).toBe('KEEP')
    expect(controlFor('Reality Public Key')?.value).toBe('KEEP-PUBLIC')
    expect(controlFor('ECH Key')?.value).toBe('KEEP-ECH')
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('keeps plain protocol fields free of generator controls', async () => {
    await mount()
    expect(host.querySelectorAll('.field-generator').length).toBe(3)
    expect(controlFor('Reality Public Key')?.value).toBe('')
  })
})