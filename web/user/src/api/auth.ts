import { api, clearAuthData, request, saveAuthData } from './client'
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
  const result = await request<AuthPayload>(api.post('/passport/auth/login', form))
  saveAuthData(result.auth_data)
  return result
}

export async function register(form: RegisterForm) {
  const result = await request<AuthPayload>(api.post('/passport/auth/register', form))
  saveAuthData(result.auth_data)
  return result
}

export async function forgetPassword(form: ForgetForm) {
  return request<boolean>(api.post('/passport/auth/forget', form))
}

function verifyCaptchaPayload(captcha?: CaptchaPayload) {
  if (!captcha) return {}
  if (captcha.skip_recaptcha_v3 || captcha.skip_recaptcha_v3_error) {
    return { skip_recaptcha_v3: 1 }
  }
  const { skip_recaptcha_v3: _a, skip_recaptcha_v3_error: _b, ...rest } = captcha
  return rest
}

export async function sendEmailVerify(email: string, purpose: 'register' | 'forget', captcha?: CaptchaPayload) {
  return request<null>(api.post('/passport/comm/sendEmailVerify', {
    email,
    purpose,
    ...verifyCaptchaPayload(captcha),
  }))
}

export async function loginWithMailLink(email: string, captcha?: CaptchaPayload) {
  return request<boolean>(api.post('/passport/auth/loginWithMailLink', { email, ...captcha }))
}

export async function token2Login(verify: string) {
  const { data } = await api.get<{ data?: AuthPayload; message?: string }>('/passport/auth/token2Login', {
    params: { verify },
  })
  if (data.data?.auth_data) {
    saveAuthData(data.data.auth_data)
    return data.data
  }
  throw new Error(data.message || 'Token login failed')
}

export async function telegramLogin(payload: Record<string, unknown>, endpoint: string) {
  const result = await request<AuthPayload>(api.post(endpoint, payload))
  saveAuthData(result.auth_data)
  return result
}

export async function logout() {
  // Current Xboard core has no /user/logout route. Clearing the local
  // Sanctum bearer is the supported frontend logout behavior.
  clearAuthData()
}
