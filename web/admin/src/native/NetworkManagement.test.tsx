import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { workflowActions } from './session'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  return { workflowActions: createWorkflowActions(vi.fn()) }
})

let host: HTMLDivElement, root: Root
let management: ReturnType<typeof useNetworkManagement>
const read = vi.fn<() => Promise<void>>(async () => undefined)
function View({ id }: { id: string }) {
  management = useNetworkManagement('network.nodes.', [], read, id)
  return <span>{id}</span>
}
function deferred() {
  let resolve!: () => void
  const promise = new Promise<void>(done => { resolve = done })
  return { promise, resolve }
}
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  read.mockReset().mockResolvedValue(undefined); workflowActions.reset()
})
afterEach(() => { act(() => root.unmount()); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })

it.each(['switch', 'unmount'] as const)('ignores a delayed mutation after view %s without navigation or refetch', async mode => {
  await act(async () => root.render(<View id="A" />))
  const command = deferred(), navigate = vi.fn()
  let completion!: Promise<void>
  act(() => { completion = management.perform(() => command.promise, navigate) })
  await act(async () => root.render(mode === 'switch' ? <View id="B" /> : null))
  await act(async () => { command.resolve(); await completion })
  expect(navigate).not.toHaveBeenCalled()
  expect(read).not.toHaveBeenCalled()
})

it.each(['switch', 'unmount'] as const)('ignores a delayed authorization refresh after view %s', async mode => {
  await act(async () => root.render(<View id="A" />))
  const response = deferred()
  read.mockReturnValueOnce(response.promise)
  const confirm = vi.spyOn(workflowActions, 'confirmReadAuthorization')
  const clear = vi.spyOn(workflowActions, 'clearError')
  let completion!: Promise<boolean>
  act(() => { completion = management.refresh() })
  await act(async () => root.render(mode === 'switch' ? <View id="B" /> : null))
  await act(async () => { response.resolve(); expect(await completion).toBe(false) })
  expect(confirm).not.toHaveBeenCalled()
  expect(clear).not.toHaveBeenCalled()
})

it('does not revive an old mutation when the view changes A to B to A', async () => {
  await act(async () => root.render(<View id="A" />))
  const command = deferred(), after = vi.fn()
  let completion!: Promise<void>
  act(() => { completion = management.perform(() => command.promise, after) })
  await act(async () => root.render(<View id="B" />))
  await act(async () => root.render(<View id="A" />))
  await act(async () => { command.resolve(); await completion })
  expect(after).not.toHaveBeenCalled()
  expect(read).not.toHaveBeenCalled()
})

it('runs completion and refresh for a mutation in the current view', async () => {
  await act(async () => root.render(<View id="A" />))
  const after = vi.fn()
  await act(async () => management.perform(async () => undefined, after))
  expect(after).toHaveBeenCalledOnce()
  expect(read).toHaveBeenCalledOnce()
})

it('conceals retained protocol secrets without changing the original pending input', async () => {
  await act(async () => root.render(<View id="A" />))
  const configuration = '{"protocol_settings":{"private_key":"never-display"}}'
  const pending = { operation: 'network.nodes.create' as const, input: { configuration_json: configuration }, key: 'original-key', unknown: true }
  await act(async () => root.render(<NetworkRecovery management={{ ...management, pending }} blocked={false} />))
  expect(host.querySelector('pre')!.textContent).toContain('敏感内容不回显')
  expect(host.textContent).not.toContain('never-display')
  expect(pending.input.configuration_json).toBe(configuration)
})

it.each([401, 403])('scopes unrelated authorization errors while preserving global session expiry (%s)', async status => {
  const state = { busy: false, error: new CoreAdminApiError('FORBIDDEN', status), errorOperation: 'settings.configuration.update' as const }
  vi.spyOn(workflowActions, 'snapshot').mockReturnValue(state)
  await act(async () => root.render(<View id="A" />))
  expect(management.denied).toBe(status === 401)
  expect(management.error).toBe('')
})

it('withdraws data for an authorization error owned by the current operation scope', async () => {
  vi.spyOn(workflowActions, 'snapshot').mockReturnValue({ busy: false, error: new CoreAdminApiError('FORBIDDEN', 403), errorOperation: 'network.nodes.patch' })
  await act(async () => root.render(<View id="A" />))
  expect(management.denied).toBe(true)
  expect(management.error).toContain('当前账号没有此操作的权限')
})
