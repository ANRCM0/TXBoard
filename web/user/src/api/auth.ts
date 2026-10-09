import { api, nativeApi, nativeRequest, clearAuthData, request, saveAuthData } from './client'
import type { CaptchaPayload } from './comm'

export type LoginForm = { email: string; password: string; email_code?: string } & CaptchaPayload
export type RegisterForm = {
  email: string
  password: string
  invite_code?: string
  email_code?: string
} & CaptchaPayload
export type ForgetForm = {
  email: string
  password: string
  email_code: string
} & CaptchaPayload

type AuthPayload = { auth_data: string; is_admin?: boolean }

export async function login(form: LoginForm) {
  const result = await nativeRequest<AuthPayload>(nativeApi.post('/auth/login', form))
  saveAuthData(result.auth_data)
  return result
}

export async function register(form: RegisterForm) {
  const result = await nativeRequest<AuthPayload>(nativeApi.post('/auth/register', form))
  saveAuthData(result.auth_data)
  return result
}

export async function forgetPassword(form: ForgetForm) {
  return nativeRequest<{ ok: boolean }>(nativeApi.post('/auth/password/forgot', form))
}

export async function sendEmailVerify(email: string, purpose: 'register' | 'forget', captcha?: CaptchaPayload) {
  return nativeRequest<{ ok: boolean }>(nativeApi.post('/auth/email-code', {
    email, purpose, ...(captcha ?? {}),
  }))
}

export async function loginWithMailLink(email: string, captcha?: CaptchaPayload) {
  return nativeRequest<{ ok: boolean }>(nativeApi.post('/auth/mail-link', { email, ...captcha }))
}

export async function token2Login(verify: string) {
  // Never send a disposable login credential as a GET query parameter.
  const result = await nativeRequest<AuthPayload>(nativeApi.post('/auth/one-time-token', { verify }))
  if (!result || typeof result.auth_data !== 'string' || !result.auth_data) {
    throw new Error('Invalid TXAPI token login response')
  }
  saveAuthData(result.auth_data)
  return result
}

export async function telegramLogin(payload: Record<string, unknown>, endpoint: string) {
  const result = await request<AuthPayload>(api.post(endpoint, payload))
  saveAuthData(result.auth_data)
  return result
}

export async function logout() {
  try {
    await nativeRequest<{ ok: boolean }>(nativeApi.post('/auth/logout'))
  } catch {
    // Server revocation is best effort if offline. Avoid leaving the UI stuck
    // in an authenticated state when local credentials are already discarded.
  } finally {
    clearAuthData()
  }
}
