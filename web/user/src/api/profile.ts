import { api, request } from './client'

export async function changePassword(payload: { old_password: string; new_password: string }) {
  return request<null>(api.post('/user/changePassword', payload))
}

export async function updateUser(payload: Record<string, unknown>) {
  return request<null>(api.post('/user/update', payload))
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
  return request<ActiveSession[]>(api.get('/user/getActiveSession'))
}

export async function removeActiveSession(sessionId: string) {
  return request<null>(api.post('/user/removeActiveSession', { session_id: sessionId }))
}

export async function getQuickLoginUrl() {
  return request<string>(api.post('/user/getQuickLoginUrl'))
}
