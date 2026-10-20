import { act, type ReactNode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { PaymentMethodsPage } from './PaymentMethodsPage'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})

const paymentId = '9007199254740993'
const initialVersion = 'a'.repeat(64), nextVersion = 'b'.repeat(64)
type Method = CoreOutput<'billing.admin-payment-methods.list'>[number]
let method: Method, host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  method = { id: paymentId, name: 'Private merchant method', provider: 'EPay', icon: null, enabled: true,
    available: true, version: initialVersion, sort: 3, fee_fixed_minor: '0', fee_percent: '0' }
  workflowActions.reset()
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: { id: paymentId }, meta: {} } as never)
  vi.mocked(admin.read).mockReset().mockImplementation(async (operation, input) => {
    if (operation === 'billing.admin-payment-methods.list') {
      const page = 'page' in input ? input.page ?? 1 : 1
      return { data: [{ ...method, name: page === 2 ? 'Second page method' : method.name }], meta: { page, per_page: 20, total: 21, last_page: 2 } } as never
    }
    if (operation === 'billing.admin-payment-providers.list') return { data: [{ provider: 'EPay', plugin_code: 'epay', version: '1.0.0', currencies: ['CNY'], external_refund: false }], meta: {} } as never
    if (operation === 'billing.admin-payment-methods.form') return { data: { provider: 'EPay', fields: [
      { name: 'url', label: '网关地址', kind: 'text', value: null, configured: true },
      { name: 'pid', label: '商户ID', kind: 'text', value: null, configured: true },
      { name: 'key', label: '通信密钥', kind: 'password', value: null, configured: true },
    ] }, meta: {} } as never
    throw new Error('Unexpected read: ' + operation)
  })
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(page: ReactNode = <PaymentMethodsPage />) {
  await act(async () => root.render(<QueryClientProvider client={client}>{page}</QueryClientProvider>)); await settle()
}
function button(text: string) { return [...host.querySelectorAll<HTMLButtonElement>('button')].find(node => node.getAttribute('aria-label') === text || node.textContent?.trim() === text)! }
async function click(text: string) { await act(async () => button(text).click()); await settle() }
function field(label: string) { return [...host.querySelectorAll('label')].find(node => node.textContent?.startsWith(label))!.querySelector('input')! }
async function input(label: string, value: string) {
  const node = field(label)
  await act(async () => {
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(node, value)
    node.dispatchEvent(new Event('input', { bubbles: true }))
  })
}
async function submit() {
  await act(async () => { host.querySelector('form.native-content-form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })) }); await settle()
}

it('reads real pagination and provider discovery without performing any write', async () => {
  await render()
  expect(admin.read).toHaveBeenCalledWith('billing.admin-payment-methods.list', { page: 1, per_page: 20 }, expect.any(AbortSignal))
  expect(admin.read).toHaveBeenCalledWith('billing.admin-payment-providers.list', {}, expect.any(AbortSignal))
  expect(host.textContent).toContain('Private merchant method')
  await click('下一页')
  expect(admin.read).toHaveBeenCalledWith('billing.admin-payment-methods.list', { page: 2, per_page: 20 }, expect.any(AbortSignal))
  expect(host.textContent).toContain('Second page method')
  expect(admin.write).not.toHaveBeenCalled()
})

it('shows configured status while leaving every merchant input empty until an operator types', async () => {
  await render(); await click('配置支付方式')
  expect(admin.read).toHaveBeenCalledWith('billing.admin-payment-methods.form', { provider: 'EPay', payment_id: paymentId }, expect.any(AbortSignal))
  expect(field('网关地址').value).toBe(''); expect(field('商户ID').value).toBe(''); expect(field('通信密钥').value).toBe('')
  expect(field('通信密钥').type).toBe('password')
  expect(field('通信密钥').closest('label')?.querySelector('.settings-configured')?.textContent).toBe('已配置')
  expect(admin.write).not.toHaveBeenCalled()
})

it('preserves configured values by omitting blank merchant inputs and uses the observed version', async () => {
  await render(); await click('配置支付方式')
  await input('名称', 'Renamed merchant')
  await input('商户ID', 'temporary-change'); await input('商户ID', '')
  await input('通信密钥', 'replacement-secret')
  expect(admin.write).not.toHaveBeenCalled()
  await submit()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('billing.admin-payment-methods.save', {
    payment_id: paymentId, expected_version: initialVersion, name: 'Renamed merchant', provider: 'EPay', icon: null,
    enabled: true, sort: 3, configuration: [{ name: 'key', value: 'replacement-secret' }],
  }, expect.any(String))
})

it('supports a metadata-only update without resubmitting merchant configuration', async () => {
  await render(); await click('配置支付方式'); await input('排序', '8'); await submit()
  expect(vi.mocked(admin.write).mock.calls[0]![1]).toMatchObject({ payment_id: paymentId, expected_version: initialVersion, sort: 8, configuration: [] })
})

it('retains the exact unknown save across navigation and recovers only on explicit request', async () => {
  await render(); await click('配置支付方式'); await input('通信密钥', 'sensitive-original-intent')
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('acknowledgement lost'))
  await submit()
  const original = vi.mocked(admin.write).mock.calls[0]
  expect(workflowActions.snapshot().pending?.unknown).toBe(true)
  await render(<div>Another management page</div>); await render()
  expect(admin.write).toHaveBeenCalledTimes(1)
  expect(host.querySelector('input[type=password]')).toBeNull()
  expect(host.textContent).not.toContain('sensitive-original-intent')
  expect(button('配置支付方式').disabled).toBe(true)
  await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(original)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('hides cached rows, pagination, and an unsubmitted secret after list permission is lost', async () => {
  await render(); await click('配置支付方式'); await input('通信密钥', 'never-submit-this')
  vi.mocked(admin.read).mockRejectedValue(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新支付配置')
  expect(host.textContent).not.toContain('Private merchant method')
  expect(host.querySelector('table')).toBeNull()
  expect(host.querySelector('input[type=password]')).toBeNull()
  expect(button('下一页').disabled).toBe(true)
  expect(host.textContent).not.toContain('共 21 项')
  expect(admin.write).not.toHaveBeenCalled()
})

it('keeps a denied configuration read blocked until that exact resource is reauthorized', async () => {
  const normalRead = vi.mocked(admin.read).getMockImplementation()!
  vi.mocked(admin.read).mockImplementation((operation, input, signal) => operation === 'billing.admin-payment-methods.form'
    ? Promise.reject(new CoreAdminApiError('FORBIDDEN', 403)) : normalRead(operation, input, signal))
  await render(); await click('配置支付方式')
  expect(host.textContent).toContain('已隐藏数据')
  expect(host.textContent).not.toContain('Private merchant method')
  await click('刷新支付配置')
  expect(host.textContent).not.toContain('Private merchant method')
  vi.mocked(admin.read).mockImplementation(normalRead)
  await click('刷新支付配置')
  expect(host.textContent).toContain('Private merchant method')
  expect(vi.mocked(admin.read).mock.calls.filter(([operation, input]) => operation === 'billing.admin-payment-methods.form' && 'payment_id' in input && input.payment_id === paymentId).length).toBeGreaterThanOrEqual(3)
  expect(admin.write).not.toHaveBeenCalled()
})

it('discards an obsolete save result after session replacement without closing a new-session editor', async () => {
  let complete!: (value: never) => void
  vi.mocked(admin.write).mockImplementationOnce(() => new Promise(resolve => { complete = resolve }) as never)
  await render(); await click('配置支付方式'); await input('通信密钥', 'old-session-secret')
  await act(async () => { host.querySelector('form.native-content-form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })) }); await settle()
  expect(workflowActions.snapshot().busy).toBe(true)
  method = { ...method, name: 'New session merchant', version: nextVersion }
  await act(async () => workflowActions.reset()); await settle()
  expect(host.querySelector('input[type=password]')).toBeNull()
  await click('配置支付方式'); await input('名称', 'New session draft')
  await act(async () => complete({ data: { id: paymentId }, meta: {} } as never)); await settle()
  expect(field('名称').value).toBe('New session draft')
  expect(field('通信密钥').value).toBe('')
  expect(workflowActions.snapshot().pending).toBeUndefined()
  expect(admin.write).toHaveBeenCalledTimes(1)
})

it('requires fresh editing after a version conflict and submits the newly observed version with a new key', async () => {
  await render(); await click('配置支付方式'); await input('通信密钥', 'discarded-conflict-secret')
  method = { ...method, version: nextVersion, name: 'Concurrent merchant update' }
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('PAYMENT_CONFIGURATION_CONFLICT', 409))
  await submit()
  expect(host.querySelector('form.native-content-form')).toBeNull()
  expect(workflowActions.snapshot().pending).toBeUndefined()
  expect(admin.write).toHaveBeenCalledTimes(1)
  await click('刷新支付配置'); await click('配置支付方式')
  expect(field('名称').value).toBe('Concurrent merchant update')
  expect(field('通信密钥').value).toBe('')
  await input('名称', 'Reviewed current update'); await submit()
  const calls = vi.mocked(admin.write).mock.calls
  expect(calls[1]![1]).toMatchObject({ payment_id: paymentId, expected_version: nextVersion, name: 'Reviewed current update', configuration: [] })
  expect(calls[1]![2]).not.toBe(calls[0]![2])
})
it('keeps a dirty merchant draft on rejected close and prevents a reload until it is discarded', async () => {
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
  await render(); await click('配置支付方式'); await input('通信密钥', 'unsaved-secret')
  const unload = new Event('beforeunload', { cancelable: true })
  window.dispatchEvent(unload)
  expect(unload.defaultPrevented).toBe(true)
  await click('关闭编辑')
  expect(field('通信密钥').value).toBe('unsaved-secret')
  expect(admin.write).not.toHaveBeenCalled()
  confirm.mockReturnValue(true)
  await click('关闭编辑')
  expect(host.querySelector('input[type=password]')).toBeNull()
  const cleanUnload = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(cleanUnload)
  expect(cleanUnload.defaultPrevented).toBe(false)
  confirm.mockRestore()
})
