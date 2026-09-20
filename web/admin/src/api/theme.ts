import { apiClient } from './client'
import { unwrap } from '../lib/api'

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
  configs?: unknown[]
  [key: string]: unknown
}

export type ThemesResponse = {
  themes: ThemeItem[]
  active?: string
}

export async function getThemes() {
  const { data } = await apiClient.get('/theme/getThemes')
  const payload = unwrap<ThemesResponse | ThemeItem[] | Record<string, ThemeItem>>(data)

  if (Array.isArray(payload)) {
    return { themes: payload, active: undefined } satisfies ThemesResponse
  }

  if (payload && typeof payload === 'object' && 'themes' in payload) {
    const current = payload as ThemesResponse
    const active = current.active
    return {
      themes: (Array.isArray(current.themes) ? current.themes : []).map(theme => ({
        ...theme,
        is_active: Boolean(theme.is_active || theme.active || (active && theme.name === active)),
      })),
      active,
    } satisfies ThemesResponse
  }

  const themes = payload && typeof payload === 'object'
    ? Object.entries(payload).map(([name, item]) => ({ name, ...item }))
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
