export const subscribeTemplateTabs = [
  {
    key: 'subscribe_template_singbox',
    id: 'singbox',
    label: 'Sing-box',
    format: 'json',
    description: '配置 Sing-box 客户端使用的订阅模板。',
  },
  {
    key: 'subscribe_template_clash',
    id: 'clash',
    label: 'Clash',
    format: 'text',
    description: '配置 Clash 客户端使用的订阅模板。',
  },
  {
    key: 'subscribe_template_clashmeta',
    id: 'clash-meta',
    label: 'Clash Meta',
    format: 'text',
    description: '配置 Clash Meta / Mihomo 客户端使用的订阅模板。',
  },
  {
    key: 'subscribe_template_stash',
    id: 'stash',
    label: 'Stash',
    format: 'text',
    description: '配置 Stash 客户端使用的订阅模板。',
  },
  {
    key: 'subscribe_template_surge',
    id: 'surge',
    label: 'Surge',
    format: 'text',
    description: '配置 Surge 客户端使用的订阅模板。',
  },
  {
    key: 'subscribe_template_surfboard',
    id: 'surfboard',
    label: 'Surfboard',
    format: 'text',
    description: '配置 Surfboard 客户端使用的订阅模板。',
  },
] as const

export type SubscribeTemplateTab = (typeof subscribeTemplateTabs)[number]
export type SubscribeTemplateKey = SubscribeTemplateTab['key']
export type SubscribeTemplateMap = Record<SubscribeTemplateKey, string>

export function normalizeSubscribeTemplates(
  value: Record<string, unknown> | null | undefined,
): SubscribeTemplateMap {
  return Object.fromEntries(
    subscribeTemplateTabs.map(tab => [
      tab.key,
      typeof value?.[tab.key] === 'string' ? value[tab.key] : '',
    ]),
  ) as SubscribeTemplateMap
}

export function formatJsonTemplate(value: string): string {
  const parsed = JSON.parse(value)
  return JSON.stringify(parsed, null, 2)
}

export function isValidJsonTemplate(value: string): boolean {
  if (!value.trim()) return true
  try {
    JSON.parse(value)
    return true
  } catch {
    return false
  }
}
