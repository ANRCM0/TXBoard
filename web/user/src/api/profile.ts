import { api, nativeApi, nativeRequest, request } from './client'

export async function changePassword(payload: { old_password: string; new_password: string }) {
  return nativeRequest<{ ok: boolean }>(nativeApi.post('/auth/password', payload))
}

export type UserPreferences = { remind_expire: boolean; remind_traffic: boolean }

export async function getUserPreferences(): Promise<UserPreferences> {
  return nativeRequest<UserPreferences>(nativeApi.get('/me/preferences'))
}

export async function updateUserPreferences(payload: UserPreferences): Promise<UserPreferences> {
  return nativeRequest<UserPreferences>(nativeApi.patch('/me/preferences', payload))
}

export async function resetSecurity() {
  return request<string>(api.get('/user/resetSecurity'))
}

export type ActiveSession = {
  id: number
  name: string
  abilities: string[]
  last_used_at: string | null
  created_at: string
  updated_at: string
  expires_at: string | null
}

export async function getActiveSessions() {
  return nativeRequest<ActiveSession[]>(nativeApi.get('/auth/sessions'))
}

export async function removeActiveSession(sessionId: string) {
  return nativeRequest<{ ok: boolean }>(nativeApi.delete('/auth/sessions/' + encodeURIComponent(sessionId)))
}

export async function getQuickLoginUrl() {
  return request<string>(api.post('/user/getQuickLoginUrl'))
}
