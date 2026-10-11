import { describe, expect, it } from 'vitest'
import entryHtml from '../index.html?raw'

describe('admin browser tab metadata', () => {
  it('uses native TXBoard branding in the shipped HTML entry', () => {
    const html = new DOMParser().parseFromString(entryHtml, 'text/html')
    expect(html.title).toBe('TXBoard Admin')
    expect(html.title).not.toMatch(/xboard|replica/i)
  })
})
