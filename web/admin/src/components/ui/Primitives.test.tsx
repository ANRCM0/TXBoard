import { act, type ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import { expect, it } from 'vitest'
import { Field, Input, Switch } from './Field'
import { StatusBadge } from './StatusBadge'
import { EmptyState } from './EmptyState'
import { QuotaBar } from './QuotaBar'
import { BulkActionBar } from './BulkActionBar'
import { FilterBar } from './FilterBar'
import { DetailSection } from './DetailSection'
import { DangerZone } from './DangerZone'
import { Popover } from './Popover'
import { ColumnSettings, useColumnVisibility } from './ColumnSettings'

function mount(node: ReactNode) {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  const host = document.createElement('div')
  document.body.append(host)
  const root = createRoot(host)
  act(() => root.render(node))
  return { host, root }
}

it('links field labels, help and errors to the control', () => {
  const { host, root } = mount(<Field label="服务器 ID" hint="留空解除绑定" required error="请输入服务器 ID"><Input value="" readOnly /></Field>)
  const input = host.querySelector('input')!
  expect(input.getAttribute('aria-invalid')).toBe('true')
  expect(input.getAttribute('aria-describedby')).toContain('error')
  expect(host.querySelector('label')?.getAttribute('for')).toBe(input.id)
  act(() => root.unmount()); host.remove()
})

it('renders semantic status tones and quota values without float loss', () => {
  const { host, root } = mount(<>
    <StatusBadge tone="success">正常</StatusBadge>
    <QuotaBar used="20000000000" total="100000000000" />
  </>)
  expect(host.querySelector('.ui-status')?.getAttribute('class')).toContain('is-success')
  expect(host.textContent).toContain('20.00%')
  expect(host.querySelector('[role="progressbar"]')?.getAttribute('aria-valuenow')).toBe('20')
  expect(host.textContent).toContain('20.00 GB / 100.00 GB')
  expect(host.textContent).toContain('剩余 80.00 GB')
  act(() => root.unmount()); host.remove()
})

it('renders empty and bulk selection feedback with explicit scope', () => {
  const { host, root } = mount(<>
    <EmptyState title="没有节点" description="创建第一个节点后即可查看流量" />
    <BulkActionBar count={2} onClear={() => {}}><button type="button">批量启用</button></BulkActionBar>
    <FilterBar applied={[{ key: 'status', label: '已启用' }]} onClear={() => {}}><span>筛选控件</span></FilterBar>
  </>)
  expect(host.textContent).toContain('没有节点')
  expect(host.textContent).toContain('已选当前页 2 项')
  expect(host.textContent).toContain('已启用')
  act(() => root.unmount()); host.remove()
})

it('organizes detail sections and danger actions', () => {
  const { host, root } = mount(<>
    <DetailSection label="账户信息" title="账户资料"><p>本字段</p></DetailSection>
    <DangerZone description="删除后不可恢复"><button type="button">删除</button></DangerZone>
  </>)
  expect(host.querySelector('[aria-label="账户信息"]')?.textContent).toContain('账户资料')
  expect(host.querySelector('[aria-label="危险操作"]')?.textContent).toContain('删除后不可恢复')
  act(() => root.unmount()); host.remove()
})

it('opens a help popover with keyboard dismissal', () => {
  const { host, root } = mount(<Popover label="统计口径"><p>仅统计已结算批次</p></Popover>)
  const trigger = host.querySelector<HTMLButtonElement>('.ui-popover-trigger')!
  act(() => trigger.click())
  expect(host.querySelector('[role="dialog"]')?.textContent).toContain('已结算')
  act(() => { document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })) })
  expect(host.querySelector('[role="dialog"]')).toBeNull()
  act(() => root.unmount()); host.remove()
})

it('keeps switch semantics for boolean settings', () => {
  const { host, root } = mount(<Switch label="启用节点" defaultChecked />)
  expect(host.querySelector('input')?.getAttribute('role')).toBe('switch')
  expect(host.querySelector('input')?.checked).toBe(true)
  act(() => root.unmount()); host.remove()
})

it('persists hidden columns and refuses to hide the last visible column', () => {
  const columns = [{ key: 'name', header: '名称' }, { key: 'state', header: '状态' }]
  function Harness() {
    const { hidden, onChange } = useColumnVisibility('test-columns', columns)
    return <><ColumnSettings columns={columns} hidden={hidden} onChange={onChange} /><span data-testid="hidden">{[...hidden].join(',')}</span></>
  }
  const { host, root } = mount(<Harness />)
  act(() => host.querySelector<HTMLButtonElement>('button[aria-label="列设置"]')!.click())
  const inputs = host.querySelectorAll<HTMLInputElement>('.ui-column-settings input[type="checkbox"]')
  expect(inputs).toHaveLength(2)
  act(() => inputs[1].click())
  expect(host.querySelector('[data-testid="hidden"]')?.textContent).toBe('state')
  const remaining = host.querySelectorAll<HTMLInputElement>('.ui-column-settings input[type="checkbox"]')
  expect(remaining[0].disabled).toBe(true)
  expect(remaining[1].checked).toBe(false)
  act(() => remaining[1].click())
  expect(host.querySelector('[data-testid="hidden"]')?.textContent).toBe('')
  window.localStorage.removeItem('txboard:admin:columns:test-columns')
  act(() => root.unmount()); host.remove()
})

it('keeps required identity and action columns visible despite stale stored preferences', () => {
  const columns = [{ key: 'email', header: '邮箱', hideable: false }, { key: 'role', header: '角色' }, { key: 'actions', header: '操作', hideable: false }]
  localStorage.setItem('txboard:admin:columns:required-columns', JSON.stringify(['email', 'role', 'actions']))
  function Harness() {
    const { hidden, onChange } = useColumnVisibility('required-columns', columns)
    return <><ColumnSettings columns={columns} hidden={hidden} onChange={onChange} /><output>{[...hidden].join(',')}</output></>
  }
  const { host, root } = mount(<Harness />)
  try {
    expect(host.querySelector('output')!.textContent).toBe('role')
    act(() => host.querySelector<HTMLButtonElement>('[aria-label="列设置"]')!.click())
    const inputs = host.querySelectorAll<HTMLInputElement>('.ui-column-settings input')
    expect(inputs[0].checked && inputs[0].disabled).toBe(true)
    expect(inputs[2].checked && inputs[2].disabled).toBe(true)
    expect(inputs[1].disabled).toBe(false)
    act(() => inputs[1].click())
    expect(host.querySelector('output')!.textContent).toBe('')
  } finally { act(() => root.unmount()); host.remove(); localStorage.removeItem('txboard:admin:columns:required-columns') }
})
