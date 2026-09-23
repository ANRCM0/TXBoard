import { describe, expect, it } from 'vitest'
import {
  formatJsonTemplate,
  isValidJsonTemplate,
  normalizeSubscribeTemplates,
  subscribeTemplateTabs,
} from './subscribeTemplateModel'

describe('subscription template editor model', () => {
  it('exposes the six backend-supported template types in UI order', () => {
    expect(subscribeTemplateTabs.map(tab => tab.label)).toEqual([
      'Sing-box',
      'Clash',
      'Clash Meta',
      'Stash',
      'Surge',
      'Surfboard',
    ])
  })

  it('normalizes missing or non-string values without inventing state', () => {
    const result = normalizeSubscribeTemplates({
      subscribe_template_singbox: '{"dns":{}}',
      subscribe_template_clash: 'rules: []',
      subscribe_template_stash: null,
    })

    expect(result.subscribe_template_singbox).toBe('{"dns":{}}')
    expect(result.subscribe_template_clash).toBe('rules: []')
    expect(result.subscribe_template_stash).toBe('')
    expect(result.subscribe_template_surge).toBe('')
  })

  it('formats valid Sing-box JSON and detects invalid JSON', () => {
    expect(formatJsonTemplate('{"dns":{"rules":[]}}')).toBe(
      '{\n  "dns": {\n    "rules": []\n  }\n}',
    )
    expect(isValidJsonTemplate('{"dns":{}}')).toBe(true)
    expect(isValidJsonTemplate('{dns:}')).toBe(false)
    expect(isValidJsonTemplate('')).toBe(true)
  })
})
