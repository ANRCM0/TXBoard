import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it } from 'vitest'
import { Field, Input } from './Field'

let host: HTMLDivElement, root: Root
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
})
afterEach(() => { act(() => root.unmount()); host.remove() })

it('retains instructions alongside an error and preserves caller descriptions', () => {
  act(() => root.render(<><p id="external-help">Existing instructions</p><Field label="Host" hint="Use a host name without a scheme" error="Enter a host name"><Input id="host" required aria-describedby="external-help" /></Field></>))
  const input = host.querySelector('input')!
  expect(input.getAttribute('aria-describedby')?.split(' ')).toEqual(['external-help', 'host-hint', 'host-error'])
  expect(input.getAttribute('aria-errormessage')).toBe('host-error')
  expect(input.getAttribute('aria-invalid')).toBe('true')
  expect(host.querySelector('#host-hint')?.textContent).toBe('Use a host name without a scheme')
  expect(host.querySelector('.ui-required')).not.toBeNull()
})

it('removes only its own error association after correction', () => {
  act(() => root.render(<Field label="Host" error="Invalid host"><Input id="host" aria-errormessage="server-error" aria-describedby="external-help" /></Field>))
  act(() => root.render(<Field label="Host" hint="Host instructions"><Input id="host" aria-errormessage="server-error" aria-describedby="external-help" /></Field>))
  const input = host.querySelector('input')!
  expect(input.getAttribute('aria-errormessage')).toBe('server-error')
  expect(input.getAttribute('aria-describedby')).toBe('external-help host-hint')
  expect(input.hasAttribute('aria-invalid')).toBe(false)
  expect(host.querySelector('[role="alert"]')).toBeNull()
})
