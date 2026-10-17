// Session composable: owns token lifetime, login/logout, 401 handling.
import { computed, ref } from 'vue'
import type { LoginPayload, UserAuth } from '@txboard/theme-sdk'
import { api, clearToken, readToken, writeToken } from '../api'

const token = ref(readToken())
export const isAuthed = computed(() => token.value.length > 0)

export function useAuth() {
  async function login(payload: LoginPayload): Promise<UserAuth> {
    const result = await api.auth.login(payload)
    // Gateway returns only auth_data (+ optional is_admin); it never
    // returns the legacy `token` or `secure_path` fields.
    writeToken(result.auth_data)
    token.value = readToken()
    return result
  }

  async function register(payload: Parameters<typeof api.auth.register>[0]) {
    const result = await api.auth.register(payload)
    writeToken(result.auth_data)
    token.value = readToken()
    return result
  }

  function logout(): void {
    clearToken()
    token.value = ''
  }

  return { token, isAuthed, login, register, logout }
}
