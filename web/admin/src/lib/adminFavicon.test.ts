import { afterEach, describe, expect, it } from 'vitest'
import { setAdminFavicon } from './adminFavicon'

afterEach(() => {
  document.getElementById('txboard-admin-favicon')?.remove()
})

describe('administrator favicon', () => {
  it('applies a saved URL without a page reload', () => {
    setAdminFavicon('https://assets.example.test/favicon.ico')
    const link = document.querySelector<HTMLLinkElement>('#txboard-admin-favicon')
    expect(link?.rel).toBe('icon')
    expect(link?.href).toBe('https://assets.example.test/favicon.ico')
    expect(link?.referrerPolicy).toBe('no-referrer')
  })

  it('replaces and clears an existing icon element', () => {
    setAdminFavicon('https://assets.example.test/old.png')
    setAdminFavicon('https://assets.example.test/new.png')
    expect(document.querySelectorAll('#txboard-admin-favicon')).toHaveLength(1)
    expect(document.querySelector<HTMLLinkElement>('#txboard-admin-favicon')?.href)
      .toBe('https://assets.example.test/new.png')
    setAdminFavicon('')
    expect(document.getElementById('txboard-admin-favicon')).toBeNull()
  })
})
