import { act, useState } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin } from './session'
import { OfferSelector } from './OfferSelector'

vi.mock('./session', () => ({ admin: { read: vi.fn() } }))
let host: HTMLDivElement, root: Root, client: QueryClient
const ready = vi.fn(), selected = vi.fn()
const ids = ['9007199254740993', '9007199254740995']
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  ready.mockReset(); selected.mockReset()
  vi.mocked(admin.read).mockReset().mockImplementation(async (_operation, input) => {
    const page = (input as { page: number }).page
    return { data: [{ offer_id: ids[page - 1], name: 'Plan ' + page, offer_code: 'monthly', price_minor: '9007199254740993', currency: 'CNY' }],
      meta: { page, per_page: 100, total: 101, last_page: 2 } } as never
  })
})
afterEach(() => { act(() => root.unmount()); client.clear(); host.remove() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
function Harness() {
  const [value, setValue] = useState<string[]>([])
  return <OfferSelector value={value} onChange={ids => { selected(ids); setValue(ids) }} onReady={ready} />
}
async function render() {
  await act(async () => root.render(<QueryClientProvider client={client}><Harness /></QueryClientProvider>)); await settle()
}
async function next() {
  await act(async () => [...host.querySelectorAll<HTMLButtonElement>('button')].find(button => button.textContent === '下一页商品')!.click()); await settle()
}

it('keeps exact selected offers across pages and formats minor amounts without rounding', async () => {
  await render()
  expect(host.textContent).toContain('90,071,992,547,409.93 CNY')
  await act(async () => host.querySelector<HTMLInputElement>('input[type="checkbox"]')!.click()); await settle()
  await next()
  await act(async () => host.querySelector<HTMLInputElement>('input[type="checkbox"]')!.click()); await settle()
  expect(selected).toHaveBeenLastCalledWith(ids)
  expect(host.querySelector('[aria-label="已选商品编号"]')!.textContent).toContain(ids[0])
  expect(admin.read).toHaveBeenLastCalledWith('catalog.admin-offers.list', { page: 2, per_page: 100, status: 'published' }, expect.any(AbortSignal))
})

it('withdraws choices and readiness after a failed page read while retaining original selection externally', async () => {
  await render()
  await act(async () => host.querySelector<HTMLInputElement>('input[type="checkbox"]')!.click()); await settle()
  vi.mocked(admin.read).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await next()
  expect(host.querySelectorAll('input[type="checkbox"]')).toHaveLength(0)
  expect(host.querySelector('[aria-label="已选商品编号"]')).toBeNull()
  expect(ready).toHaveBeenLastCalledWith(false)
  expect(selected).toHaveBeenLastCalledWith([ids[0]])
})
