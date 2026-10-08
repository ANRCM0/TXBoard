import { apiClient } from './client'
import { unwrap } from '../lib/api'

export type ThemeConfigField = {
  field_name: string
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
  const { data } = await apiClient.get('/theme/getThemes')
  const payload = unwrap<RawThemesResponse | ThemeItem[] | Record<string, ThemeItem>>(data)

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
  const { data } = await apiClient.post('/theme/getThemeConfig', { name: theme })
  return unwrap<Record<string, unknown>>(data)
}

export async function saveThemeConfig(theme: string, config: Record<string, unknown>) {
  const { data } = await apiClient.post('/theme/saveThemeConfig', { name: theme, config })
  return unwrap<Record<string, unknown>>(data)
}

export async function uploadTheme(file: File) {
  const form = new FormData()
  form.append('file', file)
  const { data } = await apiClient.post('/theme/upload', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return unwrap(data)
}

export async function deleteTheme(theme: string) {
  const { data } = await apiClient.post('/theme/delete', { name: theme })
  return unwrap(data)
}
