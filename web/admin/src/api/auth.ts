import { nativeApiClient, unwrapNative, type NativeApiEnvelope } from './client'
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
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<LoginResponse>>(
    '/auth/admin/login', { email, password, ...captcha },
  ))
}
