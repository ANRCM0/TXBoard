import { apiClient } from './client'
import { unwrap } from '../lib/api'

export type Settings = Record<string, unknown>

export async function fetchSettings(key: string) {
  const { data } = await apiClient.get('/config/fetch', { params: { key } })
  const payload = unwrap<Settings>(data) || {}
  const scoped = payload[key]
  if (scoped && typeof scoped === 'object' && !Array.isArray(scoped)) {
    return scoped as Settings
  }
  return payload
}

export async function saveSettings(payload: Settings) {
  const { data } = await apiClient.post('/config/save', payload)
  return unwrap(data)
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
