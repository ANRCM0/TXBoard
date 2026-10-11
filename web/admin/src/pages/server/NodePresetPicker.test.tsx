import { act, useState } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { Simulate } from 'react-dom/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { NodePresetPicker } from './NodePresetPicker'

const options = [
  { value:'aes-128-gcm', label:'AES 128' },
  { value:'aes-256-gcm', label:'AES 256' },
  { value:'chacha20-poly1305', label:'ChaCha 20' },
]

let host: HTMLDivElement
let root: Root

function Fixture({ initial = 'aes-128-gcm', allowCustom = true }: {initial?: string;allowCustom?: boolean}) {
  const [value, setValue] = useState(initial)
  return <NodePresetPicker label="加密方式" value={value} options={options}
    allowCustom={allowCustom} onChange={setValue}/>
}

async function mount(initial?: string, allowCustom = true) {
  await act(async () => {
    root.render(<Fixture initial={initial} allowCustom={allowCustom}/>)
    await new Promise(resolve => setTimeout(resolve, 0))
  })
}

beforeEach(() => {
  ;(globalThis as {IS_REACT_ACT_ENVIRONMENT?:boolean}).IS_REACT_ACT_ENVIRONMENT = true
  vi.clearAllMocks()
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
})
afterEach(() => {
  act(() => root.unmount())
  host.remove()
})

describe('NodePresetPicker accessible keyboard flow', () => {
  it('puts search and custom actions in a dialog, not inside the option listbox', async () => {
    await mount()
    const trigger = host.querySelector<HTMLButtonElement>('[aria-label="加密方式"]')!
    expect(trigger.getAttribute('aria-haspopup')).toBe('dialog')
    await act(async () => trigger.click())
    const popup = host.querySelector<HTMLElement>('[role="dialog"]')!
    const list = popup.querySelector<HTMLElement>('[role="listbox"]')!
    expect(trigger.getAttribute('aria-controls')).toBe(popup.id)
    expect(list.querySelectorAll('[role="option"]')).toHaveLength(3)
    expect(list.querySelector('input')).toBeNull()
    expect(list.querySelector('.node-preset-custom-option')).toBeNull()
    expect(popup.querySelector('input[type="search"]')).not.toBeNull()
    expect(popup.querySelector('.node-preset-custom-option')).not.toBeNull()
    expect(list.querySelectorAll('[aria-selected="true"]')).toHaveLength(1)
    expect(document.activeElement).toBe(popup.querySelector('input[type="search"]'))
  })

  it('supports search ArrowDown then option ArrowDown/End and returns focus when chosen', async () => {
    await mount()
    const trigger = host.querySelector<HTMLButtonElement>('[aria-label="加密方式"]')!
    await act(async () => trigger.click())
    const input = host.querySelector<HTMLInputElement>('.node-preset-search input')!
    await act(async () => Simulate.keyDown(input, { key:'ArrowDown' }))
    const entries = Array.from(host.querySelectorAll<HTMLButtonElement>('[role="option"]'))
    expect(document.activeElement).toBe(entries[0])
    await act(async () => Simulate.keyDown(entries[0], {key:'End'}))
    expect(document.activeElement).toBe(entries[2])
    await act(async () => entries[2].click())
    expect(host.querySelector('[role="dialog"]')).toBeNull()
    expect(trigger.textContent).toContain('ChaCha 20')
    expect(document.activeElement).toBe(trigger)
  })

  it('Escape closes the chooser and restores focus without altering the selected value', async () => {
    await mount()
    const trigger = host.querySelector<HTMLButtonElement>('[aria-label="加密方式"]')!
    await act(async () => trigger.click())
    const input = host.querySelector<HTMLInputElement>('.node-preset-search input')!
    input.focus()
    await act(async () => {
      document.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape',bubbles:true}))
    })
    expect(host.querySelector('[role="dialog"]')).toBeNull()
    expect(trigger.getAttribute('aria-expanded')).toBe('false')
    expect(trigger.textContent).toContain('AES 128')
    expect(document.activeElement).toBe(trigger)
  })

  it('focuses custom input after explicit custom choice; legacy values do not steal initial focus', async () => {
    await mount()
    const trigger = host.querySelector<HTMLButtonElement>('[aria-label="加密方式"]')!
    await act(async () => trigger.click())
    const custom = host.querySelector<HTMLButtonElement>('.node-preset-custom-option')!
    await act(async () => custom.click())
    const input = host.querySelector<HTMLInputElement>('input[aria-label="加密方式自定义值"]')!
    expect(document.activeElement).toBe(input)
    await act(async () => {
      input.value = 'custom-cipher'
      Simulate.change(input)
    })
    expect(input.value).toBe('custom-cipher')
    expect(host.querySelector('[role="dialog"]')).toBeNull()
  })

  it('preserves legacy unknown selections in the custom editor without auto-opening options', async () => {
    await mount('legacy-cipher')
    expect(host.querySelector<HTMLInputElement>('input[aria-label="加密方式自定义值"]')?.value).toBe('legacy-cipher')
    expect(host.querySelector('[role="dialog"]')).toBeNull()
    expect(host.textContent).toContain('已保留现有自定义配置')
  })
})
