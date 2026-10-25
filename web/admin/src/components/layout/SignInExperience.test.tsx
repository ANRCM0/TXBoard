import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { SignInPage } from '../../pages/SignInPage'
import { loginAdmin } from '../../native/session'

vi.mock('../../native/session', () => ({ loginAdmin: vi.fn(), admin: { forgetSession: vi.fn() } }))
vi.mock('../../lib/useSiteBranding', () => ({ useSiteBranding: () => ({ name: 'TXBoard', description: '', logo: null }) }))
vi.mock('../../lib/useSiteAppearance', () => ({ SiteAppearanceConsumer: () => null, SiteLoginBackground: () => null }))

let host: HTMLDivElement, root: Root, client: QueryClient
beforeEach(async () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  vi.mocked(loginAdmin).mockReset()
  client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity }, mutations: { retry: false } } })
  client.setQueryData(['admin', 'guest-config'], { is_captcha: 0 })
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  await act(async () => root.render(<QueryClientProvider client={client}><MemoryRouter><SignInPage /></MemoryRouter></QueryClientProvider>))
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove() })
async function fill(name: string, value: string) {
  const input = host.querySelector<HTMLInputElement>(`input[name="${name}"]`)!
  await act(async () => {
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, value)
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })
}
async function submit(count = 1) {
  await act(async () => {
    for (let index = 0; index < count; index++) host.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await new Promise(resolve => setTimeout(resolve, 20))
  })
}

it('keeps a failed login visible and focused, retains the email and clears stale feedback on editing', async () => {
  vi.mocked(loginAdmin).mockRejectedValue(new CoreAdminApiError('UNAUTHORIZED', 401))
  await fill('email', 'admin@example.test'); await fill('password', 'example-password')
  await submit()
  const error = host.querySelector('.admin-auth-request-error')!
  expect(error.textContent).toBe('邮箱、密码或验证码未通过验证。')
  expect(document.activeElement).toBe(error)
  expect(host.querySelector<HTMLInputElement>('[name="email"]')?.value).toBe('admin@example.test')
  await fill('password', 'corrected-password')
  expect(host.querySelector('.admin-auth-request-error')).toBeNull()
})

it('admits one login when duplicate submit events arrive before the pending render', async () => {
  let reject!: (error: Error) => void
  vi.mocked(loginAdmin).mockImplementation(() => new Promise((_, fail) => { reject = fail }))
  await fill('email', 'admin@example.test'); await fill('password', 'example-password')
  await submit(2)
  expect(loginAdmin).toHaveBeenCalledTimes(1)
  expect(host.querySelector('form')?.getAttribute('aria-busy')).toBe('true')
  expect(host.querySelector<HTMLInputElement>('[name="email"]')?.disabled).toBe(true)
  await act(async () => { reject(new Error('offline')); await new Promise(resolve => setTimeout(resolve, 20)) })
  expect(host.querySelector<HTMLInputElement>('[name="email"]')?.disabled).toBe(false)
  expect(host.querySelector('.admin-auth-request-error')).not.toBeNull()
})
