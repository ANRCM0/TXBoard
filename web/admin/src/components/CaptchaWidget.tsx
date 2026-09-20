import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react'
import type { CaptchaPayload, GuestConfig } from '../api/comm'

const TURNSTILE_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js'
const RECAPTCHA_SRC = 'https://www.google.com/recaptcha/api.js'

type TurnstileApi = {
  render: (target: string, options: Record<string, unknown>) => string
  getResponse: (id?: string) => string
  reset: (id?: string) => void
}

type ReCaptchaApi = {
  render: (target: string, options: Record<string, unknown>) => number
  getResponse: (id?: number) => string
  reset: (id?: number) => void
  ready: (callback: () => void) => void
  execute: (siteKey: string, options: { action: string }) => Promise<string>
}

type CaptchaWindow = Window & { turnstile?: TurnstileApi; grecaptcha?: ReCaptchaApi }

function captchaWindow() {
  return window as CaptchaWindow
}

function loadScript(src: string, id: string) {
  return new Promise<void>((resolve, reject) => {
    if (document.getElementById(id)) {
      resolve()
      return
    }
    const script = document.createElement('script')
    script.id = id
    script.src = src
    script.async = true
    script.defer = true
    script.onload = () => resolve()
    script.onerror = () => reject(new Error('Failed to load captcha script'))
    document.head.appendChild(script)
  })
}

function captchaType(config: GuestConfig | null) {
  return config?.captcha_type || 'recaptcha'
}

export function captchaSiteKey(config: GuestConfig | null) {
  if (!config || Number(config.is_captcha || 0) !== 1) return ''
  const type = captchaType(config)
  if (type === 'turnstile') return String(config.turnstile_site_key || '')
  if (type === 'recaptcha-v3') return String(config.recaptcha_v3_site_key || '')
  return String(config.recaptcha_site_key || '')
}

export type CaptchaWidgetHandle = {
  getPayload: () => Promise<CaptchaPayload>
  reset: () => void
}

export const CaptchaWidget = forwardRef<CaptchaWidgetHandle, { config: GuestConfig | null }>(
  function CaptchaWidget({ config }, ref) {
    const [containerId] = useState(() => 'admin-captcha-' + Math.random().toString(36).slice(2))
    const widgetId = useRef<number | string | null>(null)
    const [ready, setReady] = useState(false)
    const type = captchaType(config)
    const siteKey = captchaSiteKey(config)
    const active = Boolean(siteKey)

    useEffect(() => {
      widgetId.current = null
      setReady(false)
      if (!siteKey) return
      let cancelled = false
      const mount = async () => {
        try {
          if (type === 'turnstile') {
            await loadScript(TURNSTILE_SRC, 'admin-cf-turnstile')
            if (cancelled) return
            const api = captchaWindow().turnstile
            if (!api) return
            widgetId.current = api.render('#' + containerId, { sitekey: siteKey, theme: 'auto' })
            setReady(true)
            return
          }
          if (type === 'recaptcha-v3') {
            await loadScript(
              RECAPTCHA_SRC + '?render=' + encodeURIComponent(siteKey),
              'admin-google-recaptcha-v3',
            )
            if (cancelled) return
            const api = captchaWindow().grecaptcha
            if (!api) return
            await new Promise<void>(resolve => api.ready(() => resolve()))
            if (cancelled) return
            setReady(true)
            return
          }
          await loadScript(RECAPTCHA_SRC + '?render=explicit', 'admin-google-recaptcha')
          if (cancelled) return
          const api = captchaWindow().grecaptcha
          if (!api) return
          widgetId.current = api.render(containerId, { sitekey: siteKey })
          setReady(true)
        } catch {
          if (!cancelled) setReady(false)
        }
      }
      void mount()
      return () => {
        cancelled = true
      }
    }, [type, siteKey, containerId])

    useImperativeHandle(
      ref,
      () => ({
        getPayload: async () => {
          if (!active) return {}
          if (type === 'turnstile') {
            const token = captchaWindow().turnstile?.getResponse(widgetId.current as string)
            return token ? { turnstile_token: token } : {}
          }
          if (type === 'recaptcha') {
            const token = captchaWindow().grecaptcha?.getResponse(widgetId.current as number)
            return token ? { recaptcha_data: token } : {}
          }
          if (type === 'recaptcha-v3' && ready) {
            try {
              const token = await captchaWindow().grecaptcha?.execute(siteKey, { action: 'login' })
              return token ? { recaptcha_v3_token: token } : {}
            } catch {
              return {}
            }
          }
          return {}
        },
        reset: () => {
          if (type === 'turnstile') captchaWindow().turnstile?.reset(widgetId.current as string)
          else if (type === 'recaptcha') captchaWindow().grecaptcha?.reset(widgetId.current as number)
        },
      }),
      [active, ready, siteKey, type],
    )

    if (!active) return null
    return (
      <div className="admin-captcha">
        <div id={containerId} />
      </div>
    )
  },
)