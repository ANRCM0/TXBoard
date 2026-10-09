import { nativeApiClient, nativeAdminPath, unwrapNative, type NativeApiEnvelope } from './client'

const base = () => nativeAdminPath('themes')
const themePath = (name: string) => {
  if (!/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/.test(name)) throw new Error('Invalid theme name')
  return base() + '/' + encodeURIComponent(name)
}

export type ThemeConfigField = {
  field_name: string
  group?: string
  description?: string
  label?: string
  field_type?: string
  placeholder?: string
  default_value?: unknown
  select_options?: Record<string, string> | Array<{ label: string; value: string | number }>
}

export type ThemeItem = {
  name?: string
  theme?: string
  title?: string
  description?: string
  version?: string
  images?: string[] | string
  image?: string
  preview?: string
  is_active?: boolean
  active?: boolean
  configs?: ThemeConfigField[]
  can_delete?: boolean
  is_system?: boolean
  [key: string]: unknown
}

type RawThemesResponse = {
  themes: ThemeItem[] | Record<string, ThemeItem>
  active?: string
}

export type ThemesResponse = {
  themes: ThemeItem[]
  active?: string
}

export async function getThemes() {
  const payload = await unwrapNative(nativeApiClient.get<NativeApiEnvelope<RawThemesResponse | ThemeItem[] | Record<string, ThemeItem>>>(
    base(),
  ))

  if (Array.isArray(payload)) {
    return { themes: payload, active: undefined } satisfies ThemesResponse
  }

  if (payload && typeof payload === 'object' && 'themes' in payload) {
    const current = payload as RawThemesResponse
    const active = current.active || 'TXBoard'
    const rawThemes = Array.isArray(current.themes)
      ? current.themes
      : Object.entries(current.themes || {})
        .filter((entry): entry is [string, ThemeItem] => typeof entry[1] === 'object' && entry[1] !== null)
        .map(([name, item]) => ({ name, ...item }))

    return {
      themes: rawThemes.map(theme => {
        const id = String(theme.name || theme.theme || theme.title || '')
        return {
          ...theme,
          is_active: Boolean(theme.is_active || theme.active || id === active),
        }
      }),
      active,
    } satisfies ThemesResponse
  }

  const themes = payload && typeof payload === 'object'
    ? Object.entries(payload)
      .filter((entry): entry is [string, ThemeItem] => typeof entry[1] === 'object' && entry[1] !== null)
      .map(([name, item]) => ({ name, ...item }))
    : []

  return { themes, active: undefined } satisfies ThemesResponse
}

export async function getThemeConfig(theme: string) {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<Record<string, unknown>>>(
    themePath(theme) + '/config',
  ))
}

export async function saveThemeConfig(theme: string, config: Record<string, unknown>) {
  return unwrapNative(nativeApiClient.put<NativeApiEnvelope<Record<string, unknown>>>(
    themePath(theme) + '/config', { config },
  ))
}

export async function uploadTheme(file: File) {
  const form = new FormData()
  form.append('file', file)
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    base() + '/upload', form, { headers: { 'Content-Type': 'multipart/form-data' } },
  ))
}

export async function deleteTheme(theme: string) {
  return unwrapNative(nativeApiClient.delete<NativeApiEnvelope<{ ok: boolean }>>(
    themePath(theme),
  ))
}
