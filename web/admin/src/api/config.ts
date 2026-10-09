import { apiClient, nativeApiClient, nativeAdminPath, unwrapNative, setAdminSecurePath, type NativeApiEnvelope } from './client'
import { unwrap } from '../lib/api'

export type Settings = Record<string, unknown>

export async function fetchSettings(key: string) {
  const payload = (await unwrapNative(nativeApiClient.get<NativeApiEnvelope<Settings>>(
    nativeAdminPath('settings') + '/' + encodeURIComponent(key),
  ))) || {}
  const scoped = payload[key]
  if (scoped && typeof scoped === 'object' && !Array.isArray(scoped)) {
    return scoped as Settings
  }
  return payload
}

export async function saveSettings(payload: Settings) {
  const saved = await unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('settings'), payload,
  ))

  // The backend validates {admin_path} against the current setting on every
  // request. Re-point the client only after the rotation request succeeds so
  // the next admin call immediately uses the new path without a re-login.
  const nextSecurePath = typeof payload.secure_path === 'string'
    ? payload.secure_path.trim()
    : ''
  if (nextSecurePath) setAdminSecurePath(nextSecurePath)

  if (saved?.ok !== true) throw new Error('Native administrator settings save not acknowledged')
  return true
}

export async function testSendMail(payload: Settings = {}) {
  const { data } = await apiClient.post('/config/testSendMail', payload)
  return unwrap(data)
}

export async function setTelegramWebhook(telegramBotToken: string) {
  const { data } = await apiClient.post('/config/setTelegramWebhook', {
    telegram_bot_token: telegramBotToken,
  })
  return unwrap(data)
}
