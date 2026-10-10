/**
 * Framework-agnostic glue for Cloudflare Turnstile and Google reCAPTCHA
 * (v2 checkbox + v3 invisible).
 *
 * The React admin SPA and the Vue user SPA render the same widgets against the
 * same `/guest/comm/config` payload. Before this module each app carried its
 * own copy of the script loading, widget mounting, token retrieval and reset
 * logic, which meant every captcha fix had to be made twice. The mechanics live
 * here; each app keeps a thin component that binds this controller to its own
 * framework and payload conventions.
 */

export type CaptchaType = 'turnstile' | 'recaptcha' | 'recaptcha-v3'

/** The subset of the guest config that selects and configures a captcha. */
export type CaptchaConfig = {
  is_captcha?: number | boolean | null
  captcha_type?: string
  recaptcha_site_key?: string
  recaptcha_v3_site_key?: string
  turnstile_site_key?: string
}

export type CaptchaPayload = {
  recaptcha_data?: string
  recaptcha_v3_token?: string
  turnstile_token?: string
}

/** Why a reCAPTCHA v3 token could not be produced. */
export type V3UnavailableReason = 'not-ready' | 'error'

/**
 * Raised when the configured provider needs a token but none could be
 * obtained. Callers must let this abort the request rather than sending it
 * without a token: the API rejects a missing reCAPTCHA v3 token, and a
 * client-supplied "skip" flag would be a captcha bypass, so there is
 * deliberately no way for the browser to opt out.
 */
export class CaptchaUnavailableError extends Error {
  readonly reason: V3UnavailableReason

  constructor(reason: V3UnavailableReason) {
    super(
      reason === 'not-ready'
        ? '人机验证尚未就绪，请稍后重试'
        : '人机验证失败，请刷新页面后重试',
    )
    this.name = 'CaptchaUnavailableError'
    this.reason = reason
  }
}

const TURNSTILE_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js'
const RECAPTCHA_SRC = 'https://www.google.com/recaptcha/api.js'

/**
 * One script tag per provider, shared by every widget on the page. The ids are
 * intentionally generic now that both SPAs use this module; the two apps never
 * share a document.
 */
const SCRIPT_IDS: Record<CaptchaType, string> = {
  turnstile: 'tx-cf-turnstile',
  recaptcha: 'tx-google-recaptcha',
  'recaptcha-v3': 'tx-google-recaptcha-v3',
}

type TurnstileApi = {
  render: (target: string | HTMLElement, options: Record<string, unknown>) => string
  getResponse: (id?: string) => string
  reset: (id?: string) => void
}

type ReCaptchaApi = {
  render: (target: string | HTMLElement, options: Record<string, unknown>) => number
  getResponse: (id?: number) => string
  reset: (id?: number) => void
  ready: (callback: () => void) => void
  execute: (siteKey: string, options: { action: string }) => Promise<string>
}

type CaptchaWindow = Window & { turnstile?: TurnstileApi; grecaptcha?: ReCaptchaApi }

function captchaWindow(): CaptchaWindow {
  return window as CaptchaWindow
}

/**
 * Provider selected by the config. An absent type keeps the historical
 * reCAPTCHA v2 default; an unrecognized type returns `null` so callers fail
 * closed (render nothing) instead of silently falling back to a provider the
 * server did not ask for, which would be a captcha bypass.
 */
export function captchaType(config: CaptchaConfig | null | undefined): CaptchaType | null {
  const type = config?.captcha_type
  if (!type) return 'recaptcha'
  if (type === 'turnstile' || type === 'recaptcha' || type === 'recaptcha-v3') return type
  return null
}

function isEnabled(config: CaptchaConfig | null | undefined): boolean {
  return Number(config?.is_captcha || 0) === 1
}

/**
 * Site key for the configured provider, or `''` when captcha is disabled or
 * the matching key is missing. An empty result means "render nothing".
 */
export function captchaSiteKey(config: CaptchaConfig | null | undefined): string {
  if (!config || !isEnabled(config)) return ''
  const type = captchaType(config)
  if (type === null) return ''
  if (type === 'turnstile') return String(config.turnstile_site_key || '')
  if (type === 'recaptcha-v3') return String(config.recaptcha_v3_site_key || '')
  return String(config.recaptcha_site_key || '')
}

/**
 * Identity of the widget the current config asks for. Re-mounting is skipped
 * while this value is unchanged, so re-rendering a parent component cannot
 * stack duplicate widgets.
 */
export function captchaRenderKey(config: CaptchaConfig | null | undefined): string | null {
  const siteKey = captchaSiteKey(config)
  return siteKey ? `${captchaType(config)}:${siteKey}` : null
}

function loadScript(src: string, id: string): Promise<void> {
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
    script.onerror = () => {
      // Drop the failed tag so a later loadScript() retries instead of
      // treating the dead script as loaded.
      script.remove()
      reject(new Error('Failed to load captcha script'))
    }
    document.head.appendChild(script)
  })
}

/** Providers accept a selector or an element; prefer the element itself. */
function resolveContainer(containerId: string): string | HTMLElement {
  return document.getElementById(containerId) ?? '#' + containerId
}

export type CaptchaControllerOptions = {
  /** Read the current config. Called on every mount/payload/reset. */
  getConfig: () => CaptchaConfig | null | undefined
  /** Id of the element the provider renders into. */
  containerId: string
  /** Notified when the widget becomes usable (needed for v3 tokens). */
  onReadyChange?: (ready: boolean) => void
}

/**
 * Controls one captcha widget: mount it into a container, read its token, and
 * reset it. Framework wrappers own the element's lifecycle; this controller is
 * inert until `mount()` is called.
 */
export function createCaptchaController(options: CaptchaControllerOptions) {
  const { getConfig, containerId, onReadyChange } = options
  let widgetId: number | string | null = null
  let renderedKey: string | null = null
  let renderedType: CaptchaType | null = null
  let mountToken = 0
  let ready = false

  function setReady(value: boolean) {
    ready = value
    onReadyChange?.(value)
  }

  async function mount(): Promise<void> {
    const config = getConfig()
    const key = captchaRenderKey(config)
    if (key && key === renderedKey) return
    setReady(false)
    if (!key) {
      renderedKey = null
      renderedType = null
      return
    }
    const type = captchaType(config)
    const siteKey = captchaSiteKey(config)
    if (!type) return
    // A newer mount supersedes this one: after every await, bail out if the
    // token moved on, so concurrent mounts cannot stack duplicate widgets.
    const token = ++mountToken
    // Drop any previous widget (e.g. a stale iframe after a site-key rotation)
    // before rendering the new one into the same container.
    const container = document.getElementById(containerId)
    if (container) container.innerHTML = ''
    try {
      if (type === 'turnstile') {
        await loadScript(TURNSTILE_SRC, SCRIPT_IDS.turnstile)
        if (token !== mountToken) return
        const api = captchaWindow().turnstile
        if (!api) return
        widgetId = api.render(resolveContainer(containerId), { sitekey: siteKey, theme: 'auto' })
        renderedKey = key
        renderedType = type
        setReady(true)
        return
      }
      if (type === 'recaptcha-v3') {
        await loadScript(
          RECAPTCHA_SRC + '?render=' + encodeURIComponent(siteKey),
          SCRIPT_IDS['recaptcha-v3'],
        )
        if (token !== mountToken) return
        const api = captchaWindow().grecaptcha
        if (!api) return
        await new Promise<void>(resolve => api.ready(() => resolve()))
        if (token !== mountToken) return
        renderedKey = key
        renderedType = type
        setReady(true)
        return
      }
      await loadScript(RECAPTCHA_SRC + '?render=explicit', SCRIPT_IDS.recaptcha)
      if (token !== mountToken) return
      const api = captchaWindow().grecaptcha
      if (!api) return
      widgetId = api.render(resolveContainer(containerId), { sitekey: siteKey })
      renderedKey = key
      renderedType = type
      setReady(true)
    } catch {
      if (token === mountToken) {
        renderedKey = null
        renderedType = null
        setReady(false)
      }
    }
  }

  /**
   * Token(s) for the configured provider. reCAPTCHA v3 can legitimately fail
   * (script blocked, quota, not ready); that raises CaptchaUnavailableError so
   * the caller surfaces a real message instead of posting a request the API
   * will reject.
   */
  async function getPayload(payloadOptions?: {
    action?: string
  }): Promise<CaptchaPayload> {
    const config = getConfig()
    const siteKey = captchaSiteKey(config)
    if (!siteKey) return {}
    const type = captchaType(config)

    if (type === 'turnstile') {
      const token = captchaWindow().turnstile?.getResponse(widgetId as string)
      return token ? { turnstile_token: token } : {}
    }
    if (type === 'recaptcha') {
      const token = captchaWindow().grecaptcha?.getResponse(widgetId as number)
      return token ? { recaptcha_data: token } : {}
    }
    if (type === 'recaptcha-v3') {
      const api = captchaWindow().grecaptcha
      if (!ready || !api) throw new CaptchaUnavailableError('not-ready')
      try {
        const token = await api.execute(siteKey, { action: payloadOptions?.action ?? 'submit' })
        if (!token) throw new CaptchaUnavailableError('not-ready')
        return { recaptcha_v3_token: token }
      } catch (error) {
        if (error instanceof CaptchaUnavailableError) throw error
        throw new CaptchaUnavailableError('error')
      }
    }
    return {}
  }

  function reset(): void {
    // Dispatch on the provider the widget was actually rendered with, not the
    // current config: the config may have switched types since the render.
    if (renderedType === 'turnstile') captchaWindow().turnstile?.reset(widgetId as string)
    else if (renderedType === 'recaptcha') captchaWindow().grecaptcha?.reset(widgetId as number)
  }

  function dispose(): void {
    widgetId = null
    renderedKey = null
    renderedType = null
    setReady(false)
  }

  return {
    mount,
    getPayload,
    reset,
    dispose,
    isReady: () => ready,
    isActive: () => captchaSiteKey(getConfig()) !== '',
  }
}

export type CaptchaController = ReturnType<typeof createCaptchaController>
