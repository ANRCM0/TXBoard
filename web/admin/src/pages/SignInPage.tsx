import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { useRef, useState } from 'react'
import { Eye, EyeOff, KeyRound, Mail } from 'lucide-react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { z } from 'zod'
import { loginAdmin } from '../native/session'
import { managementError } from '../native/Feedback'
import { CoreAdminApiError } from '../native/api'
import { fetchGuestConfig } from '../api/comm'
import { CaptchaWidget, type CaptchaWidgetHandle } from '../components/CaptchaWidget'
import { loginRedirect } from '../lib/basePath'
import { ThemeToggle } from '../components/ui/ThemeToggle'

const schema = z.object({
  email: z.string().trim().email('请输入有效邮箱'),
  password: z.string().min(8, '密码至少需要 8 个字符'),
})

type FormValues = z.infer<typeof schema>

export function SignInPage() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [passwordVisible, setPasswordVisible] = useState(false)
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '' },
  })
  const captchaRef = useRef<CaptchaWidgetHandle | null>(null)
  const captchaConfig = useQuery({
    queryKey: ['admin', 'guest-config'],
    queryFn: fetchGuestConfig,
    staleTime: 300_000,
    retry: false,
  })
  const captchaEnabled = Number(captchaConfig.data?.is_captcha || 0) === 1

  const mutation = useMutation({
    mutationFn: async (value: FormValues) => {
      const captcha = await captchaRef.current?.getPayload()
      try {
        return await loginAdmin({ email: value.email.trim(), password: value.password, ...captcha })
      } finally {
        captchaRef.current?.reset()
      }
    },
    onSuccess: () => {
      form.resetField('password')
      toast.success('登录成功')

      navigate(loginRedirect(params.get('redirect') || ''), { replace: true })
    },
    onError: error => {
      toast.error(error instanceof CoreAdminApiError && error.status === 401
        ? '邮箱、密码或验证码未通过验证。' : managementError(error))
    },
  })

  return (
    <div className="admin-auth-page">
      <div className="admin-auth-tools"><ThemeToggle /></div>
      <div className="admin-auth-wrap">
        <div className="admin-auth-card">
          <div className="admin-auth-brand">
            <div className="admin-brand-mark" aria-hidden="true">TX</div>
            <div><strong>TXBoard</strong><small>ADMIN CONSOLE</small></div>
          </div>
          <div className="admin-auth-card-head">
            <h2>管理员登录</h2>
            <p>欢迎回来，登录以管理你的站点</p>
          </div>

          <form onSubmit={form.handleSubmit(value => mutation.mutate(value))} className="admin-auth-form">
            <label>
              <span>邮箱</span>
              <div className="admin-auth-input"><Mail size={17} aria-hidden="true" />
              <input
                {...form.register('email')}
                aria-label="邮箱"
                type="text"
                autoComplete="email"
                placeholder="admin@example.com"
                autoFocus
                aria-invalid={!!form.formState.errors.email}
                aria-describedby={form.formState.errors.email ? 'admin-email-error' : undefined}
              />
              </div>
              {form.formState.errors.email ? <small id="admin-email-error">{form.formState.errors.email.message}</small> : null}
            </label>

            <label>
              <span>密码</span>
              <div className="admin-auth-input"><KeyRound size={17} aria-hidden="true" />
              <input
                {...form.register('password')}
                aria-label="密码"
                type={passwordVisible ? 'text' : 'password'}
                autoComplete="current-password"
                placeholder="请输入密码"
                aria-invalid={!!form.formState.errors.password}
                aria-describedby={form.formState.errors.password ? 'admin-password-error' : undefined}
              />
              <button type="button" className="admin-password-toggle" aria-label={passwordVisible ? '隐藏密码' : '显示密码'} aria-pressed={passwordVisible} onMouseDown={event => event.preventDefault()} onClick={() => setPasswordVisible(value => !value)}>{passwordVisible ? <EyeOff size={16} /> : <Eye size={16} />}</button>
              </div>
              {form.formState.errors.password ? <small id="admin-password-error">{form.formState.errors.password.message}</small> : null}
            </label>

            {captchaConfig.isError ? (
              <div role="alert" className="admin-auth-config-error">
                验证码配置加载失败，管理员登录已暂停。
                <button type="button" onClick={() => void captchaConfig.refetch()}>重新加载</button>
              </div>
            ) : null}
            {captchaEnabled ? (
              <CaptchaWidget ref={captchaRef} config={captchaConfig.data ?? null} />
            ) : null}
            <button type="submit" className="admin-auth-submit"
              disabled={mutation.isPending || captchaConfig.isPending || captchaConfig.isError}>
              {mutation.isPending ? '登录中…' : '登录'}
            </button>
          </form>
        </div>
      </div>
    </div>
  )
}
