import { publicApiClient } from './client'
import { unwrap } from '../lib/api'

export type LoginResponse = {
  auth_data?: string
  token?: string
  access_token?: string
  is_admin?: boolean | number
  is_staff?: boolean | number
  [key: string]: unknown
}

export async function login(email: string, password: string) {
  const { data } = await publicApiClient.post('/passport/auth/login', { email, password })
  return unwrap<LoginResponse>(data)
}
