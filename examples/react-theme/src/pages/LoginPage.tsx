// LoginPage: CAPTCHA-aware, fail-closed when CAPTCHA config is broken (GW-505).
import { useState, type FormEvent } from 'react'
import type { CaptchaConfig } from '@txboard/theme-sdk'
import { api, captchaBlocksLogin } from '../api'
import { useAuth } from '../hooks/useAuth'
import { useBootstrap } from '../hooks/useBootstrap'
import { CaptchaField } from '../components/CaptchaField'
import { ErrorState } from '../components/ErrorState'

export function LoginPage({ onDone }: { onDone: () => void }) {
  const auth = useAuth()
  const bootstrap = useBootstrap()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [captchaTokens, setCaptchaTokens] = useState<Record<string, string>>({})
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<Error | null>(null)

  const captcha: CaptchaConfig | null = bootstrap.data?.security.captcha ?? null
  const blocked = captchaBlocksLogin(captcha)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    if (blocked) return
    setLoading(true)
    setError(null)
    try {
      await auth.login({
        email,
        password,
        ...(captchaTokens.turnstile_token ? { turnstile_token: captchaTokens.turnstile_token } : {}),
        ...(captchaTokens.recaptcha_v3_token ? { recaptcha_v3_token: captchaTokens.recaptcha_v3_token } : {}),
        ...(captchaTokens.recaptcha_data ? { recaptcha_data: captchaTokens.recaptcha_data } : {}),
      })
      onDone()
    } catch (err) {
      setError(err as Error)
    } finally {
      setLoading(false)
    }
  }

  return (
    <form className="login" onSubmit={submit} noValidate>
      <h1>登录</h1>
      <label>邮箱地址
        <input type="email" value={email} onChange={e => setEmail(e.target.value)} autoComplete="off" required />
      </label>
      <label>密码
        <input type="password" value={password} onChange={e => setPassword(e.target.value)} autoComplete="off" required />
      </label>

      {captcha?.enabled && <CaptchaField captcha={captcha} />}

      <button className="primary" type="submit" disabled={blocked || loading}>
        {loading ? '登录中…' : '登录'}
      </button>

      <ErrorState error={error ?? bootstrap.error} />
    </form>
  )
}
