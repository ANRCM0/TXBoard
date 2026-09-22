import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { Simulate } from 'react-dom/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { NodeEditorModal } from './NodeEditorModal'
import { toast } from 'sonner'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import type { NodeItem, ProtocolDefinitionMeta } from '../../api/server'

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))
vi.mock('../../components/ui/ConfirmDialog', () => ({ requestConfirm: vi.fn() }))

const node: NodeItem = {
  id: 7,
  name: 'Tokyo-01',
  type: 'shadowsocks',
  host: 'tokyo.example.com',
  port: 443,
  server_port: 8443,
  rate: 1,
  transfer_enable: 0,
  protocol_settings: { cipher: 'aes-128-gcm', network_settings: { path: '/ws' } },
}

const protocolDefinitions: ProtocolDefinitionMeta[] = [
  {
    type: 'shadowsocks',
    label: 'Shadowsocks',
    schema_version: 1,
    defaults: { cipher: 'aes-128-gcm', network_settings: {} },
    form_schema: [
      { key: 'cipher', label: 'Cipher', type: 'text' },
      { key: 'network_settings', label: 'Network Settings', type: 'json', full: true },
    ],
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

async function mount() {
  await act(async () => {
    root.render(<NodeEditorModal
      open
      node={node}
      nodes={[]}
      machines={[]}
      groups={[]}
      routes={[]}
      protocolDefinitions={protocolDefinitions}
      saving={false}
      onClose={() => {}}
      onSubmit={() => {}}
    />)
    await new Promise(resolve => setTimeout(resolve, 10))
  })
}

// The modal portals to document.body, so scope lookups to the dialog itself.
function editorFor(label: string) {
  const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
  const field = Array.from(dialog.querySelectorAll<HTMLElement>('label'))
    .find(element => element.querySelector('span')?.textContent === label)
  return field?.querySelector<HTMLTextAreaElement>('textarea') ?? null
}

function submitButton() {
  const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
  return Array.from(dialog.querySelectorAll<HTMLButtonElement>('button'))
    .find(button => button.textContent === '提交')!
}

describe('NodeEditorModal JSON editors', () => {
  it('keeps the text exactly as typed instead of pretty-printing mid-edit', async () => {
    await mount()
    const editor = editorFor('Network Settings')!

    act(() => { editor.value = '{"path":"/h2"}'; Simulate.change(editor) })
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 10)) })

    expect(editor.value).toBe('{"path":"/h2"}')
    expect(editor.className).not.toContain('is-invalid')
  })

  it('commits freshly typed JSON into the submitted payload', async () => {
    const onSubmit = vi.fn()
    await act(async () => {
      root.render(<NodeEditorModal
        open
        node={node}
        nodes={[]}
        machines={[]}
        groups={[]}
        routes={[]}
        protocolDefinitions={protocolDefinitions}
        saving={false}
        onClose={() => {}}
        onSubmit={onSubmit}
      />)
      await new Promise(resolve => setTimeout(resolve, 10))
    })

    const editor = editorFor('Network Settings')!
    act(() => { editor.value = '{"path":"/h2"}'; Simulate.change(editor) })
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 10)) })

    act(() => Simulate.click(submitButton()))

    expect(onSubmit).toHaveBeenCalledTimes(1)
    const payload = onSubmit.mock.calls[0][0]
    expect(payload.protocol_settings.network_settings).toEqual({ path: '/h2' })
  })

  it('refuses to submit while a JSON editor holds unparsable text', async () => {
    const onSubmit = vi.fn()
    await act(async () => {
      root.render(<NodeEditorModal
        open
        node={node}
        nodes={[]}
        machines={[]}
        groups={[]}
        routes={[]}
        protocolDefinitions={protocolDefinitions}
        saving={false}
        onClose={() => {}}
        onSubmit={onSubmit}
      />)
      await new Promise(resolve => setTimeout(resolve, 10))
    })

    const editor = editorFor('Network Settings')!
    act(() => { editor.value = '{"path":'; Simulate.change(editor) })
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 10)) })

    act(() => Simulate.click(submitButton()))

    expect(onSubmit).not.toHaveBeenCalled()
    expect(editor.className).toContain('is-invalid')
    expect(toast.error).toHaveBeenCalled()
  })

  it('unblocks the submit once the operator repairs the JSON', async () => {
    const onSubmit = vi.fn()
    await act(async () => {
      root.render(<NodeEditorModal
        open
        node={node}
        nodes={[]}
        machines={[]}
        groups={[]}
        routes={[]}
        protocolDefinitions={protocolDefinitions}
        saving={false}
        onClose={() => {}}
        onSubmit={onSubmit}
      />)
      await new Promise(resolve => setTimeout(resolve, 10))
    })

    const editor = editorFor('Network Settings')!
    act(() => { editor.value = '{oops'; Simulate.change(editor) })
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 10)) })
    act(() => Simulate.click(submitButton()))
    expect(onSubmit).not.toHaveBeenCalled()

    act(() => { editor.value = '{"path":"/ws"}'; Simulate.change(editor) })
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 10)) })

    expect(editor.className).not.toContain('is-invalid')
    act(() => Simulate.click(submitButton()))
    expect(onSubmit).toHaveBeenCalledTimes(1)
    expect(onSubmit.mock.calls[0][0].protocol_settings.network_settings).toEqual({ path: '/ws' })
  })

  it('asks before dropping protocol settings on a protocol switch', async () => {
    const onSubmit = vi.fn()
    await act(async () => {
      root.render(<NodeEditorModal
        open
        node={node}
        nodes={[]}
        machines={[]}
        groups={[]}
        routes={[]}
        protocolDefinitions={[...protocolDefinitions, {
          type: 'trojan',
          label: 'Trojan',
          schema_version: 1,
          defaults: { tls: 0, network: 'tcp' },
          form_schema: [{ key: 'network', label: 'Network', type: 'text' }],
        }]}
        saving={false}
        onClose={() => {}}
        onSubmit={onSubmit}
      />)
      await new Promise(resolve => setTimeout(resolve, 10))
    })

    const protocolPicker = document.querySelector<HTMLElement>('.node-protocol-picker')!
    const summary = protocolPicker.querySelector<HTMLElement>('summary')!
    act(() => summary.click())
    const trojanOption = Array.from(protocolPicker.querySelectorAll<HTMLElement>('button'))
      .find(button => button.textContent === 'Trojan')!
    act(() => trojanOption.click())

    expect(requestConfirm).toHaveBeenCalledTimes(1)
    expect(vi.mocked(requestConfirm).mock.calls[0][0].title).toBe('切换协议类型')
    // Nothing is saved behind the operator's back.
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('switches protocols without a prompt when nothing was typed yet', async () => {
    await act(async () => {
      root.render(<NodeEditorModal
        open
        node={null}
        nodes={[]}
        machines={[]}
        groups={[]}
        routes={[]}
        protocolDefinitions={protocolDefinitions}
        saving={false}
        onClose={() => {}}
        onSubmit={() => {}}
      />)
      await new Promise(resolve => setTimeout(resolve, 10))
    })

    const protocolPicker = document.querySelector<HTMLElement>('.node-protocol-picker')!
    act(() => protocolPicker.querySelector<HTMLElement>('summary')!.click())
    const option = Array.from(protocolPicker.querySelectorAll<HTMLElement>('button'))[0]
    act(() => option.click())

    expect(requestConfirm).not.toHaveBeenCalled()
  })

  it('guards unsaved edits when the operator closes the editor', async () => {
    const onClose = vi.fn()
    await act(async () => {
      root.render(<NodeEditorModal
        open
        node={node}
        nodes={[]}
        machines={[]}
        groups={[]}
        routes={[]}
        protocolDefinitions={protocolDefinitions}
        saving={false}
        onClose={onClose}
        onSubmit={() => {}}
      />)
      await new Promise(resolve => setTimeout(resolve, 10))
    })

    // A pristine modal closes straight away.
    const closeButton = document.querySelector<HTMLButtonElement>('.modal-header .icon-button')!
    act(() => closeButton.click())
    expect(onClose).toHaveBeenCalledTimes(1)
    expect(requestConfirm).not.toHaveBeenCalled()
  })

  it('asks before discarding edited node data', async () => {
    const onClose = vi.fn()
    await act(async () => {
      root.render(<NodeEditorModal
        open
        node={node}
        nodes={[]}
        machines={[]}
        groups={[]}
        routes={[]}
        protocolDefinitions={protocolDefinitions}
        saving={false}
        onClose={onClose}
        onSubmit={() => {}}
      />)
      await new Promise(resolve => setTimeout(resolve, 10))
    })

    const nameInput = document.querySelector<HTMLInputElement>('.node-editor-form input')!
    act(() => { nameInput.value = 'Tokyo-02'; Simulate.change(nameInput) })
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 10)) })

    act(() => document.querySelector<HTMLButtonElement>('.modal-header .icon-button')!.click())

    expect(onClose).not.toHaveBeenCalled()
    expect(requestConfirm).toHaveBeenCalledTimes(1)
    expect(vi.mocked(requestConfirm).mock.calls[0][0].title).toBe('放弃未保存的更改')

    // Confirming routes straight to the caller's close handler.
    act(() => vi.mocked(requestConfirm).mock.calls[0][0].action())
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('blocks submit for unparsable advanced JSON fields too', async () => {
    const onSubmit = vi.fn()
    await act(async () => {
      root.render(<NodeEditorModal
        open
        node={node}
        nodes={[]}
        machines={[]}
        groups={[]}
        routes={[]}
        protocolDefinitions={protocolDefinitions}
        saving={false}
        onClose={() => {}}
        onSubmit={onSubmit}
      />)
      await new Promise(resolve => setTimeout(resolve, 10))
    })

    const excludes = editorFor('Excludes')!
    act(() => { excludes.value = '[1,'; Simulate.change(excludes) })
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 10)) })

    act(() => Simulate.click(submitButton()))
    expect(onSubmit).not.toHaveBeenCalled()

    act(() => { excludes.value = '[1, 2]'; Simulate.change(excludes) })
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 10)) })
    act(() => Simulate.click(submitButton()))
    expect(onSubmit).toHaveBeenCalledTimes(1)
    expect(onSubmit.mock.calls[0][0].excludes).toEqual([1, 2])
  })
})