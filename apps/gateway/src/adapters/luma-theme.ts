import { toPublicTheme } from './txboard-v1.js'

/**
 * adapters/luma-theme.ts — Luma 主题适配器（GW-217）。
 *
 * Luma 是一个独立主题，通过 Gateway 访问 TXBoard。此适配器扩展现有
 * toPublicTheme，添加 Luma 专用元数据，同时保持与现有主题系统的集成。
 *
 * 设计原则：
 * - 不修改现有 toPublicTheme 的行为（向后兼容）
 * - Luma 专用字段通过独立函数添加
 * - 所有函数都是纯函数，无 I/O 副作用
 */

/** Luma 主题配置元数据。 */
export type LumaThemeMeta = {
  /** 主题版本 */
  version: string
  /** 是否启用 Luma 渐进接入 */
  enabled: boolean
  /** 支持的特性列表 */
  features: string[]
}

/** Luma 主题配置响应。 */
export type LumaThemeConfig = {
  /** 主题名称 */
  name: string
  /** 主题配置 */
  config: Record<string, unknown>
  /** Luma 专用元数据 */
  luma: LumaThemeMeta
}

/**
 * 将上游主题配置转换为 Luma 专用格式。
 *
 * 此函数扩展 toPublicTheme，添加 Luma 渐进接入所需的元数据。
 * 现有主题客户端继续接收 toPublicTheme 的输出，不受影响。
 */
export function toLumaThemeConfig(data: unknown): LumaThemeConfig {
  const base = toPublicTheme(data)
  return {
    ...base,
    luma: {
      version: '1.0.0',
      enabled: true,
      features: [
        'theme.config',
        'bootstrap',
        'plans.list',
        'user.profile',
        'orders.list',
        'orders.detail',
        'payments.methods',
        'notices.list',
        'dashboard.stats',
      ],
    },
  }
}

/**
 * Luma 主题配置发现。
 *
 * 返回当前主题的 Luma 专用配置，供主题客户端初始化使用。
 * 此函数是纯函数，不执行 I/O。
 */
export function discoverLumaTheme(data: unknown): LumaThemeConfig {
  return toLumaThemeConfig(data)
}

/**
 * Luma 主题配置加载。
 *
 * 加载指定主题的配置。当前实现与 discoverLumaTheme 相同，
 * 但为未来扩展（如多主题支持）预留接口。
 */
export function loadLumaTheme(data: unknown, _themeName?: string): LumaThemeConfig {
  // 当前版本不支持多主题加载，忽略 themeName 参数
  // 未来可扩展为根据 themeName 加载不同主题配置
  return toLumaThemeConfig(data)
}

/**
 * 检查 Luma 主题是否支持指定特性。
 */
export function lumaSupports(config: LumaThemeConfig, feature: string): boolean {
  return config.luma.features.includes(feature)
}
