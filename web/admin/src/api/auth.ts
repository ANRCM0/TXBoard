import { publicApiClient } from './client'
import { unwrap } from '../lib/api'
import type { CaptchaPayload } from './comm'

export type LoginResponse = {
  auth_data?: string
  token?: string
  access_token?: string
  is_admin?: boolean | number
  is_staff?: boolean | number
  secure_path?: string
  [key: string]: unknown
}

export async function login(email: string, password: string, captcha: CaptchaPayload = {}) {
  const { data } = await publicApiClient.post('/passport/auth/login', { email, password, ...captcha })
  return unwrap<LoginResponse>(data)
}
