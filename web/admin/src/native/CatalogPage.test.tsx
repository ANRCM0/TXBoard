import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { CatalogPage } from './CatalogPage'
import { ConfirmDialogHost, dismissConfirm } from '../components/ui/ConfirmDialog'

vi.mock('./session', async () => {
  const { createWorkflowActions } = await import('./workflowActions')
  const admin = { read: vi.fn(), write: vi.fn() }
  return { admin, workflowActions: createWorkflowActions((operation, input, key) => admin.write(operation, input, key)) }
})
type Detail = CoreOutput<'catalog.admin-plans.get'>
const planId = '9007199254740993'
const groupId = '9007199254740995'
const offer = { offer_id: '71', plan_id: planId, revision_id: '11', name: 'Partial Offer projection', offer_code: 'monthly',
  price_minor: '100', currency: 'USD', status: 'published', show: true, sell: true, renew: false, capacity_limit: '4', row_version: '8' }
const original: Detail = { plan_id: planId, current_revision_id: '11', revision: {
  revision_id: '11', revision_no: '3', content_hash: 'a'.repeat(64), published_at: 1792368000,
  terms: { name: 'Complete authoritative plan', content: '<b>Plain terms</b>', tags: ['comma, retained', 'second'],
    quota_gib: 123, group_id: groupId, speed_limit_mbps: 0, device_limit: null, reset_method: 4, currency: 'USD',
    prices_minor: { monthly: '9007199254740993', quarterly: '0', half_yearly: '10203', yearly: '400', two_yearly: '500', three_yearly: '600', onetime: '700', reset_traffic: '800' } },
}, offers: [offer] }
let host: HTMLDivElement, root: Root, client: QueryClient, current: Detail
const listMeta = { page: 1, per_page: 20, total: 21, last_page: 2 }
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
  client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: 0 } } })
  workflowActions.reset(); current = structuredClone(original)
  vi.spyOn(window, 'confirm').mockReturnValue(true)
  vi.mocked(admin.read).mockReset().mockImplementation(async (operation, input) => {
    if (operation === 'catalog.admin-plans.get') {
      const requested = input as { revision_id?: string }
      if (requested.revision_id === '10') return { data: { ...current, revision: { ...current.revision, revision_id: '10', revision_no: '2', terms: { ...current.revision.terms, name: 'Historical immutable plan' } } }, meta: {} } as never
      return { data: structuredClone(current), meta: {} } as never
    }
    if (operation === 'network.groups.names.get') return { data: { groups: [{ id: groupId, name: 'Authoritative group' }] }, meta: {} } as never
    if (operation === 'network.groups.list') return { data: [{ id: groupId, name: 'Authoritative group' }], meta: listMeta } as never
    if (operation === 'catalog.admin-plans.list') return { data: [{ plan_id: planId, current_revision_id: current.current_revision_id, revision_no: current.revision.revision_no, name: 'Plan list summary', currency: current.revision.terms.currency, published_at: 1792368000 }], meta: listMeta } as never
    if (operation === 'catalog.admin-plans.history.list') return { data: [{ plan_id: planId, revision_id: '10', revision_no: '2', name: 'Historical summary', currency: 'USD', published_at: 1792300000 }], meta: listMeta } as never
    if (operation === 'catalog.admin-offers.list') return { data: [structuredClone(offer)], meta: listMeta } as never
    throw new Error('Unexpected read ' + operation)
  })
  vi.mocked(admin.write).mockReset().mockResolvedValue({ data: {} } as never)
})
afterEach(() => { act(() => dismissConfirm()); act(() => root.unmount()); client.clear(); host.remove(); workflowActions.reset(); vi.restoreAllMocks() })
async function settle() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 20)) }) }
async function render(show = true) {
  await act(async () => root.render(<QueryClientProvider client={client}>{show ? <CatalogPage /> : null}<ConfirmDialogHost /></QueryClientProvider>)); await settle()
}
function button(text: string, scope: ParentNode = document) { return [...scope.querySelectorAll<HTMLButtonElement>('button')].find(node => node.getAttribute('aria-label') === text || node.textContent?.trim() === text)! }
async function click(text: string, scope: ParentNode = document) { await act(async () => button(text, scope).click()); await settle() }
function field(id: string) { return document.querySelector<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('#' + id)! }
async function change(id: string, value: string) {
  const node = field(id), prototype = node.tagName === 'SELECT' ? HTMLSelectElement.prototype : node.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype
  await act(async () => { Object.getOwnPropertyDescriptor(prototype, 'value')!.set!.call(node, value); node.dispatchEvent(new Event(node.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })) }); await settle()
}
async function submit(label = '发布完整套餐条款') {
  const form = document.querySelector<HTMLFormElement>('form[aria-label="' + label + '"]')!
  await act(async () => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))); await settle()
}
async function existing() { await render(); await click('编辑当前套餐') }
async function capacity() { await change('catalog-capacity-choice', 'unlimited') }

it('confirms clearing all cycle price drafts without publishing or losing the observed revision', async () => {
  await existing()
  vi.mocked(window.confirm).mockReturnValueOnce(false)
  await click('清空全部周期价格')
  expect(field('catalog-price-monthly').value).toBe(original.revision.terms.prices_minor.monthly)
  vi.mocked(window.confirm).mockReturnValueOnce(true)
  await click('清空全部周期价格')
  for (const period of Object.keys(original.revision.terms.prices_minor)) expect(field('catalog-price-' + period).value).toBe('')
  expect(admin.write).not.toHaveBeenCalled()
  await change('catalog-price-monthly', '0'); await capacity(); await submit()
  expect(vi.mocked(admin.write).mock.calls[0]?.[1]).toMatchObject({ plan_id: planId, expected_revision_id: '11', prices_minor: { monthly: '0' } })
})

it('loads authoritative full current terms instead of reconstructing a plan from partial Offer rows', async () => {
  await existing()
  expect(admin.read).toHaveBeenCalledWith('catalog.admin-plans.get', { plan_id: planId })
  expect(field('catalog-name').value).toBe('Complete authoritative plan')
  expect(field('catalog-content').value).toBe('<b>Plain terms</b>')
  expect(document.querySelector('[aria-label="已选套餐权限组"]')?.textContent).toContain('Authoritative group · #' + groupId)
  expect(field('catalog-speed').value).toBe('0')
  expect(field('catalog-devices').value).toBe('')
  expect(field('catalog-price-monthly').value).toBe('9007199254740993')
  expect(field('catalog-price-quarterly').value).toBe('0')
  expect(field('catalog-capacity-choice').value).toBe('')
  expect(button('发布完整条款').disabled).toBe(true)
  // Required markers are native UI; the supplied plan markup must remain text.
  expect(document.querySelectorAll('b:not(.ui-required)').length).toBe(0)
  expect([...document.querySelectorAll('.ui-required')].every(marker => marker.textContent?.trim() === '*')).toBe(true)
})

it('publishes a complete observed revision with exact minor strings, all periods and preserved null/zero limits', async () => {
  await existing(); await capacity(); await submit()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('catalog.plans.publish', {
    plan_id: planId, expected_revision_id: '11', name: original.revision.terms.name,
    content: '<b>Plain terms</b>', tags: ['comma, retained', 'second'], quota_gib: 123, group_id: groupId,
    speed_limit_mbps: 0, device_limit: null, capacity_limit: null, reset_method: 4, currency: 'USD',
    prices_minor: original.revision.terms.prices_minor,
  }, expect.any(String))
  expect(document.querySelector('[aria-label="套餐发布编辑器"]')).toBeNull()
})

it('creates a separate new publication with no inherited Plan ID or CAS token', async () => {
  await existing(); await click('关闭编辑'); await click('创建套餐')
  await change('catalog-name', 'New native plan'); await change('catalog-price-monthly', '1234'); await capacity(); await submit()
  expect(admin.write).toHaveBeenCalledExactlyOnceWith('catalog.plans.publish', {
    name: 'New native plan', content: null, tags: [], quota_gib: 0, group_id: null,
    speed_limit_mbps: null, device_limit: null, capacity_limit: null, reset_method: 0, currency: 'CNY', prices_minor: { monthly: '1234' },
  }, expect.any(String))
})

it('clears an uncommitted tag draft when opening a different publication editor', async () => {
  current.revision.terms.tags = Array.from({ length: 40 }, (_, index) => 'tag-' + index)
  await existing(); await change('catalog-tags', 'uncommitted previous plan tag')
  expect(field('catalog-tags').value).toBe('uncommitted previous plan tag')
  await click('创建套餐')
  expect(field('catalog-tags').value).toBe('')
  expect(document.querySelectorAll('.ui-tag-input-item')).toHaveLength(0)
})

it('keeps the submitted draft and CAS revision after conflict until an explicit authoritative replacement', async () => {
  await existing(); await change('catalog-name', 'Operator draft'); await capacity()
  current.current_revision_id = '12'; current.revision.revision_id = '12'; current.revision.terms.name = 'External publication'
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('NATIVE_PLAN_REVISION_CONFLICT', 409))
  await submit()
  expect(field('catalog-name').value).toBe('Operator draft')
  expect(document.querySelector('[aria-label="套餐发布编辑器"]')!.textContent).toContain('11')
  expect(document.body.textContent).toContain('草稿仍保留')
  expect(button('发布完整条款').disabled).toBe(true)
  await submit(); expect(admin.write).toHaveBeenCalledTimes(1)
  await click('重新读取当前条款并替换草稿')
  expect(field('catalog-name').value).toBe('External publication')
  expect(field('catalog-capacity-choice').value).toBe('')
  await capacity(); await submit()
  expect(vi.mocked(admin.write).mock.calls[1][1]).toMatchObject({ expected_revision_id: '12', name: 'External publication' })
})

it('recovers the identical original publication and key after navigation without replaying a new draft', async () => {
  await existing(); await capacity()
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost response'))
  await submit()
  const originalCall = vi.mocked(admin.write).mock.calls[0]
  expect(workflowActions.snapshot().pending?.unknown).toBe(true)
  await render(false); await render()
  expect(document.querySelector('[aria-label="套餐发布编辑器"]')).toBeNull()
  await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(originalCall)
  expect(workflowActions.snapshot().pending).toBeUndefined()
})

it('uses the observed current Offer version and never enables changes to superseded Offers', async () => {
  await render(); await click('调整可用性'); await change('catalog-availability-capacity', '0')
  await act(async () => field('catalog-availability-renew').click()); await settle()
  vi.mocked(admin.write).mockRejectedValueOnce(new TypeError('lost response'))
  await click('保存可用性')
  const originalCall = vi.mocked(admin.write).mock.calls[0]
  expect(originalCall).toEqual(['catalog.offers.availability.update', {
    offer_id: '71', expected_version: '8', show: true, sell: true, renew: true, capacity_limit: 0,
  }, expect.any(String)])
  await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(originalCall)
  vi.mocked(admin.read).mockImplementation(async operation => ({ data: operation === 'catalog.admin-offers.list'
    ? [{ ...offer, status: 'superseded' }] : [], meta: listMeta }) as never)
  await click('刷新')
  expect(button('调整可用性').disabled).toBe(true)
})

it('server-filters current versus superseded Offers and preserves decimal Plan IDs across pagination', async () => {
  await render()
  const filters = document.querySelector<HTMLElement>('section[aria-label="周期商品"]')!
  const select = filters.querySelector<HTMLSelectElement>('select')!
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')!.set!.call(select, 'superseded'); select.dispatchEvent(new Event('change', { bubbles: true })) }); await settle()
  const input = filters.querySelector<HTMLInputElement>('input')!
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, planId); input.dispatchEvent(new Event('input', { bubbles: true })) })
  await click('筛选商品'); await click('下一页', filters)
  expect(admin.read).toHaveBeenCalledWith('catalog.admin-offers.list', { page: 2, per_page: 20, status: 'superseded', plan_id: planId }, expect.any(AbortSignal))
  expect(admin.write).not.toHaveBeenCalled()
})

it('keeps a conflicted Offer draft locked until its own authoritative revision is explicitly read again', async () => {
  await render(); await click('调整可用性'); await change('catalog-availability-capacity', '0')
  current.offers[0].row_version = '9'; current.offers[0].capacity_limit = '6'
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('PUBLICATION_AVAILABILITY_VERSION_CONFLICT', 409))
  await click('保存可用性')
  expect(field('catalog-availability-capacity').value).toBe('0')
  expect(button('保存可用性').disabled).toBe(true)
  await click('重新读取商品并替换设置')
  expect(admin.read).toHaveBeenCalledWith('catalog.admin-plans.get', { plan_id: planId, revision_id: '11' })
  expect(field('catalog-availability-capacity').value).toBe('6')
  await click('保存可用性')
  expect(vi.mocked(admin.write).mock.calls[1][1]).toMatchObject({ offer_id: '71', expected_version: '9', capacity_limit: 6 })
})

it('reads historical full terms as read-only evidence and still opens the current revision for editing', async () => {
  await render(); await click('查看完整条款'); await click('查看此修订')
  expect(admin.read).toHaveBeenCalledWith('catalog.admin-plans.get', { plan_id: planId, revision_id: '10' }, expect.any(AbortSignal))
  expect(document.body.textContent).toContain('Historical immutable plan')
  const versionDetails = [...document.querySelectorAll('details')].find(node => node.querySelector('summary')?.textContent === '查看完整条款与版本标识')!
  expect(versionDetails.open).toBe(false)
  await act(async () => versionDetails.querySelector('summary')!.click())
  expect(versionDetails.open).toBe(true)
  expect(versionDetails.textContent).toContain(original.revision.content_hash)
  expect(document.querySelector('[aria-label="套餐发布编辑器"]')).toBeNull()
  await click('基于当前完整条款编辑')
  expect(field('catalog-name').value).toBe('Complete authoritative plan')
  expect(document.querySelector('[aria-label="套餐发布编辑器"]')!.textContent).toContain('11')
})

it('preserves an unsupported historical currency until the operator explicitly chooses a supported publication currency', async () => {
  current.revision.terms.currency = 'EUR'
  await existing(); await capacity()
  expect(field('catalog-currency').value).toBe('EUR')
  expect(field('catalog-price-monthly').value).toBe('9007199254740993')
  expect(button('发布完整条款').disabled).toBe(true)
  await submit(); expect(admin.write).not.toHaveBeenCalled()
  await change('catalog-currency', 'USD'); await submit()
  expect(vi.mocked(admin.write).mock.calls[0][1]).toMatchObject({ currency: 'USD', prices_minor: original.revision.terms.prices_minor })
})

it('withdraws stale terms, drafts and pagination on permission loss and requires a successful explicit refresh for unknown recovery', async () => {
  await existing(); await capacity()
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403, undefined, true))
  await submit()
  const originalCall = vi.mocked(admin.write).mock.calls[0]
  expect(document.querySelector('table')).toBeNull()
  expect(document.querySelector('[aria-label="套餐发布编辑器"]')).toBeNull()
  expect(document.body.textContent).not.toContain('Partial Offer projection')
  expect(document.body.textContent).not.toContain('共 21 项')
  expect(button('使用原操作确认结果').disabled).toBe(true)
  vi.mocked(admin.read).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新'); expect(button('使用原操作确认结果').disabled).toBe(true)
  await click('刷新'); expect(button('使用原操作确认结果').disabled).toBe(false)
  await click('使用原操作确认结果')
  expect(vi.mocked(admin.write).mock.calls[1]).toEqual(originalCall)
})

it('ignores a completed authoritative editor read after the administrator session generation changes', async () => {
  await render()
  let complete!: (value: never) => void
  vi.mocked(admin.read).mockImplementationOnce(() => new Promise(resolve => { complete = resolve }) as never)
  await click('编辑当前套餐')
  act(() => workflowActions.reset())
  await act(async () => complete({ data: original, meta: {} } as never)); await settle()
  expect(document.querySelector('[aria-label="套餐发布编辑器"]')).toBeNull()
  expect(admin.write).not.toHaveBeenCalled()
})

it('hides cached catalog rows when a direct editor read is forbidden and rechecks that exact resource on refresh', async () => {
  await render()
  vi.mocked(admin.read).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await click('编辑当前套餐')
  expect(document.querySelector('table')).toBeNull()
  expect(document.querySelector('[aria-label="套餐发布编辑器"]')).toBeNull()
  expect(document.body.textContent).not.toContain('Partial Offer projection')
  vi.mocked(admin.read).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await click('刷新')
  expect(document.querySelector('table')).toBeNull()
  await click('刷新')
  expect(document.body.textContent).toContain('Partial Offer projection')
  expect(vi.mocked(admin.read).mock.calls.filter(([operation, input]) => operation === 'catalog.admin-plans.get' && 'plan_id' in input && input.plan_id === planId)).toHaveLength(3)
  expect(admin.write).not.toHaveBeenCalled()
})

it('rejects fractional or overflowing minor prices before contacting the publication owner', async () => {
  await existing(); await capacity(); await change('catalog-price-monthly', '1.23'); await submit()
  expect(admin.write).not.toHaveBeenCalled()
  expect(document.body.textContent).toContain('最小货币单位整数')
  await change('catalog-price-monthly', '9223372036854775808'); await submit()
  expect(admin.write).not.toHaveBeenCalled()
})

it('requires confirmation before replacing plan content and preserves all other frozen terms', async () => {
  await existing(); const originalPrice = field('catalog-price-monthly').value
  await click('使用内容模板')
  expect(field('catalog-content').value).toBe('<b>Plain terms</b>')
  await click('保留原文', document.body)
  expect(field('catalog-content').value).toBe('<b>Plain terms</b>')
  await click('使用内容模板'); await click('替换正文', document.body)
  expect(field('catalog-content').value).toContain('123 GiB')
  expect(field('catalog-content').value).toContain('0 Mbps')
  expect(field('catalog-content').value).toContain('设备数量：不限')
  expect(field('catalog-price-monthly').value).toBe(originalPrice)
  expect(document.querySelector('[aria-label="已选套餐权限组"]')?.textContent).toContain('Authoritative group · #' + groupId)
  expect(admin.write).not.toHaveBeenCalled()
})

it('inserts a content template immediately when the plan description is empty', async () => {
  await existing(); await change('catalog-content', '')
  await click('使用内容模板')
  expect(document.querySelector('.admin-confirm-dialog')).toBeNull()
  expect(field('catalog-content').value).toContain('## Complete authoritative plan')
  expect(admin.write).not.toHaveBeenCalled()
})


it('keeps the publication draft when an inline permission group create is denied', async () => {
  await existing(); await change('catalog-name', 'Still retained')
  await click('选择套餐权限组')
  await click('新建权限组')
  const input = document.querySelector<HTMLInputElement>('[aria-label="新权限组名称"]')!
  await act(async () => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, 'New group'); input.dispatchEvent(new Event('input', { bubbles: true })) })
  vi.mocked(admin.write).mockRejectedValueOnce(new CoreAdminApiError('FORBIDDEN', 403))
  await click('创建并选择权限组')
  expect(field('catalog-name').value).toBe('Still retained')
  expect(workflowActions.snapshot().errorOperation).toBe('network.groups.save')
})

it('confirms replacement of cycle prices and preserves free and non-recurring amounts', async () => {
  await existing(); await click('按月价重算周期价格')
  expect(field('catalog-price-yearly').value).toBe('400')
  const dialog = document.querySelector('[role="dialog"][aria-label="按月价重算周期价格？"]')!
  await click('重算周期价格', dialog)
  expect(field('catalog-price-quarterly').value).toBe('0')
  expect(field('catalog-price-yearly').value).toBe('108086391056891916')
  expect(field('catalog-price-onetime').value).toBe('700')
  expect(admin.write).not.toHaveBeenCalled()
})

it('retains an edited draft when closing or replacing it is cancelled', async () => {
  await existing(); await change('catalog-name', 'Unsaved draft')
  vi.mocked(window.confirm).mockReturnValue(false)
  await click('关闭编辑'); await click('创建套餐')
  expect(field('catalog-name').value).toBe('Unsaved draft')
})
