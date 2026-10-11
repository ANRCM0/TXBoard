// Session hook: owns token lifetime and login/logout (GW-505).
import { useCallback, useState } from 'react'
import type { LoginPayload, RegistrationPayload, UserAuth } from '@txboard/theme-sdk'
import { api, clearToken, readToken, writeToken } from '../api'

export function useAuth() {
  const [token, setToken] = useState(readToken)

  const login = useCallback(async (payload: LoginPayload): Promise<UserAuth> => {
    const result = await api.auth.login(payload)
    writeToken(result.auth_data)
    setToken(readToken())
    return result
  }, [])

  const register = useCallback(async (payload: RegistrationPayload): Promise<UserAuth> => {
    const result = await api.auth.register(payload)
    writeToken(result.auth_data)
    setToken(readToken())
    return result
  }, [])

  const logout = useCallback(() => {
    clearToken()
    setToken('')
  }, [])

  return { token, isAuthed: token.length > 0, login, register, logout }
}
