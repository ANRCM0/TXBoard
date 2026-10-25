import { t, useLocale } from '../lib/i18n'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { useEffect, useRef, useState } from 'react'
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
import { useSiteBranding } from '../lib/useSiteBranding'
import { SiteMark } from '../components/layout/SiteMark'
import { Modal } from '../components/ui/Modal'
import { CopyButton } from '../components/ui/CopyButton'
import { LanguageSelector } from '../components/ui/LanguageSelector'
import { SiteAppearanceConsumer, SiteLoginBackground } from '../lib/useSiteAppearance'
import '../components/layout/experience-navigation.css'

const schema = z.object({
  email: z.string().trim().email('请输入有效邮箱'),
  password: z.string().min(8, '密码至少需要 8 个字符'),
})

type FormValues = z.infer<typeof schema>

export function SignInPage() {
  useLocale()
  const site = useSiteBranding(t('管理员登录'))
  const [help, setHelp] = useState(false)
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [passwordVisible, setPasswordVisible] = useState(false)
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '' },
  })
  const captchaRef = useRef<CaptchaWidgetHandle | null>(null)
  const errorRef = useRef<HTMLDivElement>(null)
  const submitting = useRef(false)
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
      toast.success(t('登录成功'))

      navigate(loginRedirect(params.get('redirect') || ''), { replace: true })
    },
    onError: error => {
      toast.error(error instanceof CoreAdminApiError && error.status === 401
        ? t('邮箱、密码或验证码未通过验证。') : managementError(error))
    },
  })
  const loginError = mutation.isError
    ? mutation.error instanceof CoreAdminApiError && mutation.error.status === 401
      ? t('邮箱、密码或验证码未通过验证。') : managementError(mutation.error)
    : ''

  useEffect(() => { if (mutation.isError) errorRef.current?.focus() }, [mutation.isError])

  const submit = form.handleSubmit(value => {
    if (submitting.current || !captchaConfig.isSuccess) return
    submitting.current = true
    mutation.mutate(value, { onSettled: () => { submitting.current = false } })
  })

  return (
    <div className="admin-auth-page">
      <SiteAppearanceConsumer /><SiteLoginBackground />
      <div className="admin-auth-tools"><LanguageSelector /><ThemeToggle /></div>
      <div className="admin-auth-shell">
        <aside className="admin-auth-aside">
          <div className="admin-auth-aside-brand">
            <SiteMark logo={site.logo} />
            <div><strong>{site.name}</strong><small>ADMIN CONSOLE</small></div>
          </div>
          <div className="admin-auth-aside-copy">
            <span>{t('管理控制台')}</span>
            <p>{site.description || t('欢迎回来，登录以管理你的站点')}</p>
          </div>
          <div className="admin-auth-aside-foot"><span>TXBoard</span><i aria-hidden="true" /><span>ADMIN CONSOLE</span></div>
        </aside>
        <div className="admin-auth-wrap">
        <div className="admin-auth-card">
          <div className="admin-auth-brand">
            <SiteMark logo={site.logo} />
            <div><strong>{site.name}</strong><small>ADMIN CONSOLE</small></div>
          </div>
          <div className="admin-auth-card-head">
            <h2>{t("管理员登录")}</h2>
            <p>{site.description || t('欢迎回来，登录以管理你的站点')}</p>
          </div>

          <form onSubmit={submit} className="admin-auth-form" aria-busy={mutation.isPending}
            onChange={() => { if (mutation.isError) mutation.reset() }}>
            <label>
              <span>{t("邮箱")}</span>
              <div className="admin-auth-input"><Mail size={17} aria-hidden="true" />
              <input
                {...form.register('email')}
                aria-label={t("邮箱")}
                type="text"
                autoComplete="email"
                placeholder="admin@example.com"
                autoFocus
                disabled={mutation.isPending}
                aria-invalid={!!form.formState.errors.email}
                aria-describedby={form.formState.errors.email ? 'admin-email-error' : undefined}
              />
              </div>
              {form.formState.errors.email ? <small id="admin-email-error">{t(form.formState.errors.email.message ?? '')}</small> : null}
            </label>

            <label>
              <span>{t("密码")}</span>
              <div className="admin-auth-input"><KeyRound size={17} aria-hidden="true" />
              <input
                {...form.register('password')}
                aria-label={t("密码")}
                type={passwordVisible ? 'text' : 'password'}
                autoComplete="current-password"
                disabled={mutation.isPending}
                placeholder={t("请输入密码")}
                aria-invalid={!!form.formState.errors.password}
                aria-describedby={form.formState.errors.password ? 'admin-password-error' : undefined}
              />
              <button type="button" disabled={mutation.isPending} className="admin-password-toggle" aria-label={t(passwordVisible ? '隐藏密码' : '显示密码')} aria-pressed={passwordVisible} onMouseDown={event => event.preventDefault()} onClick={() => setPasswordVisible(value => !value)}>{passwordVisible ? <EyeOff size={16} /> : <Eye size={16} />}</button>
              </div>
              {form.formState.errors.password ? <small id="admin-password-error">{t(form.formState.errors.password.message ?? '')}</small> : null}
            </label>

            {captchaConfig.isError ? (
              <div role="alert" className="admin-auth-config-error">{t("验证码配置加载失败，管理员登录已暂停。")}<button type="button" onClick={() => void captchaConfig.refetch()}>{t("重新加载")}</button>
              </div>
            ) : null}
            {captchaEnabled ? (
              <CaptchaWidget ref={captchaRef} config={captchaConfig.data ?? null} />
            ) : null}
            {loginError ? <div ref={errorRef} tabIndex={-1} role="alert" className="admin-auth-request-error">{loginError}</div> : null}
            <button type="submit" className="admin-auth-submit"
              disabled={mutation.isPending || captchaConfig.isPending || captchaConfig.isError}>
              {t(mutation.isPending ? '登录中…' : '登录')}
            </button>
          </form>
          <button className="admin-auth-help" type="button" onClick={() => setHelp(true)}>{t("无法登录？查看恢复帮助")}</button>
        </div>
        </div>
      </div>
      <Modal open={help} title={t("管理员登录帮助")} onClose={() => setHelp(false)}>
        <div className="admin-login-help">
          <p>{t("请先确认邮箱、密码、系统时间和验证码。如果验证码无法加载，可联系站点运维检查服务配置。")}</p>
          <p>{t("遗失管理员凭据时，请由部署维护者通过当前安装版本支持的身份恢复流程处理，并核实管理员身份。恢复后重新登录，并检查已有登录会话。")}</p>
          <p>{t("联系运维时可提供站点、出错时间和页面提示。请勿发送密码、验证码、会话令牌或连接凭据。")}</p>
          <CopyButton value={t('站点：{name}\n登录地址：{url}\n问题：管理员登录失败\n发生时间：{time}', { name: site.name, url: window.location.origin + window.location.pathname, time: new Date().toISOString() })} label={t("复制运维联系说明")} />
        </div>
      </Modal>
    </div>
  )
}
