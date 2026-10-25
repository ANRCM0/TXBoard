import { act, useLayoutEffect } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it } from 'vitest'
import { ColumnSettings, useColumnVisibility } from './ColumnSettings'

let host: HTMLDivElement, root: Root
const columns = [{ key: 'name', header: '名称' }, { key: 'state', header: '状态' }]
const prefix = 'txboard:admin:columns:'

beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
})
afterEach(() => {
  act(() => root.unmount()); host.remove()
  localStorage.removeItem(prefix + 'experience-a'); localStorage.removeItem(prefix + 'experience-b')
})

it('uses a new list preference in its first layout commit without leaking the previous list columns', () => {
  localStorage.setItem(prefix + 'experience-a', JSON.stringify(['name']))
  localStorage.setItem(prefix + 'experience-b', JSON.stringify(['state']))
  const observed: string[] = []
  function Harness({ scope }: { scope: string }) {
    const { hidden } = useColumnVisibility(scope, columns)
    useLayoutEffect(() => { observed.push(scope + ':' + [...hidden].join(',')) })
    return <output>{[...hidden].join(',')}</output>
  }
  act(() => root.render(<Harness scope="experience-a" />))
  act(() => root.render(<Harness scope="experience-b" />))
  expect(observed.filter(value => value.startsWith('experience-b:')).every(value => value === 'experience-b:state')).toBe(true)
})

it('syncs the current list across tabs and ignores unrelated preferences', () => {
  function Harness() {
    const { hidden, onChange } = useColumnVisibility('experience-a', columns)
    return <><ColumnSettings columns={columns} hidden={hidden} onChange={onChange} /><output>{[...hidden].join(',')}</output></>
  }
  act(() => root.render(<Harness />))
  localStorage.setItem(prefix + 'experience-a', JSON.stringify(['state']))
  act(() => window.dispatchEvent(new StorageEvent('storage', { key: prefix + 'experience-b' })))
  expect(host.querySelector('output')!.textContent).toBe('')
  act(() => window.dispatchEvent(new StorageEvent('storage', { key: prefix + 'experience-a' })))
  expect(host.querySelector('output')!.textContent).toBe('state')
  act(() => host.querySelector<HTMLButtonElement>('[aria-label="列设置"]')!.click())
  expect(host.querySelector('[role="status"]')?.textContent).toContain('1 / 2')
  localStorage.removeItem(prefix + 'experience-a')
  act(() => window.dispatchEvent(new StorageEvent('storage', { key: null })))
  expect(host.querySelector('output')!.textContent).toBe('')
})

it('keeps local column choices when no persistence scope is supplied', () => {
  function Harness() {
    const { hidden, onChange } = useColumnVisibility(undefined, columns)
    return <><button onClick={() => onChange(new Set(['state']))}>选择</button><output>{[...hidden].join(',')}</output></>
  }
  act(() => root.render(<Harness />))
  act(() => host.querySelector('button')!.click())
  act(() => window.dispatchEvent(new StorageEvent('storage', { key: null })))
  expect(host.querySelector('output')!.textContent).toBe('state')
})
