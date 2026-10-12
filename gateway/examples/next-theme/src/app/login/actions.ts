// Login server action (GW-506).
//
// Token isolation rules:
//  1. The login POST happens SERVER-SIDE inside this action. The raw
//     auth_data is never sent to the browser as JSON, never placed in the
//     RSC payload, and never written to any browser-accessible storage.
//  2. The token is stored ONLY in an httpOnly, secure, sameSite=lax cookie.
//     Browser JavaScript cannot read it (structural XSS token theft defense).
//  3. Every SDK call elsewhere in the app creates a fresh request-scoped
//     client via createClient() reading THIS request's cookie — no module
//     singleton, so no cross-request session pollution. The token is never
//     written to any browser-accessible storage.
'use server'

import { cookies } from 'next/headers'
import { redirect } from 'next/navigation'
import { createClient, SESSION_COOKIE } from '@/lib/server'
import type { LoginPayload } from '@txboard/theme-sdk'

const SESSION_MAX_AGE = 60 * 60 * 24 * 7 // 7 days

export async function loginAction(formData: FormData): Promise<void> {
  const email = String(formData.get('email') || '')
  const password = String(formData.get('password') || '')
  if (!email || !password) return

  const api = createClient()
  const result = await api.auth.login({ email, password } as LoginPayload)

  cookies().set(SESSION_COOKIE, result.auth_data, {
    httpOnly: true,
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'lax',
    path: '/',
    maxAge: SESSION_MAX_AGE,
  })
  redirect('/dashboard')
}

export async function logoutAction(): Promise<void> {
  cookies().delete(SESSION_COOKIE)
  redirect('/')
}
