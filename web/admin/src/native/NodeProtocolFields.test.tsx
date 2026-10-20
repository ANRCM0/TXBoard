import { act, useState, type ReactNode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { NodeCertificateFields } from './NodeCertificateFields'
import { NodeJsonField, NodeProtocolFields } from './NodeProtocolFields'
import { parseNodeProtocols, type NodeProtocol } from './nodeConfiguration'
import { dirtyDrafts } from '../lib/useDirtyDraft'

let host: HTMLDivElement, root: Root
const changed = vi.fn()
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  changed.mockReset(); vi.spyOn(window, 'confirm').mockReturnValue(true)
})
afterEach(() => { act(() => root.unmount()); host.remove(); vi.restoreAllMocks() })
async function render(element: ReactNode) { await act(async () => root.render(element)) }
async function input(node: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement, value: string) {
  await act(async () => {
    const prototype = node instanceof HTMLSelectElement ? HTMLSelectElement.prototype : node instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype
    Object.getOwnPropertyDescriptor(prototype, 'value')!.set!.call(node, value)
    node.dispatchEvent(new Event(node instanceof HTMLSelectElement ? 'change' : 'input', { bubbles: true }))
  })
}
async function click(text: string) {
  const button = [...host.querySelectorAll<HTMLButtonElement>('button')].find(item => item.textContent?.trim() === text)!
  await act(async () => button.click())
}
function JsonHarness() {
  const [value, setValue] = useState<unknown>({ path: '/old' })
  return <form><NodeJsonField label="Network Settings" value={value} onChange={next => { changed(next); setValue(next) }} templates={[{ label: 'WebSocket', value: { path: '/', headers: { Host: 'example.com' } } }]} /></form>
}
it('holds parsed JSON locally, blocks form submission and navigation, and applies only after confirmation', async () => {
  await render(<JsonHarness />)
  const textarea = host.querySelector('textarea')!
  await input(textarea, '{"path":"/new"}')
  expect(changed).not.toHaveBeenCalled()
  expect(host.querySelector('form')!.checkValidity()).toBe(false)
  expect(dirtyDrafts.snapshot()).toBe(true)
  vi.mocked(window.confirm).mockReturnValueOnce(false)
  await click('解析并应用 Network Settings')
  expect(changed).not.toHaveBeenCalled()
  await click('解析并应用 Network Settings')
  expect(changed).toHaveBeenCalledExactlyOnceWith({ path: '/new' })
  expect(host.querySelector('form')!.checkValidity()).toBe(true)
  expect(dirtyDrafts.snapshot()).toBe(false)
})

it('fills a JSON template without writing it until explicit apply, and keeps parse failures unapplied', async () => {
  await render(<JsonHarness />)
  await input(host.querySelector('select')!, '0')
  expect(changed).not.toHaveBeenCalled()
  expect(host.querySelector('textarea')!.value).toContain('example.com')
  await input(host.querySelector('textarea')!, '{')
  expect(host.textContent).toContain('JSON 格式有误')
  expect(host.querySelector('form')!.checkValidity()).toBe(false)
  expect(changed).not.toHaveBeenCalled()
  await click('还原 JSON')
  expect(host.querySelector('form')!.checkValidity()).toBe(true)
})

const protocol: NodeProtocol = {
  type: 'vmess', label: 'VMess', schema_version: 1, defaults: {}, form_schema: [{ key: 'rules', label: 'VMess Rules', type: 'json-array' }],
  certificate_schema: [
    { key: 'cert_mode', label: '证书模式', type: 'select', options: [{ value: 'none', label: '无证书' }, { value: 'dns', label: 'ACME DNS' }, { value: 'content', label: '证书内容' }] },
    { key: 'domain', label: '证书域名', type: 'text', visible_when: [{ field: 'cert_mode', one_of: ['dns', 'self'] }] },
    { key: 'dns_env', label: 'DNS 环境变量', type: 'json-object', visible_when: [{ field: 'cert_mode', equals: 'dns' }] },
    { key: 'key_content', label: '私钥 PEM 内容', type: 'textarea', visible_when: [{ field: 'cert_mode', equals: 'content' }] },
  ],
}
it('accepts actual array metadata and enforces array-only JSON instead of rejecting the complete protocol catalog', async () => {
  const parsed = parseNodeProtocols(JSON.stringify([protocol]))
  await render(<form><NodeProtocolFields protocol={parsed[0]!} value={{ rules: [] }} onChange={changed} onGenerate={() => undefined} /></form>)
  await input(host.querySelector('textarea')!, '{}')
  expect(host.textContent).toContain('请填写 JSON 数组')
  expect(host.querySelector('form')!.checkValidity()).toBe(false)
  expect(changed).not.toHaveBeenCalled()
})

it('shows certificate conditions from metadata and preserves native unknown fields while canonicalizing the legacy mode alias', async () => {
  function CertificateHarness() {
    const [value, setValue] = useState<unknown>({ mode: 'dns', domain: 'node.example.test', dns_env: { CF_API_TOKEN: 'secret' }, native_extension: 'preserved' })
    return <NodeCertificateFields protocol={protocol} value={value} onChange={next => { changed(next); setValue(next) }} />
  }
  await render(<CertificateHarness />)
  expect(host.textContent).toContain('DNS 环境变量')
  expect(host.textContent).not.toContain('私钥 PEM 内容')
  await input(host.querySelector('select')!, 'content')
  expect(changed).toHaveBeenLastCalledWith({ cert_mode: 'content', domain: 'node.example.test', dns_env: { CF_API_TOKEN: 'secret' }, native_extension: 'preserved' })
  expect(host.textContent).not.toContain('DNS 环境变量')
  expect(host.textContent).toContain('私钥 PEM 内容')
})
