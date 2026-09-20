import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { z } from 'zod'
import { login } from '../api/auth'
import { setAccessToken } from '../lib/storage'

const schema = z.object({
  email: z.string().min(1, '请输入邮箱'),
  password: z.string().min(1, '请输入密码'),
})

type FormValues = z.infer<typeof schema>

export function SignInPage() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '' },
  })

  const mutation = useMutation({
    mutationFn: (value: FormValues) => login(value.email, value.password),
    onSuccess: data => {
      if (!Boolean(data.is_admin)) {
        toast.error('该账号不是管理员，无法进入管理后台')
        return
      }

      const authorization = String(data.auth_data || data.access_token || '')
      if (!authorization) {
        toast.error('登录成功响应中未返回管理员 Sanctum auth_data')
        return
      }

      setAccessToken(authorization)
      toast.success('登录成功')
      navigate(params.get('redirect') || '/config/system', { replace: true })
    },
    onError: error => {
      toast.error(error instanceof Error ? error.message : '登录失败')
    },
  })

  return (
    <div className="admin-auth-page">
      <div className="admin-auth-wrap">
        <div className="admin-auth-brand">
          <h1>TXBoard</h1>
          <p>Xboard 管理中心</p>
        </div>

        <div className="admin-auth-card">
          <div className="admin-auth-card-head">
            <h2>管理员登录</h2>
            <p>使用管理员账号登录控制台</p>
          </div>

          <form onSubmit={form.handleSubmit(value => mutation.mutate(value))} className="admin-auth-form">
            <label>
              <span>邮箱</span>
              <input
                {...form.register('email')}
                type="text"
                autoComplete="email"
                placeholder="admin@example.com"
                autoFocus
              />
              {form.formState.errors.email ? <small>{form.formState.errors.email.message}</small> : null}
            </label>

            <label>
              <span>密码</span>
              <input
                {...form.register('password')}
                type="password"
                autoComplete="current-password"
                placeholder="请输入密码"
              />
              {form.formState.errors.password ? <small>{form.formState.errors.password.message}</small> : null}
            </label>

            <button type="submit" className="admin-auth-submit" disabled={mutation.isPending}>
              {mutation.isPending ? '登录中…' : '登录'}
            </button>
          </form>
        </div>
      </div>
    </div>
  )
}
