import type { BootstrapData, ThemeData } from './index.js'

/**
 * luma.ts — Luma 主题专用 SDK 方法（GW-217）。
 *
 * 这些方法支持 Luma 渐进接入，允许主题客户端发现和加载 Luma 主题配置。
 * 与现有主题系统集成，不破坏现有 API。
 */

/** Luma 主题配置。 */
export type LumaThemeConfig = {
  name: string
  config: Record<string, unknown>
  luma: {
    version: string
    enabled: boolean
    features: string[]
  }
}

/** Luma 特性标志。 */
export type LumaFeatureFlag = {
  /** 是否启用 Luma 主题 */
  enabled: boolean
  /** 启用的特性列表 */
  features: string[]
}

/**
 * 从 Bootstrap 数据中提取 Luma 主题配置。
 *
 * 这是纯函数，不执行 I/O。如果 Bootstrap 数据不包含 Luma 配置，
 * 返回 null。
 */
export function extractLumaTheme(bootstrap: BootstrapData): LumaThemeConfig | null {
  if (bootstrap.theme.name === 'Luma' || (bootstrap as { luma?: unknown }).luma) {
    const lumaData = (bootstrap as unknown as { luma?: { version?: string; enabled?: boolean; features?: string[] } }).luma
    return {
      name: bootstrap.theme.name,
      config: bootstrap.theme.config,
      luma: {
        version: lumaData?.version ?? '1.0.0',
        enabled: lumaData?.enabled ?? true,
        features: lumaData?.features ?? ['theme.config', 'bootstrap'],
      },
    }
  }
  return null
}

/**
 * Luma 主题配置发现。
 *
 * 从 Gateway 获取 Luma 主题配置。使用现有 bootstrap 端点，
 * 因为 Luma 配置已包含在 bootstrap 响应中。
 */
export async function discoverLumaTheme(
  fetchImpl: typeof fetch,
  baseURL: string,
): Promise<LumaThemeConfig> {
  const response = await fetchImpl(`${baseURL}/bootstrap`, {
    method: 'GET',
    headers: { Accept: 'application/json' },
    redirect: 'error',
    credentials: 'omit',
  })
  if (!response.ok) {
    throw new Error(`Luma theme discovery failed: ${response.status}`)
  }
  const envelope = (await response.json()) as {
    ok: boolean
    data: BootstrapData & { luma?: { version?: string; enabled?: boolean; features?: string[] } }
  }
  if (!envelope.ok || !envelope.data) {
    throw new Error('Invalid Gateway response')
  }
  const config = extractLumaTheme(envelope.data)
  if (!config) {
    throw new Error('Luma theme not available')
  }
  return config
}

/**
 * Luma 主题配置加载。
 *
 * 加载指定主题的配置。当前实现与 discoverLumaTheme 相同，
 * 但为未来扩展（如多主题支持）预留接口。
 */
export async function loadLumaTheme(
  fetchImpl: typeof fetch,
  baseURL: string,
  _themeName?: string,
): Promise<LumaThemeConfig> {
  // 当前版本不支持多主题加载，忽略 themeName 参数
  // 未来可扩展为根据 themeName 加载不同主题配置
  return discoverLumaTheme(fetchImpl, baseURL)
}

/**
 * 创建 Luma 特性标志。
 *
 * 根据主题配置生成特性标志，用于控制 Luma 特性的启用/禁用。
 */
export function createLumaFeatureFlag(config: LumaThemeConfig): LumaFeatureFlag {
  return {
    enabled: config.luma.enabled,
    features: config.luma.features,
  }
}

/**
 * 检查是否支持指定 Luma 特性。
 */
export function supportsLumaFeature(config: LumaThemeConfig, feature: string): boolean {
  return config.luma.features.includes(feature)
}
